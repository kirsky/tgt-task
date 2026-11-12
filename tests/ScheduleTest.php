<?php

declare(strict_types=1);

namespace Tests;

use App\Roles;
use App\Schedule;
use PHPUnit\Framework\TestCase;

/**
 * Probation-day math, question/deadline times and the quiet-hours window
 * (no messages earlier than 2 h before work or later than 2 h after it).
 */
final class ScheduleTest extends TestCase
{
    private Schedule $s;
    /** @var array<string,mixed> Warehouse role: 08:00–20:00, questions at 20:15. */
    private array $wh;

    protected function setUp(): void
    {
        $this->s = new Schedule();
        $this->wh = Roles::load(__DIR__ . '/../config/roles.php')->get('warehouse_picker');
    }

    /** Builds a Minsk-time moment. */
    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Minsk'));
    }

    /** Day N is due on start + (N−1) days. */
    public function test_due_date(): void
    {
        self::assertSame('2026-11-17', $this->s->dueDate('2026-11-17', 1));
        self::assertSame('2026-12-16', $this->s->dueDate('2026-11-17', 30));
    }

    /** The start date is day 1; before it the number is 0 or negative. */
    public function test_day_number(): void
    {
        self::assertSame(2, $this->s->dayNumber('2026-11-17', $this->at('2026-11-18 23:59')));
        self::assertSame(1, $this->s->dayNumber('2026-11-17', $this->at('2026-11-17 00:00')));
        self::assertSame(0, $this->s->dayNumber('2026-11-17', $this->at('2026-11-16 10:00')));
    }

    /** Questions go out at the role's ask time; the Bitrix24 deadline is the end of the working day. */
    public function test_ask_at_and_deadline(): void
    {
        self::assertSame('2026-11-17T20:15:00+03:00', $this->s->askAt($this->wh, '2026-11-17')->format(DATE_ATOM));
        self::assertSame('2026-11-17T20:00:00+03:00', $this->s->deadline($this->wh, '2026-11-17')->format(DATE_ATOM));
    }

    /** Window is [work_start − 2h, work_end + 2h): 06:00–22:00 for the warehouse. */
    public function test_window_bounds(): void
    {
        self::assertTrue($this->s->windowOpen($this->wh, $this->at('2026-11-17 06:00')));
        self::assertFalse($this->s->windowOpen($this->wh, $this->at('2026-11-17 05:59')));
        self::assertTrue($this->s->windowOpen($this->wh, $this->at('2026-11-17 21:59')));
        self::assertFalse($this->s->windowOpen($this->wh, $this->at('2026-11-17 22:00')));
    }

    /** After the window closes, the next opening is 06:00 the next morning; before it opens, 06:00 today. */
    public function test_next_window_open(): void
    {
        self::assertSame('2026-11-18T06:00:00+03:00', $this->s->nextWindowOpen($this->wh, $this->at('2026-11-17 23:10'))->format(DATE_ATOM));
        self::assertSame('2026-11-17T06:00:00+03:00', $this->s->nextWindowOpen($this->wh, $this->at('2026-11-17 03:00'))->format(DATE_ATOM));
    }

    /** A reminder goes out one hour after work starts on the next calendar day. */
    public function test_reminder_at(): void
    {
        self::assertSame('2026-11-18T09:00:00+03:00', $this->s->reminderAt($this->wh, $this->at('2026-11-17 20:16'))->format(DATE_ATOM));
    }
}
