<?php

declare(strict_types=1);

namespace Tests;

use App\Demo\Examples;
use App\Web\Kpi;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * Example newcomers shown in the panel: five fixed situations whose days stay the same
 * whatever the date, refreshed nightly, never touching real newcomers or Bitrix24.
 */
final class ExamplesTest extends TestCase
{
    /** On any date the examples sit on days 2, 34, 12, 5 and 68 and produce problem KPIs. */
    public function test_reset_creates_five_with_relative_days(): void
    {
        foreach (['2026-11-12T10:00:00+03:00', '2027-03-01T10:00:00+03:00'] as $now) {
            $c = TestApp::make($now);
            $c->db()->exec("INSERT INTO newcomers(full_name, first_name, role_key, start_date, invite_code, created_at) VALUES('Реальный Человек', 'Человек', 'warehouse_picker', '2026-11-12', 'real', 'x')");

            Examples::reset($c->db(), $c->roles(), $c->schedule(), $c->clock());
            Examples::reset($c->db(), $c->roles(), $c->schedule(), $c->clock());

            $rows = $c->db()->query('SELECT full_name, start_date FROM newcomers WHERE is_example = 1 ORDER BY id')->fetchAll();
            self::assertCount(5, $rows);
            $days = array_map(fn ($r) => $c->schedule()->dayNumber($r['start_date'], $c->clock()->now()), $rows);
            self::assertSame([2, 34, 12, 5, 68], $days);
            self::assertSame(1, (int) $c->db()->query("SELECT COUNT(*) FROM newcomers WHERE invite_code = 'real'")->fetchColumn(), 'real newcomer untouched');
            self::assertSame(0, (int) $c->db()->query('SELECT COUNT(*) FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id WHERE n.is_example = 1 AND g.bitrix_task_id IS NOT NULL')->fetchColumn());

            $k = Kpi::compute($c->db(), $c->schedule(), $c->roles(), $c->clock());
            self::assertGreaterThanOrEqual(1, $k['overdue']);
            self::assertGreaterThanOrEqual(1, $k['closedNotReceived']);
            self::assertGreaterThanOrEqual(1, $k['notConnected']);
            self::assertNotNull($k['onTimePct']);
        }
    }

    /** Петров's workwear item shows the «со 2-го раза» history; Ковальчук's scanner is disputed with a note. */
    public function test_example_details(): void
    {
        $c = TestApp::make('2026-11-12T10:00:00+03:00');
        Examples::reset($c->db(), $c->roles(), $c->schedule(), $c->clock());
        $q = fn (string $sql) => $c->db()->query($sql)->fetch();
        $ww = $q("SELECT g.state, g.first_answer FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id WHERE n.full_name = 'Петров Алексей' AND g.key = 'workwear'");
        self::assertSame(['state' => 'confirmed', 'first_answer' => 'partial'], $ww);
        $sc = $q("SELECT g.state, a.note FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id JOIN answers a ON a.agreement_id = g.id AND a.kind = 'recheck' WHERE n.full_name = 'Ковальчук Дмитрий' AND g.key = 'scanner'");
        self::assertSame(['state' => 'disputed', 'note' => 'логин не работает'], $sc);
    }
}
