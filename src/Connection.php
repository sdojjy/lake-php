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

        try {
            return array_map(static fn (Row $row): array => $row->toArray(), $rows->fetchAll());
        } finally {
            $rows->close();
        }
    }

    /**
     * Logs the session out (best-effort).
     */
    public function close(): void
    {
        $this->client->logout();
    }

    public function ping(): void
    {
        $this->client->ping();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        return $this->client->verify();
    }

    public function version(): string
    {
        $row = $this->queryRow('SELECT version() AS version');

        return (string) ($row?->get('version') ?? '');
    }

    /**
     * @return array{handler: string, host: string, port: int, user: string, database: string, warehouse: string}
     */
    public function info(): array
    {
        return $this->client->info();
    }

    public function begin(): void
    {
        $this->execute('BEGIN');
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    public function rollback(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * Executes a callback inside a transaction.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollback();
            } catch (\Throwable) {
                // Preserve the original failure.
            }
            throw $e;
        }
    }

    /**
     * Uploads a CSV-like local file to a temporary user stage and runs the
     * given INSERT/REPLACE statement with a stage attachment.
     *
     * @param array<string, string>|null $fileFormatOptions
     * @param array<string, string>|null $copyOptions
     */
    public function loadFile(
        string $sql,
        string $file,
        ?array $fileFormatOptions = null,
        ?array $copyOptions = null,
    ): int {
        $stagePath = sprintf('load/%d-%s-%s', time(), bin2hex(random_bytes(4)), basename($file));
        $this->client->uploadToStage('~', $stagePath, $file);
        $response = $this->client->insertWithStage($sql, '~', $stagePath, $fileFormatOptions, $copyOptions);

        return self::affectedRows($response);
    }

    /**
     * @param list<list<mixed>> $data
     * @param array<string, string>|null $fileFormatOptions
     * @param array<string, string>|null $copyOptions
     */
    public function streamLoad(
        string $sql,
        array $data,
        ?array $fileFormatOptions = null,
        ?array $copyOptions = null,
    ): int {
        $file = $this->writeTempCsv($data);
        try {
            return $this->loadFile($sql, $file, $fileFormatOptions, $copyOptions);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param list<list<mixed>> $rows
     */
    public function batchInsert(string $sql, array $rows): int
    {
        if (preg_match('/^\s*(?:INSERT|REPLACE)\s+INTO\b/i', $sql) !== 1) {
            throw new \TiDBCloud\Lake\Exception\LakeException('batchInsert only supports INSERT/REPLACE');
        }

        return $this->streamLoad($sql, $rows);
    }

    /**
     * INSERT/UPDATE/DELETE responses report the count through a
     * "number of rows ..." column, mirroring lake-go's parseAffectedRows.
     * Stage-attachment INSERTs return no result schema at all; there the
     * count is only available via stats.write_progress.
     */
    private static function affectedRows(QueryResponse $response): int
    {
        if ($response->schema !== [] && str_contains($response->schema[0]['name'], 'number of rows')) {
            $value = $response->typedRows[0][0] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && is_numeric($value)) {
                return (int) $value;
            }

            return 0;
        }

        $written = $response->stats['write_progress']['rows'] ?? null;

        return is_numeric($written) ? (int) $written : 0;
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function writeTempCsv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'lake-php-');
        if ($file === false) {
            throw new \TiDBCloud\Lake\Exception\LakeException('failed to create temporary CSV file');
        }
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new \TiDBCloud\Lake\Exception\LakeException('failed to open temporary CSV file');
        }
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \TiDBCloud\Lake\Exception\LakeException('streamLoad rows must be lists');
                }
                fputcsv($fh, array_map(self::csvValue(...), $row), ',', '"', '');
            }
        } finally {
            fclose($fh);
        }

        return $file;
    }

    private static function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }
        if (is_array($value)) {
            return trim(SqlEncoder::encodeValue($value), "'");
        }

        return (string) $value;
    }
}
