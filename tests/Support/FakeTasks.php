<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bitrix\BitrixUnavailable;
use App\Bitrix\Tasks;

/**
 * In-memory Bitrix24 tasks for tests: records every call, hands out ids from 1000,
 * lets a test set statuses and simulate an outage on the n-th call.
 */
final class FakeTasks implements Tasks
{
    /** @var list<array{0:string,1:array}> Every call as [method, args]. */
    public array $calls = [];
    /** @var array<int,array<string,mixed>> Created tasks by id. */
    public array $created = [];
    /** @var array<int,int> Task id → status code returned by statuses(). */
    public array $status = [];
    /** @var list<array{id:int,title:string,description:string}> Returned by newHireTasks(). */
    public array $hireTasks = [];

    private int $nextId = 1000;
    private int $callNo = 0;
    private ?int $failOn = null;

    /**
     * Makes the n-th call from now (1-based, counting all methods) throw {@see BitrixUnavailable}.
     */
    public function failOnCall(int $n): void
    {
        $this->failOn = $this->callNo + $n;
    }

    /** Records a call and throws if this is the configured failing call. */
    private function hit(string $method, array $args): void
    {
        $this->callNo++;
        if ($this->failOn === $this->callNo) {
            $this->failOn = null;
            throw new BitrixUnavailable("fake outage on $method");
        }
        $this->calls[] = [$method, $args];
    }

    /**
     * Calls of one method only.
     *
     * @return list<array>
     */
    public function callsOf(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    public function create(array $fields): int
    {
        $this->hit('create', [$fields]);
        $id = $this->nextId++;
        $this->created[$id] = $fields;
        $this->status[$id] = 2;

        return $id;
    }

    public function complete(int $id): void
    {
        $this->hit('complete', [$id]);
        $this->status[$id] = self::STATUS_COMPLETED;
    }

    public function comment(int $id, string $text): void
    {
        $this->hit('comment', [$id, $text]);
    }

    public function statuses(array $ids): array
    {
        $this->hit('statuses', [$ids]);

        return array_intersect_key($this->status, array_flip($ids));
    }

    public function newHireTasks(int $afterId): array
    {
        $this->hit('newHireTasks', [$afterId]);

        return array_values(array_filter($this->hireTasks, fn ($t) => $t['id'] > $afterId));
    }

    public function url(int $id): string
    {
        return "https://portal.test/task/$id/";
    }
}
