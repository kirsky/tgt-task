<?php

declare(strict_types=1);

namespace Tests;

use App\Web\Kpi;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * The four numbers at the top of the HR panel (spec §8).
 */
final class KpiTest extends TestCase
{
    /** Builds a fixture and checks every KPI against hand-counted values. */
    public function test_kpis(): void
    {
        $c = TestApp::make('2026-11-20T12:00:00+03:00');
        $db = $c->db();
        $db->exec("INSERT INTO newcomers(id, full_name, first_name, role_key, start_date, invite_code, tg_chat_id, created_at) VALUES
            (1, 'А Б', 'Б', 'warehouse_picker', '2026-11-17', 'c1', 11, 'x'),
            (2, 'В Г', 'Г', 'warehouse_picker', '2026-11-21', 'c2', NULL, 'x'),
            (3, 'Д Е', 'Е', 'store_seller', '2026-12-30', 'c3', NULL, 'x')");
        // newcomer 1: 4 first answers (3 yes, 1 partial), one overdue not_received, one open dispute
        $db->exec("INSERT INTO agreements(id, newcomer_id, key, day, due_date, state, first_answer, updated_at) VALUES
            (1, 1, 'met', 1, '2026-11-17', 'confirmed', 'yes', 'x'),
            (2, 1, 'workwear', 1, '2026-11-17', 'not_received', 'partial', 'x'),
            (3, 1, 'mentor', 1, '2026-11-17', 'confirmed', 'yes', 'x'),
            (4, 1, 'scanner', 3, '2026-11-19', 'disputed', 'yes', 'x'),
            (5, 1, 'shifts', 3, '2026-11-19', 'scheduled', NULL, 'x')");
        foreach ([[1, 'yes'], [2, 'partial'], [3, 'yes'], [4, 'yes']] as [$a, $ans]) {
            $db->exec("INSERT INTO answers(agreement_id, kind, answer, created_at) VALUES($a, 'initial', '$ans', '2026-11-18T10:00:00+03:00')");
        }
        $db->exec("INSERT INTO answers(agreement_id, kind, answer, created_at) VALUES(4, 'recheck', 'no', '2026-11-19T10:00:00+03:00')");
        $db->exec("INSERT INTO disputes(agreement_id, bitrix_task_id, created_at) VALUES(4, 99, 'x')");

        $k = Kpi::compute($db, $c->schedule(), $c->roles(), $c->clock());
        self::assertSame(75, $k['onTimePct']);
        self::assertSame(1, $k['overdue']);
        self::assertSame(1, $k['closedNotReceived']);
        self::assertSame(1, $k['notConnected'], 'starts tomorrow and not connected; the one in 40 days does not count');
    }

    /** With no answers yet the percentage is unknown, not zero. */
    public function test_empty(): void
    {
        $c = TestApp::make();
        self::assertNull(Kpi::compute($c->db(), $c->schedule(), $c->roles(), $c->clock())['onTimePct']);
    }
}
