<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Human status pills for agreements in the panel.
 */
final class Labels
{
    /**
     * Label and tone of one agreement.
     *
     * @param array<string,mixed> $row      Agreement row with `state`, `first_answer` and
     *                                      `dispute_open` (1 open, 0 resolved, null none).
     * @param \DateTimeImmutable  $now      Current moment.
     * @param \DateTimeImmutable  $askAt    When the question is (or was) asked.
     * @param \DateTimeImmutable  $deadline Owner's task deadline.
     * @return array{text: string, tone: 'ok'|'wait'|'warn'|'bad'}
     */
    public static function agreement(array $row, \DateTimeImmutable $now, \DateTimeImmutable $askAt, \DateTimeImmutable $deadline): array
    {
        $late = $now > $deadline;

        return match ($row['state']) {
            'scheduled' => ['text' => 'спросим ' . $askAt->format('d.m'), 'tone' => 'wait'],
            'asked' => $late ? ['text' => 'просрочено', 'tone' => 'bad'] : ['text' => 'ждём ответа', 'tone' => 'wait'],
            'confirmed' => in_array($row['first_answer'], [null, 'yes'], true)
                ? ['text' => '✓ подтвердил', 'tone' => 'ok']
                : ['text' => '✓ со 2-го раза', 'tone' => 'ok'],
            'not_received' => $late ? ['text' => 'просрочено', 'tone' => 'bad'] : ['text' => 'не получено', 'tone' => 'warn'],
            'owner_closed' => ['text' => 'закрыто, ждём подтверждения', 'tone' => 'wait'],
            'disputed' => (int) $row['dispute_open'] === 0 && $row['dispute_open'] !== null
                ? ['text' => 'решено через HR', 'tone' => 'ok']
                : ['text' => 'закрыто, но не получено', 'tone' => 'bad'],
            'no_answer' => ['text' => 'нет ответа', 'tone' => 'warn'],
            default => ['text' => (string) $row['state'], 'tone' => 'wait'],
        };
    }

    /** Russian name of a Bitrix24 task status code. */
    public static function bitrixStatus(?int $code): string
    {
        return match ($code) {
            1 => 'новая', 2 => 'ждёт выполнения', 3 => 'выполняется', 4 => 'ждёт контроля',
            5 => 'завершена', 6 => 'отложена', null => 'нет в Битрикс24', default => "статус $code",
        };
    }
}
