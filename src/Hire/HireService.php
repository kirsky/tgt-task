<?php

declare(strict_types=1);

namespace App\Hire;

use App\Bitrix\Tasks;
use App\Clock;
use App\Domain\State;
use App\Roles;
use App\Schedule;

/**
 * Turns a «Приём» task into a newcomer on probation.
 *
 * For every agreement of the newcomer's role it creates a Bitrix24 task for the person
 * responsible (shift lead, HR, accounting…), with HR as observer and the deadline on the
 * day the agreement is due. It then posts the newcomer's personal bot link as a comment
 * on the hire task, so HR can send it.
 *
 * Idempotent and retry-safe: if Bitrix24 fails halfway, the next call creates only the
 * tasks that are still missing and never duplicates the invite comment.
 */
final class HireService
{
    /**
     * @param array<string,mixed> $portal Owner role → Bitrix24 user id map (`config/portal.php`).
     * @param string $appUrl      Panel URL, used for links in task descriptions.
     * @param string $botUsername Telegram bot username for invite links.
     */
    public function __construct(
        private \PDO $db,
        private Tasks $tasks,
        private Roles $roles,
        private Schedule $schedule,
        private array $portal,
        private Clock $clock,
        private string $appUrl,
        private string $botUsername,
    ) {
    }

    /**
     * Processes one hire task.
     *
     * @param int    $hireTaskId  Bitrix24 id of the «Приём» task.
     * @param string $title       Its title.
     * @param string $description Its description.
     * @return int|null Newcomer id, or null when the task is not usable (not a hire task,
     *                  bad format, unknown role — the last two get one explanatory comment).
     * @throws \App\Bitrix\BitrixUnavailable When the portal fails; call again later to resume.
     */
    public function process(int $hireTaskId, string $title, string $description): ?int
    {
        $done = $this->processed($hireTaskId);
        if ($done !== null && $done['status'] !== 'ok') {
            return null;
        }
        $newcomerId = $this->newcomerByHireTask($hireTaskId);

        if ($newcomerId === null) {
            $req = HireTaskParser::parse($title, $description);
            if ($req === HireTaskParser::NOT_HIRE) {
                return null;
            }
            if ($req === HireTaskParser::BAD_FORMAT) {
                $this->reject($hireTaskId, 'bad_format', 'Не смог разобрать задачу. Нужны строки: «Должность: …», «Подразделение: …», «Дата выхода: ДД.ММ.ГГГГ».');

                return null;
            }
            $roleKey = $this->roles->findByTitle($req->roleTitle);
            if ($roleKey === null) {
                $this->reject($hireTaskId, 'unknown_role', "Не нашёл список договорённостей для должности «{$req->roleTitle}».");

                return null;
            }
            $newcomerId = $this->createNewcomer($hireTaskId, $req, $roleKey);
        }

        $this->createMissingTasks($newcomerId);

        if ($done === null) {
            $code = (string) $this->db->query("SELECT invite_code FROM newcomers WHERE id = $newcomerId")->fetchColumn();
            $this->tasks->comment($hireTaskId, "Личная ссылка для новичка в боте «Новичок»: {$this->inviteLink($code)}\nКарточка: {$this->appUrl}/n/$newcomerId");
            $this->markProcessed($hireTaskId, 'ok', $newcomerId);
        }

        return $newcomerId;
    }

    /**
     * Personal deep link that binds the newcomer's Telegram chat on /start.
     */
    public function inviteLink(string $code): string
    {
        return "https://t.me/{$this->botUsername}?start=$code";
    }

    /**
     * Inserts the newcomer and their scheduled agreements (no Bitrix24 calls yet).
     */
    private function createNewcomer(int $hireTaskId, HireRequest $req, string $roleKey): int
    {
        $now = $this->clock->now()->format(DATE_ATOM);
        $this->db->beginTransaction();
        $this->db->prepare('INSERT INTO newcomers(full_name, first_name, role_key, start_date, invite_code, hire_task_id, created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$req->fullName, $req->firstName, $roleKey, $req->startDate, bin2hex(random_bytes(8)), $hireTaskId, $now]);
        $id = (int) $this->db->lastInsertId();
        $ins = $this->db->prepare('INSERT INTO agreements(newcomer_id, key, day, due_date, state, updated_at) VALUES(?,?,?,?,?,?)');
        foreach ($this->roles->get($roleKey)['agreements'] as $a) {
            $ins->execute([$id, $a['key'], $a['day'], $this->schedule->dueDate($req->startDate, $a['day']), State::Scheduled->value, $now]);
        }
        $this->db->commit();

        return $id;
    }

    /**
     * Creates owner tasks for agreements that do not have one yet, saving each id immediately
     * so a failure in the middle loses nothing.
     */
    private function createMissingTasks(int $newcomerId): void
    {
        $n = $this->db->query("SELECT * FROM newcomers WHERE id = $newcomerId")->fetch();
        $role = $this->roles->get($n['role_key']);
        $short = HireRequest::short($n['full_name']);
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $n['start_date'])->format('d.m.Y');
        $save = $this->db->prepare('UPDATE agreements SET bitrix_task_id = ?, updated_at = ? WHERE id = ?');

        $rows = $this->db->query("SELECT id, key, due_date FROM agreements WHERE newcomer_id = $newcomerId AND bitrix_task_id IS NULL ORDER BY id")->fetchAll();
        foreach ($rows as $row) {
            $a = $this->roles->agreement($n['role_key'], $row['key']);
            $taskId = $this->tasks->create([
                'TITLE' => "{$a['task_title']} — $short (новичок, {$role['department']})",
                'RESPONSIBLE_ID' => $this->portal['owners'][$a['owner']],
                'AUDITORS' => [$this->portal['owners']['hr']],
                'DEADLINE' => $this->schedule->deadline($role, $row['due_date'])->format(DATE_ATOM),
                'DESCRIPTION' => "Договорённость при приёме: {$a['promise']}\n"
                    . "Новичок: {$n['full_name']}, {$role['title']}, выход $start\n"
                    . "Карточка: {$this->appUrl}/n/$newcomerId\n\n"
                    . 'Закроется автоматически, когда новичок подтвердит получение.',
                'TAGS' => ['новичок', 'договорённость'],
            ]);
            $save->execute([$taskId, $this->clock->now()->format(DATE_ATOM), $row['id']]);
        }
    }

    /** Comments why a hire task cannot be used and records it so the comment is posted once. */
    private function reject(int $hireTaskId, string $status, string $comment): void
    {
        $this->tasks->comment($hireTaskId, $comment);
        $this->markProcessed($hireTaskId, $status, null);
    }

    /** Records the outcome of a hire task. */
    private function markProcessed(int $hireTaskId, string $status, ?int $newcomerId): void
    {
        $this->db->prepare('INSERT OR REPLACE INTO processed_hire_tasks(task_id, status, newcomer_id, processed_at) VALUES(?,?,?,?)')
            ->execute([$hireTaskId, $status, $newcomerId, $this->clock->now()->format(DATE_ATOM)]);
    }

    /** @return array{status:string,newcomer_id:?int}|null Previous outcome, if any. */
    private function processed(int $hireTaskId): ?array
    {
        $st = $this->db->prepare('SELECT status, newcomer_id FROM processed_hire_tasks WHERE task_id = ?');
        $st->execute([$hireTaskId]);

        return $st->fetch() ?: null;
    }

    /** Newcomer already created from this hire task (possibly with tasks still missing). */
    private function newcomerByHireTask(int $hireTaskId): ?int
    {
        $st = $this->db->prepare('SELECT id FROM newcomers WHERE hire_task_id = ?');
        $st->execute([$hireTaskId]);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
