<?php

declare(strict_types=1);

namespace App\Bitrix;

/**
 * Everything the app needs from Bitrix24 tasks. The only seam to the portal:
 * production uses {@see BitrixTasks}, tests use a recording fake.
 */
interface Tasks
{
    /** Bitrix24 status code of a completed task. */
    public const STATUS_COMPLETED = 5;

    /**
     * Creates a task.
     *
     * @param array<string,mixed> $fields Bitrix24 task fields (TITLE, RESPONSIBLE_ID, AUDITORS, DEADLINE, DESCRIPTION, TAGS…).
     * @return int New task id.
     */
    public function create(array $fields): int;

    /**
     * Marks a task completed.
     */
    public function complete(int $id): void;

    /**
     * Adds a comment (shown in the task chat on the new task card).
     */
    public function comment(int $id, string $text): void;

    /**
     * Current status of several tasks in one request. Unknown/deleted tasks are absent.
     *
     * @param list<int> $ids
     * @return array<int,int> Task id → status code.
     */
    public function statuses(array $ids): array;

    /**
     * Hire tasks («Приём: …») with id greater than the given one, oldest first.
     *
     * @return list<array{id:int, title:string, description:string}>
     */
    public function newHireTasks(int $afterId): array;

    /**
     * Link to open the task in the portal.
     */
    public function url(int $id): string;
}
