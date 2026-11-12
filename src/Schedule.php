<?php

declare(strict_types=1);

namespace App;

/**
 * Date and time rules of the probation period.
 *
 * - Day 1 is the start date; a day-N agreement is due on start + (N−1) days.
 * - Questions for a due date go out at the role's `ask_at`.
 * - The owner's Bitrix24 deadline is the role's `work_end` on the due date.
 * - Quiet hours: the bot writes only within [work_start − 2h, work_end + 2h).
 *
 * Stateless; all times are in {@see Clock::TZ}.
 */
final class Schedule
{
    /** Margin around working hours during which the bot may write. */
    private const WINDOW_MARGIN = 'PT2H';

    /**
     * Calendar date on which a day-N agreement is due.
     *
     * @param string $start Start date, `Y-m-d`.
     * @param int    $day   Probation day, 1-based.
     * @return string Due date, `Y-m-d`.
     */
    public function dueDate(string $start, int $day): string
    {
        return $this->date($start)->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Probation day number of a moment: 1 on the start date, 0 or less before it.
     *
     * @param string $start Start date, `Y-m-d`.
     */
    public function dayNumber(string $start, \DateTimeImmutable $now): int
    {
        $today = $this->date($now->setTimezone($this->tz())->format('Y-m-d'));
        $diff = $this->date($start)->diff($today);

        return ($diff->invert ? -$diff->days : $diff->days) + 1;
    }

    /**
     * Moment when the questions for a due date are sent.
     *
     * @param array<string,mixed> $role    Role definition (needs `ask_at`).
     * @param string              $dueDate `Y-m-d`.
     */
    public function askAt(array $role, string $dueDate): \DateTimeImmutable
    {
        return $this->atTime($dueDate, $role['ask_at']);
    }

    /**
     * Deadline of the owner's Bitrix24 task: end of the working day on the due date.
     *
     * @param array<string,mixed> $role    Role definition (needs `work_end`).
     * @param string              $dueDate `Y-m-d`.
     */
    public function deadline(array $role, string $dueDate): \DateTimeImmutable
    {
        return $this->atTime($dueDate, $role['work_end']);
    }

    /**
     * Whether the bot may message a newcomer of this role right now.
     *
     * @param array<string,mixed> $role Role definition (needs `work_start`, `work_end`).
     */
    public function windowOpen(array $role, \DateTimeImmutable $now): bool
    {
        [$open, $close] = $this->window($role, $now->setTimezone($this->tz())->format('Y-m-d'));

        return $now >= $open && $now < $close;
    }

    /**
     * Next moment the window opens (or `$now` itself if it is open).
     *
     * @param array<string,mixed> $role Role definition.
     */
    public function nextWindowOpen(array $role, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($this->windowOpen($role, $now)) {
            return $now;
        }
        $today = $now->setTimezone($this->tz())->format('Y-m-d');
        [$open] = $this->window($role, $today);

        return $now < $open ? $open : $this->window($role, $this->date($today)->modify('+1 day')->format('Y-m-d'))[0];
    }

    /**
     * When to remind about an unanswered question: one hour after work starts on the next day.
     *
     * @param array<string,mixed> $role   Role definition (needs `work_start`).
     * @param \DateTimeImmutable  $sentAt When the question was sent.
     */
    public function reminderAt(array $role, \DateTimeImmutable $sentAt): \DateTimeImmutable
    {
        $next = $this->date($sentAt->setTimezone($this->tz())->format('Y-m-d'))->modify('+1 day')->format('Y-m-d');

        return $this->atTime($next, $role['work_start'])->modify('+1 hour');
    }

    /**
     * Opening and closing moments of the messaging window on a date.
     *
     * @param array<string,mixed> $role
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function window(array $role, string $date): array
    {
        $margin = new \DateInterval(self::WINDOW_MARGIN);

        return [
            $this->atTime($date, $role['work_start'])->sub($margin),
            $this->atTime($date, $role['work_end'])->add($margin),
        ];
    }

    /** Midnight of a `Y-m-d` date in the company time zone. */
    private function date(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 00:00:00', $this->tz());
    }

    /** A `Y-m-d` date at an `H:i` time in the company time zone. */
    private function atTime(string $ymd, string $hm): \DateTimeImmutable
    {
        return new \DateTimeImmutable("$ymd $hm:00", $this->tz());
    }

    /** Company time zone. */
    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone(Clock::TZ);
    }
}
