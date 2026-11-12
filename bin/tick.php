<?php

/**
 * Cron tick, once a minute:
 *   * * * * * cd <repo> && flock -n var/tick.lock php bin/tick.php
 *
 * 1. Finishes hires that stopped halfway, polls Bitrix24 for new «Приём» tasks, tasks closed by owners and disputes resolved by HR.
 *    Each step is independent: a Bitrix24 outage skips it without stopping the rest.
 * 2. Sends what is due in Telegram (questions, re-checks, reminders) within quiet hours.
 */

declare(strict_types=1);

use App\Bitrix\BitrixUnavailable;
use App\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

$c = Container::boot();
$log = static function (string $msg): void {
    $dir = Container::ROOT . '/var/log';
    is_dir($dir) || mkdir($dir, 0775, true);
    file_put_contents("$dir/tick.log", date('c') . " $msg\n", FILE_APPEND);
};

foreach (['resumeIncompleteHires', 'pollHires', 'pollOwnerClosed', 'pollDisputes'] as $step) {
    try {
        $n = $c->poller()->$step();
        if ($n > 0) {
            $log("$step: $n");
        }
    } catch (BitrixUnavailable $e) {
        $log("$step: bitrix unavailable");
    } catch (\Throwable $e) {
        $log("$step: error " . get_class($e) . ': ' . $e->getMessage());
    }
}

try {
    $c->dispatcher()->run();
} catch (\Throwable $e) {
    $log('dispatcher: error ' . get_class($e) . ': ' . $e->getMessage());
}
