<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Config;
use App\Container;
use App\Db;

final class TestApp
{
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
}
