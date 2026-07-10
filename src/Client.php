<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TiDBCloud\Lake\Exception\ApiException;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Exception\QueryException;

/**
 * Low-level HTTP client for the Lake (Databend-compatible) HTTP API,
 * mirroring lake-go's APIClient.
 *
 * The HTTP transport is any PSR-18 client; Guzzle is used by default.
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const HEADER_TENANT = 'X-DATABEND-TENANT';
    public const HEADER_WAREHOUSE = 'X-DATABEND-WAREHOUSE';
    public const HEADER_QUERY_ID = 'X-DATABEND-QUERY-ID';
    public const HEADER_ROUTE = 'X-DATABEND-ROUTE';
    public const HEADER_ROUTE_HINT = 'X-DATABEND-ROUTE-HINT';
    public const HEADER_SESSION_ID = 'X-DATABEND-SESSION-ID';
    public const HEADER_STICKY_NODE = 'X-DATABEND-STICKY-NODE';
    public const HEADER_NODE_ID = 'X-DATABEND-NODE-ID';

    private PsrClientInterface $http;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    private string $endpoint;
    private string $sessionId;
    private int $querySeq = 0;
    private string $routeHint;
    private string $nodeId = '';
    private bool $initialized = false;
    private bool $loggedIn = false;

    /** Client-side session state, updated from every query response. */
    private array $sessionState;

    public function __construct(
        public readonly Config $config,
        ?PsrClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->http = $http ?? new GuzzleClient([
            'http_errors' => false,
            'timeout' => $config->timeout > 0 ? $config->timeout : 0,
        ]);
        $factory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;

        $scheme = $config->sslMode === Config::SSL_MODE_DISABLE ? 'http' : 'https';
        $this->endpoint = $scheme . '://' . $config->host;
        $this->sessionId = self::uuid4();
        $this->routeHint = self::randRouteHint();
        $this->sessionState = $this->initialSessionState();
    }

    public static function fromDsn(
        string $dsn,
        ?PsrClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        return new self(Config::fromDsn($dsn), $http, $requestFactory, $streamFactory);
    }

    /**
     * Opens a connection: performs session login (unless login=disable)
     * and returns a Connection for running queries.
     */
    public function connect(): Connection
    {
        $this->ensureInitialized();

        return new Connection($this);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function queryId(): string
    {
        return sprintf('%s.%d', $this->sessionId, $this->querySeq);
    }

    /**
     * POST /v1/session/login. Saves the X-DATABEND-SESSION-ID response
     * header and marks the session as logged in.
     */
    public function login(): void
    {
        $body = [];
        if ($this->config->database !== '') {
            $body['database'] = $this->config->database;
        }
        if ($this->config->role !== '') {
            $body['role'] = $this->config->role;
        }
        if ($this->config->params !== []) {
            $body['settings'] = $this->config->params;
        }

        [, $response] = $this->withRetry(
            fn (): array => $this->doRequest('POST', '/v1/session/login', $body, false),
            attempts: 3,
            delaySeconds: 1.0,
        );

        $sessionId = $response->getHeaderLine(self::HEADER_SESSION_ID);
        if ($sessionId !== '') {
            $this->sessionId = $sessionId;
        }
        $this->loggedIn = true;
    }

    /**
     * POST /v1/session/logout. Errors are swallowed: logout is best-effort.
     */
    public function logout(): void
    {
        if (!$this->loggedIn && !($this->sessionState['need_keep_alive'] ?? false)) {
            return;
        }
        try {
            $this->doRequest('POST', '/v1/session/logout', [], $this->needSticky());
        } catch (LakeException) {
            // best effort
        }
        $this->loggedIn = false;
    }

    /**
     * POST /v1/query. Returns the first response page with typed rows
     * materialized. Use {@see waitForResults()} or {@see Rows} to consume
     * the remaining pages.
     */
    public function startQuery(string $sql): QueryResponse
    {
        $this->ensureInitialized();
        $this->querySeq++;
        if (($this->sessionState['txn_state'] ?? '') !== 'Active') {
            $this->routeHint = self::randRouteHint();
        }

        $body = ['sql' => $sql];
        $pagination = $this->paginationConfig();
        if ($pagination !== null) {
            $body['pagination'] = $pagination;
        }
        $body['session'] = $this->sessionState === [] ? new \stdClass() : $this->sessionState;

        [$data, $response] = $this->withRetry(
            fn (): array => $this->doRequest('POST', '/v1/query', $body, $this->needSticky()),
            attempts: 5,
            delaySeconds: 2.0,
        );

        return $this->finalizeResponse($data, $response);
    }

    /**
     * GET next_uri for the following result page.
     */
    public function pollQuery(string $nextUri): QueryResponse
    {
        [$data, $response] = $this->withRetry(
            fn (): array => $this->doRequest('GET', $nextUri, null, true),
            attempts: 3,
            delaySeconds: 1.0,
        );

        return $this->finalizeResponse($data, $response);
    }

    /**
     * Polls next_uri until the query result is fully read, accumulating
     * typed rows, mirroring lake-go's PollUntilQueryEnd.
     */
    public function waitForResults(QueryResponse $response): QueryResponse
    {
        while (!$response->readFinished()) {
            $next = $this->pollQuery($response->nextUri);
            $next->typedRows = array_merge($response->typedRows, $next->typedRows);
            if ($next->finalUri === '') {
                $next->finalUri = $response->finalUri;
            }
            if ($next->killUri === '') {
                $next->killUri = $response->killUri;
            }
            $response = $next;
        }

        return $response;
    }

    /**
     * GET final_uri to release the query on the server. Best-effort.
     */
    public function closeQuery(QueryResponse $response): void
    {
        if ($response->finalUri === '') {
            return;
        }
        try {
            $this->doRequest('GET', $response->finalUri, null, true);
        } catch (LakeException) {
            // best effort
        }
    }

    /**
     * GET kill_uri to cancel a running query. Best-effort.
     */
    public function killQuery(QueryResponse $response): void
    {
        if ($response->killUri === '') {
            return;
        }
        try {
            $this->doRequest('GET', $response->killUri, null, true);
        } catch (LakeException) {
            // best effort
        }
    }

    /**
     * Builds protocol headers for a request.
     *
     * @internal exposed for tests
     * @return array<string, string>
     */
    public function makeHeaders(): array
    {
        $userAgent = 'lake-php/' . self::VERSION;
        if ($this->config->userAgent !== '') {
            $userAgent .= ' (' . $this->config->userAgent . ')';
        }

        $headers = [
            self::HEADER_ROUTE => 'warehouse',
            'User-Agent' => $userAgent,
            self::HEADER_QUERY_ID => $this->queryId(),
        ];
        if ($this->config->tenant !== '') {
            $headers[self::HEADER_TENANT] = $this->config->tenant;
        }
        if ($this->config->warehouse !== '') {
            $headers[self::HEADER_WAREHOUSE] = $this->config->warehouse;
        }
        if ($this->routeHint !== '') {
            $headers[self::HEADER_ROUTE_HINT] = $this->routeHint;
        }

        if ($this->config->user !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->config->user . ':' . $this->config->password);
        } elseif ($this->config->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->config->accessToken;
        } else {
            throw new LakeException('no user password or access token');
        }

        return $headers;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        if ($this->config->loginEnabled) {
            try {
                $this->login();
            } catch (ApiException $e) {
                // Older servers without /v1/session/login answer 404.
                if (!$e->isNotFound()) {
                    throw $e;
                }
            }
        }
        $this->initialized = true;
    }

    private function initialSessionState(): array
    {
        $state = [];
        if ($this->config->database !== '') {
            $state['database'] = $this->config->database;
        }
        if ($this->config->role !== '') {
            $state['role'] = $this->config->role;
            // Limit privileges to the configured role only (parity with lake-go).
            $state['secondary_roles'] = [];
        }
        if ($this->config->params !== []) {
            $state['settings'] = $this->config->params;
        }

        return $state;
    }

    private function paginationConfig(): ?array
    {
        $pagination = array_filter([
            'wait_time_secs' => $this->config->waitTimeSecs,
            'max_rows_in_buffer' => $this->config->maxRowsInBuffer,
            'max_rows_per_page' => $this->config->maxRowsPerPage,
        ]);

        return $pagination === [] ? null : $pagination;
    }

    private function needSticky(): bool
    {
        return (bool) ($this->sessionState['need_sticky'] ?? false);
    }

    /**
     * @return array{0: ?array, 1: ResponseInterface} decoded JSON body and PSR-7 response
     */
    private function doRequest(string $method, string $uri, ?array $body, bool $needSticky): array
    {
        $url = str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')
            ? $uri
            : $this->endpoint . $uri;

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        foreach ($this->makeHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($needSticky && $this->nodeId !== '') {
            $request = $request->withHeader(self::HEADER_STICKY_NODE, $this->nodeId);
        }
        if ($method === 'GET' && $this->nodeId !== '') {
            $request = $request->withHeader(self::HEADER_NODE_ID, $this->nodeId);
        }
        if ($body !== null) {
            $json = $body === []
                ? '{}'
                : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $request = $request->withBody($this->streamFactory->createStream($json));
        }

        $response = $this->http->sendRequest($request);
        $raw = (string) $response->getBody();

        if ($response->getStatusCode() !== 200) {
            throw ApiException::fromResponse($response->getStatusCode(), $raw);
        }

        $decoded = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = null;
            }
        }

        return [$decoded, $response];
    }

    private function finalizeResponse(?array $data, ResponseInterface $httpResponse): QueryResponse
    {
        $response = QueryResponse::fromArray($data ?? []);
        if ($response->nodeId !== '') {
            $this->nodeId = $response->nodeId;
        }
        // Update session state even when the query failed, e.g. a failed
        // COMMIT must still refresh the transaction state.
        if ($response->session !== null) {
            $this->sessionState = $response->session;
        }
        $routeHint = $httpResponse->getHeaderLine(self::HEADER_ROUTE_HINT);
        if ($routeHint !== '') {
            $this->routeHint = $routeHint;
        }
        if ($response->error !== null) {
            $this->closeQuery($response);
            throw QueryException::fromError($response->error);
        }
        $this->materialize($response);

        return $response;
    }

    private function materialize(QueryResponse $response): void
    {
        if ($response->data === []) {
            return;
        }
        if ($response->schema === [] || count($response->schema) !== count($response->data[0])) {
            throw new LakeException('query rows and schema do not match');
        }

        $parser = TypeParser::fromSettings($response->settings, $this->config);
        $types = array_column($response->schema, 'type');

        $rows = [];
        foreach ($response->data as $rawRow) {
            $row = [];
            foreach ($rawRow as $i => $cell) {
                $row[] = $parser->parse($types[$i], $cell);
            }
            $rows[] = $row;
        }
        $response->typedRows = $rows;
        $response->data = [];
    }

    /**
     * Retries on PSR-18 network failures only; HTTP-level errors surface
     * immediately as ApiException.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withRetry(callable $fn, int $attempts, float $delaySeconds): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (NetworkExceptionInterface $e) {
                if ($attempt >= $attempts) {
                    throw new LakeException('http request failed: ' . $e->getMessage(), 0, $e);
                }
                usleep((int) ($delaySeconds * 1_000_000));
            }
        }
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private static function randRouteHint(): string
    {
        return bin2hex(random_bytes(8));
    }
}
