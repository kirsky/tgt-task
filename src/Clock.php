<?php

declare(strict_types=1);

namespace App;

/**
 * Source of "now". Injected everywhere so schedules can be tested with a frozen time.
 */
interface Clock
{
    /** Time zone of the company: all dates and quiet hours are computed in it. */
    public const TZ = 'Europe/Minsk';

    /**
     * Current moment in {@see Clock::TZ}.
     */
    public function now(): \DateTimeImmutable;
}
