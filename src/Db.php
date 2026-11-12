<?php

declare(strict_types=1);

namespace App;

/**
 * SQLite connection factory and schema.
 *
 * The local database is the source of truth: Bitrix24 is synced from it, so the panel
 * and the bot keep working (read-only) when the Bitrix24 trial ends.
 */
final class Db
{
    /**
     * Opens (and creates, if needed) the database and brings the schema up to date.
     *
     * @param string $path File path, or `:memory:` for tests.
     */
    public static function open(string $path): \PDO
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        self::migrate($pdo);

        return $pdo;
    }

    /**
     * Creates all tables if they do not exist. Safe to run repeatedly.
     *
     * Tables:
     * - `newcomers`: a person on probation, their invite code and Telegram chat;
     * - `agreements`: one row per agreement of the newcomer's role, its state and pending prompt;
     * - `answers`: every Да/Частично/Нет with the optional note and the Bitrix24 effects to apply;
     * - `disputes`: «закрыто, но не получено» cases handed to HR;
     * - `chat_state`: whether the bot is waiting for a free-text note in a chat;
     * - `kv`: small key/value flags (last seen hire task, Bitrix24 outage);
     * - `processed_hire_tasks`: hire tasks already handled, so none is processed twice.
     */
    public static function migrate(\PDO $db): void
    {
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS newcomers (
              id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, first_name TEXT NOT NULL, role_key TEXT NOT NULL,
              start_date TEXT NOT NULL, invite_code TEXT NOT NULL UNIQUE, tg_chat_id INTEGER NULL UNIQUE,
              hire_task_id INTEGER NULL UNIQUE, is_example INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS agreements (
              id INTEGER PRIMARY KEY, newcomer_id INTEGER NOT NULL REFERENCES newcomers(id) ON DELETE CASCADE,
              key TEXT NOT NULL, day INTEGER NOT NULL, due_date TEXT NOT NULL, bitrix_task_id INTEGER NULL,
              state TEXT NOT NULL, first_answer TEXT NULL, prompt_kind TEXT NULL, prompt_intro INTEGER NOT NULL DEFAULT 0,
              prompt_queued_at TEXT NULL, prompt_sent_at TEXT NULL, reminded_at TEXT NULL, owner_closed_at TEXT NULL,
              updated_at TEXT NOT NULL, UNIQUE(newcomer_id, key)
            );
            CREATE TABLE IF NOT EXISTS answers (
              id INTEGER PRIMARY KEY, agreement_id INTEGER NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
              kind TEXT NOT NULL, answer TEXT NOT NULL, note TEXT NULL, effects TEXT NOT NULL DEFAULT '[]',
              created_at TEXT NOT NULL, finalized_at TEXT NULL, effects_applied_at TEXT NULL
            );
            CREATE TABLE IF NOT EXISTS disputes (
              id INTEGER PRIMARY KEY, agreement_id INTEGER NOT NULL UNIQUE REFERENCES agreements(id) ON DELETE CASCADE,
              bitrix_task_id INTEGER NULL, created_at TEXT NOT NULL, resolved_at TEXT NULL
            );
            CREATE TABLE IF NOT EXISTS chat_state (chat_id INTEGER PRIMARY KEY, awaiting_note_answer_id INTEGER NULL);
            CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS processed_hire_tasks (
              task_id INTEGER PRIMARY KEY, status TEXT NOT NULL, newcomer_id INTEGER NULL, processed_at TEXT NOT NULL
            );
            SQL);
    }
}
