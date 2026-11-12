<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Lifecycle of one agreement with a newcomer (spec §4).
 */
enum State: string
{
    /** Due date is in the future; nothing asked yet. */
    case Scheduled = 'scheduled';
    /** Question queued or sent; no answer yet. */
    case Asked = 'asked';
    /** Newcomer confirmed receipt («Да»), first time or on re-check. */
    case Confirmed = 'confirmed';
    /** Newcomer answered «Частично»/«Нет» and the owner's task is still open. */
    case NotReceived = 'not_received';
    /** Owner closed the task; waiting for the newcomer to confirm. */
    case OwnerClosed = 'owner_closed';
    /** Owner closed the task but the newcomer says it was not received; HR has a task. */
    case Disputed = 'disputed';
    /** No answer within 48 hours of asking. */
    case NoAnswer = 'no_answer';
}
