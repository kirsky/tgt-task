<?php

declare(strict_types=1);

namespace Tests;

use App\Bot\AnswerService;
use App\Container;
use App\Domain\Answer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTasks;
use Tests\Support\TestApp;

/**
 * What an answer does: local state first, then the Bitrix24 effects (after the optional note).
 */
final class AnswerServiceTest extends TestCase
{
    private Container $c;
    private FakeTasks $tasks;
    private AnswerService $answers;
    private int $newcomerId;

    protected function setUp(): void
    {
        $this->c = TestApp::make('2026-11-17T20:20:00+03:00');
        $this->tasks = TestApp::tasks($this->c);
        $this->answers = $this->c->answers();
        $this->newcomerId = TestApp::id($this->c->hire()->process(77, 'Приём: Петров Алексей', "Должность: Кладовщик-комплектовщик\nПодразделение: Склад\nДата выхода: 17.11.2026"));
        $this->tasks->calls = [];
    }

    /**
     * Puts an agreement into a state and returns [agreement id, its Bitrix24 task id].
     *
     * @return array{0: int, 1: int}
     */
    private function agreement(string $key, string $state): array
    {
        $this->c->db()->prepare('UPDATE agreements SET state = ? WHERE newcomer_id = ? AND key = ?')->execute([$state, $this->newcomerId, $key]);
        $row = TestApp::row(\App\Sql::one($this->c->db(), "SELECT id, bitrix_task_id FROM agreements WHERE newcomer_id = {$this->newcomerId} AND key = '$key'"));

        return [(int) $row['id'], (int) $row['bitrix_task_id']];
    }

    private function state(int $agreementId): string
    {
        return (string) \App\Sql::value($this->c->db(), "SELECT state FROM agreements WHERE id = $agreementId");
    }

    /** «Да» on an open task completes it and comments the confirmation, no note asked. */
    public function test_yes_completes_and_comments(): void
    {
        [$a, $task] = $this->agreement('met', 'asked');
        self::assertSame(['status' => 'done'], $this->answers->record($a, Answer::Yes));
        self::assertSame('confirmed', $this->state($a));
        self::assertSame([[$task]], $this->tasks->callsOf('complete'));
        self::assertSame([[$task, 'Новичок подтвердил получение.']], $this->tasks->callsOf('comment'));
    }

    /** «Частично» asks for a note; the comment carries it. */
    public function test_partial_needs_note_then_comments_with_note(): void
    {
        [$a, $task] = $this->agreement('workwear', 'asked');
        $r = $this->answers->record($a, Answer::Partial);
        self::assertSame('needs_note', $r['status']);
        self::assertSame('not_received', $this->state($a));
        self::assertSame([], $this->tasks->calls, 'nothing sent before the note');

        $this->answers->finalize(TestApp::noteAnswerId($r), '  обуви нет  ');
        self::assertSame([[$task, 'Получение подтверждено частично: «обуви нет»']], $this->tasks->callsOf('comment'));
        self::assertSame([], $this->tasks->callsOf('complete'));
    }

    /** Skipping the note still comments, just without a quote. */
    public function test_skip_note_comments_without_quote(): void
    {
        [$a, $task] = $this->agreement('workwear', 'asked');
        $r = $this->answers->record($a, Answer::No);
        $this->answers->finalize(TestApp::noteAnswerId($r), null);
        self::assertSame([[$task, 'Новичок пока не получил.']], $this->tasks->callsOf('comment'));
    }

    /** Re-check «Нет» after the owner closed the task: HR task + dispute, owner's task untouched. */
    public function test_recheck_no_creates_hr_task_and_dispute(): void
    {
        [$a, $task] = $this->agreement('scanner', 'owner_closed');
        $r = $this->answers->record($a, Answer::No);
        $this->answers->finalize(TestApp::noteAnswerId($r), 'логин не работает');

        self::assertSame('disputed', $this->state($a));
        $creates = $this->tasks->callsOf('create');
        self::assertCount(1, $creates);
        $f = $creates[0][0];
        self::assertSame('Закрыто, но не получено: Выдать доступ к ТСД — Петров А.', $f['TITLE']);
        self::assertSame(19, $f['RESPONSIBLE_ID']);
        self::assertStringContainsString("https://portal.test/task/$task/", $f['DESCRIPTION']);
        self::assertStringContainsString('новичок ответил «Нет»: «логин не работает»', $f['DESCRIPTION']);
        self::assertSame([], $this->tasks->callsOf('complete'));
        $dispute = TestApp::row(\App\Sql::one($this->c->db(), "SELECT bitrix_task_id, resolved_at FROM disputes WHERE agreement_id = $a"));
        self::assertSame(array_key_last($this->tasks->created), (int) $dispute['bitrix_task_id']);
        self::assertNull($dispute['resolved_at']);
    }

    /** An answer to an already confirmed agreement is ignored (stale or double tap). */
    public function test_ignored_when_confirmed(): void
    {
        [$a] = $this->agreement('met', 'confirmed');
        self::assertSame(['status' => 'ignored'], $this->answers->record($a, Answer::No));
        self::assertSame([], $this->tasks->calls);
        self::assertSame(0, (int) \App\Sql::value($this->c->db(), 'SELECT COUNT(*) FROM answers'));
    }

    /** If Bitrix24 is down, the answer is kept and the effects are applied later by retryPending(). */
    public function test_bitrix_down_keeps_state_and_retry_applies(): void
    {
        [$a, $task] = $this->agreement('met', 'asked');
        $this->tasks->failOnCall(1);
        self::assertSame('done', $this->answers->record($a, Answer::Yes)['status']);
        self::assertSame('confirmed', $this->state($a));
        self::assertSame([], $this->tasks->callsOf('complete'));

        self::assertSame(1, $this->answers->retryPending());
        self::assertSame([[$task]], $this->tasks->callsOf('complete'));
        self::assertSame(0, $this->answers->retryPending());
    }

    /** Review Focus 4: long notes with markup are cut to 500 chars and passed through without errors. */
    public function test_note_is_trimmed_and_safe(): void
    {
        [$a] = $this->agreement('workwear', 'asked');
        $r = $this->answers->record($a, Answer::Partial);
        $note = '<b>&[url]' . str_repeat('я', 2000);
        $this->answers->finalize(TestApp::noteAnswerId($r), $note);

        $stored = (string) \App\Sql::value($this->c->db(), 'SELECT note FROM answers WHERE id = ' . TestApp::noteAnswerId($r));
        self::assertSame(500, mb_strlen($stored));
        self::assertStringStartsWith('<b>&[url]', $stored);
        // BBCode is neutralized so a note cannot mention people or format the comment
        self::assertStringContainsString('<b>&［url］', $this->tasks->callsOf('comment')[0][1]);
    }

    /** The first initial answer is remembered for the «✓ со 2-го раза» label. */
    public function test_first_answer_is_recorded_once(): void
    {
        [$a] = $this->agreement('workwear', 'asked');
        $r = $this->answers->record($a, Answer::Partial);
        $this->answers->finalize(TestApp::noteAnswerId($r), null);
        $this->c->db()->exec("UPDATE agreements SET state = 'owner_closed' WHERE id = $a");
        $this->answers->record($a, Answer::Yes);
        self::assertSame('partial', \App\Sql::value($this->c->db(), "SELECT first_answer FROM agreements WHERE id = $a"));
        self::assertSame('confirmed', $this->state($a));
    }
}
