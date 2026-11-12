<?php

declare(strict_types=1);

namespace Tests;

use App\Container;
use App\Bot\Handlers;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\FakeTasks;
use Tests\Support\FrozenClock;
use Tests\Support\TestApp;

/**
 * The once-a-minute tick: polling Bitrix24 (new hires, closed tasks, resolved disputes)
 * and sending what is due within quiet hours (questions, re-checks, reminders).
 */
final class SyncTest extends TestCase
{
    private const CHAT = 555;

    private Container $c;
    private FakeNutgram $bot;
    private FakeTasks $tasks;
    private FrozenClock $clock;
    private int $newcomerId;

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T20:20:00+03:00');
        $this->clock = $this->c->clock();
        $this->bot = Nutgram::fake();
        $this->bot->setCommonUser(User::make(self::CHAT, false, 'Тест'));
        $this->bot->setCommonChat(Chat::make(self::CHAT, 'private'));
        $this->c->set('bot', $this->bot);
        Handlers::register($this->bot, $this->c);
        $this->tasks = $this->c->tasks();
        $this->newcomerId = $this->c->hire()->process(77, 'Приём: Петров Алексей', "Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 17.11.2026");
    }

    /** Connects the newcomer (as if they pressed Start) and answers all day-1 questions «Да». */
    private function connectAndAnswerDay1(): void
    {
        $code = $this->c->db()->query("SELECT invite_code FROM newcomers WHERE id = {$this->newcomerId}")->fetchColumn();
        $this->bot->hearText("/start $code")->reply();
        foreach (['met', 'workwear', 'mentor'] as $k) {
            $this->bot->hearCallbackQueryData("a:{$this->id($k)}:yes")->reply();
        }
    }

    private function id(string $key): int
    {
        return (int) $this->c->db()->query("SELECT id FROM agreements WHERE newcomer_id = {$this->newcomerId} AND key = '$key'")->fetchColumn();
    }

    private function task(string $key): int
    {
        return (int) $this->c->db()->query("SELECT bitrix_task_id FROM agreements WHERE id = {$this->id($key)}")->fetchColumn();
    }

    /** Runs one tick at a given Minsk time and returns the texts the bot sent during it. */
    private function tickAt(string $local): array
    {
        $this->clock->set($local . '+03:00');
        $before = count($this->bot->getRequestHistory());
        $this->c->poller()->pollOwnerClosed();
        $this->c->poller()->pollDisputes();
        $this->c->dispatcher()->run();
        $texts = [];
        foreach (array_slice($this->bot->getRequestHistory(), $before) as $h) {
            if (basename($h['request']->getUri()->getPath()) === 'sendMessage') {
                $p = json_decode((string) $h['request']->getBody(), true) ?? [];
                $texts[] = $p['text'] ?? '';
            }
        }

        return $texts;
    }

    /** A task completed by its owner leads to a re-check, sent inside the window. */
    public function test_owner_closed_queues_recheck_and_sends_in_window(): void
    {
        $code = $this->c->db()->query("SELECT invite_code FROM newcomers WHERE id = {$this->newcomerId}")->fetchColumn();
        $this->bot->hearText("/start $code")->reply();
        $this->bot->hearCallbackQueryData("a:{$this->id('met')}:yes")->reply();
        $this->bot->hearCallbackQueryData("a:{$this->id('workwear')}:partial")->reply();
        $this->bot->hearText('обуви нет')->reply();
        $this->bot->hearCallbackQueryData("a:{$this->id('mentor')}:yes")->reply();

        $this->tasks->status[$this->task('workwear')] = 5;
        self::assertSame(['Отмечено, что спецодежду и обувь выдали. Всё так?'], $this->tickAt('2026-11-17T21:00:00'));
        self::assertSame('owner_closed', $this->c->db()->query("SELECT state FROM agreements WHERE id = {$this->id('workwear')}")->fetchColumn());
    }

    /** Nothing is sent after 22:00; the queued question goes out when the window opens at 06:00. */
    public function test_nothing_sent_outside_window(): void
    {
        $this->connectAndAnswerDay1();
        $this->tasks->status[$this->task('scanner')] = 5; // owner closed a day-3 item early
        self::assertSame([], $this->tickAt('2026-11-19T23:00:00'));
        self::assertSame([], $this->tickAt('2026-11-20T05:59:00'));
        $sent = $this->tickAt('2026-11-20T06:00:00');
        self::assertCount(1, $sent);
    }

    /** Day-3 questions go out exactly at the role's ask time, with the short intro. */
    public function test_day3_batch_sent_at_ask_time(): void
    {
        $this->connectAndAnswerDay1();
        self::assertSame([], $this->tickAt('2026-11-19T20:14:00'));
        self::assertSame(["Уточню пару вещей.\n\nУ тебя есть доступ к ТСД под твоим логином?"], $this->tickAt('2026-11-19T20:15:00'));
        self::assertSame([], $this->tickAt('2026-11-19T20:16:00'), 'one question at a time');
    }

    /** An early close of a day-30 item is re-checked only on day 30. */
    public function test_early_close_waits_for_ask_time(): void
    {
        $this->connectAndAnswerDay1();
        $this->tasks->status[$this->task('first_pay')] = 5;
        self::assertSame([], $this->tickAt('2026-11-18T10:00:00'));
        $this->c->db()->exec("UPDATE agreements SET state = 'confirmed' WHERE newcomer_id = {$this->newcomerId} AND day < 30");
        self::assertSame([], $this->tickAt('2026-12-16T20:14:00'));
        self::assertSame(['Отмечено, что первая зарплата совпала с условиями. Всё так?'], $this->tickAt('2026-12-16T20:15:00'));
    }

    /** An unanswered question is repeated once, the next day one hour after work starts. */
    public function test_reminder_next_morning(): void
    {
        $this->connectAndAnswerDay1();
        $this->tickAt('2026-11-19T20:15:00');
        self::assertSame([], $this->tickAt('2026-11-20T08:59:00'));
        self::assertSame(["Напомню вопрос:\n\nУ тебя есть доступ к ТСД под твоим логином?"], $this->tickAt('2026-11-20T09:00:00'));
        self::assertSame([], $this->tickAt('2026-11-20T10:00:00'));
    }

    /** No answer within 48 hours: the item is marked «нет ответа» and the next question follows. */
    public function test_no_answer_after_48h(): void
    {
        $this->connectAndAnswerDay1();
        $this->tickAt('2026-11-19T20:15:00');
        $sent = $this->tickAt('2026-11-21T20:15:00');
        self::assertSame('no_answer', $this->c->db()->query("SELECT state FROM agreements WHERE id = {$this->id('scanner')}")->fetchColumn());
        self::assertSame(['Ты знаешь свой график смен на следующую неделю?'], $sent);
    }

    /** Example newcomers (demo data) never touch Bitrix24 or Telegram. */
    public function test_examples_are_ignored(): void
    {
        $this->c->db()->exec("UPDATE newcomers SET is_example = 1, tg_chat_id = 999 WHERE id = {$this->newcomerId}");
        $this->tasks->calls = [];
        self::assertSame([], $this->tickAt('2026-11-17T20:30:00'));
        self::assertSame([], $this->tasks->calls);
    }

    /** A new hire task is picked up once and the cursor advances. */
    public function test_new_hire_is_processed_once(): void
    {
        $this->tasks->hireTasks = [['id' => 90, 'title' => 'Приём: Савич Анна', 'description' => "Должность: Продавец-консультант\nПодразделение: Магазин\nДата выхода: 18.11.2026"]];
        self::assertSame(1, $this->c->poller()->pollHires());
        self::assertSame(0, $this->c->poller()->pollHires());
        self::assertSame('90', $this->c->kv()->get('last_hire_task_id'));
        self::assertSame(2, (int) $this->c->db()->query('SELECT COUNT(*) FROM newcomers')->fetchColumn());
    }

    /** HR completing the dispute task marks the dispute resolved. */
    public function test_dispute_resolution(): void
    {
        $this->c->db()->exec("INSERT INTO disputes(agreement_id, bitrix_task_id, created_at) VALUES({$this->id('scanner')}, 4242, '2026-11-20T10:00:00+03:00')");
        $this->tasks->status[4242] = 5;
        self::assertSame(1, $this->c->poller()->pollDisputes());
        self::assertNotNull($this->c->db()->query("SELECT resolved_at FROM disputes WHERE bitrix_task_id = 4242")->fetchColumn());
    }
}
