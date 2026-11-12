<?php

declare(strict_types=1);

namespace App\Web;

use App\Roles;
use App\Sql;

/**
 * Process view for HR: which agreements most often are not kept on the first ask
 * (first answer «Частично» or «Нет»). One newcomer's «Нет» is fixed by a task; the same «Нет»
 * from many newcomers means the process itself needs fixing for the next hires.
 */
final class WeakSpots
{
    /**
     * Failure rate of each agreement that has been asked at least once, worst first
     * (ties: more asked first, then role order).
     *
     * @return list<array{role: string, question: string, asked: int, failed: int, pct: int}>
     */
    public static function compute(\PDO $db, Roles $roles): array
    {
        $rows = Sql::all(
            $db,
            "SELECT n.role_key, g.key, COUNT(*) AS asked, SUM(g.first_answer <> 'yes') AS failed
             FROM agreements g JOIN newcomers n ON n.id = g.newcomer_id
             WHERE g.first_answer IS NOT NULL
             GROUP BY n.role_key, g.key"
        );
        $out = [];
        foreach ($rows as $r) {
            $role = $roles->get((string) $r['role_key']);
            $asked = (int) $r['asked'];
            $failed = (int) $r['failed'];
            $out[] = [
                'role' => (string) $role['title'],
                'question' => (string) $roles->agreement((string) $r['role_key'], (string) $r['key'])['question'],
                'asked' => $asked,
                'failed' => $failed,
                'pct' => (int) round(100 * $failed / $asked),
            ];
        }
        usort($out, fn (array $a, array $b) => [$b['pct'], $b['asked']] <=> [$a['pct'], $a['asked']]);

        return $out;
    }
}
