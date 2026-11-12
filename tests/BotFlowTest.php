<?php

declare(strict_types=1);

namespace Tests;

use App\Bot\Handlers;
use App\Bot\Texts;
use App\Container;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * The Telegram conversation end to end, with Nutgram's fake API and a fake Bitrix24.
 */
final class BotFlowTest extends TestCase
{
    private const CHAT = 555;

    private Container $c;
    private FakeNutgram $bot;
    private FakeTasks $tasks;
    private int $newcomerId;
    private string $code;

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T20:20:00+03:00');
        $this->bot = Nutgram::fake();
        $this->c->set('bot', $this->bot);
        Handlers::register($this->bot, $this->c);
        $this->as(self::CHAT);
        $this->tasks = TestApp::tasks($this->c);
        $this->newcomerId = TestApp::id($this->c->hire()->process(77, 'Приём: Петров Алексей', "Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 17.11.2026"));
        $this->code = (string) \App\Sql::value($this->c->db(), "SELECT invite_code FROM newcomers WHERE id = {$this->newcomerId}");
        $this->tasks->calls = [];
    }

    /** Makes the next heard updates come from this Telegram chat/user. */
    private function as(int $chatId): void
    {
        $this->bot->setCommonUser(User::make($chatId, false, 'Тест'));
        $this->bot->setCommonChat(Chat::make($chatId, 'private'));
    }

    /**
     * Outgoing API calls since the last heard update.
     *
     * @return list<array{method:string, params:array<mixed>}>
     */
    private function sent(): array
    {
        return array_values(array_map(function (array $h): array {
            $req = $h['request'];
            $body = (string) $req->getBody();
            $params = json_decode($body, true);
            if (!is_array($params) && preg_match_all('/name="([^"]+)"\r\n\r\n(.*?)\r\n--/s', $body, $m)) {
                $params = array_combine($m[1], $m[2]); // multipart (e.g. editMessageText)
            }
            if (!is_array($params)) {
                parse_str($body, $params);
            }

            return ['method' => basename($req->getUri()->getPath()), 'params' => $params];
        }, $this->bot->getRequestHistory()));
    }

    /** @return list<string> Texts of sent messages, in order. */
    private function texts(): array
    {
        return array_values(array_map(fn ($s) => $s['params']['text'], array_filter($this->sent(), fn ($s) => $s['method'] === 'sendMessage')));
    }

    /**
     * Inline button callback data of the last sent message.
     *
     * @return list<string>
     */
    private function lastButtons(): array
    {
        $msgs = array_values(array_filter($this->sent(), fn ($s) => $s['method'] === 'sendMessage'));
        $markup = end($msgs)['params']['reply_markup'] ?? null;
        $markup = is_string($markup) ? json_decode($markup, true) : $markup;

        return array_merge(...array_map(fn ($row) => array_column($row, 'callback_data'), $markup['inline_keyboard'] ?? []));
    }

    /** Agreement id by key for the test newcomer. */
    private function agreementId(string $key): int
    {
        return (int) \App\Sql::value($this->c->db(), "SELECT id FROM agreements WHERE newcomer_id = {$this->newcomerId} AND key = '$key'");
    }

    private function start(): void
    {
        $this->bot->hearText('/start ' . $this->code)->reply();
    }

    /** /start binds the chat, greets, and right away asks the first due question with three buttons. */
    public function test_start_binds_and_sends_welcome_and_first_question(): void
    {
        $this->start();
        $texts = $this->texts();
        self::assertStringStartsWith('Привет, Алексей!', $texts[0]);
        self::assertStringStartsWith("С первым рабочим днём! Уточню пару вещей.\n\nТебя встретили и показали рабочее место?", $texts[1]);
        $met = $this->agreementId('met');
        self::assertSame(["a:$met:yes", "a:$met:partial", "a:$met:no"], $this->lastButtons());
        self::assertSame(self::CHAT, (int) \App\Sql::value($this->c->db(), "SELECT tg_chat_id FROM newcomers WHERE id = {$this->newcomerId}"));
    }

    /** «Да» completes the owner's task and the next question follows. */
    public function test_yes_completes_task_and_asks_next(): void
    {
        $this->start();
        $this->bot->hearCallbackQueryData('a:' . $this->agreementId('met') . ':yes')->reply();
        self::assertCount(1, $this->tasks->callsOf('complete'));
        self::assertSame(['Тебе выдали спецодежду и обувь?'], $this->texts());
    }

    /**
     * After a tap the question keeps the chosen answer (buttons are replaced by «Твой ответ: Да»),
     * so the chat history shows what the newcomer answered.
     */
    public function test_answered_question_shows_the_answer(): void
    {
        $this->start();
        $met = $this->agreementId('met');
        $this->bot->hearUpdateType(\SergiX44\Nutgram\Telegram\Properties\UpdateType::CALLBACK_QUERY, [
            'data' => "a:$met:yes",
            'message' => ['message_id' => 42, 'date' => 1794450000, 'text' => "С первым рабочим днём! Уточню пару вещей.\n\nТебя встретили и показали рабочее место?"],
        ])->reply();
        $edits = array_values(array_filter($this->sent(), fn ($s) => $s['method'] === 'editMessageText'));
        self::assertCount(1, $edits);
        self::assertSame(42, (int) $edits[0]['params']['message_id']);
        self::assertStringEndsWith("Тебя встретили и показали рабочее место?\n\n<i>Твой ответ: Да</i>", $edits[0]['params']['text']);
    }

    /** «Частично» → note prompt → note → comment with the note → «Спасибо, учли.» → next question. */
    public function test_partial_then_note_then_next(): void
    {
        $this->start();
        $this->bot->hearCallbackQueryData('a:' . $this->agreementId('met') . ':yes')->reply();
        $this->bot->hearCallbackQueryData('a:' . $this->agreementId('workwear') . ':partial')->reply();
        self::assertSame(['Чего не хватает? Можно коротко.'], $this->texts());
        $skip = $this->lastButtons();
        self::assertCount(1, $skip);
        self::assertStringStartsWith('skip:', $skip[0]);

        $this->bot->hearText('обуви нет')->reply();
        self::assertSame('Получение подтверждено частично: «обуви нет»', $this->tasks->callsOf('comment')[1][1]);
        self::assertSame(['Спасибо, учли.', 'Ты знаешь, кто твой наставник и как с ним связаться?'], $this->texts());
    }

    /** After the last question of the day the bot says thanks once. */
    public function test_batch_ends_with_thanks(): void
    {
        $this->start();
        foreach (['met', 'workwear', 'mentor'] as $key) {
            $this->bot->hearCallbackQueryData('a:' . $this->agreementId($key) . ':yes')->reply();
        }
        self::assertSame(['Спасибо!'], $this->texts());
    }

    /** Review Focus 1: tapping an already answered question changes nothing and calls no Bitrix24. */
    public function test_double_tap_is_ignored(): void
    {
        $this->start();
        $data = 'a:' . $this->agreementId('met') . ':yes';
        $this->bot->hearCallbackQueryData($data)->reply();
        $calls = count($this->tasks->calls);
        $this->bot->hearCallbackQueryData($data)->reply();
        self::assertSame(['Спасибо, уже учли.'], $this->texts());
        self::assertCount($calls, $this->tasks->calls);
    }

    /** Review Focus 2: a forwarded link opened by another account does not steal the newcomer. */
    public function test_used_code_from_other_chat(): void
    {
        $this->start();
        $this->as(777);
        $this->bot->hearText('/start ' . $this->code)->reply();
        self::assertSame([Texts::linkUsed()], $this->texts());
        self::assertSame(self::CHAT, (int) \App\Sql::value($this->c->db(), "SELECT tg_chat_id FROM newcomers WHERE id = {$this->newcomerId}"));
    }

    /** /start without a valid code explains where to get the link. */
    public function test_start_without_code(): void
    {
        $this->bot->hearText('/start')->reply();
        self::assertSame([Texts::noCode()], $this->texts());
        $this->bot->hearText('/start nope')->reply();
        self::assertSame([Texts::noCode()], $this->texts());
    }

    /** Review Focus 5: free text with no note expected gets a polite fixed reply and changes nothing. */
    public function test_free_text_without_awaited_note(): void
    {
        $this->start();
        $this->bot->hearText('привет, а когда зарплата?')->reply();
        self::assertSame([Texts::freeText()], $this->texts());
        self::assertSame([], $this->tasks->calls);
    }

    /** «Кто видит ответы?» answers plainly. */
    public function test_who_sees(): void
    {
        $this->start();
        $this->bot->hearCallbackQueryData('who')->reply();
        self::assertSame(['Ответы видят сотрудники HR и тот, кто отвечает за конкретный пункт, — чтобы его выполнить.'], $this->texts());
    }

    /** A newcomer who starts before the first day gets the logistics and no questions yet. */
    public function test_welcome_before_start_has_logistics(): void
    {
        TestApp::clock($this->c)->set('2026-11-16T12:00:00+03:00');
        $this->start();
        $texts = $this->texts();
        self::assertCount(1, $texts);
        self::assertStringContainsString('Ждём тебя 17 ноября к 08:00.', $texts[0]);
        self::assertStringContainsString('ЛЦ «Колядичи»', $texts[0]);
        self::assertStringContainsString('всё ли, о чём договаривались, ты уже получил', $texts[0]);
        self::assertSame(['who'], $this->lastButtons());
    }

    /** One Telegram account can re-bind to a newer hire (a reviewer trying the demo twice). */
    public function test_same_chat_rebinds_to_newer_hire(): void
    {
        $this->start();
        $second = $this->c->hire()->process(78, 'Приём: Иванова Марина', "Должность: Продавец-консультант\nПодразделение: Магазин\nДата выхода: 17.11.2026");
        $code2 = (string) \App\Sql::value($this->c->db(), "SELECT invite_code FROM newcomers WHERE id = $second");
        $this->bot->hearText('/start ' . $code2)->reply();
        self::assertStringStartsWith('Привет, Марина!', $this->texts()[0]);
        self::assertNull(\App\Sql::value($this->c->db(), "SELECT tg_chat_id FROM newcomers WHERE id = {$this->newcomerId}"));
    }

    /** No bot copy uses the banned word «обещ…». */
    public function test_no_bot_text_contains_obeshch(): void
    {
        $role = $this->c->roles()->get('warehouse_picker');
        $all = [
            Texts::welcomeBefore('Алексей', '2026-11-17', $role), Texts::welcomeAfter('Алексей'), Texts::whoSees(),
            Texts::noCode(), Texts::linkUsed(), Texts::intro(1), Texts::intro(3), Texts::askNote(), Texts::thanksNoted(),
            Texts::batchDone(), Texts::recheck('x'), Texts::recheckYes(), Texts::reminderPrefix(), Texts::alreadyNoted(), Texts::freeText(),
        ];
        foreach ($all as $t) {
            self::assertDoesNotMatchRegularExpression('/обещ/iu', $t);
        }
    }
}
