<?php

declare(strict_types=1);

namespace App\Web;

use App\Clock;
use App\Roles;
use App\Schedule;

/**
 * The four numbers at the top of the HR panel (spec §8). Examples are counted together
 * with real newcomers so the panel is not empty before the first hire.
 */
final class Kpi
{
    /**
     * @return array{onTimePct: ?int, overdue: int, closedNotReceived: int, notConnected: int}
     *   - onTimePct: share of agreements whose first answer in the last 30 days was «Да»
     *     (null when there were no answers);
     *   - overdue: not confirmed, owner's deadline passed, task not closed;
     *   - closedNotReceived: open «закрыто, но не получено» disputes;
     *   - notConnected: start within 7 days or already started, bot not connected.
     */
    public static function compute(\PDO $db, Schedule $s, Roles $roles, Clock $clock): array
    {
        $now = $clock->now();
        $since = $now->modify('-30 days')->format(DATE_ATOM);

        $first = \App\Sql::column($db, 
            "SELECT a.answer FROM answers a
             JOIN (SELECT agreement_id, MIN(id) AS id FROM answers WHERE kind = 'initial' GROUP BY agreement_id) f ON f.id = a.id
             WHERE a.created_at >= " . $db->quote($since)
        );
        $onTime = $first === [] ? null : (int) round(100 * count(array_filter($first, fn ($a) => $a === 'yes')) / count($first));

        $overdue = 0;
        $rows = \App\Sql::all($db, "SELECT g.due_date, n.role_key FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id WHERE g.state IN ('not_received','asked')");
        foreach ($rows as $r) {
            if ($s->deadline($roles->get($r['role_key']), $r['due_date']) < $now) {
                $overdue++;
            }
        }

        $soon = $now->modify('+7 days')->format('Y-m-d');

        return [
            'onTimePct' => $onTime,
            'overdue' => $overdue,
            'closedNotReceived' => (int) \App\Sql::value($db, 'SELECT COUNT(*) FROM disputes WHERE resolved_at IS NULL'),
            'notConnected' => (int) \App\Sql::value($db, 'SELECT COUNT(*) FROM newcomers WHERE tg_chat_id IS NULL AND start_date <= ' . $db->quote($soon)),
        ];
    }
}
