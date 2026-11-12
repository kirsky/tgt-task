<?php

declare(strict_types=1);

namespace App\Bot;

use App\Container;
use App\Domain\Answer;
use SergiX44\Nutgram\Nutgram;

/**
 * Wires Telegram updates to {@see Conversation}.
 *
 * One handler per update kind, parsing the text/callback data itself, so the routing
 * does not depend on handler-matching order inside the library.
 */
final class Handlers
{
    /**
     * Registers the message and callback handlers on a bot.
     */
    public static function register(Nutgram $bot, Container $c): void
    {
        $bot->onMessage(function (Nutgram $bot) use ($c): void {
            $chatId = $bot->chatId();
            $text = trim((string) $bot->message()?->text);
            if ($chatId === null || $text === '') {
                return;
            }
            if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?~u', $text, $m)) {
                $c->conversation()->start($chatId, $m[1] ?? null);

                return;
            }
            $c->conversation()->text($chatId, $text);
        });

        $bot->onCallbackQuery(function (Nutgram $bot) use ($c): void {
            $chatId = $bot->chatId();
            $data = (string) $bot->callbackQuery()?->data;
            $bot->answerCallbackQuery();
            if ($chatId === null) {
                return;
            }
            $conv = $c->conversation();
            if ($data === Conversation::CB_WHO) {
                $conv->whoSees($chatId);

                return;
            }
            if (preg_match('/^a:(\d+):(yes|partial|no)$/', $data, $m)) {
                self::dropButtons($bot);
                $conv->answer($chatId, (int) $m[1], Answer::from($m[2]));

                return;
            }
            if (preg_match('/^skip:(\d+)$/', $data, $m)) {
                self::dropButtons($bot);
                $conv->skip($chatId, (int) $m[1]);
            }
        });
    }

    /**
     * Removes the inline buttons from the message that was tapped, so old questions
     * cannot be answered twice by accident. Failures (message too old) are ignored.
     */
    private static function dropButtons(Nutgram $bot): void
    {
        $msg = $bot->callbackQuery()?->message;
        if ($msg === null) {
            return;
        }
        try {
            $bot->editMessageReplyMarkup(chat_id: $bot->chatId(), message_id: $msg->message_id);
        } catch (\Throwable) {
        }
    }
}
