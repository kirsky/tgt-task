<?php

declare(strict_types=1);

namespace App\Bitrix;

/**
 * {@see Tasks} over the Bitrix24 REST API.
 *
 * Methods were checked on the demo portal before the build (see README):
 * `tasks.task.add|complete|list`, and `task.commentitem.add` for comments —
 * on the new task card it posts into the task chat, while
 * `tasks.task.chat.message.send` is not available to a webhook with these scopes.
 */
final class BitrixTasks implements Tasks
{
    /** Title prefix that marks a hire task. */
    public const HIRE_PREFIX = 'Приём:';

    public function __construct(private BitrixClient $client)
    {
    }

    /** {@inheritDoc} */
    public function create(array $fields): int
    {
        $res = $this->client->call('tasks.task.add', ['fields' => $fields]);

        return (int) ($res['task']['id'] ?? throw new BitrixError('tasks.task.add: no id in response'));
    }

    /** {@inheritDoc} */
    public function complete(int $id): void
    {
        $this->client->call('tasks.task.complete', ['taskId' => $id]);
    }

    /** {@inheritDoc} */
    public function comment(int $id, string $text): void
    {
        $this->client->call('task.commentitem.add', ['TASKID' => $id, 'FIELDS' => ['POST_MESSAGE' => $text]]);
    }

    /** {@inheritDoc} */
    public function statuses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values($ids), 50) as $chunk) {
            $res = $this->client->call('tasks.task.list', ['filter' => ['ID' => $chunk], 'select' => ['ID', 'STATUS']]);
            foreach ($res['tasks'] ?? [] as $t) {
                $out[(int) $t['id']] = (int) $t['status'];
            }
        }

        return $out;
    }

    /** {@inheritDoc} */
    public function newHireTasks(int $afterId): array
    {
        $res = $this->client->call('tasks.task.list', [
            'filter' => ['>ID' => $afterId, '%TITLE' => self::HIRE_PREFIX],
            'select' => ['ID', 'TITLE', 'DESCRIPTION'],
            'order' => ['ID' => 'asc'],
        ]);

        return array_map(fn (array $t) => [
            'id' => (int) $t['id'],
            'title' => (string) $t['title'],
            'description' => (string) ($t['description'] ?? ''),
        ], $res['tasks'] ?? []);
    }

    /** {@inheritDoc} */
    public function url(int $id): string
    {
        return $this->client->portalBase() . "/company/personal/user/1/tasks/task/view/$id/";
    }
}
