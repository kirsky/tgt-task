<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Bitrix24 side effects that follow an answer. Applied after the optional note is collected.
 */
enum Effect: string
{
    /** Complete the owner's task (the newcomer confirmed receipt). */
    case CompleteTask = 'complete_task';
    /** Comment «Новичок подтвердил получение.» on the owner's task. */
    case CommentConfirmed = 'comment_confirmed';
    /** Comment «Получение подтверждено частично…» with the note. */
    case CommentPartial = 'comment_partial';
    /** Comment «Новичок пока не получил…» with the note. */
    case CommentNotReceived = 'comment_not_received';
    /** Create «Закрыто, но не получено: …» task for HR (the owner's task stays closed). */
    case CreateHrTask = 'create_hr_task';
}
