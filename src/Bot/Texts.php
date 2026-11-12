<?php

declare(strict_types=1);

namespace App\Bot;

/**
 * All bot copy (Russian), in one place.
 *
 * Rules (spec §6): the bot asks about what the company did, never about feelings;
 * never names who will fix something or promises deadlines; never reassures by denial;
 * never opens with a broad question it does not actually ask. Messages use Telegram HTML,
 * so every dynamic value is escaped with {@see e()}.
 */
final class Texts
{
    /** Button labels of the three answers. */
    public const YES = 'Да';
    public const PARTIAL = 'Частично';
    public const NO = 'Нет';
    /** Button to skip the optional note. */
    public const SKIP = 'Пропустить';
    /** Button that explains who sees the answers. */
    public const WHO = 'Кто видит ответы?';

    /** Russian month names in the genitive case, for «17 ноября». */
    private const MONTHS = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /** Line shared by both greetings: what the bot will do, said once and plainly. */
    private const PURPOSE = 'В первые недели я иногда буду уточнять, всё ли, о чём договаривались, ты уже получил, — так нам проще ничего не упустить.';

    /**
     * Greeting before the first working day, with the practical details of day one.
     *
     * @param string              $firstName Newcomer's first name.
     * @param string              $startDate `Y-m-d`.
     * @param array<string,mixed> $role      Role definition with `logistics`.
     */
    public static function welcomeBefore(string $firstName, string $startDate, array $role): string
    {
        $l = $role['logistics'];
        $d = new \DateTimeImmutable($startDate);
        $date = $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')];

        return 'Привет, ' . self::e($firstName) . "! Ждём тебя {$date} к " . self::e($l['time']) . ".\n"
            . '📍 ' . self::e($l['place']) . ' · как добраться: ' . self::e($l['route']) . "\n"
            . '👤 Тебя встретит ' . self::e($l['meet_title']) . ' ' . self::e($l['meet_name']) . ', ' . self::e($l['meet_phone']) . "\n"
            . '🎒 Взять: ' . self::e($l['bring']) . "\n\n"
            . self::PURPOSE;
    }

    /**
     * Greeting when the newcomer connects on or after the first day.
     */
    public static function welcomeAfter(string $firstName): string
    {
        return 'Привет, ' . self::e($firstName) . "!\n\n" . self::PURPOSE;
    }

    /** Answer to «Кто видит ответы?». */
    public static function whoSees(): string
    {
        return 'Ответы видят сотрудники HR и тот, кто отвечает за конкретный пункт, — чтобы его выполнить.';
    }

    /** /start without a valid personal code. */
    public static function noCode(): string
    {
        return 'Этот бот работает по личной ссылке от отдела кадров. Если ты новый сотрудник — попроси ссылку у HR.';
    }

    /** /start with a code already bound to another Telegram account. */
    public static function linkUsed(): string
    {
        return 'Эта ссылка уже привязана к другому аккаунту. Если это ошибка — напиши в HR.';
    }

    /**
     * First line of a day's batch of questions.
     *
     * @param int $day Probation day of the batch.
     */
    public static function intro(int $day): string
    {
        return $day === 1 ? 'С первым рабочим днём! Уточню пару вещей.' : 'Уточню пару вещей.';
    }

    /** Follow-up after «Частично»/«Нет». */
    public static function askNote(): string
    {
        return 'Чего не хватает? Можно коротко.';
    }

    /** After a note (or skip). */
    public static function thanksNoted(): string
    {
        return 'Спасибо, учли.';
    }

    /** After the last question of a batch. */
    public static function batchDone(): string
    {
        return 'Спасибо!';
    }

    /**
     * Re-check after the owner closed the task.
     *
     * @param string $doneText E.g. «спецодежду и обувь выдали».
     */
    public static function recheck(string $doneText): string
    {
        return 'Отмечено, что ' . self::e($doneText) . '. Всё так?';
    }

    /** After «Да» on a re-check. */
    public static function recheckYes(): string
    {
        return 'Отлично, спасибо!';
    }

    /** Prefix of a reminder about an unanswered question. */
    public static function reminderPrefix(): string
    {
        return 'Напомню вопрос:';
    }

    /** Reply to a stale or repeated button tap. */
    public static function alreadyNoted(): string
    {
        return 'Спасибо, уже учли.';
    }

    /** Reply to free text when no note is expected. */
    public static function freeText(): string
    {
        return 'Спасибо! Я сам напишу, когда будут вопросы.';
    }

    /** Escapes a dynamic value for Telegram HTML. */
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
