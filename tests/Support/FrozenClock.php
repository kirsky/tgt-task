<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Clock;

/**
 * Test clock that stays at a fixed moment until moved with {@see FrozenClock::set()}.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    /**
     * @param string $iso Any ISO-8601 datetime; converted to Europe/Minsk.
     */
    public function __construct(string $iso)
    {
        $this->set($iso);
    }

    /**
     * Moves the clock to another moment.
     */
    public function set(string $iso): void
    {
        $this->now = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(self::TZ));
    }

    /**
     * Returns the frozen moment.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
