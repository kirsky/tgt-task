<?php

declare(strict_types=1);

namespace Tests;

use App\Demo\Examples;
use App\Web\WeakSpots;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * «Где договорённости не выполняются с первого раза»: which agreements most often get
 * «Частично»/«Нет» on the first ask, so fixes go to the process, not just to one newcomer.
 */
final class WeakSpotsTest extends TestCase
{
    /** Rates are per agreement of a role, sorted worst first, only agreements already asked. */
    public function test_ranks_agreements_by_first_answer_failures(): void
    {
        $c = TestApp::make('2026-11-20T12:00:00+03:00');
        $db = $c->db();
        $db->exec("INSERT INTO newcomers(id, full_name, first_name, role_key, start_date, invite_code, created_at) VALUES
            (1, 'А Б', 'Б', 'warehouse_picker', '2026-11-10', 'a', 'x'), (2, 'В Г', 'Г', 'warehouse_picker', '2026-11-11', 'b', 'x')");
        // workwear: 2 asked, both failed first time; met: 2 asked, 0 failed; scanner: 1 asked, failed
        $db->exec("INSERT INTO agreements(id, newcomer_id, key, day, due_date, state, first_answer, updated_at) VALUES
            (1, 1, 'workwear', 1, '2026-11-10', 'confirmed', 'partial', 'x'),
            (2, 2, 'workwear', 1, '2026-11-11', 'not_received', 'no', 'x'),
            (3, 1, 'met', 1, '2026-11-10', 'confirmed', 'yes', 'x'),
            (4, 2, 'met', 1, '2026-11-11', 'confirmed', 'yes', 'x'),
            (5, 1, 'scanner', 3, '2026-11-12', 'not_received', 'no', 'x'),
            (6, 2, 'scanner', 3, '2026-11-13', 'scheduled', NULL, 'x')");

        $rows = WeakSpots::compute($db, $c->roles());
        self::assertSame(
            [
                ['Тебе выдали спецодежду и обувь?', 'Кладовщик-комплектовщик', 2, 2, 100],
                ['У тебя есть доступ к ТСД под твоим логином?', 'Кладовщик-комплектовщик', 1, 1, 100],
                ['Тебя встретили и показали рабочее место?', 'Кладовщик-комплектовщик', 2, 0, 0],
            ],
            array_map(fn ($r) => [$r['question'], $r['role'], $r['asked'], $r['failed'], $r['pct']], $rows),
        );
    }

    /** The main page shows the block, fed by the examples on a fresh install. */
    public function test_shown_on_main_page(): void
    {
        $c = TestApp::make('2026-11-20T12:00:00+03:00');
        Examples::reset($c->db(), $c->roles(), $c->schedule(), $c->clock());
        $router = new \App\Web\Router($c);
        $s = [];
        $router->dispatch('GET', '/login', [], $s);
        $router->dispatch('POST', '/login', ['password' => 'secret', 'csrf' => $s['csrf']], $s);
        $body = $router->dispatch('GET', '/', [], $s)->body;
        self::assertStringContainsString('Где договорённости не выполняются с первого раза', $body);
        self::assertStringContainsString('Тебе выдали спецодежду и обувь?', $body);
    }
}
