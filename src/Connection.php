<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

/**
 * High-level query API on top of {@see Client}.
 */
final class Connection
{
    public function __construct(private readonly Client $client)
    {
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Executes a statement and returns the number of affected rows
     * (0 when the server does not report it, e.g. for DDL).
     */
    public function execute(string $sql, array|object|null $params = null): int
    {
        $sql = SqlEncoder::interpolate($sql, $params);
        $response = $this->client->startQuery($sql);
        try {
            $response = $this->client->waitForResults($response);

            return self::affectedRows($response);
        } finally {
            $this->client->closeQuery($response);
        }
    }

    /**
     * Runs a query and returns a lazily-paginated result set.
     */
    public function query(string $sql, array|object|null $params = null): Rows
    {
        $sql = SqlEncoder::interpolate($sql, $params);
        $response = $this->client->startQuery($sql);

        return new Rows($this->client, $response);
    }

    /**
     * Runs a query and returns the first row, or null when empty.
     */
    public function queryRow(string $sql, array|object|null $params = null): ?Row
    {
        $rows = $this->query($sql, $params);
        try {
            foreach ($rows as $row) {
                return $row;
            }

            return null;
        } finally {
            $rows->close();
        }
    }

    /**
     * Runs a query and returns all rows as associative arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function queryAll(string $sql, array|object|null $params = null): array
    {
        $rows = $this->query($sql, $params);

        return array_map(static fn (Row $row): array => $row->toArray(), $rows->fetchAll());
    }

    /**
     * Logs the session out (best-effort).
     */
    public function close(): void
    {
        $this->client->logout();
    }

    /**
     * INSERT/UPDATE/DELETE responses report the count through a
     * "number of rows ..." column, mirroring lake-go's parseAffectedRows.
     */
    private static function affectedRows(QueryResponse $response): int
    {
        if ($response->schema === [] || !str_contains($response->schema[0]['name'], 'number of rows')) {
            return 0;
        }
        $value = $response->typedRows[0][0] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
