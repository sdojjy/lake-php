# lake-php

![CI](https://github.com/tidbcloud/lake-php/actions/workflows/ci.yaml/badge.svg)

PHP driver for TiDB Cloud Lake, speaking the Lake HTTP API.
This is a pure-PHP port of the HTTP JSON transport implemented by
[`lake-go`](https://github.com/tidbcloud/lake-go) — no PHP extension, no Arrow.

Requires **PHP >= 8.1** with `ext-json`.

## Installation

```bash
composer require tidbcloud/lake-php
```

While the package is not yet published on Packagist, add it as a VCS repository:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/tidbcloud/lake-php"}
    ],
    "require": {
        "tidbcloud/lake-php": "dev-main"
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
| `location` / `timezone` | IANA timezone for `Timestamp` values (aliases) |
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

### Using with Laravel (no Laravel dependency required)

Register the connection as a singleton in a service provider:

```php
// app/Providers/AppServiceProvider.php
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Connection;

public function register(): void
{
    $this->app->singleton(Connection::class, function () {
        return Client::fromDsn(config('services.lake.dsn'))->connect();
    });
}
```

```php
// config/services.php
'lake' => [
    'dsn' => env('LAKE_DSN', 'lake://user:pass@lake.tidbcloud.com:443/default?warehouse=wh'),
],
```

Then type-hint `TiDBCloud\Lake\Connection` anywhere:

```php
public function index(Connection $lake)
{
    return $lake->queryAll('SELECT * FROM books LIMIT ?', [20]);
}
```

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
- Headers sent on every request: `Authorization` (Basic or Bearer),
  `X-DATABEND-TENANT`, `X-DATABEND-WAREHOUSE`, `X-DATABEND-QUERY-ID`,
  `X-DATABEND-ROUTE: warehouse`, `X-DATABEND-ROUTE-HINT`, and
  `X-DATABEND-STICKY-NODE` / `X-DATABEND-NODE-ID` when the session requires
  node affinity.

## Not in this version (roadmap)

- Arrow result transport (JSON only)
- Server-side prepared statements (the HTTP API is client-interpolated)
- Laravel-specific package/service provider

## Development

```bash
composer install
composer test

# or, without a local PHP installation:
make test-docker

# Start a local Lake server stack (minio + meta + query) in Docker and run
# the integration test group against it, then tear it down:
make integration
```

Unit tests run against an in-memory PSR-18 mock — no server or network is
required. Integration tests (`tests/Integration/`, `@group integration`) are
gated by `LAKE_DSN` and skipped without it; `make integration` provisions a
self-contained server stack under `tests/docker/` and can also be pointed at
any real warehouse via `IT_DSN=...`. CI (`.github/workflows/ci.yaml`) runs
the unit matrix on PHP 8.1–8.4 plus the dockerized integration job for every
push and pull request against `main`.
