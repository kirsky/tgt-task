<?php

declare(strict_types=1);

namespace App;

/**
 * Real wall-clock time in the company time zone.
 */
final class SystemClock implements Clock
{
    /**
     * Returns the current system time in Europe/Minsk.
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }
}
