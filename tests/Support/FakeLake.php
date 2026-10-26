<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Connection;
use TiDBCloud\Lake\Pdo\LakePdo;

/**
 * Scripted Lake server for unit tests: queue single-page query responses,
 * then inspect the SQL the driver sent.
 */
final class FakeLake
{
    public readonly MockHttpClient $http;

    public function __construct()
    {
        $this->http = new MockHttpClient();
    }

    public function connection(): Connection
    {
        $config = Config::fromDsn('http://u:p@localhost:8000/db?login=disable');

        return (new Client($config, $this->http))->connect();
    }

    /**
     * @param array<int, mixed> $options
     */
    public function pdo(array $options = []): LakePdo
    {
        return new LakePdo($this->connection(), $options);
    }

    /**
     * Queues a result set.
     *
     * @param array<string, string> $columns name => Lake type
     * @param list<list<?string>> $rows raw JSON cells
     */
    public function result(array $columns, array $rows = []): void
    {
        $schema = [];
        foreach ($columns as $name => $type) {
            $schema[] = ['name' => $name, 'type' => $type];
        }
        $this->push(['schema' => $schema, 'data' => $rows]);
    }

    /**
     * Queues a DML summary, e.g. ['number of rows inserted' => 2].
     *
     * @param array<string, int> $counts
     */
    public function dml(array $counts): void
    {
        $this->result(
            array_fill_keys(array_keys($counts), 'UInt64'),
            [array_map('strval', array_values($counts))],
        );
    }

    /** Queues an empty response (DDL, BEGIN, COMMIT, ...). */
    public function ok(): void
    {
        $this->push([]);
    }

    /**
     * Queues a failed query. $txnState overrides the transaction state the
     * response reports, e.g. "Fail" as the server does for execution errors.
     */
    public function error(int $code, string $message, ?string $txnState = null): void
    {
        $body = ['state' => 'Failed', 'error' => ['code' => $code, 'message' => $message]];
        if ($txnState !== null) {
            $body['session'] = ['database' => 'db', 'txn_state' => $txnState];
        }
        $this->push($body);
    }

    /**
     * SQL text of every query sent so far.
     *
     * @return list<string>
     */
    public function sqls(): array
    {
        $sqls = [];
        foreach ($this->http->bodies as $body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['sql'])) {
                $sqls[] = $decoded['sql'];
            }
        }

        return $sqls;
    }

    /**
     * Like the server, the echoed session carries the transaction state:
     * "Active" after a successful BEGIN, "AutoCommit" after COMMIT/ROLLBACK.
     */
    private function push(array $body): void
    {
        $this->http->push(static function (RequestInterface $request, string $sent) use ($body): Response {
            $sent = json_decode($sent, true);
            $txnState = $sent['session']['txn_state'] ?? 'AutoCommit';
            $succeeded = ($body['state'] ?? 'Succeeded') === 'Succeeded';
            if ($succeeded && preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK)\b/i', (string) ($sent['sql'] ?? ''), $m) === 1) {
                $txnState = strtoupper($m[1]) === 'BEGIN' ? 'Active' : 'AutoCommit';
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode(
                $body + ['state' => 'Succeeded', 'session' => ['database' => 'db', 'txn_state' => $txnState]],
            ));
        });
    }
}
