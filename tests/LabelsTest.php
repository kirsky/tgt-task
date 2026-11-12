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

    /** One assertion per label text and tone. */
    public function test_labels(): void
    {
        self::assertSame(['text' => 'спросим 25.11', 'tone' => 'wait'], $this->label(['state' => 'scheduled']));
        self::assertSame(['text' => 'ждём ответа', 'tone' => 'wait'], $this->label(['state' => 'asked'], '2026-11-21 20:00'));
        self::assertSame(['text' => 'просрочено', 'tone' => 'bad'], $this->label(['state' => 'asked']));
        self::assertSame(['text' => '✓ подтвердил', 'tone' => 'ok'], $this->label(['state' => 'confirmed', 'first_answer' => 'yes']));
        self::assertSame(['text' => '✓ со 2-го раза', 'tone' => 'ok'], $this->label(['state' => 'confirmed', 'first_answer' => 'partial']));
        self::assertSame(['text' => 'не получено', 'tone' => 'warn'], $this->label(['state' => 'not_received'], '2026-11-21 20:00'));
        self::assertSame(['text' => 'просрочено', 'tone' => 'bad'], $this->label(['state' => 'not_received']));
        self::assertSame(['text' => 'закрыто, ждём подтверждения', 'tone' => 'wait'], $this->label(['state' => 'owner_closed']));
        self::assertSame(['text' => 'закрыто, но не получено', 'tone' => 'bad'], $this->label(['state' => 'disputed', 'dispute_open' => 1]));
        self::assertSame(['text' => 'решено через HR', 'tone' => 'ok'], $this->label(['state' => 'disputed', 'dispute_open' => 0]));
        self::assertSame(['text' => 'нет ответа', 'tone' => 'warn'], $this->label(['state' => 'no_answer']));
    }
}
