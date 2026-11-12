<?php

declare(strict_types=1);

namespace Tests;

use App\Bitrix\BitrixError;
use App\Bitrix\BitrixUnavailable;
use App\Bot\Handlers;
use App\Container;
use App\Domain\Answer;
use App\Domain\State;
use App\Domain\Transitions;
use App\Web\Router;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * Regression tests for the findings of the final review and the live demo failure
 * (a Bitrix24 call failing in the middle of a demo hire).
 */
final class ReviewFixesTest extends TestCase
{
    private Container $c;
    private FakeNutgram $bot;
    private FakeTasks $tasks;

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T20:20:00+03:00');
        $this->bot = Nutgram::fake();
        $this->bot->setCommonUser(User::make(555, false, 'Т'));
        $this->bot->setCommonChat(Chat::make(555, 'private'));
        $this->c->set('bot', $this->bot);
        Handlers::register($this->bot, $this->c);
        $this->tasks = TestApp::tasks($this->c);
    }

    private function hire(int $taskId = 77): int
    {
        return TestApp::id($this->c->hire()->process($taskId, 'Приём: Петров Алексей', "Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 17.11.2026"));
    }

    private function aid(int $nid, string $key): int
    {
        return (int) \App\Sql::value($this->c->db(), "SELECT id FROM agreements WHERE newcomer_id = $nid AND key = '$key'");
    }

    private function connect(int $nid): void
    {
        $code = \App\Sql::value($this->c->db(), "SELECT invite_code FROM newcomers WHERE id = $nid");
        $this->bot->hearText("/start $code")->reply();
    }

    /** #4 / live failure: a demo hire that fails midway is completed by the next tick. */
    public function test_failed_demo_hire_is_resumed_by_tick(): void
    {
        $this->tasks->failOnCall(3); // hire task OK, first agreement OK, second fails
        try {
            $this->c->demo()->hire('Петров Алексей', 'warehouse_picker', '2026-11-17');
            self::fail('expected BitrixUnavailable');
        } catch (BitrixUnavailable) {
        }
        self::assertSame(1, $this->c->poller()->resumeIncompleteHires());
        $nid = (int) \App\Sql::value($this->c->db(), 'SELECT id FROM newcomers');
        self::assertSame(0, (int) \App\Sql::value($this->c->db(), "SELECT COUNT(*) FROM agreements WHERE newcomer_id = $nid AND bitrix_task_id IS NULL"));
        self::assertCount(1, $this->tasks->callsOf('comment'), 'invite comment posted once');
        self::assertSame(0, $this->c->poller()->resumeIncompleteHires());
    }

    /** #1: a portal whose REST access ended (401/403 JSON) counts as unavailable, not a business error. */
    public function test_expired_portal_is_unavailable(): void
    {
        foreach ([[401, 'NO_AUTH_FOUND'], [403, 'ACCESS_DENIED'], [402, 'PAYMENT_REQUIRED'], [400, 'INVALID_CREDENTIALS'], [403, 'insufficient_scope']] as [$status, $err]) {
            $kv = $this->c->kv();
            $kv->delete('bitrix_down_since');
            $client = new \App\Bitrix\BitrixClient('https://p.test/rest/1/x/', $kv, fn () => ['status' => $status, 'body' => json_encode(['error' => $err])], retryDelayMs: 0);
            try {
                $client->call('tasks.task.list');
                self::fail("expected BitrixUnavailable for $err");
            } catch (BitrixUnavailable) {
            }
            self::assertNotNull($kv->get('bitrix_down_since'), $err);
        }
    }

    /** #1: the card page survives any Bitrix24 error when reading live statuses. */
    public function test_card_survives_bitrix_error(): void
    {
        $nid = $this->hire();
        $this->c->set('tasks', new class extends FakeTasks {
            public function statuses(array $ids): array
            {
                throw new BitrixError('tasks.task.list: weird');
            }
        });
        $router = new Router($this->c);
        $s = [];
        $router->dispatch('GET', '/login', [], $s);
        $router->dispatch('POST', '/login', ['password' => 'secret', 'csrf' => $s['csrf']], $s);
        $r = $router->dispatch('GET', "/n/$nid", [], $s);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Статусы из Битрикс24 сейчас недоступны', $r->body);
    }

    /** #7 + #1: a permanent Bitrix24 error on one answer is recorded and does not block later retries. */
    public function test_permanent_error_does_not_block_retries(): void
    {
        $nid = $this->hire();
        $this->c->db()->exec("UPDATE agreements SET state = 'asked' WHERE newcomer_id = $nid");
        $failing = new class extends FakeTasks {
            /** @var list<int> Task ids whose completion fails permanently. */
            public array $broken = [];

            public function complete(int $id): void
            {
                if (in_array($id, $this->broken, true)) {
                    throw new BitrixError('task deleted');
                }
                parent::complete($id);
            }
        };
        $this->c->set('tasks', $failing);
        $this->c->set('answers', null);
        $answers = $this->c->answers();
        $met = $this->aid($nid, 'met');
        $failing->broken[] = (int) \App\Sql::value($this->c->db(), "SELECT bitrix_task_id FROM agreements WHERE id = $met");

        $answers->record($met, Answer::Yes); // permanent error → recorded, no exception
        $answers->record($this->aid($nid, 'workwear'), Answer::Yes);
        self::assertCount(1, $failing->callsOf('complete'), 'second answer applied despite the first failing');
        self::assertSame(0, $answers->retryPending(), 'failed one is not retried forever');
        self::assertNotNull(\App\Sql::value($this->c->db(), "SELECT effects_error FROM answers WHERE agreement_id = $met"));
    }

    /** #7: when an effect list partly succeeded, a retry continues where it stopped (no duplicate complete). */
    public function test_retry_resumes_partial_effects(): void
    {
        $nid = $this->hire();
        $met = $this->aid($nid, 'met');
        $this->c->db()->exec("UPDATE agreements SET state = 'asked' WHERE id = $met");
        $this->tasks->calls = [];
        $this->tasks->failOnCall(2); // complete OK, comment fails
        $this->c->answers()->record($met, Answer::Yes);
        self::assertSame(1, $this->c->answers()->retryPending());
        self::assertCount(1, $this->tasks->callsOf('complete'));
        self::assertCount(1, $this->tasks->callsOf('comment'));
    }

    /** #5: once «не получено», only «Да» changes anything; repeated «Нет»/«Частично» add no comments. */
    public function test_not_received_accepts_only_yes(): void
    {
        self::assertSame([State::NotReceived, []], Transitions::answer(State::NotReceived, Answer::No));
        self::assertSame([State::NotReceived, []], Transitions::answer(State::NotReceived, Answer::Partial));
        self::assertSame(State::Confirmed, Transitions::answer(State::NotReceived, Answer::Yes)[0]);
    }

    /** #3: reopening the invite while a note is awaited finalizes the pending answer (HR task is not lost). */
    public function test_pending_note_is_finalized_on_restart(): void
    {
        $nid = $this->hire();
        $this->connect($nid);
        $ww = $this->aid($nid, 'workwear');
        $this->c->db()->exec("UPDATE agreements SET state = 'owner_closed', prompt_kind = 'recheck', prompt_queued_at = 'x', prompt_sent_at = 'x' WHERE id = $ww");
        $this->bot->hearCallbackQueryData("a:$ww:no")->reply(); // «Чего не хватает?»
        $this->connect($nid); // reopens the link instead of answering

        self::assertSame(1, (int) \App\Sql::value($this->c->db(), "SELECT COUNT(*) FROM disputes WHERE agreement_id = $ww"));
        self::assertCount(1, array_filter($this->tasks->callsOf('create'), fn ($c) => str_starts_with($c[0]['TITLE'], 'Закрыто, но не получено')));
    }

    /** #3: an answer left without a note and without a chat is finalized by the dispatcher sweep. */
    public function test_orphan_unfinalized_answer_is_swept(): void
    {
        $nid = $this->hire();
        $met = $this->aid($nid, 'met');
        $this->c->db()->exec("UPDATE agreements SET state = 'asked' WHERE id = $met");
        $answerId = TestApp::noteAnswerId($this->c->answers()->record($met, Answer::No));
        $this->c->db()->exec("UPDATE answers SET created_at = '2026-11-17T10:00:00+03:00' WHERE id = $answerId");
        $this->c->dispatcher()->run();
        self::assertNotNull(\App\Sql::value($this->c->db(), "SELECT finalized_at FROM answers WHERE id = $answerId"));
    }

    /** #2: a chat that blocked the bot is unbound and does not stop the tick for others. */
    public function test_blocked_chat_does_not_stop_dispatch(): void
    {
        $a = $this->hire(77);
        $b = $this->c->hire()->process(78, 'Приём: Иванова Марина', "Должность: Продавец-консультант\nПодразделение: Магазин\nДата выхода: 17.11.2026");
        $this->c->db()->exec("UPDATE newcomers SET tg_chat_id = 111 WHERE id = $a");
        $this->c->db()->exec("UPDATE newcomers SET tg_chat_id = 222 WHERE id = $b");
        TestApp::clock($this->c)->set('2026-11-17T21:20:00+03:00');
        // exactly what Telegram answers when the user blocked the bot
        (fn () => $this->mockHandler->append(new \GuzzleHttp\Psr7\Response(403, [], '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}')))->call($this->bot);

        $this->c->dispatcher()->run(); // must not throw
        self::assertNull(\App\Sql::value($this->c->db(), "SELECT tg_chat_id FROM newcomers WHERE id = $a"));
        self::assertNotNull(\App\Sql::value($this->c->db(), "SELECT prompt_sent_at FROM agreements WHERE newcomer_id = $b AND prompt_sent_at IS NOT NULL"), 'second newcomer still got a question');
    }

    /** #6: an owner-closed mark does not overwrite an agreement confirmed in the meantime. */
    public function test_owner_closed_is_guarded_by_state(): void
    {
        $nid = $this->hire();
        $met = $this->aid($nid, 'met');
        $this->c->db()->exec("UPDATE agreements SET state = 'confirmed' WHERE id = $met");
        $this->c->poller()->markOwnerClosed($met, State::Asked); // stale state read before the network call
        self::assertSame('confirmed', \App\Sql::value($this->c->db(), "SELECT state FROM agreements WHERE id = $met"));
    }

    /** #8: examples refresh on the first panel visit of a day, so «день 2» stays day 2 without a cron. */
    public function test_examples_refresh_daily_on_visit(): void
    {
        $router = new Router($this->c);
        $s = [];
        $router->dispatch('GET', '/login', [], $s);
        $router->dispatch('POST', '/login', ['password' => 'secret', 'csrf' => $s['csrf']], $s);
        $router->dispatch('GET', '/', [], $s);
        self::assertSame(5, (int) \App\Sql::value($this->c->db(), 'SELECT COUNT(*) FROM newcomers WHERE is_example = 1'));
        TestApp::clock($this->c)->set('2026-12-01T10:00:00+03:00');
        $router->dispatch('GET', '/', [], $s);
        $start = \App\Sql::value($this->c->db(), "SELECT start_date FROM newcomers WHERE is_example = 1 AND full_name = 'Петров Алексей'");
        self::assertSame('2026-11-30', $start);
    }
}
