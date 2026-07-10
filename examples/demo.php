<?php

declare(strict_types=1);

/**
 * End-to-end demo of the lake-php driver against a real Lake endpoint.
 *
 * Usage:
 *   LAKE_DSN='lake://user:pass@host:443/default?warehouse=wh' php examples/demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Row;

$dsn = getenv('LAKE_DSN') ?: ($argv[1] ?? '');
if ($dsn === '') {
    fwrite(STDERR, "usage: LAKE_DSN='lake://user:pass@host/db?warehouse=wh' php examples/demo.php\n");
    exit(1);
}

function show(string $label, mixed $value): void
{
    if ($value instanceof DateTimeInterface) {
        $value = $value->format('Y-m-d H:i:s.u P') . ' (DateTimeImmutable)';
    } elseif (is_array($value)) {
        $value = json_encode($value);
    } else {
        $value = var_export($value, true) . ' (' . get_debug_type($value) . ')';
    }
    printf("  %-28s %s\n", $label . ':', $value);
}

$step = function (string $name, callable $fn): void {
    printf("==> %s\n", $name);
    $start = microtime(true);
    $fn();
    printf("    ok (%.0f ms)\n\n", (microtime(true) - $start) * 1000);
};

$client = Client::fromDsn($dsn);
$conn = $client->connect();
printf("connected, session id: %s\n\n", $client->sessionId());

$step('queryRow: server version', function () use ($conn) {
    $row = $conn->queryRow('SELECT version() AS v');
    show('version', $row?->get('v'));
});

$step('type mapping', function () use ($conn) {
    $row = $conn->queryRow(<<<'SQL'
        SELECT
            true                                    AS c_bool,
            127::Int8                               AS c_int8,
            9223372036854775807::Int64              AS c_int64,
            18446744073709551615::UInt64            AS c_uint64_overflow,
            1.5::Float64                            AS c_float,
            12345.6789::Decimal(18, 4)              AS c_decimal,
            'hello lake'                            AS c_string,
            to_binary('hello')                      AS c_binary,
            to_date('2024-05-01')                   AS c_date,
            now()                                   AS c_timestamp,
            NULL                                    AS c_null,
            [1, 2, 3]                               AS c_array,
            parse_json('{"k":"v"}')                 AS c_variant
        SQL);
    foreach ($row->toArray() as $name => $value) {
        show($name, $value);
    }
});

$step('parameter binding (with injection-y input)', function () use ($conn) {
    $row = $conn->queryRow(
        'SELECT ? AS a, ? AS b, ? AS c, ? AS d',
        [42, "it's a '; DROP TABLE x; -- test", 3.14, null],
    );
    foreach ($row->toArray() as $name => $value) {
        show($name, $value);
    }
});

$table = 'lake_php_demo_' . bin2hex(random_bytes(4));

$step("DDL + DML on temp table {$table}", function () use ($conn, $table) {
    $conn->execute("CREATE TABLE {$table} (id INT, name STRING, score DOUBLE)");
    $inserted = $conn->execute(
        "INSERT INTO {$table} VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)",
        [1, 'alice', 90.5, 2, 'bob', 82.0, 3, "o'neil", 75.25],
    );
    show('affected rows (INSERT)', $inserted);

    $rows = $conn->query("SELECT id, name, score FROM {$table} WHERE score >= ? ORDER BY id", [80]);
    foreach ($rows as $i => $row) {
        show("row[$i]", $row->toArray());
    }
});

$step('pagination: 100k rows via next_uri', function () use ($conn) {
    $rows = $conn->query('SELECT number FROM numbers(100000)');
    $count = 0;
    $sum = 0;
    foreach ($rows as $row) {
        $count++;
        $sum += $row[0];
    }
    show('rows fetched', $count);
    show('sum', $sum);
    assert($count === 100000);
});

$step('cleanup', function () use ($conn, $table) {
    $conn->execute("DROP TABLE IF EXISTS {$table}");
});

$conn->close();
echo "demo finished successfully\n";
