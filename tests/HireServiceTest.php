<?php

declare(strict_types=1);

namespace Tests;

use App\Bitrix\BitrixUnavailable;
use App\Container;
use App\Hire\HireService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * From a «Приём» task to a newcomer, one owner task per agreement and an invite link.
 */
final class HireServiceTest extends TestCase
{
    private Container $c;
    private FakeTasks $tasks;
    private HireService $hire;

    private const TITLE = 'Приём: Петров Алексей';
    private const DESC = "Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 17.11.2026";

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-16T12:00:00+03:00');
        $this->tasks = TestApp::tasks($this->c);
        $this->hire = $this->c->hire();
    }

    /** Eight warehouse agreements become eight owner tasks with exact fields, plus one invite comment. */
    public function test_creates_newcomer_and_eight_tasks(): void
    {
        $id = $this->hire->process(77, self::TITLE, self::DESC);
        self::assertNotNull($id);

        $creates = $this->tasks->callsOf('create');
        self::assertCount(8, $creates);
        $first = $creates[0][0];
        self::assertSame('Встретить и показать рабочее место — Петров А. (новичок, Склад)', $first['TITLE']);
        self::assertSame(13, $first['RESPONSIBLE_ID']);
        self::assertSame([19], $first['AUDITORS']);
        self::assertSame('2026-11-17T20:00:00+03:00', $first['DEADLINE']);
        self::assertSame(['новичок', 'договорённость'], $first['TAGS']);
        self::assertStringStartsWith('Договорённость при приёме: В первый день новичка встречают', $first['DESCRIPTION']);
        self::assertStringContainsString("https://novichok.test/n/$id", $first['DESCRIPTION']);
        self::assertStringContainsString('Закроется автоматически, когда новичок подтвердит получение.', $first['DESCRIPTION']);
        self::assertSame(21, $creates[6][0]['RESPONSIBLE_ID'], 'day-30 pay check goes to accounting');

        $rows = \App\Sql::all($this->c->db(), "SELECT key, day, due_date, state, bitrix_task_id FROM agreements WHERE newcomer_id = $id ORDER BY id");
        self::assertCount(8, $rows);
        self::assertSame(['scheduled'], array_values(array_unique(array_column($rows, 'state'))));
        self::assertSame('2026-11-19', $rows[3]['due_date']);
        self::assertSame('2026-12-16', $rows[6]['due_date']);
        self::assertNotContains(null, array_column($rows, 'bitrix_task_id'));

        $comments = $this->tasks->callsOf('comment');
        self::assertCount(1, $comments);
        self::assertSame(77, $comments[0][0]);
        self::assertMatchesRegularExpression('~https://t\.me/novichok_promises_bot\?start=[0-9a-f]{16}~', $comments[0][1]);
    }

    /** Processing the same hire task again does nothing. */
    public function test_second_process_is_noop(): void
    {
        $id = $this->hire->process(77, self::TITLE, self::DESC);
        $calls = count($this->tasks->calls);
        self::assertSame($id, $this->hire->process(77, self::TITLE, self::DESC));
        self::assertCount($calls, $this->tasks->calls);
    }

    /** Review Focus 3: an outage mid-hire leaves no duplicates; the retry creates only what is missing. */
    public function test_retry_creates_only_missing_tasks(): void
    {
        $this->tasks->failOnCall(3);
        try {
            $this->hire->process(77, self::TITLE, self::DESC);
            self::fail('expected BitrixUnavailable');
        } catch (BitrixUnavailable) {
        }
        $id = $this->hire->process(77, self::TITLE, self::DESC);

        self::assertCount(8, $this->tasks->callsOf('create'));
        $ids = \App\Sql::column($this->c->db(), "SELECT bitrix_task_id FROM agreements WHERE newcomer_id = $id");
        self::assertCount(8, array_unique(array_filter($ids)));
        self::assertCount(1, $this->tasks->callsOf('comment'));
    }

    /**
     * A create whose response timed out (the task exists in Bitrix24, we have no id) is found by
     * its XML_ID on retry instead of being created twice.
     */
    public function test_read_timeout_on_create_does_not_duplicate(): void
    {
        $this->tasks->timeoutAfterCreateOnCall(3);
        try {
            $this->hire->process(77, self::TITLE, self::DESC);
            self::fail('expected BitrixUnavailable');
        } catch (BitrixUnavailable) {
        }
        $id = TestApp::id($this->hire->process(77, self::TITLE, self::DESC));

        self::assertCount(8, $this->tasks->created, 'eight tasks in Bitrix24, no duplicate');
        $ids = \App\Sql::column($this->c->db(), 'SELECT bitrix_task_id FROM agreements WHERE newcomer_id = ? ORDER BY id', [$id]);
        self::assertSame(array_keys($this->tasks->created), array_map('intval', $ids));
        foreach ($this->tasks->created as $fields) {
            self::assertMatchesRegularExpression('/^novichok-agreement-\d+$/', $fields['XML_ID']);
        }
    }

    /** An unknown position gets one explanatory comment and is never processed again. */
    public function test_unknown_role_comments_once(): void
    {
        $desc = "Должность: Директор\nПодразделение: Офис\nДата выхода: 17.11.2026";
        self::assertNull($this->hire->process(78, self::TITLE, $desc));
        self::assertNull($this->hire->process(78, self::TITLE, $desc));
        $comments = $this->tasks->callsOf('comment');
        self::assertCount(1, $comments);
        self::assertSame('Не нашёл список договорённостей для должности «Директор».', $comments[0][1]);
    }

    /** A malformed hire task gets one comment describing the expected lines. */
    public function test_bad_format_comments_once(): void
    {
        self::assertNull($this->hire->process(79, self::TITLE, 'Должность: Кладовщик-комплектовщик'));
        self::assertNull($this->hire->process(79, self::TITLE, 'Должность: Кладовщик-комплектовщик'));
        $comments = $this->tasks->callsOf('comment');
        self::assertCount(1, $comments);
        self::assertStringStartsWith('Не смог разобрать задачу.', $comments[0][1]);
    }

    /** The invite link format. */
    public function test_invite_link(): void
    {
        self::assertSame('https://t.me/novichok_promises_bot?start=abc', $this->hire->inviteLink('abc'));
    }
}
