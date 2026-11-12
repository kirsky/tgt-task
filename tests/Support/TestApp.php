<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Config;
use App\Container;
use App\Db;

/**
 * Builds a fully wired container for tests: in-memory SQLite, frozen clock,
 * fake Bitrix24 gateway (once it exists) and safe demo settings.
 */
final class TestApp
{
    /**
     * @param string $now Moment the frozen clock starts at.
     */
    public static function make(string $now = '2026-11-17T10:00:00+03:00'): Container
    {
        $c = new Container(
            Config::fromArray([
                'APP_URL' => 'https://novichok.test',
                'TELEGRAM_BOT_USERNAME' => 'novichok_promises_bot',
                'DEMO' => '1',
                'PANEL_PASSWORD' => 'secret',
            ]),
            new FrozenClock($now),
            Db::open(':memory:'),
        );
        if (class_exists(FakeTasks::class)) {
            $c->set('tasks', new FakeTasks());
        }

        return $c;
    }

    /**
     * The fake Bitrix24 gateway of a test container (typed access for assertions).
     */
    public static function tasks(Container $c): FakeTasks
    {
        $t = $c->tasks();
        \PHPUnit\Framework\Assert::assertInstanceOf(FakeTasks::class, $t);

        return $t;
    }

    /**
     * Asserts that a newcomer id (or any id) was returned and gives it as int.
     */
    public static function id(?int $id): int
    {
        \PHPUnit\Framework\Assert::assertNotNull($id);

        return $id;
    }

    /**
     * Asserts that a query found a row and returns it.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    public static function row(?array $row): array
    {
        \PHPUnit\Framework\Assert::assertNotNull($row);

        return $row;
    }

    /**
     * Answer id from an `AnswerService::record()` result that must be waiting for a note.
     *
     * @param array{status: string, answerId?: int} $result
     */
    public static function noteAnswerId(array $result): int
    {
        \PHPUnit\Framework\Assert::assertSame('needs_note', $result['status']);
        \PHPUnit\Framework\Assert::assertArrayHasKey('answerId', $result);

        return $result['answerId'];
    }

    /**
     * The frozen clock of a test container, to move time in tests.
     */
    public static function clock(Container $c): FrozenClock
    {
        $clock = $c->clock();
        \PHPUnit\Framework\Assert::assertInstanceOf(FrozenClock::class, $clock);

        return $clock;
    }
}
