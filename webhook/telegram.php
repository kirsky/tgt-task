<?php

/**
 * Telegram webhook endpoint (served on alpha.indigit.info under a hard-to-guess path).
 *
 * Telegram sends every update with the `X-Telegram-Bot-Api-Secret-Token` header set by
 * `bin/set-webhook.php`; anything without the right header is refused before the bot runs.
 */

declare(strict_types=1);

use App\Bot\Handlers;
use App\Container;
use SergiX44\Nutgram\RunningMode\Webhook;

require dirname(__DIR__) . '/vendor/autoload.php';

$c = Container::boot();
$secret = (string) $c->config()->get('TELEGRAM_WEBHOOK_SECRET');
if ($secret === '' || !hash_equals($secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    exit;
}

try {
    $bot = $c->bot();
    $bot->setRunningMode(Webhook::class);
    Handlers::register($bot, $c);
    $bot->run();
} catch (\Throwable $e) {
    // Always answer 200 so Telegram does not retry the same update forever; keep the error.
    $dir = Container::ROOT . '/var/log';
    is_dir($dir) || mkdir($dir, 0775, true);
    file_put_contents("$dir/webhook.log", date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . "\n", FILE_APPEND);
}
