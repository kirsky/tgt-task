<?php

declare(strict_types=1);

namespace App\Sync;

use App\Bitrix\Tasks;
use App\Clock;
use App\Domain\State;
use App\Domain\Transitions;
use App\Hire\HireService;
use App\Kv;

/**
 * Reads Bitrix24 for changes the app must react to. The trial portal cannot push events
 * to us, so the cron polls every minute.
 */
final class Poller
{
    /** Kv key: highest hire task id already looked at. */
    public const HIRE_CURSOR = 'last_hire_task_id';

    public function __construct(
        private \PDO $db,
        private Tasks $tasks,
        private HireService $hire,
        private Kv $kv,
        private Clock $clock,
    ) {
    }

    /**
     * Processes «Приём» tasks created since the last poll.
     *
     * @return int Number of new newcomers created.
     * @throws \App\Bitrix\BitrixUnavailable When the portal is down (the cursor does not advance).
     */
    public function pollHires(): int
    {
        $created = 0;
        foreach ($this->tasks->newHireTasks((int) $this->kv->get(self::HIRE_CURSOR)) as $t) {
            if ($this->hire->process($t['id'], $t['title'], $t['description']) !== null) {
                $created++;
            }
            $this->advanceCursor($t['id']);
        }

        return $created;
    }

    /**
     * Finishes hires that stopped halfway (Bitrix24 failed after the newcomer was created, e.g.
     * during a demo hire): creates the missing owner tasks and posts the invite comment.
     *
     * @return int Number of hires completed now.
     * @throws \App\Bitrix\BitrixUnavailable When the portal is still down.
     */
    public function resumeIncompleteHires(): int
    {
        $ids = $this->db->query(
            "SELECT n.hire_task_id FROM newcomers n LEFT JOIN processed_hire_tasks p ON p.task_id = n.hire_task_id
             WHERE n.is_example = 0 AND n.hire_task_id IS NOT NULL AND p.task_id IS NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $taskId) {
            $this->hire->process((int) $taskId, '', '');
        }

        return count($ids);
    }

    /**
     * Moves the hire cursor forward (never back), e.g. after the demo created a hire task itself.
     */
    public function advanceCursor(int $taskId): void
    {
        if ($taskId > (int) $this->kv->get(self::HIRE_CURSOR)) {
            $this->kv->set(self::HIRE_CURSOR, (string) $taskId);
        }
    }

    /**
     * Finds agreement tasks completed by their owners and marks them for a re-check.
     * Example newcomers are skipped.
     *
     * @return int Number of agreements that moved to `owner_closed`.
     */
    public function pollOwnerClosed(): int
    {
        $rows = $this->db->query(
            "SELECT g.id, g.state, g.bitrix_task_id FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id
             WHERE n.is_example = 0 AND g.bitrix_task_id IS NOT NULL AND g.state IN ('scheduled','asked','not_received','no_answer')"
        )->fetchAll();
        if ($rows === []) {
            return 0;
        }
        $status = $this->tasks->statuses(array_map(fn ($r) => (int) $r['bitrix_task_id'], $rows));
        $moved = 0;
        foreach ($rows as $r) {
            if (($status[(int) $r['bitrix_task_id']] ?? null) === Tasks::STATUS_COMPLETED) {
                $this->markOwnerClosed((int) $r['id'], State::from($r['state']));
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Applies «owner closed the task» to one agreement: clears any open initial question
     * (it will be asked again as a re-check at its ask time).
     */
    public function markOwnerClosed(int $agreementId, State $current): void
    {
        if (Transitions::ownerClosed($current) === null) {
            return;
        }
        $now = $this->clock->now()->format(DATE_ATOM);
        // Guarded by the state read before the Bitrix24 call: if the newcomer confirmed meanwhile
        // (and our own completion is what we just read back), nothing changes.
        $this->db->prepare(
            "UPDATE agreements SET state = 'owner_closed', owner_closed_at = ?, prompt_kind = NULL, prompt_intro = 0,
             prompt_queued_at = NULL, prompt_sent_at = NULL, reminded_at = NULL, updated_at = ? WHERE id = ? AND state = ?"
        )->execute([$now, $now, $agreementId, $current->value]);
    }

    /**
     * Marks disputes resolved once HR completes their «Закрыто, но не получено» task.
     *
     * @return int Number of disputes resolved now.
     */
    public function pollDisputes(): int
    {
        $rows = $this->db->query('SELECT id, bitrix_task_id FROM disputes WHERE resolved_at IS NULL AND bitrix_task_id IS NOT NULL')->fetchAll();
        if ($rows === []) {
            return 0;
        }
        $status = $this->tasks->statuses(array_map(fn ($r) => (int) $r['bitrix_task_id'], $rows));
        $resolved = 0;
        foreach ($rows as $r) {
            if (($status[(int) $r['bitrix_task_id']] ?? null) === Tasks::STATUS_COMPLETED) {
                $this->db->prepare('UPDATE disputes SET resolved_at = ? WHERE id = ?')->execute([$this->clock->now()->format(DATE_ATOM), $r['id']]);
                $resolved++;
            }
        }

        return $resolved;
    }
}
