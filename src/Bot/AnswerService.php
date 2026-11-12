<?php

declare(strict_types=1);

namespace App\Bot;

use App\Bitrix\BitrixError;
use App\Bitrix\BitrixUnavailable;
use App\Bitrix\Tasks;
use App\Clock;
use App\Domain\Answer;
use App\Domain\Effect;
use App\Domain\State;
use App\Domain\Transitions;
use App\Hire\HireRequest;
use App\Roles;

/**
 * Applies a newcomer's answer: saves the new state locally first, then the Bitrix24 effects.
 *
 * Two steps because «Частично»/«Нет» are followed by an optional note («Чего не хватает?»)
 * that must go into the comment or the HR task: {@see record()} saves the answer,
 * {@see finalize()} adds the note and applies the effects. If Bitrix24 is down, the
 * answer is kept and {@see retryPending()} applies the effects later.
 */
final class AnswerService
{
    /** Longest note stored and forwarded to Bitrix24. */
    public const NOTE_MAX = 500;

    /**
     * @param array<string,mixed> $portal Owner map; `owners.hr` receives dispute tasks.
     * @param string $appUrl Panel URL for links in HR tasks.
     */
    public function __construct(
        private \PDO $db,
        private Tasks $tasks,
        private Roles $roles,
        private array $portal,
        private Clock $clock,
        private string $appUrl,
    ) {
    }

    /**
     * Records an answer to an agreement and clears its pending prompt.
     *
     * @return array{status: 'ignored'|'done'|'needs_note', answerId?: int}
     *         `ignored` — the answer changes nothing (stale/double tap; nothing stored);
     *         `done` — «Да», effects applied (or queued if Bitrix24 is down);
     *         `needs_note` — ask «Чего не хватает?», then call {@see finalize()} with `answerId`.
     */
    public function record(int $agreementId, Answer $answer): array
    {
        $ag = $this->db->query("SELECT state, first_answer FROM agreements WHERE id = $agreementId")->fetch();
        if (!$ag) {
            return ['status' => 'ignored'];
        }
        $current = State::from($ag['state']);
        [$next, $effects] = Transitions::answer($current, $answer);
        if ($effects === []) {
            return ['status' => 'ignored'];
        }
        $recheck = Transitions::isRecheck($current);
        $now = $this->now();

        $this->db->beginTransaction();
        // Guarded by the state we decided on: a concurrent tap or the cron changing it first wins.
        $upd = $this->db->prepare(
            'UPDATE agreements SET state = ?, first_answer = COALESCE(first_answer, ?), prompt_kind = NULL, prompt_intro = 0,
             prompt_queued_at = NULL, prompt_sent_at = NULL, reminded_at = NULL, updated_at = ? WHERE id = ? AND state = ?'
        );
        $upd->execute([$next->value, $recheck ? null : $answer->value, $now, $agreementId, $current->value]);
        if ($upd->rowCount() === 0) {
            $this->db->rollBack();

            return ['status' => 'ignored'];
        }
        $this->db->prepare('INSERT INTO answers(agreement_id, kind, answer, effects, created_at) VALUES(?,?,?,?,?)')
            ->execute([$agreementId, $recheck ? 'recheck' : 'initial', $answer->value, json_encode(array_map(fn (Effect $e) => $e->value, $effects)), $now]);
        $answerId = (int) $this->db->lastInsertId();
        $this->db->commit();

        if ($answer === Answer::Yes) {
            $this->finalize($answerId, null);

            return ['status' => 'done'];
        }

        return ['status' => 'needs_note', 'answerId' => $answerId];
    }

    /**
     * Saves the optional note (trimmed, at most {@see NOTE_MAX} chars, empty → none) and applies
     * the Bitrix24 effects. Never throws on a Bitrix24 outage: effects stay pending.
     */
    public function finalize(int $answerId, ?string $note): void
    {
        $note = $note === null ? null : mb_substr(trim($note), 0, self::NOTE_MAX);
        $note = $note === '' ? null : $note;
        $this->db->prepare('UPDATE answers SET note = ?, finalized_at = ? WHERE id = ? AND finalized_at IS NULL')
            ->execute([$note, $this->now(), $answerId]);
        $this->applyEffects($answerId);
    }

    /**
     * Re-applies effects of finalized answers that could not reach Bitrix24 earlier.
     *
     * @return int Number of answers whose effects were applied now.
     */
    public function retryPending(): int
    {
        $ids = $this->db->query('SELECT id FROM answers WHERE finalized_at IS NOT NULL AND effects_applied_at IS NULL ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $applied = 0;
        foreach ($ids as $id) {
            if (!$this->applyEffects((int) $id)) {
                break; // Bitrix24 still down; try again next tick
            }
            $applied++;
        }

        return $applied;
    }

    /**
     * Finalizes answers whose note never came (e.g. the chat was re-bound or abandoned),
     * so their Bitrix24 effects are not lost.
     *
     * @param \DateTimeImmutable $before Answers created before this moment are finalized without a note.
     * @return int Number of answers finalized.
     */
    public function finalizeStale(\DateTimeImmutable $before): int
    {
        $st = $this->db->prepare('SELECT id FROM answers WHERE finalized_at IS NULL AND created_at < ?');
        $st->execute([$before->format(DATE_ATOM)]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->db->prepare('UPDATE chat_state SET awaiting_note_answer_id = NULL WHERE awaiting_note_answer_id = ?')->execute([$id]);
            $this->finalize((int) $id, null);
        }

        return count($ids);
    }

    /**
     * Performs the stored effects of one answer in Bitrix24.
     *
     * @return bool True when all effects were applied, false when Bitrix24 was unavailable.
     */
    private function applyEffects(int $answerId): bool
    {
        $row = $this->db->query(
            "SELECT a.*, g.bitrix_task_id, g.key, g.id AS agreement_id, n.full_name, n.role_key, n.id AS newcomer_id
             FROM answers a JOIN agreements g ON g.id = a.agreement_id JOIN newcomers n ON n.id = g.newcomer_id
             WHERE a.id = $answerId"
        )->fetch();
        if (!$row || $row['effects_applied_at'] !== null) {
            return true;
        }
        $task = (int) $row['bitrix_task_id'];
        $suffix = $row['note'] !== null ? ': «' . self::plain($row['note']) . '»' : '.';
        $effects = json_decode($row['effects'], true);
        $done = (int) $row['effects_done'];
        $progress = $this->db->prepare('UPDATE answers SET effects_done = ? WHERE id = ?');

        try {
            // Resume after the last effect that succeeded, so a retry never repeats a complete or a comment.
            foreach (array_slice($effects, $done) as $effect) {
                match (Effect::from($effect)) {
                    Effect::CompleteTask => $this->tasks->complete($task),
                    Effect::CommentConfirmed => $this->tasks->comment($task, 'Новичок подтвердил получение.'),
                    Effect::CommentPartial => $this->tasks->comment($task, 'Получение подтверждено частично' . $suffix),
                    Effect::CommentNotReceived => $this->tasks->comment($task, 'Новичок пока не получил' . $suffix),
                    Effect::CreateHrTask => $this->createHrTask($row, $task, $suffix),
                };
                $progress->execute([++$done, $answerId]);
            }
        } catch (BitrixUnavailable) {
            return false;
        } catch (BitrixError $e) {
            // Permanent (e.g. the task was deleted): record it and move on instead of retrying forever.
            $this->db->prepare('UPDATE answers SET effects_error = ?, effects_applied_at = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 300), $this->now(), $answerId]);

            return true;
        }
        $this->db->prepare('UPDATE answers SET effects_applied_at = ? WHERE id = ?')->execute([$this->now(), $answerId]);

        return true;
    }

    /**
     * Hands a «закрыто, но не получено» case to HR and records the dispute.
     * The owner's task is deliberately left closed: HR talks to both sides.
     *
     * @param array<string,mixed> $row Joined answer/agreement/newcomer row.
     */
    private function createHrTask(array $row, int $ownerTask, string $suffix): void
    {
        $a = $this->roles->agreement($row['role_key'], $row['key']);
        $label = Answer::from($row['answer'])->label();
        $id = $this->tasks->create([
            'TITLE' => "Закрыто, но не получено: {$a['task_title']} — " . HireRequest::short($row['full_name']),
            'RESPONSIBLE_ID' => $this->portal['owners']['hr'],
            'DEADLINE' => $this->clock->now()->modify('+24 hours')->format(DATE_ATOM),
            'DESCRIPTION' => "Ответственный закрыл задачу «{$a['task_title']}» ({$this->tasks->url($ownerTask)}), "
                . "но новичок ответил «{$label}»$suffix\nКарточка: {$this->appUrl}/n/{$row['newcomer_id']}",
            'TAGS' => ['новичок', 'договорённость'],
        ]);
        $this->db->prepare('INSERT OR IGNORE INTO disputes(agreement_id, bitrix_task_id, created_at) VALUES(?,?,?)')
            ->execute([$row['agreement_id'], $id, $this->now()]);
    }

    /**
     * Neutralizes Bitrix24 BBCode in user text (e.g. `[USER=19]` would mention someone),
     * keeping it readable: `[` becomes a full-width bracket.
     */
    private static function plain(string $text): string
    {
        return str_replace(['[', ']'], ['［', '］'], $text);
    }

    /** Current time as ISO string. */
    private function now(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }
}
