<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Clock;

final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $iso)
    {
        $this->set($iso);
    }

    public function set(string $iso): void
    {
        $this->now = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(self::TZ));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
