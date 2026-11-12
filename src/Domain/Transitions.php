<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Pure transition rules of an agreement.
 *
 * Initial answers (task still open) either confirm and close the task, or leave it open
 * with a comment. Re-check answers (owner already closed the task) either confirm,
 * or create a dispute for HR — the owner's task is never reopened automatically,
 * so the newcomer does not end up contradicting their boss directly.
 */
final class Transitions
{
    /** States in which the newcomer has been (or is being) asked and the task is open. */
    private const OPEN_ASKED = [State::Asked, State::NotReceived, State::NoAnswer];

    /**
     * Applies an answer to the current state.
     *
     * @return array{0: State, 1: list<Effect>} New state and effects; an unchanged state with
     *                                            no effects means the answer is ignored
     *                                            (e.g. a stale or double button tap).
     */
    public static function answer(State $current, Answer $answer): array
    {
        if ($current === State::OwnerClosed) {
            return $answer === Answer::Yes
                ? [State::Confirmed, [Effect::CommentConfirmed]]
                : [State::Disputed, [Effect::CreateHrTask]];
        }
        if (!in_array($current, self::OPEN_ASKED, true)) {
            return [$current, []];
        }

        return match ($answer) {
            Answer::Yes => [State::Confirmed, [Effect::CompleteTask, Effect::CommentConfirmed]],
            Answer::Partial => [State::NotReceived, [Effect::CommentPartial]],
            Answer::No => [State::NotReceived, [Effect::CommentNotReceived]],
        };
    }

    /**
     * Reaction to the owner completing the task in Bitrix24.
     *
     * @return State|null `OwnerClosed` for open states; null when the closure is irrelevant
     *                    (already confirmed, disputed, or already closed).
     */
    public static function ownerClosed(State $current): ?State
    {
        return in_array($current, [State::Scheduled, ...self::OPEN_ASKED], true) ? State::OwnerClosed : null;
    }

    /**
     * Whether an answer in this state is a re-check («Отмечено, что …. Всё так?»).
     */
    public static function isRecheck(State $current): bool
    {
        return $current === State::OwnerClosed;
    }
}
