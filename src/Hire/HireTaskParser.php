<?php

declare(strict_types=1);

namespace App\Hire;

/**
 * Reads the demo hire-task format:
 *
 *     Приём: Петров Алексей
 *     Должность: Кладовщик-комплектовщик
 *     Подразделение: Склад
 *     Дата выхода: 17.11.2026
 *
 * The format is invented for the demo; a real company's hire tasks will differ,
 * which is why parsing is isolated in this one class.
 */
final class HireTaskParser
{
    /** Error code: the task is not a hire task at all. */
    public const NOT_HIRE = 'not_hire';
    /** Error code: a hire task, but required lines are missing or invalid. */
    public const BAD_FORMAT = 'bad_format';

    /**
     * @param string $title       Task title.
     * @param string $description Task description (may contain BBCode/HTML from Bitrix24).
     * @return HireRequest|string The request, or {@see NOT_HIRE} / {@see BAD_FORMAT}.
     */
    public static function parse(string $title, string $description): HireRequest|string
    {
        if (!preg_match('/^\s*Приём:\s*(.*)$/u', $title, $m)) {
            return self::NOT_HIRE;
        }
        $fullName = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
        $text = strip_tags(preg_replace('/\[\/?[a-z]+[^\]]*\]/iu', '', $description) ?? '');

        $role = self::line($text, 'Должность');
        $dept = self::line($text, 'Подразделение');
        $date = self::line($text, 'Дата выхода');
        $start = $date !== null ? \DateTimeImmutable::createFromFormat('!d.m.Y', $date) : false;
        $valid = $start !== false && $start->format('d.m.Y') === $date;

        if ($fullName === '' || $role === null || $dept === null || !$valid) {
            return self::BAD_FORMAT;
        }
        $parts = explode(' ', $fullName);

        return new HireRequest(
            fullName: $fullName,
            firstName: $parts[1] ?? $parts[0],
            lastName: $parts[0],
            roleTitle: $role,
            department: $dept,
            startDate: $start->format('Y-m-d'),
        );
    }

    /** Value of a «Label: value» line, or null when absent or empty. */
    private static function line(string $text, string $label): ?string
    {
        if (!preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)\s*$/mu', $text, $m)) {
            return null;
        }

        return $m[1];
    }
}
