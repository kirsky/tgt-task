<?php

declare(strict_types=1);

namespace App\Demo;

use App\Container;
use App\Domain\State;

/**
 * Demo controls that let a reviewer play every role in a few minutes instead of waiting
 * days: hire someone (a real «Приём» task in Bitrix24), send the next day's questions now,
 * and act as the owner who closes a task. All of them go through the same code paths
 * as the real cron and webhook.
 */
final class DemoService
{
    public function __construct(private Container $c)
    {
    }

    /**
     * Creates a «Приём» task in Bitrix24 exactly as HR would, then processes it right away
     * (no waiting for the next cron tick).
     *
     * @param string $fullName  «Фамилия Имя».
     * @param string $roleKey   Configured role key.
     * @param string $startDate `Y-m-d`.
     * @return int Newcomer id.
     * @throws \App\Bitrix\BitrixUnavailable When the portal is down.
     */
    public function hire(string $fullName, string $roleKey, string $startDate): int
    {
        $role = $this->c->roles()->get($roleKey);
        $title = 'Приём: ' . trim($fullName);
        $description = "Должность: {$role['title']}\nПодразделение: {$role['department']}\nДата выхода: "
            . (new \DateTimeImmutable($startDate))->format('d.m.Y');

        $taskId = $this->c->tasks()->create([
            'TITLE' => $title,
            'RESPONSIBLE_ID' => $this->c->portal()['owners']['hr'],
            'DESCRIPTION' => $description,
        ]);
        $this->c->poller()->advanceCursor($taskId);

        return $this->c->hire()->process($taskId, $title, $description)
            ?? throw new \RuntimeException('Hire task was not accepted');
    }

    /**
     * Sends the questions of the earliest day that still has scheduled agreements, as if that
     * day had come. Ignores quiet hours: the reviewer is waiting for the message.
     */
    public function nextBatch(int $newcomerId): void
    {
        $db = $this->c->db();
        $day = $db->query("SELECT MIN(day) FROM agreements WHERE newcomer_id = $newcomerId AND state = 'scheduled'")->fetchColumn();
        if ($day === null || $day === false) {
            return;
        }
        $now = $this->c->clock()->now()->format(DATE_ATOM);
        $ids = $db->query("SELECT id FROM agreements WHERE newcomer_id = $newcomerId AND state = 'scheduled' AND day = $day ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $upd = $db->prepare("UPDATE agreements SET state = 'asked', prompt_kind = 'initial', prompt_intro = ?, prompt_queued_at = ?, updated_at = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $upd->execute([$i === 0 ? 1 : 0, $now, $now, $id]);
        }
        $this->pump($newcomerId);
    }

    /**
     * Acts as the responsible person: completes the agreement's task in Bitrix24, then applies
     * the same «owner closed» rule as the poller and sends the re-check now.
     *
     * @throws \App\Bitrix\BitrixUnavailable When the portal is down.
     */
    public function closeAsOwner(int $agreementId): void
    {
        $db = $this->c->db();
        $ag = $db->query("SELECT newcomer_id, state, bitrix_task_id, due_date FROM agreements WHERE id = $agreementId")->fetch();
        if (!$ag || $ag['bitrix_task_id'] === null) {
            return;
        }
        $this->c->tasks()->complete((int) $ag['bitrix_task_id']);
        $this->c->poller()->markOwnerClosed($agreementId, State::from($ag['state']));

        // In the demo the re-check is wanted now, even if the item's own day is still ahead.
        $now = $this->c->clock()->now()->format(DATE_ATOM);
        $db->prepare("UPDATE agreements SET prompt_kind = 'recheck', prompt_queued_at = ?, updated_at = ? WHERE id = ? AND state = 'owner_closed'")
            ->execute([$now, $now, $agreementId]);
        $this->pump((int) $ag['newcomer_id']);
    }

    /** Sends the next queued question to the newcomer's chat, ignoring quiet hours. */
    private function pump(int $newcomerId): void
    {
        $chat = $this->c->db()->query("SELECT tg_chat_id FROM newcomers WHERE id = $newcomerId")->fetchColumn();
        if ($chat !== null && $chat !== false) {
            $this->c->conversation()->pump((int) $chat, ignoreWindow: true);
        }
    }
}
