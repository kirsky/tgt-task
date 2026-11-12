<?php

declare(strict_types=1);

namespace App;

final class Kv
{
    public function __construct(private \PDO $db)
    {
    }

    public function get(string $key): ?string
    {
        $st = $this->db->prepare('SELECT value FROM kv WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    public function delete(string $key): void
    {
        $this->db->prepare('DELETE FROM kv WHERE key = ?')->execute([$key]);
    }
}
