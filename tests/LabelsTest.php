<?php

declare(strict_types=1);

namespace Tests;

use App\Web\Labels;
use PHPUnit\Framework\TestCase;

/**
 * Status pills of agreements in the panel (texts from spec §4, as amended).
 */
final class LabelsTest extends TestCase
{
    /** Minsk moment helper. */
    private function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('Europe/Minsk'));
    }

    /**
     * Label of a row at 2026-11-20 12:00, asked at 25.11 20:15, deadline 19.11 20:00 unless given.
     *
     * @param array<string,mixed> $row
     * @return array{text: string, tone: string}
     */
    private function label(array $row, string $deadline = '2026-11-19 20:00'): array
    {
        return Labels::agreement($row + ['first_answer' => null, 'dispute_open' => null], $this->t('2026-11-20 12:00'), $this->t('2026-11-25 20:15'), $this->t($deadline));
    }

    /** Russian plural forms used in panel counters. */
    public function test_plural(): void
    {
        $f = fn (int $n) => \App\Web\Views::plural($n, 'требует', 'требуют', 'требуют');
        self::assertSame(['требует', 'требуют', 'требуют', 'требуют', 'требует', 'требуют'], [$f(1), $f(2), $f(5), $f(11), $f(21), $f(22)]);
        $g = fn (int $n) => \App\Web\Views::plural($n, 'задача', 'задачи', 'задач');
        self::assertSame(['задача', 'задачи', 'задач', 'задач', 'задач'], [$g(1), $g(3), $g(8), $g(12), $g(0)]);
    }

    /** Times are shown in Minsk time whatever the server's time zone is. */
    public function test_time_is_shown_in_minsk(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        self::assertSame('12.11 13:14', \App\Web\Views::time('2026-11-12T10:14:00+00:00', 'd.m H:i'));
        self::assertSame('12.11.2026', \App\Web\Views::time('2026-11-12', 'd.m.Y'));
        date_default_timezone_set($tz);
    }

    /** One assertion per label text and tone. */
    public function test_labels(): void
    {
        self::assertSame(['text' => 'спросим 25.11', 'tone' => 'wait'], $this->label(['state' => 'scheduled']));
        self::assertSame(['text' => 'ждём ответа', 'tone' => 'wait'], $this->label(['state' => 'asked'], '2026-11-21 20:00'));
        self::assertSame(['text' => 'просрочено', 'tone' => 'bad'], $this->label(['state' => 'asked']));
        self::assertSame(['text' => 'подтвердил', 'tone' => 'ok'], $this->label(['state' => 'confirmed', 'first_answer' => 'yes']));
        self::assertSame(['text' => 'со 2-го раза', 'tone' => 'ok'], $this->label(['state' => 'confirmed', 'first_answer' => 'partial']));
        self::assertSame(['text' => 'не получено', 'tone' => 'warn'], $this->label(['state' => 'not_received'], '2026-11-21 20:00'));
        self::assertSame(['text' => 'просрочено', 'tone' => 'bad'], $this->label(['state' => 'not_received']));
        self::assertSame(['text' => 'закрыто, ждём подтверждения', 'tone' => 'wait'], $this->label(['state' => 'owner_closed']));
        self::assertSame(['text' => 'закрыто, но не получено', 'tone' => 'bad'], $this->label(['state' => 'disputed', 'dispute_open' => 1]));
        self::assertSame(['text' => 'решено через HR', 'tone' => 'ok'], $this->label(['state' => 'disputed', 'dispute_open' => 0]));
        self::assertSame(['text' => 'нет ответа', 'tone' => 'warn'], $this->label(['state' => 'no_answer']));
    }
}
