<?php

declare(strict_types=1);

namespace App\Sync;

use App\Bot\AnswerService;
use App\Bot\Conversation;
use App\Clock;
use App\Roles;
use App\Schedule;

/**
 * Time-driven part of the cron tick: for every connected newcomer, queue what became due,
 * remind once about an unanswered question, give up after 48 hours, and send the next
 * question — all within the role's quiet-hours window.
 */
final class Dispatcher
{
    /** After this long without an answer, the question counts as «нет ответа». */
    private const NO_ANSWER_AFTER = '+48 hours';

    public function __construct(
        private \PDO $db,
        private Conversation $conversation,
        private AnswerService $answers,
        private Roles $roles,
        private Schedule $schedule,
        private Clock $clock,
    ) {
    }

    /**
     * Runs one pass over all connected, non-example newcomers, then retries Bitrix24 effects
     * that failed earlier.
     */
    public function run(): void
    {
        $now = $this->clock->now();
        $newcomers = $this->db->query('SELECT id, role_key, tg_chat_id FROM newcomers WHERE is_example = 0 AND tg_chat_id IS NOT NULL')->fetchAll();
        foreach ($newcomers as $n) {
            $role = $this->roles->get($n['role_key']);
            $chat = (int) $n['tg_chat_id'];
            $this->conversation->expireStaleNote($chat);
            $this->conversation->queueDue((int) $n['id']);

            $open = $this->db->query("SELECT id, prompt_kind, prompt_sent_at, reminded_at FROM agreements WHERE newcomer_id = {$n['id']} AND prompt_sent_at IS NOT NULL")->fetch();
            if ($open) {
                $sentAt = new \DateTimeImmutable($open['prompt_sent_at']);
                if ($now >= $sentAt->modify(self::NO_ANSWER_AFTER)) {
                    $this->expire($open);
                } elseif ($open['reminded_at'] === null && $now >= $this->schedule->reminderAt($role, $sentAt) && $this->schedule->windowOpen($role, $now)) {
                    $this->conversation->resend((int) $open['id']);
                }
            }
            $this->conversation->pump($chat);
        }
        $this->answers->retryPending();
    }

    /**
     * Closes an unanswered question. An initial question becomes «нет ответа»; an unanswered
     * re-check keeps the `owner_closed` state but is not asked again (`prompt_kind = expired`).
     *
     * @param array<string,mixed> $open Agreement row with the open prompt.
     */
    private function expire(array $open): void
    {
        $recheck = $open['prompt_kind'] === 'recheck';
        $this->db->prepare(
            "UPDATE agreements SET state = CASE WHEN ? THEN state ELSE 'no_answer' END, prompt_kind = CASE WHEN ? THEN 'expired' ELSE NULL END,
             prompt_intro = 0, prompt_queued_at = NULL, prompt_sent_at = NULL, reminded_at = NULL, updated_at = ? WHERE id = ?"
        )->execute([$recheck ? 1 : 0, $recheck ? 1 : 0, $this->clock->now()->format(DATE_ATOM), $open['id']]);
    }
}
