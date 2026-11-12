<?php

declare(strict_types=1);

namespace Tests;

use App\Hire\HireRequest;
use App\Hire\HireTaskParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading the (invented, demo) hire-task format: «Приём: Фамилия Имя» + three lines.
 */
final class HireTaskParserTest extends TestCase
{
    /** A well-formed task yields all fields; lines may come in any order among other text. */
    public function test_parses_hire_task(): void
    {
        $r = HireTaskParser::parse(
            'Приём: Петров Алексей',
            "Оформить с понедельника.\nДата выхода: 17.11.2026\nДолжность: Кладовщик-комплектовщик\nПодразделение: Склад",
        );
        self::assertInstanceOf(HireRequest::class, $r);
        self::assertSame('Петров Алексей', $r->fullName);
        self::assertSame('Алексей', $r->firstName);
        self::assertSame('Петров', $r->lastName);
        self::assertSame('Кладовщик-комплектовщик', $r->roleTitle);
        self::assertSame('Склад', $r->department);
        self::assertSame('2026-11-17', $r->startDate);
    }

    /** Other task titles are not hire tasks. */
    public function test_not_hire(): void
    {
        self::assertSame('not_hire', HireTaskParser::parse('Увольнение: Иванов Иван', ''));
    }

    /** Missing or impossible fields are reported as a bad format. */
    public function test_bad_format(): void
    {
        self::assertSame('bad_format', HireTaskParser::parse('Приём: Петров Алексей', "Должность: Кладовщик-комплектовщик\nПодразделение: Склад"));
        self::assertSame('bad_format', HireTaskParser::parse('Приём: Петров Алексей', "Должность: X\nПодразделение: Склад\nДата выхода: 32.13.2026"));
        self::assertSame('bad_format', HireTaskParser::parse('Приём:   ', "Должность: X\nПодразделение: Склад\nДата выхода: 17.11.2026"));
    }

    /** BBCode or HTML that Bitrix24 may put around lines does not break parsing. */
    public function test_tolerates_markup(): void
    {
        $r = HireTaskParser::parse('Приём: Иванова Марина', "[B]Должность:[/B] Продавец-консультант<br>\n[B]Подразделение:[/B] Магазин\nДата выхода: 01.12.2026");
        self::assertInstanceOf(HireRequest::class, $r);
        self::assertSame('Продавец-консультант', $r->roleTitle);
        self::assertSame('2026-12-01', $r->startDate);
    }
}
