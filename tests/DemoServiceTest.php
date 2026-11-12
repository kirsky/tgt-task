<?php

declare(strict_types=1);

namespace Tests;

use App\Bot\Handlers;
use App\Container;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * Demo controls a reviewer uses instead of waiting days: hire, «next questions now», close as owner.
 */
final class DemoServiceTest extends TestCase
{
    private Container $c;
    private FakeNutgram $bot;
    private FakeTasks $tasks;

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T23:30:00+03:00');
        $this->bot = Nutgram::fake();
        $this->bot->setCommonUser(User::make(555, false, 'Тест'));
        $this->bot->setCommonChat(Chat::make(555, 'private'));
        $this->c->set('bot', $this->bot);
        Handlers::register($this->bot, $this->c);
        $this->tasks = $this->c->tasks();
    }

    /** @return list<string> Texts of messages sent since the given history position. */
    private function textsSince(int $pos): array
    {
        $out = [];
        foreach (array_slice($this->bot->getRequestHistory(), $pos) as $h) {
            if (basename($h['request']->getUri()->getPath()) === 'sendMessage') {
                $out[] = json_decode((string) $h['request']->getBody(), true)['text'] ?? '';
            }
        }

        return $out;
    }

    /** Hiring creates a real-looking «Приём» task in Bitrix24 and processes it at once. */
    public function test_hire_creates_bitrix_hire_task_and_newcomer(): void
    {
        $id = $this->c->demo()->hire('Петров Алексей', 'warehouse_picker', '2026-11-18');
        $hire = $this->tasks->callsOf('create')[0][0];
        self::assertSame('Приём: Петров Алексей', $hire['TITLE']);
        self::assertSame("Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 18.11.2026", $hire['DESCRIPTION']);
        self::assertSame(19, $hire['RESPONSIBLE_ID']);
        self::assertCount(9, $this->tasks->callsOf('create'), 'hire task + 8 agreement tasks');
        self::assertSame((string) array_key_first($this->tasks->created), $this->c->kv()->get('last_hire_task_id'));
        self::assertSame('Петров Алексей', $this->c->db()->query("SELECT full_name FROM newcomers WHERE id = $id")->fetchColumn());
    }

    /** «Следующий опрос сейчас» sends the next day's questions immediately, even at night. */
    public function test_next_batch_sends_day3_ignoring_window(): void
    {
        $id = $this->c->demo()->hire('Петров Алексей', 'warehouse_picker', '2026-11-17');
        $code = $this->c->db()->query("SELECT invite_code FROM newcomers WHERE id = $id")->fetchColumn();
        $this->bot->hearText("/start $code")->reply();
        $this->c->db()->exec("UPDATE agreements SET state = 'confirmed', prompt_queued_at = NULL, prompt_sent_at = NULL WHERE newcomer_id = $id AND day = 1");

        $pos = count($this->bot->getRequestHistory());
        $this->c->demo()->nextBatch($id);
        self::assertSame(["Уточню пару вещей.\n\nУ тебя есть доступ к ТСД под твоим логином?"], $this->textsSince($pos));
    }

    /** «Закрыть как ответственный» completes the task in Bitrix24 and the re-check follows at once. */
    public function test_close_as_owner_triggers_recheck(): void
    {
        $id = $this->c->demo()->hire('Петров Алексей', 'warehouse_picker', '2026-11-17');
        $code = $this->c->db()->query("SELECT invite_code FROM newcomers WHERE id = $id")->fetchColumn();
        $this->bot->hearText("/start $code")->reply();
        $met = (int) $this->c->db()->query("SELECT id FROM agreements WHERE newcomer_id = $id AND key = 'met'")->fetchColumn();
        $this->bot->hearCallbackQueryData("a:$met:no")->reply();
        $this->bot->hearText('никто не встретил')->reply();
        // answer the rest of day 1 so nothing else is open
        foreach (['workwear', 'mentor'] as $k) {
            $a = (int) $this->c->db()->query("SELECT id FROM agreements WHERE newcomer_id = $id AND key = '$k'")->fetchColumn();
            $this->bot->hearCallbackQueryData("a:$a:yes")->reply();
        }

        $pos = count($this->bot->getRequestHistory());
        $this->c->demo()->closeAsOwner($met);
        $task = (int) $this->c->db()->query("SELECT bitrix_task_id FROM agreements WHERE id = $met")->fetchColumn();
        self::assertContains([$task], $this->tasks->callsOf('complete'));
        self::assertSame(['Отмечено, что тебя встретили и показали рабочее место. Всё так?'], $this->textsSince($pos));
    }
}
