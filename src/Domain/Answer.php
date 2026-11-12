<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The newcomer's three possible answers to «received?».
 */
enum Answer: string
{
    /** «Да» — received in full. */
    case Yes = 'yes';
    /** «Частично» — something is missing. */
    case Partial = 'partial';
    /** «Нет» — not received. */
    case No = 'no';

    /**
     * Russian button label shown in Telegram and in the panel.
     */
    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Да',
            self::Partial => 'Частично',
            self::No => 'Нет',
        };
    }
}
