<?php

declare(strict_types=1);

namespace Tests;

use App\Container;
use PHPUnit\Framework\TestCase;

/**
 * Wiring of real runs: where secrets are read from.
 */
final class ContainerTest extends TestCase
{
    /** By default the `.env` is the one next to the repository folder, not inside it. */
    public function test_default_env_path_is_outside_repo(): void
    {
        putenv('APP_ENV_FILE');
        self::assertSame(dirname(__DIR__, 2) . '/.env', Container::envPath());
    }
}
