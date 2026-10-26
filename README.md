# lake-php

![CI](https://github.com/sdojjy/lake-php/actions/workflows/ci.yaml/badge.svg)

PHP driver for TiDB Cloud Lake, speaking the Lake HTTP API.
This is a pure-PHP port of the HTTP JSON transport implemented by
[`lake-go`](https://github.com/tidbcloud/lake-go) — no PHP extension, no Arrow.

Requires **PHP >= 8.1** with `ext-json`.

## Installation

```bash
composer require sdojjy/lake-php
```

While the package is not yet published on Packagist, add it as a VCS repository:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/sdojjy/lake-php"}
    ],
    "require": {
        "sdojjy/lake-php": "dev-main"
    }
}
```

## DSN

```
lake://<user>:<password>@<host>:<port>/<database>?<params>
```

Examples:

```
lake://user:password@lake.tidbcloud.com:443/default?warehouse=my-warehouse
lake://user:password@lake.tidbcloud.com/default?warehouse=wh&tenant=t1&role=readonly
http://user:password@localhost:8000/default              # plain HTTP (local dev)
lake+http://user:password@localhost/default               # plain HTTP, port 80
lake://lake.tidbcloud.com/default?access_token=<token>    # bearer-token auth
```

Any scheme ending in `http` (`http`, `lake+http`, …) disables TLS; the
default port is then 80 instead of 443.

Supported parameters:

| Parameter | Meaning |
|---|---|
| `tenant` | `X-DATABEND-TENANT` header |
| `warehouse` | `X-DATABEND-WAREHOUSE` header |
| `role` | Lake role for the session (secondary roles are disabled) |
| `access_token` | Bearer token; used when user/password are absent |
| `timeout` | HTTP timeout, Go duration (`30s`, `1m30s`) or plain seconds |
| `wait_time_secs` | Pagination: max wait per HTTP request |
| `max_rows_in_buffer` | Pagination: server-side row buffer |
| `max_rows_per_page` | Pagination: max rows per response page |
| `location` / `timezone` | IANA session timezone (aliases): the server reads and returns `Timestamp` values, and evaluates `now()`, in it |
| `sslmode` | `disable` for plain HTTP |
| `login` | `disable` to skip `POST /v1/session/login` |
| `empty_field_as` | Empty-CSV-field handling (stage upload, phase 2) |
| `binary_output_format` | `hex` (default) / `base64` / `utf-8`, session setting |
| `http_json_result_mode` | `driver` (default) / `display`, session setting |

Unknown parameters are forwarded to the server as session settings, matching
`lake-go` behavior.

## Usage

```php
use TiDBCloud\Lake\Client;

$client = Client::fromDsn('lake://user:password@lake.tidbcloud.com:443/default?warehouse=my-warehouse');
$conn = $client->connect(); // performs POST /v1/session/login unless login=disable

// DDL / DML: returns affected rows when the server reports them
$conn->execute('CREATE TABLE IF NOT EXISTS books (title STRING, price DOUBLE)');
$inserted = $conn->execute('INSERT INTO books VALUES (?, ?)', ['TiDB in Action', 39.9]);

// Streaming iteration; pages are fetched lazily via next_uri
$rows = $conn->query('SELECT title, price FROM books WHERE price > ?', [10]);
foreach ($rows as $row) {
    echo $row['title'], ' - ', $row['price'], PHP_EOL;   // by name
    echo $row[0], PHP_EOL;                               // by index
}

// Single row (or null)
$row = $conn->queryRow('SELECT COUNT(*) AS cnt FROM books');
$count = $row?->get('cnt');

// Everything as associative arrays
$all = $conn->queryAll('SELECT * FROM books');

$conn->close(); // best-effort POST /v1/session/logout
```

### Parameter binding

Supports positional `?` placeholders and named `:name` placeholders. Because
the Lake HTTP protocol has no server-side prepared statements, values are
interpolated client-side as escaped SQL literals (same as `lake-go`).
Supported types: `null`, `bool`, `int`, `float`, `string`,
`DateTimeInterface`, and list arrays. Pass decimals as strings to avoid float
precision loss.

Named mode is used only when the params array has string keys. With a plain
list, `:x` tokens stay literal, so VARIANT path access (`SELECT v:name FROM t
WHERE id = ?`) works with positional params.

```php
$conn->queryRow('SELECT :id AS id, :name AS name', [
    'id' => 1,
    'name' => 'alice',
]);
```

### Utilities

```php
$conn->ping();              // POST /v1/verify
$info = $conn->info();      // handler, host, port, user, database, warehouse
$version = $conn->version();

$conn->transaction(function (Connection $tx) {
    $tx->execute('INSERT INTO books VALUES (?, ?)', ['Dune', 19.9]);
});
```

### Stage Loading

`loadFile()`, `streamLoad()`, and `batchInsert()` upload CSV data to the user
stage and run the statement with `stage_attachment`.

```php
$conn->loadFile('INSERT INTO books VALUES', '/tmp/books.csv');

$conn->streamLoad('INSERT INTO books VALUES', [
    ['Dune', 19.9],
    ['Foundation', 14.5],
]);

$conn->batchInsert('INSERT INTO books VALUES', [
    ['Hyperion', 12.0],
]);
```

Set `presigned_url_disabled=true` in the DSN to force `/v1/upload_to_stage`
instead of presigned URL upload.

### Type mapping

| Lake type | PHP type |
|---|---|
| `NULL` cell | `null` |
| `Boolean` | `bool` |
| `Int8/16/32`, `UInt8/16/32` | `int` |
| `Int64` / `UInt64` | `int`, or `string` when outside PHP's int range |
| `Float32/64` | `float` |
| `Decimal(p, s)` | `string` |
| `Date`, `Timestamp`, `Timestamp_Tz` | `DateTimeImmutable` |
| `Binary` | binary `string` (decoded per `binary_output_format`) |
| `String`, `Variant`, `Array`, `Tuple`, `Map`, `Bitmap`, `Geometry`, … | `string` (raw server representation) |

### Custom HTTP client

The driver is written against **PSR-18 / PSR-7** and defaults to **Guzzle**.
Guzzle was chosen because it is the de-facto standard PHP HTTP client, it
natively implements PSR-18 and ships PSR-7/PSR-17 factories, which avoids an
extra `php-http/discovery` dependency. Any other PSR-18 client can be
injected:

```php
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;

$client = new Client(
    Config::fromDsn($dsn),
    $myPsr18Client,        // Psr\Http\Client\ClientInterface
    $myRequestFactory,     // Psr\Http\Message\RequestFactoryInterface
    $myStreamFactory,      // Psr\Http\Message\StreamFactoryInterface
);
```

## Laravel / Eloquent

lake-php ships a `lake` database driver for **Laravel 9, 10, 11 and 12**:
Eloquent models and the query builder read and write Lake without code
changes. It needs PHP >= 8.1, also on Laravel 9. The driver relies on
version-specific Laravel internals, so Composer refuses to install lake-php
next to other `illuminate/database` versions.

```bash
composer require sdojjy/lake-php
```

The service provider (`TiDBCloud\Lake\Laravel\LakeServiceProvider`) is
auto-discovered. Add a connection in `config/database.php`:

```php
'connections' => [
    'lake' => [
        'driver' => 'lake',
        'host' => env('LAKE_HOST', 'lake.tidbcloud.com'),
        'port' => env('LAKE_PORT', 443),
        'database' => env('LAKE_DATABASE', 'default'),
        'username' => env('LAKE_USERNAME'),
        'password' => env('LAKE_PASSWORD'),
        'warehouse' => env('LAKE_WAREHOUSE'),
        // Lake's session timezone: keep it equal to the app's (see "Dates" below).
        'timezone' => config('app.timezone'),
        'prefix' => '',
        // Optional: any DSN parameter (tenant, role, access_token, timeout,
        // sslmode, ...), extra session settings, and the sequences used for
        // generated keys (see below).
        // 'settings' => ['binary_output_format' => 'hex'],
        // 'sequences' => ['user_link_attribution_logs' => 'user_link_attribution_logs_id_seq'],
    ],
],
```

Instead of the individual keys you can pass a full DSN as `'dsn' =>
env('LAKE_DSN')`; the other keys, `timezone` included, then belong in the DSN.
Keep `'database'` set as well, because Laravel reads it. A `url` with a
`lake://` scheme also works.

To move an existing app over without touching its code, point the connection
its models already use at the `lake` driver (or set `DB_CONNECTION=lake`).
Queries then run on Lake unchanged:

```php
UserLinkAttributionLog::query()
    ->whereKey(1)
    ->where('created_at', '<', '2026-01-01')
    ->first();

DB::connection('lake')->table('books')->where('price', '>', 10)->paginate(20);
DB::connection('lake')->transaction(fn () => Book::create(['id' => 1, 'title' => 'Dune']));
```

`LakeConnection` runs Laravel's own query code through
`TiDBCloud\Lake\Pdo\LakePdo`, a PDO-compatible adapter (see below). The SQL
comes from Laravel's MySQL grammar, since Lake accepts backtick identifiers,
with these Lake-specific differences:

| Feature | Behavior on Lake |
|---|---|
| Generated keys | Lake has no `LAST_INSERT_ID()`. For `create()` / `save()` / `insertGetId()` on auto-incrementing models, a key you supply is used. Otherwise, if `sequences` maps the table to a Lake sequence (`CREATE SEQUENCE ...`), the driver takes `NEXTVAL()` and inserts with it. Otherwise it throws `UnsupportedFeatureException` before writing. Alternatively set `$incrementing = false` on the model. |
| `upsert()` | Compiled to `MERGE INTO`. Matched rows only get the given update columns (MySQL semantics). The return value is inserted + updated rows. |
| Transactions | `BEGIN` / `COMMIT` / `ROLLBACK`. Lake has no savepoints, so a nested `transaction()` joins the outer one. If a nested transaction rolls back, Lake cannot undo only its part, so the whole transaction is rolled back instead: later statements in it fail, and committing the outer transaction rolls back and throws a `PDOException`, dropping its `afterCommit` callbacks (on MySQL, only the nested part would be undone). Likewise, a statement that fails inside a transaction aborts it on Lake, so committing it afterwards throws instead of silently rolling back. |
| Eager loading with `limit()` (Laravel 11+) | Compiled with `row_number() over (partition by ...)`. |
| `whereTime()` | Compiled to `date_format(col, '%H:%M:%S')`. Lake has no `TIME()`. |
| Dates | Read back as `'Y-m-d'` / `'Y-m-d H:i:s[.u]'` strings like pdo_mysql, in the Lake session timezone (the connection's `timezone`, UTC by default). Laravel writes wall-clock values in `app.timezone`. They round-trip unchanged either way, but SQL evaluated on the server (`DB::raw('now()')`, `today()`, `date_trunc(...)`, retention jobs) is off by the app's UTC offset unless `timezone` matches `app.timezone`. |
| `cursor()` / `lazy()` | The result is buffered client-side, like pdo_mysql's default buffered mode. |
| Not supported | Schema builder and migrations (manage Lake tables with SQL), `insertOrIgnore()`, `lockForUpdate()` / `sharedLock()`, MySQL JSON operations (`col->key`, `whereJsonContains()`, ...). These throw `TiDBCloud\Lake\Exception\UnsupportedFeatureException` before anything is sent. |

Outside a Laravel app (`Illuminate\Database\Capsule\Manager`), register the
driver yourself:

```php
$capsule = new Illuminate\Database\Capsule\Manager();
TiDBCloud\Lake\Laravel\LakeServiceProvider::registerDriver($capsule->getContainer());
$capsule->addConnection(['driver' => 'lake', /* ... */], 'lake');
```

The low-level API stays available from Laravel:
`DB::connection('lake')->getPdo()->connection()` returns the
`TiDBCloud\Lake\Connection` (e.g. for `streamLoad()`).

### PDO adapter

`LakePdo` extends `\PDO` and works anywhere PDO is expected:

```php
use TiDBCloud\Lake\Pdo\LakePdo;

$pdo = LakePdo::fromDsn('lake://user:password@lake.tidbcloud.com:443/default?warehouse=wh');
$stmt = $pdo->prepare('SELECT title, price FROM books WHERE price > ?');
$stmt->execute([10]);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

It supports `prepare` / `bindValue` / `bindParam` / `execute`, `query`,
`exec`, the `FETCH_ASSOC` / `NUM` / `BOTH` / `OBJ` / `COLUMN` fetch modes,
`rowCount()`, transactions and `quote()`. Like the rest of the driver, values
are interpolated client-side as SQL literals. Errors are thrown as
`PDOException` with the original `LakeException` as `getPrevious()`.
`lastInsertId()` is not supported.

`inTransaction()` follows the transaction state the server reports, so
`BEGIN` / `COMMIT` / `ROLLBACK` sent with `exec()` count too. After a statement
fails inside a transaction (Lake then aborts the transaction), `commit()` rolls
back and throws instead of reporting success.

## Protocol notes

- `POST /v1/session/login` on connect (skipped with `login=disable`; a 404
  from older servers is tolerated). The `X-DATABEND-SESSION-ID` response
  header replaces the client-generated session id.
- `POST /v1/query` with `sql`, optional `pagination`, and the current
  `session` state; the response's `session` object is stored and echoed back
  on the next request (client-side session).
- Incomplete results are paged with `GET <next_uri>`; `final_uri` is called
  when the result is fully read or `Rows::close()` is invoked; `kill_uri`
  is available via `Client::killQuery()`.
- Requests that fail in transit are retried, except a statement that may
  already have run: a timeout or a dropped connection can come after the
  server received it. Such a `POST /v1/query` is sent again only if the
  request provably never left (Guzzle's cURL handler reports no bytes sent)
  or the statement only reads (`SELECT`, `SHOW`, `DESC`, `PRESIGN`).
  Otherwise `QueryOutcomeUnknownException` is thrown (in Laravel, a
  `QueryException` saying the statement "may or may not have run"): check
  its effect before running it again. Inside a transaction, the
  transaction can then only be rolled back.
- Headers sent on every request: `Authorization` (Basic or Bearer),
  `X-DATABEND-TENANT`, `X-DATABEND-WAREHOUSE`, `X-DATABEND-QUERY-ID`,
  `X-DATABEND-ROUTE: warehouse`, `X-DATABEND-ROUTE-HINT`, and
  `X-DATABEND-STICKY-NODE` / `X-DATABEND-NODE-ID` when the session requires
  node affinity.

## Not in this version (roadmap)

- Arrow result transport (JSON only)
- Server-side prepared statements (the HTTP API is client-interpolated)

## Development

```bash
composer install
composer test

# or, without a local PHP installation:
make test-docker

# Start a local Lake server stack (minio + meta + query) in Docker and run
# the integration test group against it, then tear it down:
make integration
make integration IT_LARAVEL='9.*'   # pin the Laravel version under test
```

Unit tests run against an in-memory PSR-18 mock — no server or network is
required. Integration tests (`tests/Integration/`, `@group integration`) are
gated by `LAKE_DSN` and skipped without it; `make integration` provisions a
self-contained server stack under `tests/docker/` and can also be pointed at
any real warehouse via `IT_DSN=...`. CI (`.github/workflows/ci.yaml`) runs
the unit matrix on PHP 8.1–8.4 across Laravel 9–12, plus the dockerized
integration job on Laravel 9 and 12, for every push and pull request against
`main`.
