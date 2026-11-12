<?php

declare(strict_types=1);

namespace App\Bot;

use App\Clock;
use App\Domain\Answer;
use App\Domain\State;
use App\Roles;
use App\Schedule;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * The newcomer's side of the conversation: which question to ask, when, and what to say back.
 *
 * Questions are asked one at a time per chat. An agreement "has a queued prompt" when
 * `prompt_queued_at` is set and "an open prompt" once `prompt_sent_at` is set; answering
 * clears both. Used by the webhook (reacting to the newcomer) and by the cron dispatcher
 * (sending what became due), so it never relies on the current Telegram update.
 */
final class Conversation
{
    /** Callback data of the «Кто видит ответы?» button. */
    public const CB_WHO = 'who';

    /** How long the bot waits for the optional note before moving on without it. */
    public const NOTE_WAIT = '1 hour';

    public function __construct(
        private \PDO $db,
        private Nutgram $bot,
        private AnswerService $answers,
        private Roles $roles,
        private Schedule $schedule,
        private Clock $clock,
    ) {
    }

    /**
     * Handles `/start <code>`: binds the chat to the newcomer, greets, and asks what is already due.
     *
     * A code bound to another chat is refused (a forwarded link). The same chat opening a newer
     * code moves to that newcomer — one person, one chat (e.g. a reviewer trying the demo twice).
     *
     * @param string|null $code Invite code from the deep link, or null for a bare /start.
     */
    public function start(int $chatId, ?string $code): void
    {
        $n = null;
        if ($code !== null && $code !== '') {
            $st = $this->db->prepare('SELECT * FROM newcomers WHERE invite_code = ? AND is_example = 0');
            $st->execute([$code]);
            $n = $st->fetch() ?: null;
        }
        if ($n === null) {
            $this->say($chatId, Texts::noCode());

            return;
        }
        if ($n['tg_chat_id'] !== null && (int) $n['tg_chat_id'] !== $chatId) {
            $this->say($chatId, Texts::linkUsed());

            return;
        }
        $this->db->prepare('UPDATE newcomers SET tg_chat_id = NULL WHERE tg_chat_id = ? AND id <> ?')->execute([$chatId, $n['id']]);
        $this->db->prepare('UPDATE newcomers SET tg_chat_id = ? WHERE id = ?')->execute([$chatId, $n['id']]);
        $this->setAwaitingNote($chatId, null);

        $role = $this->roles->get($n['role_key']);
        $today = $this->clock->now()->format('Y-m-d');
        $text = $today < $n['start_date'] ? Texts::welcomeBefore($n['first_name'], $n['start_date'], $role) : Texts::welcomeAfter($n['first_name']);
        $this->say($chatId, $text, [[InlineKeyboardButton::make(Texts::WHO, callback_data: self::CB_WHO)]]);

        $this->queueDue((int) $n['id']);
        $this->pump($chatId, ignoreWindow: true);
    }

    /**
     * Handles a tap on Да/Частично/Нет and sends the follow-up.
     */
    public function answer(int $chatId, int $agreementId, Answer $answer): void
    {
        $ag = $this->agreementOfChat($chatId, $agreementId);
        if ($ag === null) {
            $this->say($chatId, Texts::alreadyNoted());

            return;
        }
        $wasRecheck = $ag['state'] === State::OwnerClosed->value;
        $r = $this->answers->record($agreementId, $answer);

        if ($r['status'] === 'ignored') {
            $this->say($chatId, Texts::alreadyNoted());

            return;
        }
        if ($r['status'] === 'needs_note') {
            $this->setAwaitingNote($chatId, $r['answerId']);
            $this->say($chatId, Texts::askNote(), [[InlineKeyboardButton::make(Texts::SKIP, callback_data: 'skip:' . $r['answerId'])]]);

            return;
        }
        if ($wasRecheck) {
            $this->say($chatId, Texts::recheckYes());
            $this->pump($chatId, ignoreWindow: true);

            return;
        }
        $this->nextOrDone($chatId);
    }

    /**
     * Handles free text: the awaited note, or a polite fixed reply if no note is expected.
     */
    public function text(int $chatId, string $text): void
    {
        $answerId = $this->awaitingNote($chatId);
        if ($answerId === null) {
            $this->say($chatId, Texts::freeText());

            return;
        }
        $this->finishNote($chatId, $answerId, $text);
    }

    /**
     * Handles the «Пропустить» button under the note question.
     */
    public function skip(int $chatId, int $answerId): void
    {
        if ($this->awaitingNote($chatId) !== $answerId) {
            $this->say($chatId, Texts::alreadyNoted());

            return;
        }
        $this->finishNote($chatId, $answerId, null);
    }

    /**
     * Gives up waiting for a note the newcomer never wrote: after {@see NOTE_WAIT} the answer is
     * finalized without a note (its Bitrix24 comment goes out) and the chat is unblocked.
     * Nothing is sent to the newcomer — they simply get the next question when it is due.
     *
     * @return bool Whether a stale note wait was closed.
     */
    public function expireStaleNote(int $chatId): bool
    {
        $answerId = $this->awaitingNote($chatId);
        if ($answerId === null) {
            return false;
        }
        $created = $this->db->query("SELECT created_at FROM answers WHERE id = $answerId")->fetchColumn();
        if ($created !== false && new \DateTimeImmutable((string) $created) > $this->clock->now()->modify('-' . self::NOTE_WAIT)) {
            return false;
        }
        $this->setAwaitingNote($chatId, null);
        $this->answers->finalize($answerId, null);

        return true;
    }

    /** Handles «Кто видит ответы?». */
    public function whoSees(int $chatId): void
    {
        $this->say($chatId, Texts::whoSees());
    }

    /**
     * Queues the questions of a newcomer that are due now: scheduled agreements whose ask time
     * has come (as initial questions) and agreements closed by their owner (as re-checks) —
     * a re-check also waits for the agreement's ask time, so an early close of a day-30 item is
     * confirmed on day 30. The first item of each day's batch carries the intro line.
     *
     * @return int Number of questions queued.
     */
    public function queueDue(int $newcomerId): int
    {
        $n = $this->db->query("SELECT role_key FROM newcomers WHERE id = $newcomerId")->fetch();
        if (!$n) {
            return 0;
        }
        $role = $this->roles->get($n['role_key']);
        $now = $this->clock->now();
        $rows = $this->db->query(
            "SELECT id, day, due_date, state FROM agreements WHERE newcomer_id = $newcomerId AND prompt_queued_at IS NULL
             AND (state = 'scheduled' OR (state = 'owner_closed' AND prompt_kind IS NULL)) ORDER BY day, id"
        )->fetchAll();

        $queued = 0;
        $introDays = [];
        $upd = $this->db->prepare('UPDATE agreements SET state = ?, prompt_kind = ?, prompt_intro = ?, prompt_queued_at = ?, updated_at = ? WHERE id = ?');
        foreach ($rows as $r) {
            if ($this->schedule->askAt($role, $r['due_date']) > $now) {
                continue;
            }
            $recheck = $r['state'] === State::OwnerClosed->value;
            $intro = !$recheck && !isset($introDays[$r['day']]) ? 1 : 0;
            $introDays[$r['day']] = true;
            $upd->execute([$recheck ? State::OwnerClosed->value : State::Asked->value, $recheck ? 'recheck' : 'initial', $intro, $now->format(DATE_ATOM), $now->format(DATE_ATOM), $r['id']]);
            $queued++;
        }

        return $queued;
    }

    /**
     * Sends the next queued question to a chat, if nothing is open and the quiet-hours
     * window allows it.
     *
     * @param bool $ignoreWindow True when the newcomer (or a demo reviewer) is actively chatting.
     * @return bool Whether a question was sent.
     */
    public function pump(int $chatId, bool $ignoreWindow = false): bool
    {
        $n = $this->db->query("SELECT id, role_key FROM newcomers WHERE tg_chat_id = $chatId")->fetch();
        if (!$n || $this->awaitingNote($chatId) !== null) {
            return false;
        }
        $open = $this->db->query("SELECT COUNT(*) FROM agreements WHERE newcomer_id = {$n['id']} AND prompt_sent_at IS NOT NULL")->fetchColumn();
        if ((int) $open > 0) {
            return false;
        }
        if (!$ignoreWindow && !$this->schedule->windowOpen($this->roles->get($n['role_key']), $this->clock->now())) {
            return false;
        }
        $next = $this->db->query(
            "SELECT * FROM agreements WHERE newcomer_id = {$n['id']} AND prompt_queued_at IS NOT NULL AND prompt_sent_at IS NULL
             ORDER BY prompt_kind = 'recheck', day, id LIMIT 1"
        )->fetch();
        if (!$next) {
            return false;
        }
        $this->sendPrompt($chatId, $n['role_key'], $next, null);

        return true;
    }

    /**
     * Sends the reminder for an open, unanswered question («Напомню вопрос: …»).
     */
    public function resend(int $agreementId): void
    {
        $row = $this->db->query(
            "SELECT g.*, n.role_key, n.tg_chat_id FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id WHERE g.id = $agreementId"
        )->fetch();
        if (!$row || $row['tg_chat_id'] === null || $row['prompt_sent_at'] === null) {
            return;
        }
        $this->sendPrompt((int) $row['tg_chat_id'], $row['role_key'], $row, Texts::reminderPrefix());
        $this->db->prepare('UPDATE agreements SET reminded_at = ? WHERE id = ?')->execute([$this->clock->now()->format(DATE_ATOM), $agreementId]);
    }

    /**
     * Sends one question with the three answer buttons and marks it open.
     *
     * @param array<string,mixed> $ag     Agreement row.
     * @param string|null         $prefix Reminder prefix, or null for a first send.
     */
    private function sendPrompt(int $chatId, string $roleKey, array $ag, ?string $prefix): void
    {
        $def = $this->roles->agreement($roleKey, $ag['key']);
        $question = $ag['prompt_kind'] === 'recheck' ? Texts::recheck($def['done_text']) : Texts::e($def['question']);
        $lead = $prefix ?? ($ag['prompt_intro'] ? Texts::intro((int) $ag['day']) : null);
        $buttons = array_map(
            fn (Answer $a) => InlineKeyboardButton::make($a->label(), callback_data: "a:{$ag['id']}:{$a->value}"),
            Answer::cases(),
        );
        $this->say($chatId, $lead !== null ? "$lead\n\n$question" : $question, [$buttons]);
        if ($prefix === null) {
            $this->db->prepare('UPDATE agreements SET prompt_sent_at = ? WHERE id = ?')->execute([$this->clock->now()->format(DATE_ATOM), $ag['id']]);
        }
    }

    /**
     * Saves the note, thanks the newcomer and asks the next queued question, if any.
     * «Спасибо, учли.» already closes the exchange, so no extra «Спасибо!» follows it.
     */
    private function finishNote(int $chatId, int $answerId, ?string $note): void
    {
        $this->setAwaitingNote($chatId, null);
        $this->answers->finalize($answerId, $note);
        $this->say($chatId, Texts::thanksNoted());
        $this->pump($chatId, ignoreWindow: true);
    }

    /** After an initial answer: the next question, or «Спасибо!» when the batch is over. */
    private function nextOrDone(int $chatId): void
    {
        if (!$this->pump($chatId, ignoreWindow: true)) {
            $this->say($chatId, Texts::batchDone());
        }
    }

    /**
     * The agreement if it belongs to the newcomer bound to this chat, else null.
     *
     * @return array<string,mixed>|null
     */
    private function agreementOfChat(int $chatId, int $agreementId): ?array
    {
        $st = $this->db->prepare('SELECT g.* FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id WHERE g.id = ? AND n.tg_chat_id = ?');
        $st->execute([$agreementId, $chatId]);

        return $st->fetch() ?: null;
    }

    /** Answer id whose note the bot is waiting for in this chat, if any. */
    private function awaitingNote(int $chatId): ?int
    {
        $v = $this->db->query("SELECT awaiting_note_answer_id FROM chat_state WHERE chat_id = $chatId")->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    /** Sets or clears the awaited note for a chat. */
    private function setAwaitingNote(int $chatId, ?int $answerId): void
    {
        $this->db->prepare('INSERT INTO chat_state(chat_id, awaiting_note_answer_id) VALUES(?, ?) ON CONFLICT(chat_id) DO UPDATE SET awaiting_note_answer_id = excluded.awaiting_note_answer_id')
            ->execute([$chatId, $answerId]);
    }

    /**
     * Sends an HTML message, optionally with inline buttons.
     *
     * @param list<list<InlineKeyboardButton>> $rows Button rows.
     */
    private function say(int $chatId, string $text, array $rows = []): void
    {
        $markup = null;
        if ($rows !== []) {
            $markup = InlineKeyboardMarkup::make();
            foreach ($rows as $row) {
                $markup->addRow(...$row);
            }
        }
        $this->bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
