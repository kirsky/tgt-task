<?php

declare(strict_types=1);

namespace App;

interface Clock
{
    public const TZ = 'Europe/Minsk';

    public function now(): \DateTimeImmutable;
}
