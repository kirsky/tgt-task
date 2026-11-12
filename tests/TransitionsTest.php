<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Answer;
use App\Domain\Effect;
use App\Domain\State;
use App\Domain\Transitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * State machine of one agreement (spec §4): what an answer or an owner closing
 * the task does to the state, and which Bitrix24 effects follow.
 */
final class TransitionsTest extends TestCase
{
    /**
     * Every (state, answer) pair from the plan's table.
     *
     * @return iterable<string,array{State,Answer,State,list<Effect>}>
     */
    public static function rows(): iterable
    {
        foreach ([State::Asked, State::NotReceived, State::NoAnswer] as $s) {
            yield "{$s->value}+yes" => [$s, Answer::Yes, State::Confirmed, [Effect::CompleteTask, Effect::CommentConfirmed]];
            yield "{$s->value}+partial" => [$s, Answer::Partial, State::NotReceived, [Effect::CommentPartial]];
            yield "{$s->value}+no" => [$s, Answer::No, State::NotReceived, [Effect::CommentNotReceived]];
        }
        yield 'owner_closed+yes' => [State::OwnerClosed, Answer::Yes, State::Confirmed, [Effect::CommentConfirmed]];
        yield 'owner_closed+partial' => [State::OwnerClosed, Answer::Partial, State::Disputed, [Effect::CreateHrTask]];
        yield 'owner_closed+no' => [State::OwnerClosed, Answer::No, State::Disputed, [Effect::CreateHrTask]];
        foreach ([State::Scheduled, State::Confirmed, State::Disputed] as $s) {
            foreach (Answer::cases() as $a) {
                yield "{$s->value}+{$a->value} ignored" => [$s, $a, $s, []];
            }
        }
    }

    /** Applies one answer and checks the resulting state and effects. */
    #[DataProvider('rows')]
    public function test_transitions(State $from, Answer $answer, State $to, array $effects): void
    {
        self::assertSame([$to, $effects], Transitions::answer($from, $answer));
    }

    /** Only open states react to the owner closing the task. */
    public function test_owner_closed(): void
    {
        foreach ([State::Scheduled, State::Asked, State::NotReceived, State::NoAnswer] as $s) {
            self::assertSame(State::OwnerClosed, Transitions::ownerClosed($s), $s->value);
        }
        foreach ([State::Confirmed, State::Disputed, State::OwnerClosed] as $s) {
            self::assertNull(Transitions::ownerClosed($s), $s->value);
        }
    }

    /** An answer counts as a re-check only after the owner closed the task. */
    public function test_is_recheck(): void
    {
        self::assertTrue(Transitions::isRecheck(State::OwnerClosed));
        self::assertFalse(Transitions::isRecheck(State::Asked));
    }
}
