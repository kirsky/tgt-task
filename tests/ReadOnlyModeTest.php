<?php

declare(strict_types=1);

namespace Tests;

use App\Bitrix\BitrixClient;
use App\Bot\Handlers;
use App\Bot\Texts;
use App\Web\Router;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use Tests\Support\TestApp;

/**
 * When Bitrix24 is unreachable (e.g. after the trial ends on 26.11.2026) the panel shows
 * a banner, task actions are disabled and refused, and the bot still answers.
 */
final class ReadOnlyModeTest extends TestCase
{
    /** Banner + disabled demo buttons + 503 on demo POST + bot /start still works. */
    public function test_down_flag_shows_badge_and_disables_demo(): void
    {
        $c = TestApp::make('2026-11-27T10:00:00+03:00');
        $bot = Nutgram::fake();
        $c->set('bot', $bot);
        $c->kv()->set(BitrixClient::DOWN_FLAG, '2026-11-27T00:00:00+03:00');
        $router = new Router($c);
        $session = [];
        $router->dispatch('GET', '/login', [], $session);
        $router->dispatch('POST', '/login', ['password' => 'secret', 'csrf' => $session['csrf']], $session);

        $home = $router->dispatch('GET', '/', [], $session)->body;
        self::assertStringContainsString('Битрикс24 недоступен', $home);
        self::assertMatchesRegularExpression('~<button[^>]* disabled title="Действия с задачами временно недоступны">(?:<i[^>]*></i>)?Принять нового сотрудника~u', $home);

        $r = $router->dispatch('POST', '/demo/hire', ['full_name' => 'X Y', 'role' => 'warehouse_picker', 'start_date' => '2026-11-27', 'csrf' => $session['csrf']], $session);
        self::assertSame(503, $r->status);
        self::assertStringContainsString('Действия с задачами временно недоступны', $r->body);
        self::assertSame([], TestApp::tasks($c)->calls);

        Handlers::register($bot, $c);
        $bot->setCommonUser(User::make(5, false, 'Т'));
        $bot->setCommonChat(Chat::make(5, 'private'));
        $bot->hearText('/start')->reply()->assertReplyText(Texts::noCode());
    }
}
