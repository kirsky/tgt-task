<?php

declare(strict_types=1);

namespace App\Web;

use App\Bitrix\BitrixClient;
use App\Bitrix\BitrixUnavailable;
use App\Container;
use App\Hire\HireRequest;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * HR panel: login, the list of newcomers on probation, a newcomer's card, the agreement
 * lists with the methodology, and the demo controls. Takes the request as plain values
 * and returns a {@see Response}, so it is fully testable without a web server.
 */
final class Router
{
    /** Length of the probation period shown as «день N/90». */
    public const PROBATION_DAYS = 90;

    /** Fictional names offered in the demo hire form. */
    private const DEMO_NAMES = ['Петров Алексей', 'Иванова Марина', 'Сидоренко Павел', 'Ковальчук Ольга', 'Мельник Денис', 'Захарова Анна'];

    private Views $views;

    public function __construct(private Container $c)
    {
        $this->views = new Views(Container::ROOT . '/templates');
    }

    /**
     * Handles one request.
     *
     * @param string               $method  `GET` or `POST`.
     * @param string               $path    Path without query string.
     * @param array<string,mixed>  $post    POST fields.
     * @param array<string,mixed>  $session Session data, modified in place.
     */
    public function dispatch(string $method, string $path, array $post, array &$session): Response
    {
        $session['csrf'] ??= bin2hex(random_bytes(16));
        $path = '/' . trim($path, '/');

        if ($path === '/login') {
            return $method === 'POST' ? $this->login($post, $session) : $this->page('login', ['title' => 'Вход', 'error' => null], $session);
        }
        if (empty($session['auth'])) {
            return Response::redirect('/login');
        }
        if ($method === 'POST' && !hash_equals((string) $session['csrf'], (string) ($post['csrf'] ?? ''))) {
            return new Response(403, 'Форма устарела, обновите страницу.');
        }

        // Read-only mode: while Bitrix24 is unreachable, refuse actions that need it
        // (the cron clears the flag as soon as a call succeeds again).
        if ($method === 'POST' && str_starts_with($path, '/demo/') && $path !== '/demo/reset-examples'
            && $this->c->kv()->get(BitrixClient::DOWN_FLAG) !== null) {
            return $this->unavailable($session);
        }

        try {
            return match (true) {
                $method === 'GET' && $path === '/' => $this->home($session),
                $method === 'GET' && $path === '/roles' => $this->roles($session),
                $method === 'GET' && (bool) preg_match('~^/n/(\d+)$~', $path, $m) => $this->card((int) $m[1], $session),
                $method === 'POST' && $path === '/logout' => $this->logout($session),
                $method === 'POST' && str_starts_with($path, '/demo/') && $this->c->demoEnabled() => $this->demo($path, $post, $session),
                default => $this->notFound($session),
            };
        } catch (BitrixUnavailable) {
            return $this->unavailable($session);
        }
    }

    /**
     * 503 page shown when an action needs Bitrix24 and it is unreachable.
     *
     * @param array<string,mixed> $session
     */
    private function unavailable(array &$session): Response
    {
        return new Response(503, $this->views->page('message', [
            'title' => 'Битрикс24 недоступен', 'readonly' => true, 'csrf' => $session['csrf'] ?? '', 'authed' => true,
            'message' => 'Действия с задачами временно недоступны: Битрикс24 не отвечает. Данные панели сохранены, бот продолжает работать.',
        ]));
    }

    /**
     * Checks the panel password and starts the session.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $session
     */
    private function login(array $post, array &$session): Response
    {
        $expected = (string) $this->c->config()->get('PANEL_PASSWORD');
        if ($expected !== '' && hash_equals($expected, (string) ($post['password'] ?? ''))) {
            $session['auth'] = true;

            return Response::redirect('/');
        }

        return $this->page('login', ['title' => 'Вход', 'error' => 'Неверный пароль'], $session);
    }

    /**
     * Ends the session.
     *
     * @param array<string,mixed> $session
     */
    private function logout(array &$session): Response
    {
        unset($session['auth']);

        return Response::redirect('/login');
    }

    /**
     * List of newcomers on probation with KPIs and the demo bar.
     *
     * @param array<string,mixed> $session
     */
    private function home(array &$session): Response
    {
        $c = $this->c;
        $now = $c->clock()->now();
        $this->refreshExamplesDaily();
        $rows = \App\Sql::all($c->db(), 'SELECT * FROM newcomers ORDER BY is_example, start_date DESC, id DESC');
        $list = [];
        foreach ($rows as $n) {
            $day = $c->schedule()->dayNumber($n['start_date'], $now);
            if ($day > self::PROBATION_DAYS) {
                continue;
            }
            $list[] = $n + ['day' => $day, 'pills' => $this->pills((int) $n['id'], $n, $now)];
        }

        return $this->page('list', [
            'title' => 'На испытательном сроке',
            'kpi' => Kpi::compute($c->db(), $c->schedule(), $c->roles(), $c->clock()),
            'newcomers' => $list,
            'roles' => $c->roles(),
            'demoName' => self::DEMO_NAMES[array_rand(self::DEMO_NAMES)],
            'today' => $now->format('Y-m-d'),
        ], $session);
    }

    /**
     * Rebuilds the example newcomers on the first visit of a day, so their «день N» stays
     * fixed whatever the date (in addition to the nightly cron, which may not be installed).
     */
    private function refreshExamplesDaily(): void
    {
        $today = $this->c->clock()->now()->format('Y-m-d');
        if ($this->c->kv()->get('examples_date') !== $today) {
            \App\Demo\Examples::reset($this->c->db(), $this->c->roles(), $this->c->schedule(), $this->c->clock());
            $this->c->kv()->set('examples_date', $today);
        }
    }

    /**
     * Summary pills of one newcomer for the list.
     *
     * @param array<string,mixed> $n Newcomer row.
     * @return list<array{text:string,tone:string}>
     */
    private function pills(int $newcomerId, array $n, \DateTimeImmutable $now): array
    {
        $counts = ['ok' => 0, 'bad' => 0, 'warn' => 0, 'wait' => 0];
        $disputes = 0;
        $overdue = 0;
        foreach ($this->agreementRows($newcomerId, $n['role_key'], $now) as $a) {
            $counts[$a['label']['tone']]++;
            $disputes += $a['label']['text'] === 'закрыто, но не получено' ? 1 : 0;
            $overdue += $a['label']['text'] === 'просрочено' ? 1 : 0;
        }
        $pills = [];
        if ($n['is_example']) {
            $pills[] = ['text' => 'пример', 'tone' => 'wait'];
        }
        if ($n['tg_chat_id'] === null) {
            $pills[] = ['text' => 'бот не подключён', 'tone' => 'warn'];
        }
        if ($counts['ok']) {
            $pills[] = ['text' => "{$counts['ok']} подтверждено", 'tone' => 'ok'];
        }
        if ($disputes) {
            $pills[] = ['text' => "$disputes закрыто, но не получено", 'tone' => 'bad'];
        }
        if ($overdue) {
            $pills[] = ['text' => "$overdue просрочено", 'tone' => 'bad'];
        }
        if ($counts['warn'] > 0) {
            $pills[] = ['text' => "{$counts['warn']} " . Views::plural($counts['warn'], 'требует', 'требуют', 'требуют') . ' внимания', 'tone' => 'warn'];
        }

        return $pills;
    }

    /**
     * Agreements of a newcomer with definitions, dates and labels.
     *
     * @return list<array<string,mixed>>
     */
    private function agreementRows(int $newcomerId, string $roleKey, \DateTimeImmutable $now): array
    {
        $role = $this->c->roles()->get($roleKey);
        $rows = \App\Sql::all($this->c->db(), 
            "SELECT g.*, CASE WHEN d.id IS NULL THEN NULL WHEN d.resolved_at IS NULL THEN 1 ELSE 0 END AS dispute_open, d.bitrix_task_id AS dispute_task_id
             FROM agreements g LEFT JOIN disputes d ON d.agreement_id = g.id WHERE g.newcomer_id = $newcomerId ORDER BY g.day, g.id"
        );
        $s = $this->c->schedule();
        foreach ($rows as &$r) {
            $r['def'] = $this->c->roles()->agreement($roleKey, $r['key']);
            $r['label'] = Labels::agreement($r, $now, $s->askAt($role, $r['due_date']), $s->deadline($role, $r['due_date']));
        }

        return $rows;
    }

    /**
     * One newcomer: invite link + QR, agreements by day with history and live Bitrix24 status.
     *
     * @param array<string,mixed> $session
     */
    private function card(int $id, array &$session): Response
    {
        $c = $this->c;
        $n = \App\Sql::one($c->db(), "SELECT * FROM newcomers WHERE id = $id");
        if (!$n) {
            return $this->notFound($session);
        }
        $now = $c->clock()->now();
        $agreements = $this->agreementRows($id, $n['role_key'], $now);

        $answers = [];
        foreach (\App\Sql::all($c->db(), "SELECT a.* FROM answers a JOIN agreements g ON g.id = a.agreement_id WHERE g.newcomer_id = $id ORDER BY a.id") as $a) {
            $answers[$a['agreement_id']][] = $a;
        }

        $live = null;
        $taskIds = array_values(array_filter(array_map(fn ($a) => (int) $a['bitrix_task_id'], $agreements)));
        if (!$n['is_example'] && $taskIds !== []) {
            try {
                $live = $c->tasks()->statuses($taskIds);
            } catch (BitrixUnavailable|\App\Bitrix\BitrixError) {
                $live = null; // the card still renders from local data
            }
        }
        $link = $n['is_example'] ? null : $c->hire()->inviteLink($n['invite_code']);
        $qr = $link ? (new QRCode(new QROptions(['outputBase64' => false, 'svgAddXmlHeader' => false])))->render($link) : null;

        return $this->page('card', [
            'title' => $n['full_name'],
            'n' => $n,
            'role' => $c->roles()->get($n['role_key']),
            'day' => $c->schedule()->dayNumber($n['start_date'], $now),
            'agreements' => $agreements,
            'answers' => $answers,
            'live' => $live,
            'tasks' => $c->tasks(),
            'link' => $link,
            'qr' => $qr,
            'short' => HireRequest::short($n['full_name']),
            'canNext' => !$n['is_example'] && $n['tg_chat_id'] !== null && in_array('scheduled', array_column($agreements, 'state'), true),
        ], $session);
    }

    /**
     * Agreement lists per role and the methodology.
     *
     * @param array<string,mixed> $session
     */
    private function roles(array &$session): Response
    {
        return $this->page('roles', ['title' => 'Договорённости по ролям', 'roles' => $this->c->roles(), 'portal' => $this->c->portal()], $session);
    }

    /**
     * Demo actions (only with `DEMO=1`). Each one leaves a confirmation message for the next page.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $session
     */
    private function demo(string $path, array $post, array &$session): Response
    {
        $demo = $this->c->demo();
        if ($path === '/demo/hire') {
            $name = trim((string) ($post['full_name'] ?? ''));
            $role = (string) ($post['role'] ?? '');
            $start = (string) ($post['start_date'] ?? '');
            if ($name === '' || !in_array($role, $this->c->roles()->keys(), true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                return new Response(400, 'Заполните ФИО, должность и дату выхода.');
            }

            $id = $demo->hire($name, $role, $start);
            $count = count($this->c->roles()->get($role)['agreements']);
            $session['flash'] = "Сотрудник принят: в Битрикс24 создана задача «Приём» и $count задач ответственным. Отправьте новичку ссылку или QR-код.";

            return Response::redirect('/n/' . $id);
        }
        if ($path === '/demo/reset-examples') {
            \App\Demo\Examples::reset($this->c->db(), $this->c->roles(), $this->c->schedule(), $this->c->clock());
            $this->c->kv()->set('examples_date', $this->c->clock()->now()->format('Y-m-d'));
            $session['flash'] = 'Примеры обновлены.';

            return Response::redirect('/');
        }
        if (preg_match('~^/demo/next/(\d+)$~', $path, $m)) {
            $demo->nextBatch((int) $m[1]);
            $session['flash'] = 'Вопросы следующего дня отправлены новичку в Telegram.';

            return Response::redirect('/n/' . $m[1]);
        }
        if (preg_match('~^/demo/close/(\d+)$~', $path, $m)) {
            $demo->closeAsOwner((int) $m[1]);
            $session['flash'] = 'Задача закрыта от имени ответственного — новичку отправлен вопрос «Всё так?».';
            $nid = \App\Sql::value($this->c->db(), 'SELECT newcomer_id FROM agreements WHERE id = ' . (int) $m[1]);

            return Response::redirect($nid ? "/n/$nid" : '/');
        }

        return $this->notFound($session);
    }

    /**
     * 404 page.
     *
     * @param array<string,mixed> $session
     */
    private function notFound(array &$session): Response
    {
        return new Response(404, $this->views->page('message', ['title' => 'Не найдено', 'message' => 'Такой страницы нет.', 'readonly' => false, 'csrf' => $session['csrf'] ?? '']));
    }

    /**
     * Renders a page with the common variables (CSRF token, Bitrix24 status, demo flag) and
     * shows (then forgets) the confirmation message left by the previous action.
     *
     * @param array<string,mixed> $vars
     * @param array<string,mixed> $session
     */
    private function page(string $template, array $vars, array &$session): Response
    {
        $flash = is_string($session['flash'] ?? null) ? $session['flash'] : null;
        unset($session['flash']);

        return new Response(200, $this->views->page($template, $vars + [
            'flash' => $flash,
            'csrf' => $session['csrf'] ?? '',
            'authed' => !empty($session['auth']),
            'demo' => $this->c->demoEnabled(),
            'readonly' => $this->c->kv()->get(BitrixClient::DOWN_FLAG) !== null,
        ]));
    }
}
