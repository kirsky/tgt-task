<?php

declare(strict_types=1);

namespace Tests;

use App\Container;
use App\Web\Router;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * The HR panel through its router: auth, pages, demo actions, CSRF.
 */
final class PanelSmokeTest extends TestCase
{
    private Container $c;
    private Router $router;
    /** @var array<string,mixed> Simulated PHP session. */
    private array $session = [];

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T12:00:00+03:00');
        $this->c->set('bot', Nutgram::fake());
        $this->router = new Router($this->c);
    }

    private function get(string $path): \App\Web\Response
    {
        return $this->router->dispatch('GET', $path, [], $this->session);
    }

    /**
     * Submits a form, adding the session's CSRF token unless told not to.
     *
     * @param array<string,mixed> $data
     */
    private function post(string $path, array $data, bool $csrf = true): \App\Web\Response
    {
        if ($csrf) {
            $data['csrf'] = $this->session['csrf'] ?? '';
        }

        return $this->router->dispatch('POST', $path, $data, $this->session);
    }

    private function login(): void
    {
        $this->get('/login');
        $this->post('/login', ['password' => 'secret']);
    }

    /** Pages require login; a wrong password is refused; the right one lets you in. */
    public function test_auth(): void
    {
        $r = $this->get('/');
        self::assertSame(302, $r->status);
        self::assertSame('/login', $r->headers['Location']);
        $this->get('/login');
        self::assertStringContainsString('Неверный пароль', $this->post('/login', ['password' => 'nope'])->body);
        self::assertSame(302, $this->post('/login', ['password' => 'secret'])->status);
        self::assertSame(200, $this->get('/')->status);
    }

    /** The main page shows the product name and the four KPI captions. */
    public function test_home(): void
    {
        $this->login();
        $body = $this->get('/')->body;
        foreach (['как договаривались', 'подтверждено в срок', 'просрочено', 'закрыто, но не получено', 'не подключились к боту', 'Принять нового сотрудника'] as $s) {
            self::assertStringContainsString($s, $body);
        }
        self::assertDoesNotMatchRegularExpression('/обещ/iu', $body);
        self::assertDoesNotMatchRegularExpression('/обещ/iu', $this->get('/login')->body);
    }

    /** The roles page lists agreements and the methodology, and never says «обещание». */
    public function test_roles_page(): void
    {
        $this->login();
        $body = $this->get('/roles')->body;
        self::assertStringContainsString('Договорённости по ролям', $body);
        self::assertStringContainsString('Тебе выдали спецодежду и обувь?', $body);
        self::assertStringContainsString('Наставник тебе помогает?', $body, 'bad example from the rules');
        self::assertDoesNotMatchRegularExpression('/обещ/iu', $body);
    }

    /** Demo hire creates a Bitrix24 hire task and opens the card with the QR code and agreements. */
    public function test_demo_hire_and_card(): void
    {
        $this->login();
        $r = $this->post('/demo/hire', ['full_name' => 'Петров Алексей', 'role' => 'warehouse_picker', 'start_date' => '2026-11-17']);
        self::assertSame(302, $r->status);
        self::assertMatchesRegularExpression('~^/n/\d+$~', $r->headers['Location']);
        self::assertCount(9, TestApp::tasks($this->c)->callsOf('create'));

        $card = $this->get($r->headers['Location'])->body;
        self::assertStringContainsString('<svg', $card);
        self::assertStringContainsString('https://t.me/novichok_promises_bot?start=', $card);
        self::assertStringContainsString('Тебе выдали спецодежду и обувь?', $card);
        self::assertStringContainsString('Сотрудник принят', $card, 'confirmation after the action');
        self::assertMatchesRegularExpression('~data-copy="#invite-link"[^>]*>.*Скопировать~su', $card, 'invite link has a copy button');
        self::assertStringNotContainsString('Следующий опрос сейчас', $card, 'no «next questions» while the bot is not connected');
        self::assertStringContainsString('Вопросы начнут приходить после того, как новичок откроет ссылку', $card);
    }

    /** Once the newcomer has connected the bot, the demo can send the next questions. */
    public function test_next_button_only_when_connected(): void
    {
        $this->login();
        $r = $this->post('/demo/hire', ['full_name' => 'Петров Алексей', 'role' => 'warehouse_picker', 'start_date' => '2026-11-17']);
        $id = (int) substr($r->headers['Location'], 3);
        $this->c->db()->exec("UPDATE newcomers SET tg_chat_id = 555 WHERE id = $id");
        $card = $this->get("/n/$id")->body;
        self::assertStringContainsString('Следующий опрос сейчас', $card);
        self::assertStringContainsString('Бот подключён', $card);
    }

    /** The panel uses Bootstrap Icons and regular badges: no emoji, no pill badges. */
    public function test_no_emoji_and_no_pills(): void
    {
        $this->login();
        $this->post('/demo/reset-examples', []);
        $r = $this->post('/demo/hire', ['full_name' => 'Петров Алексей', 'role' => 'warehouse_picker', 'start_date' => '2026-11-17']);
        foreach (['/', '/roles', $r->headers['Location'], '/login'] as $path) {
            $body = $this->get($path)->body;
            self::assertDoesNotMatchRegularExpression('/[➕⏩✔✓↺←📍👤🎒]/u', $body, $path);
            self::assertStringNotContainsString('rounded-pill', $body, $path);
        }
        self::assertStringContainsString('bi bi-person-plus', $this->get('/')->body);
    }

    /** «Сбросить примеры» fills the list with the five examples, marked «пример». */
    public function test_reset_examples_button(): void
    {
        $this->login();
        self::assertSame(302, $this->post('/demo/reset-examples', [])->status);
        $body = $this->get('/')->body;
        self::assertStringContainsString('На испытательном сроке · 5', $body);
        self::assertSame(5, substr_count($body, 'пример</span>'));
        self::assertStringContainsString('Примеры обновлены', $body);
    }

    /** POST without the CSRF token is rejected and does nothing. */
    public function test_csrf(): void
    {
        $this->login();
        $r = $this->post('/demo/hire', ['full_name' => 'X Y', 'role' => 'warehouse_picker', 'start_date' => '2026-11-17'], csrf: false);
        self::assertSame(403, $r->status);
        self::assertSame([], TestApp::tasks($this->c)->calls);
    }

    /** Unknown pages are a 404, not an error. */
    public function test_404(): void
    {
        $this->login();
        self::assertSame(404, $this->get('/n/999')->status);
        self::assertSame(404, $this->get('/nope')->status);
    }
}
