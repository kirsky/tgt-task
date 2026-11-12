<?php

declare(strict_types=1);

namespace App\Hire;

/**
 * Data read from a hire task: who starts, in which role and when.
 */
final readonly class HireRequest
{
    /**
     * @param string $fullName   «Фамилия Имя» as written in the title.
     * @param string $firstName  Used to greet the newcomer in Telegram.
     * @param string $lastName   Used in task titles («Петров А.»).
     * @param string $roleTitle  «Должность» — matched against configured roles.
     * @param string $department «Подразделение».
     * @param string $startDate  First working day, `Y-m-d`.
     */
    public function __construct(
        public string $fullName,
        public string $firstName,
        public string $lastName,
        public string $roleTitle,
        public string $department,
        public string $startDate,
    ) {
    }

    /**
     * Short name for task titles: «Петров А.».
     */
    public function shortName(): string
    {
        return self::short($this->fullName);
    }

    /**
     * Turns «Фамилия Имя …» into «Фамилия И.».
     */
    public static function short(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        if (count($parts) < 2) {
            return trim($fullName);
        }

        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }
}
