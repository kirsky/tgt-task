<?php

/**
 * Registers the Telegram webhook: https://alpha.indigit.info/<TELEGRAM_WEBHOOK_PATH>
 * with the secret header, message + callback updates only, dropping old pending updates.
 * Prints the resulting webhook info without the token.
 */

declare(strict_types=1);

use App\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

$c = Container::boot();
$host = $c->config()->get('TELEGRAM_WEBHOOK_HOST', 'alpha.indigit.info');
$url = "https://$host/" . $c->config()->require('TELEGRAM_WEBHOOK_PATH');

$c->bot()->setWebhook(
    url: $url,
    max_connections: 1, // one update at a time: no webhook/webhook races on the same chat
    allowed_updates: ['message', 'callback_query'],
    drop_pending_updates: true,
    secret_token: $c->config()->require('TELEGRAM_WEBHOOK_SECRET'),
);
$info = $c->bot()->getWebhookInfo();
echo json_encode([
    'url_set' => $info?->url === $url,
    'pending_update_count' => $info?->pending_update_count,
    'last_error_message' => $info?->last_error_message,
    'allowed_updates' => $info?->allowed_updates,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
