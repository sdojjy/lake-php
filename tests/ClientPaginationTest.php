<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Exception\QueryException;
use TiDBCloud\Lake\Exception\QueryOutcomeUnknownException;
use TiDBCloud\Lake\QueryResponse;
use TiDBCloud\Lake\Tests\Support\MockHttpClient;

final class ClientPaginationTest extends TestCase
{
    private function jsonResponse(array $body, array $headers = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    public function testLoginQueryPaginationAndFinal(): void
    {
        $http = new MockHttpClient([
            // POST /v1/session/login
            $this->jsonResponse(['version' => '1.2.3'], [Client::HEADER_SESSION_ID => 'server-session-1']),
            // POST /v1/query -> first page
            $this->jsonResponse([
                'id' => 'q-1',
                'node_id' => 'node-a',
                'session' => ['database' => 'db1'],
                'settings' => ['timezone' => 'UTC'],
                'schema' => [['name' => 'id', 'type' => 'Int64'], ['name' => 'name', 'type' => 'String']],
                'data' => [['1', 'a'], ['2', 'b']],
                'state' => 'Running',
                'next_uri' => '/v1/query/q-1/page/1',
                'final_uri' => '/v1/query/q-1/final',
                'kill_uri' => '/v1/query/q-1/kill',
            ]),
            // GET /v1/query/q-1/page/1 -> last page
            $this->jsonResponse([
                'id' => 'q-1',
                'schema' => [['name' => 'id', 'type' => 'Int64'], ['name' => 'name', 'type' => 'String']],
                'data' => [['3', 'c']],
                'state' => 'Succeeded',
                'next_uri' => '/v1/query/q-1/final',
                'final_uri' => '/v1/query/q-1/final',
            ]),
            // GET /v1/query/q-1/final
            $this->jsonResponse([]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost:8000/db1?sslmode=disable&tenant=t1&warehouse=wh1&max_rows_per_page=2');
        $client = new Client($config, $http);
        $conn = $client->connect();

        $rows = $conn->query('SELECT id, name FROM t');
        $all = $rows->fetchAll();

        self::assertCount(3, $all);
        self::assertSame(['id' => 1, 'name' => 'a'], $all[0]->toArray());
        self::assertSame(['id' => 3, 'name' => 'c'], $all[2]->toArray());
        self::assertSame(['id', 'name'], $rows->columnNames());

        // login, query, page, final
        self::assertCount(4, $http->requests);
        [$login, $query, $page, $final] = $http->requests;

        self::assertSame('POST', $login->getMethod());
        self::assertSame('/v1/session/login', $login->getUri()->getPath());
        self::assertSame('http', $login->getUri()->getScheme());

        self::assertSame('POST', $query->getMethod());
        self::assertSame('/v1/query', $query->getUri()->getPath());
        self::assertSame('t1', $query->getHeaderLine(Client::HEADER_TENANT));
        self::assertSame('wh1', $query->getHeaderLine(Client::HEADER_WAREHOUSE));
        self::assertSame('warehouse', $query->getHeaderLine(Client::HEADER_ROUTE));
        // session id from login response header is used in the query id
        self::assertStringStartsWith('server-session-1.', $query->getHeaderLine(Client::HEADER_QUERY_ID));

        $queryBody = json_decode($http->bodies[1], true);
        self::assertSame('SELECT id, name FROM t', $queryBody['sql']);
        self::assertSame(['max_rows_per_page' => 2], $queryBody['pagination']);
        self::assertSame('db1', $queryBody['session']['database']);

        self::assertSame('GET', $page->getMethod());
        self::assertSame('/v1/query/q-1/page/1', $page->getUri()->getPath());
        // node id from the first response is propagated on GETs
        self::assertSame('node-a', $page->getHeaderLine(Client::HEADER_NODE_ID));

        self::assertSame('GET', $final->getMethod());
        self::assertSame('/v1/query/q-1/final', $final->getUri()->getPath());
    }

    public function testLoginDisabledSkipsLoginRequest(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-2',
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['7']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $client = new Client($config, $http);
        $row = $client->connect()->queryRow('SELECT 7 AS x');

        self::assertNotNull($row);
        self::assertSame(7, $row['x']);
        self::assertCount(1, $http->requests);
        self::assertSame('/v1/query', $http->requests[0]->getUri()->getPath());
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-3',
                'schema' => [['name' => 'number of rows inserted', 'type' => 'UInt64']],
                'data' => [['5']],
                'state' => 'Succeeded',
                'next_uri' => '',
                'final_uri' => '/v1/query/q-3/final',
            ]),
            $this->jsonResponse([]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $client = new Client($config, $http);
        $affected = $client->connect()->execute('INSERT INTO t VALUES (?), (?)', [1, 2]);

        self::assertSame(5, $affected);
        $body = json_decode($http->bodies[0], true);
        self::assertSame('INSERT INTO t VALUES (1), (2)', $body['sql']);
    }

    public function testQueryErrorThrowsAndCallsFinal(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-4',
                'error' => ['code' => 1065, 'message' => 'syntax error', 'kind' => 'BadArguments'],
                'state' => 'Failed',
                'final_uri' => '/v1/query/q-4/final',
            ]),
            $this->jsonResponse([]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $client = new Client($config, $http);
        $conn = $client->connect();

        try {
            $conn->query('SELECT bogus');
            self::fail('expected QueryException');
        } catch (QueryException $e) {
            self::assertSame(1065, $e->errorCode);
            self::assertStringContainsString('syntax error', $e->getMessage());
        }

        self::assertCount(2, $http->requests);
        self::assertSame('/v1/query/q-4/final', $http->requests[1]->getUri()->getPath());
    }

    public function testQueryAllReturnsAssociativeArrays(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-5',
                'schema' => [['name' => 'n', 'type' => 'UInt64'], ['name' => 'ts', 'type' => 'Timestamp']],
                'settings' => ['timezone' => 'Asia/Shanghai'],
                'data' => [['18446744073709551615', '2024-05-01 10:00:00.000000']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $client = new Client($config, $http);
        $all = $client->connect()->queryAll('SELECT n, ts FROM t');

        self::assertCount(1, $all);
        self::assertSame('18446744073709551615', $all[0]['n']);
        self::assertInstanceOf(\DateTimeImmutable::class, $all[0]['ts']);
        self::assertSame('Asia/Shanghai', $all[0]['ts']->getTimezone()->getName());
    }

    public function testSessionEmptyObjectsSurviveRoundTrip(): void
    {
        // Real servers return "settings": {} in the session state and reject
        // it when echoed back as a JSON array ("settings": []).
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-a',
                'session' => ['database' => 'default', 'settings' => new \stdClass(), 'txn_state' => 'AutoCommit'],
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['1']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
            $this->jsonResponse([
                'id' => 'q-b',
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['2']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $conn = (new Client($config, $http))->connect();
        $conn->queryRow('SELECT 1 AS x');
        $conn->queryRow('SELECT 2 AS x');

        self::assertStringContainsString('"settings":{}', $http->bodies[1]);
        self::assertStringContainsString('"txn_state":"AutoCommit"', $http->bodies[1]);
    }

    public function testStickyNodeHeaderWhenSessionNeedsSticky(): void
    {
        $http = new MockHttpClient([
            // first query establishes node id + need_sticky session
            $this->jsonResponse([
                'id' => 'q-6',
                'node_id' => 'node-z',
                'session' => ['need_sticky' => true],
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['1']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
            // second query must carry the sticky node header
            $this->jsonResponse([
                'id' => 'q-7',
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['2']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        $conn = (new Client($config, $http))->connect();
        $conn->queryRow('SELECT 1 AS x');
        $conn->queryRow('SELECT 2 AS x');

        self::assertSame('node-z', $http->requests[1]->getHeaderLine(Client::HEADER_STICKY_NODE));
    }

    public function testInvalidJsonResponseThrows(): void
    {
        $http = new MockHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json'),
        ]);

        $this->expectException(LakeException::class);
        $this->expectExceptionMessage('failed to decode JSON response body');

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
        (new Client($config, $http))->connect()->queryRow('SELECT 1');
    }

    public function testAccessTokenFileHeader(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'lake-token-');
        file_put_contents($tokenFile, 'access_token = "file-token"');
        try {
            $http = new MockHttpClient([
                $this->jsonResponse([
                    'id' => 'q-token',
                    'schema' => [['name' => 'x', 'type' => 'Int32']],
                    'data' => [['1']],
                    'state' => 'Succeeded',
                    'next_uri' => '',
                ]),
            ]);
            $config = Config::fromDsn('lake://localhost/db?sslmode=disable&login=disable&access_token_file=' . rawurlencode($tokenFile));
            (new Client($config, $http))->connect()->queryRow('SELECT 1');

            self::assertSame('Bearer file-token', $http->requests[0]->getHeaderLine('Authorization'));
        } finally {
            @unlink($tokenFile);
        }
    }

    public function testVerifyVersionInfoAndTransactionHelpers(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse(['tenant' => 't1', 'user' => 'u']),
            $this->jsonResponse([
                'id' => 'q-version',
                'schema' => [['name' => 'version', 'type' => 'String']],
                'data' => [['1.2.3']],
                'state' => 'Succeeded',
                'next_uri' => '',
            ]),
            $this->jsonResponse(['id' => 'q-begin', 'state' => 'Succeeded', 'next_uri' => '', 'session' => ['txn_state' => 'Active']]),
            $this->jsonResponse(['id' => 'q-fail', 'state' => 'Failed', 'error' => ['code' => 1, 'message' => 'aborted'], 'session' => ['txn_state' => 'Fail']]),
            $this->jsonResponse(['id' => 'q-commit', 'state' => 'Succeeded', 'next_uri' => '', 'session' => ['txn_state' => 'AutoCommit']]),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost:8000/db?sslmode=disable&login=disable&warehouse=wh');
        $conn = (new Client($config, $http))->connect();

        self::assertSame('u', $conn->verify()['user']);
        self::assertSame('1.2.3', $conn->version());
        self::assertSame([
            'handler' => 'http',
            'host' => 'localhost',
            'port' => 8000,
            'user' => 'u',
            'database' => 'db',
            'warehouse' => 'wh',
        ], $conn->info());

        self::assertFalse($conn->inTransaction());
        $conn->begin();
        self::assertTrue($conn->inTransaction());
        self::assertSame('Active', $conn->transactionState());
        try {
            $conn->execute('SELECT 1');
            self::fail('expected QueryException');
        } catch (QueryException) {
        }
        // An aborted ("Fail") transaction is still open until it ends.
        self::assertTrue($conn->inTransaction());
        self::assertSame('Fail', $conn->transactionState());
        $conn->commit();
        self::assertFalse($conn->inTransaction());
        self::assertSame('AutoCommit', $conn->transactionState());

        self::assertSame('/v1/verify', $http->requests[0]->getUri()->getPath());
        self::assertStringContainsString('"sql":"BEGIN"', $http->bodies[2]);
        self::assertStringContainsString('"sql":"COMMIT"', $http->bodies[4]);
    }

    public function testCanResendOnlyWhatCannotRunTwice(): void
    {
        $request = new Request('POST', 'http://localhost/v1/query');
        $failures = [
            // What Guzzle's cURL handler raises; request_size is the bytes sent.
            'refused' => new ConnectException('cURL error 7: Failed to connect', $request, null, ['request_size' => 0]),
            'timed out' => new ConnectException('cURL error 28: Operation timed out', $request, null, ['request_size' => 312]),
            'reset' => new RequestException('cURL error 56: Recv failure: Connection reset by peer', $request, null, null, ['request_size' => 312]),
            // Another PSR-18 client, which does not say what was sent.
            'other client' => new class ('timed out', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            },
        ];
        $reads = ['SELECT 1', 'show tables', 'DESC t', "-- note\n /* x */ (select 1) union all (select 2)", 'PRESIGN UPLOAD @~/f'];
        $writes = ['INSERT INTO t VALUES (7)', 'update t set a = 1', 'DELETE FROM t', 'MERGE INTO t USING s ON t.id = s.id', 'REPLACE INTO t VALUES (1)', 'COMMIT', 'selection'];

        foreach ($failures as $name => $failure) {
            foreach ($reads as $sql) {
                self::assertTrue(Client::canResend($sql, $failure), "{$name}: {$sql}");
            }
            foreach ($writes as $sql) {
                self::assertSame($name === 'refused', Client::canResend($sql, $failure), "{$name}: {$sql}");
            }
        }
    }

    public function testWriteIsNotResentWhenItMayHaveRun(): void
    {
        $request = new Request('POST', 'http://localhost/v1/query');
        $failures = [
            new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds', $request, null, ['request_size' => 312]),
            new RequestException('cURL error 56: Recv failure: Connection reset by peer', $request, null, null, ['request_size' => 312]),
        ];

        foreach ($failures as $failure) {
            $http = new MockHttpClient([
                static fn () => throw $failure,
                $this->jsonResponse(['state' => 'Succeeded', 'schema' => [['name' => 'number of rows inserted', 'type' => 'UInt64']], 'data' => [['1']]]),
            ]);
            $conn = (new Client(Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable'), $http))->connect();

            try {
                $conn->execute('INSERT INTO t VALUES (7)');
                self::fail('expected QueryOutcomeUnknownException');
            } catch (QueryOutcomeUnknownException $e) {
                self::assertSame($failure, $e->getPrevious());
                self::assertStringContainsString('may or may not have run', $e->getMessage());
                // Laravel treats e.g. "reset by peer" as a lost connection and re-runs the query.
                self::assertStringNotContainsString('cURL', $e->getMessage());
            }
            self::assertCount(1, $http->requests);
        }
    }

    public function testWriteIsResentWhenTheRequestNeverLeft(): void
    {
        $refused = new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'http://localhost/v1/query'), null, ['request_size' => 0]);
        $http = new MockHttpClient([
            static fn () => throw $refused,
            $this->jsonResponse(['state' => 'Succeeded', 'schema' => [['name' => 'number of rows inserted', 'type' => 'UInt64']], 'data' => [['1']]]),
        ]);
        $conn = (new Client(Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable'), $http))->connect();

        self::assertSame(1, $conn->execute('INSERT INTO t VALUES (7)'));
        self::assertCount(2, $http->requests);
        // Re-sent as the same query, so the server can also tell it apart.
        self::assertSame($http->bodies[0], $http->bodies[1]);
        self::assertSame(
            $http->requests[0]->getHeaderLine(Client::HEADER_QUERY_ID),
            $http->requests[1]->getHeaderLine(Client::HEADER_QUERY_ID),
        );
    }

    public function testBestEffortRequestsIgnoreNetworkErrors(): void
    {
        // Seen against a real gateway: the GET of final_uri failed with a TLS
        // EOF, and that failed a query whose result was already read.
        $networkError = static fn (RequestInterface $request) => throw new ConnectException(
            'cURL error 56: OpenSSL SSL_read: unexpected eof while reading',
            $request,
        );
        $http = new MockHttpClient([
            $this->jsonResponse([
                'id' => 'q-1',
                'schema' => [['name' => 'x', 'type' => 'Int32']],
                'data' => [['1']],
                'state' => 'Succeeded',
                'final_uri' => '/v1/query/q-1/final',
                'session' => ['need_keep_alive' => true],
            ]),
            $networkError,
            $networkError,
            $networkError,
        ]);

        $client = new Client(Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable'), $http);
        $conn = $client->connect();

        self::assertSame([['x' => 1]], $conn->queryAll('SELECT 1 AS x'));
        $client->killQuery(QueryResponse::fromArray(['kill_uri' => '/v1/query/q-1/kill']));
        $conn->close();

        self::assertSame(
            ['/v1/query', '/v1/query/q-1/final', '/v1/query/q-1/kill', '/v1/session/logout'],
            array_map(static fn (RequestInterface $request): string => $request->getUri()->getPath(), $http->requests),
        );
    }

    public function testTimezoneIsSentAsSessionSetting(): void
    {
        $http = new MockHttpClient([
            $this->jsonResponse(['id' => 'q-1', 'state' => 'Succeeded', 'next_uri' => '']),
        ]);

        $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable&timezone=Asia/Shanghai');
        (new Client($config, $http))->connect()->execute('SELECT 1');

        $body = json_decode($http->bodies[0], true);
        self::assertSame(['timezone' => 'Asia/Shanghai'], $body['session']['settings']);
    }

    public function testLoadFileWithPresignedStageUpload(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lake-load-');
        file_put_contents($file, "1,a\n");
        try {
            $http = new MockHttpClient([
                $this->jsonResponse([
                    'id' => 'q-presign',
                    'schema' => [
                        ['name' => 'method', 'type' => 'String'],
                        ['name' => 'headers', 'type' => 'String'],
                        ['name' => 'url', 'type' => 'String'],
                    ],
                    'data' => [['PUT', '{"x-upload":"1"}', 'https://upload.example/object']],
                    'state' => 'Succeeded',
                    'next_uri' => '',
                ]),
                new Response(200, [], ''),
                $this->jsonResponse([
                    'id' => 'q-insert-stage',
                    'schema' => [['name' => 'number of rows inserted', 'type' => 'UInt64']],
                    'data' => [['1']],
                    'state' => 'Succeeded',
                    'next_uri' => '',
                ]),
            ]);

            $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable');
            $affected = (new Client($config, $http))->connect()->loadFile('INSERT INTO t VALUES', $file);

            self::assertSame(1, $affected);
            self::assertStringContainsString('PRESIGN UPLOAD @~/load/', $http->bodies[0]);
            self::assertSame('PUT', $http->requests[1]->getMethod());
            self::assertSame('upload.example', $http->requests[1]->getUri()->getHost());
            $insertBody = json_decode($http->bodies[2], true);
            self::assertSame('INSERT INTO t VALUES', $insertBody['sql']);
            self::assertStringStartsWith('~/load/', ltrim($insertBody['stage_attachment']['location'], '@'));
        } finally {
            @unlink($file);
        }
    }

    public function testLoadFileWithApiStageUpload(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lake-load-');
        file_put_contents($file, "1,a\n");
        try {
            $http = new MockHttpClient([
                new Response(200, [], ''),
                // Real stage-attachment INSERTs return no result schema;
                // the count is only reported through stats.write_progress.
                $this->jsonResponse([
                    'id' => 'q-insert-api-stage',
                    'schema' => [],
                    'data' => [],
                    'state' => 'Succeeded',
                    'stats' => ['write_progress' => ['rows' => 1, 'bytes' => 10]],
                    'next_uri' => '',
                ]),
            ]);

            $config = Config::fromDsn('lake://u:p@localhost/db?sslmode=disable&login=disable&presigned_url_disabled=true');
            $affected = (new Client($config, $http))->connect()->loadFile('INSERT INTO t VALUES', $file);

            self::assertSame(1, $affected);
            self::assertSame('/v1/upload_to_stage', $http->requests[0]->getUri()->getPath());
            self::assertSame('~', $http->requests[0]->getHeaderLine('stage_name'));
        } finally {
            @unlink($file);
        }
    }
}
