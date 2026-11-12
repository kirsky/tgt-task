<?php

declare(strict_types=1);

namespace App;

/**
 * Tiny persistent key/value store on top of the `kv` table.
 *
 * Used for process-wide flags such as `last_hire_task_id` and `bitrix_down_since`.
 */
final class Kv
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * Returns the stored value, or null when the key is not set.
     */
    public function get(string $key): ?string
    {
        $st = $this->db->prepare('SELECT value FROM kv WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    /**
     * Inserts or overwrites a value.
     */
    public function set(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    /**
     * Removes a key; does nothing if it is not set.
     */
    public function delete(string $key): void
    {
        $this->db->prepare('DELETE FROM kv WHERE key = ?')->execute([$key]);
    }
}
