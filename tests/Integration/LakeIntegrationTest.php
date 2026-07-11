<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;

/**
 * @group integration
 */
final class LakeIntegrationTest extends TestCase
{
    private function connect(): \TiDBCloud\Lake\Connection
    {
        $dsn = getenv('LAKE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('LAKE_DSN is not set');
        }

        return Client::fromDsn($dsn)->connect();
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

    public function testDmlAndStageStreamLoad(): void
    {
        $conn = $this->connect();
        $table = 'lake_php_it_' . bin2hex(random_bytes(4));

        try {
            $conn->execute("CREATE TABLE {$table} (id INT, name STRING)");
            $conn->streamLoad("INSERT INTO {$table} VALUES", [
                [1, 'alice'],
                [2, 'bob'],
            ]);

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
}
