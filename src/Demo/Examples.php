<?php

declare(strict_types=1);

namespace App\Demo;

use App\Clock;
use App\Roles;
use App\Schedule;

/**
 * Five example newcomers that show the panel's states before anyone is hired:
 * a fresh start, a «закрыто, но не получено» case, an overdue item, a newcomer who never
 * connected the bot, and one close to the end of probation with everything confirmed.
 *
 * Examples live only in the local database (no Bitrix24 tasks, no real Telegram chat) and
 * are rebuilt relative to today by a nightly cron, so «день 2» stays day 2.
 */
final class Examples
{
    /**
     * Example definitions: name, role, probation day today, whether the bot is connected,
     * and per-agreement outcome overrides `key => [state, initialAnswer, note, recheckAnswer, recheckNote]`.
     * Items not listed are confirmed if already asked (asked-but-unanswered when not connected),
     * scheduled otherwise.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: bool, 4: array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}>}>
     */
    private static function set(): array
    {
        return [
            ['Петров Алексей', 'warehouse_picker', 2, true, [
                'workwear' => ['confirmed', 'partial', 'обуви моего размера нет', 'yes', null],
            ]],
            ['Ковальчук Дмитрий', 'warehouse_picker', 34, true, [
                'scanner' => ['disputed', 'no', 'логина ещё нет', 'no', 'логин не работает'],
            ]],
            ['Иванова Марина', 'store_seller', 12, true, [
                'schedule' => ['not_received', 'partial', 'знаю только на неделю вперёд', null, null],
            ]],
            ['Новик Павел', 'warehouse_picker', 5, false, []],
            ['Савич Анна', 'store_seller', 68, true, []],
        ];
    }

    /**
     * Deletes previous examples (real newcomers are untouched) and creates the set anew
     * with dates relative to today.
     */
    public static function reset(\PDO $db, Roles $roles, Schedule $s, Clock $clock): void
    {
        $now = $clock->now();
        $today = $now->format('Y-m-d');
        $db->beginTransaction();
        $db->exec('DELETE FROM newcomers WHERE is_example = 1');

        foreach (self::set() as $i => [$name, $roleKey, $day, $connected, $overrides]) {
            $role = $roles->get($roleKey);
            $start = (new \DateTimeImmutable($today))->modify('-' . ($day - 1) . ' days')->format('Y-m-d');
            $db->prepare('INSERT INTO newcomers(full_name, first_name, role_key, start_date, invite_code, tg_chat_id, is_example, created_at) VALUES(?,?,?,?,?,?,1,?)')
                ->execute([$name, explode(' ', $name)[1], $roleKey, $start, 'example-' . bin2hex(random_bytes(6)), $connected ? -1000 - $i : null, $now->format(DATE_ATOM)]);
            $nid = (int) $db->lastInsertId();

            foreach ($role['agreements'] as $a) {
                $due = $s->dueDate($start, $a['day']);
                $askAt = $s->askAt($role, $due);
                $asked = $askAt <= $now;
                [$state, $first, $note, $recheck, $recheckNote] = $overrides[$a['key']]
                    ?? ($asked ? ($connected ? ['confirmed', 'yes', null, null, null] : ['asked', null, null, null, null]) : ['scheduled', null, null, null, null]);

                $db->prepare('INSERT INTO agreements(newcomer_id, key, day, due_date, state, first_answer, updated_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$nid, $a['key'], $a['day'], $due, $state, $first, $now->format(DATE_ATOM)]);
                $aid = (int) $db->lastInsertId();

                if ($first !== null) {
                    self::answer($db, $aid, 'initial', $first, $note, $askAt->modify('+20 minutes'));
                }
                if ($recheck !== null) {
                    self::answer($db, $aid, 'recheck', $recheck, $recheckNote, $askAt->modify('+1 day'));
                }
                if ($state === 'disputed') {
                    $db->prepare('INSERT INTO disputes(agreement_id, created_at) VALUES(?, ?)')->execute([$aid, $askAt->modify('+1 day')->format(DATE_ATOM)]);
                }
            }
        }
        $db->commit();
    }

    /** Inserts one historical answer (effects marked as already applied). */
    private static function answer(\PDO $db, int $agreementId, string $kind, string $answer, ?string $note, \DateTimeImmutable $at): void
    {
        $db->prepare("INSERT INTO answers(agreement_id, kind, answer, note, effects, created_at, finalized_at, effects_applied_at) VALUES(?,?,?,?,'[]',?,?,?)")
            ->execute([$agreementId, $kind, $answer, $note, $at->format(DATE_ATOM), $at->format(DATE_ATOM), $at->format(DATE_ATOM)]);
    }
}
