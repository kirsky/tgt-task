<?php

declare(strict_types=1);

namespace App;

/**
 * Typed read helpers over PDO prepared statements.
 *
 * PDO is opened with ERRMODE_EXCEPTION, so failures throw; these helpers give callers
 * precise return types instead of `PDOStatement|false` and `mixed`.
 */
final class Sql
{
    /**
     * First row of a query, or null when there is none.
     *
     * @param list<scalar|null> $params Positional parameters for `?` placeholders.
     * @return array<string,mixed>|null
     */
    public static function one(\PDO $db, string $sql, array $params = []): ?array
    {
        $row = self::run($db, $sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * All rows of a query.
     *
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public static function all(\PDO $db, string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($db, $sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * First column of the first row, or null when there is no row.
     *
     * @param list<scalar|null> $params
     */
    public static function value(\PDO $db, string $sql, array $params = []): mixed
    {
        $v = self::run($db, $sql, $params)->fetchColumn();

        return $v === false ? null : $v;
    }

    /**
     * First column of every row.
     *
     * @param list<scalar|null> $params
     * @return list<mixed>
     */
    public static function column(\PDO $db, string $sql, array $params = []): array
    {
        /** @var list<mixed> $values */
        $values = self::run($db, $sql, $params)->fetchAll(\PDO::FETCH_COLUMN);

        return $values;
    }

    /**
     * Prepares and executes a statement.
     *
     * @param list<scalar|null> $params
     */
    private static function run(\PDO $db, string $sql, array $params): \PDOStatement
    {
        $st = $db->prepare($sql);
        if ($st === false) {
            throw new \RuntimeException('Could not prepare SQL statement');
        }
        $st->execute($params);

        return $st;
    }
}
