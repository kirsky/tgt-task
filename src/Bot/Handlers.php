<?php

declare(strict_types=1);

namespace App\Bot;

use App\Container;
use App\Domain\Answer;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

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
                $answer = Answer::from($m[2]);
                self::closeQuestion($bot, 'Твой ответ: ' . $answer->label());
                $conv->answer($chatId, (int) $m[1], $answer);

                return;
            }
            if (preg_match('/^skip:(\d+)$/', $data, $m)) {
                self::closeQuestion($bot, 'Пропущено');
                $conv->skip($chatId, (int) $m[1]);
            }
        });
    }

    /**
     * Replaces the buttons of the tapped question with the chosen answer («Твой ответ: Да»),
     * so the chat keeps a readable history and an old question cannot be answered twice
     * by accident. Without the message text, only the buttons are removed. Failures (e.g. a
     * message too old to edit) are ignored.
     *
     * @param string $outcome Line appended in italics, e.g. «Твой ответ: Частично».
     */
    private static function closeQuestion(Nutgram $bot, string $outcome): void
    {
        $msg = $bot->callbackQuery()?->message;
        if ($msg === null) {
            return;
        }
        try {
            $text = $msg->text ?? null;
            if (is_string($text) && $text !== '') {
                $bot->editMessageText(
                    text: Texts::e($text) . "\n\n<i>" . Texts::e($outcome) . '</i>',
                    chat_id: $bot->chatId(),
                    message_id: $msg->message_id,
                    parse_mode: ParseMode::HTML,
                );
            } else {
                $bot->editMessageReplyMarkup(chat_id: $bot->chatId(), message_id: $msg->message_id);
            }
        } catch (\Throwable) {
        }
    }
}
