<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Connection;
use TiDBCloud\Lake\Exception\QueryException;

/**
 * Live integration tests. Gated by the LAKE_DSN environment variable and
 * skipped without it; run them via `make integration` (local Docker stack)
 * or by pointing LAKE_DSN at any real Lake warehouse.
 *
 * @group integration
 */
final class LakeIntegrationTest extends TestCase
{
    private function connect(): Connection
    {
        $dsn = getenv('LAKE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('LAKE_DSN is not set');
        }

        return Client::fromDsn($dsn)->connect();
    }

    public function testVerifyAndVersion(): void
    {
        $conn = $this->connect();
        self::assertNotSame('', (string) ($conn->verify()['user'] ?? ''));
        self::assertNotSame('', $conn->version());
    }

    public function testQueryParametersAndTypes(): void
    {
        $conn = $this->connect();
        $row = $conn->queryRow('SELECT :a AS a, :b AS b, to_date(:d) AS d', [
            'a' => 7,
            'b' => 'lake',
            'd' => '2024-05-01',
        ]);

        self::assertNotNull($row);
        self::assertSame(7, $row['a']);
        self::assertSame('lake', $row['b']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['d']);
    }

    public function testTypeMapping(): void
    {
        $conn = $this->connect();
        $row = $conn->queryRow(<<<'SQL'
            SELECT true AS c_bool, 9223372036854775807::Int64 AS c_i64,
                   18446744073709551615::UInt64 AS c_u64,
                   2.25::Float64 AS c_f64, 12345.6789::Decimal(18, 4) AS c_dec,
                   to_binary('hello') AS c_bin, NULL AS c_null
            SQL);

        self::assertTrue($row['c_bool']);
        self::assertSame(PHP_INT_MAX, $row['c_i64']);
        self::assertSame('18446744073709551615', $row['c_u64']);
        self::assertSame(2.25, $row['c_f64']);
        self::assertSame('12345.6789', $row['c_dec']);
        self::assertSame('hello', $row['c_bin']);
        self::assertNull($row['c_null']);
    }

    public function testVariantPathAccessWithPositionalParams(): void
    {
        $conn = $this->connect();
        $row = $conn->queryRow("SELECT parse_json('{\"k\":\"v1\"}'):k AS k, ? AS n", [5]);

        self::assertSame(5, $row['n']);
        self::assertStringContainsString('v1', (string) $row['k']);
    }

    public function testDmlAndStageStreamLoad(): void
    {
        $conn = $this->connect();
        $table = 'lake_php_it_' . bin2hex(random_bytes(4));

        try {
            $conn->execute("CREATE TABLE {$table} (id INT, name STRING)");
            $loaded = $conn->streamLoad("INSERT INTO {$table} VALUES", [
                [1, 'alice'],
                [2, 'bob'],
            ]);
            self::assertSame(2, $loaded);

            $rows = $conn->queryAll("SELECT id, name FROM {$table} ORDER BY id");
            self::assertSame([
                ['id' => 1, 'name' => 'alice'],
                ['id' => 2, 'name' => 'bob'],
            ], $rows);
        } finally {
            $conn->execute("DROP TABLE IF EXISTS {$table}");
            $conn->close();
        }
    }

    public function testAffectedRows(): void
    {
        $conn = $this->connect();
        $table = 'lake_php_it_' . bin2hex(random_bytes(4));

        try {
            $conn->execute("CREATE TABLE {$table} (id INT)");
            self::assertSame(3, $conn->execute("INSERT INTO {$table} VALUES (?), (?), (?)", [1, 2, 3]));
            self::assertSame(2, $conn->execute("UPDATE {$table} SET id = id + 10 WHERE id >= ?", [2]));
            self::assertSame(1, $conn->execute("DELETE FROM {$table} WHERE id = ?", [1]));
        } finally {
            $conn->execute("DROP TABLE IF EXISTS {$table}");
            $conn->close();
        }
    }

    public function testPaginationAndEarlyClose(): void
    {
        $conn = $this->connect();

        $count = 0;
        $sum = 0;
        foreach ($conn->query('SELECT number FROM numbers(100000)') as $row) {
            $count++;
            $sum += $row[0];
        }
        self::assertSame(100000, $count);
        self::assertSame(4999950000, $sum);

        $rows = $conn->query('SELECT number FROM numbers(1000000)');
        foreach ($rows as $row) {
            break;
        }
        $rows->close();
        self::assertSame(1, $conn->queryRow('SELECT 1 AS ok')['ok']);
    }

    public function testQueryErrorSurfacesCode(): void
    {
        $conn = $this->connect();

        try {
            $conn->queryRow('SELECT * FROM definitely_missing_table_xyz');
            self::fail('expected QueryException');
        } catch (QueryException $e) {
            self::assertGreaterThan(0, $e->errorCode);
        }
    }
}
