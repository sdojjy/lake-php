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

    private const DEFAULT_CSV_FORMAT_OPTIONS = [
        'type' => 'CSV',
        'field_delimiter' => ',',
        'record_delimiter' => "\n",
        'skip_header' => '0',
    ];

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

    /**
     * Client-side session state, updated from every query response and
     * echoed back verbatim on the next request. Kept as stdClass once it
     * comes from the server so that empty JSON objects (e.g. "settings": {})
     * survive the round trip — PHP assoc arrays would re-encode them as [].
     */
    private array|\stdClass $sessionState;

    /**
     * Domain-ignoring cookie store, parity with lake-go's
     * IgnoreDomainCookieJar. The server requires cookie_enabled=true and
     * tracks the session via a session_id cookie.
     *
     * @var array<string, string>
     */
    private array $cookies = ['cookie_enabled' => 'true'];

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
        if (!$this->loggedIn && !$this->sessionValue('need_keep_alive')) {
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
        return $this->startQueryRequest(['sql' => $sql]);
    }

    /**
     * Runs an INSERT/REPLACE with a stage attachment after the caller has
     * uploaded data to the given stage location.
     *
     * @param array<string, string>|null $fileFormatOptions
     * @param array<string, string>|null $copyOptions
     */
    public function insertWithStage(
        string $sql,
        string $stageName,
        string $stagePath,
        ?array $fileFormatOptions = null,
        ?array $copyOptions = null,
    ): QueryResponse {
        $fileFormatOptions ??= $this->defaultCsvFormatOptions();
        $copyOptions ??= ['purge' => 'true'];

        $response = $this->startQueryRequest([
            'sql' => $sql,
            'stage_attachment' => [
                'location' => $this->stageLocation($stageName, $stagePath),
                'file_format_options' => $fileFormatOptions,
                'copy_options' => $copyOptions,
            ],
        ]);

        try {
            return $this->waitForResults($response);
        } finally {
            $this->closeQuery($response);
        }
    }

    /**
     * Uploads a local file to a stage path. Uses PRESIGN by default and falls
     * back to /v1/upload_to_stage when `presigned_url_disabled=true`.
     */
    public function uploadToStage(string $stageName, string $stagePath, string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new LakeException('stage upload file is not readable: ' . $file);
        }

        if ($this->config->presignedUrlDisabled) {
            $this->uploadToStageByApi($stageName, $stagePath, $file);
        } else {
            $this->uploadToStageByPresignedUrl($stageName, $stagePath, $file);
        }
    }

    /**
     * POST /v1/verify.
     *
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $this->ensureInitialized();
        [$data] = $this->withRetry(
            fn (): array => $this->doRequest('POST', '/v1/verify', [], false),
            attempts: 3,
            delaySeconds: 1.0,
        );

        return $data ?? [];
    }

    public function ping(): void
    {
        $this->verify();
    }

    /**
     * @return array{handler: string, host: string, port: int, user: string, database: string, warehouse: string}
     */
    public function info(): array
    {
        [$host, $port] = $this->splitHostPort($this->config->host);

        return [
            'handler' => $this->config->sslMode === Config::SSL_MODE_DISABLE ? 'http' : 'https',
            'host' => $host,
            'port' => $port,
            'user' => $this->config->user,
            'database' => $this->config->database,
            'warehouse' => $this->config->warehouse,
        ];
    }

    private function startQueryRequest(array $requestBody): QueryResponse
    {
        $this->ensureInitialized();
        $this->querySeq++;
        if ($this->sessionValue('txn_state') !== 'Active') {
            $this->routeHint = self::randRouteHint();
        }

        $body = $requestBody;
        $pagination = $this->paginationConfig();
        if ($pagination !== null) {
            $body['pagination'] = $pagination;
        }
        $body['session'] = $this->sessionState === [] ? new \stdClass() : $this->sessionState;

        [$data, $response, $raw] = $this->withRetry(
            fn (): array => $this->doRequest('POST', '/v1/query', $body, $this->needSticky()),
            attempts: 5,
            delaySeconds: 2.0,
        );

        return $this->finalizeResponse($data, $response, $raw);
    }

    /**
     * GET next_uri for the following result page.
     */
    public function pollQuery(string $nextUri): QueryResponse
    {
        [$data, $response, $raw] = $this->withRetry(
            fn (): array => $this->doRequest('GET', $nextUri, null, true),
            attempts: 3,
            delaySeconds: 1.0,
        );

        return $this->finalizeResponse($data, $response, $raw);
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
        } elseif ($this->config->accessToken !== '' || $this->config->accessTokenFile !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->loadAccessToken();
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
        return (bool) $this->sessionValue('need_sticky');
    }

    private function sessionValue(string $key): mixed
    {
        if (is_object($this->sessionState)) {
            return $this->sessionState->{$key} ?? null;
        }

        return $this->sessionState[$key] ?? null;
    }

    /**
     * @return array{0: ?array, 1: ResponseInterface, 2: string} decoded JSON body, PSR-7 response, raw body
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
        $request = $request->withHeader('Cookie', $this->cookieHeader());

        $response = $this->http->sendRequest($request);
        $this->storeCookies($response);
        $raw = (string) $response->getBody();

        if ($response->getStatusCode() === 401 && $this->config->accessTokenFile !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->loadAccessToken());
            $response = $this->http->sendRequest($request);
            $this->storeCookies($response);
            $raw = (string) $response->getBody();
        }

        if ($response->getStatusCode() !== 200) {
            throw ApiException::fromResponse($response->getStatusCode(), $raw);
        }

        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LakeException('failed to decode JSON response body', 0, $e);
            }
            if (!is_array($decoded)) {
                throw new LakeException('unexpected JSON response body type: ' . get_debug_type($decoded));
            }
        }

        return [$decoded, $response, $raw];
    }

    private function finalizeResponse(?array $data, ResponseInterface $httpResponse, string $raw = ''): QueryResponse
    {
        $response = QueryResponse::fromArray($data ?? []);
        if ($response->nodeId !== '') {
            $this->nodeId = $response->nodeId;
        }
        // Update session state even when the query failed, e.g. a failed
        // COMMIT must still refresh the transaction state. The state is kept
        // as stdClass (raw object decode) so it round-trips verbatim,
        // matching lake-go's json.RawMessage handling: assoc arrays would
        // turn "settings": {} into "settings": [] and break the server.
        if ($response->session !== null) {
            $rawObject = $raw !== '' ? json_decode($raw) : null;
            $this->sessionState = is_object($rawObject) && isset($rawObject->session) && is_object($rawObject->session)
                ? $rawObject->session
                : $response->session;
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

    private function cookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    private function storeCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            $pair = explode(';', $line, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            if ($name !== '') {
                $this->cookies[$name] = trim(substr($pair, $eq + 1));
            }
        }
    }

    private function loadAccessToken(): string
    {
        if ($this->config->accessToken !== '') {
            return $this->config->accessToken;
        }
        if ($this->config->accessTokenFile === '') {
            throw new LakeException('no user password or access token');
        }

        $content = @file_get_contents($this->config->accessTokenFile);
        if ($content === false) {
            throw new LakeException('failed to read access_token_file: ' . $this->config->accessTokenFile);
        }
        $content = trim($content);
        if (preg_match('/^\s*access_token\s*=\s*["\']?([^"\'\r\n]+)["\']?\s*$/m', $content, $m) === 1) {
            return trim($m[1]);
        }

        return $content;
    }

    /**
     * @return array{method: string, headers: array<string, string>, url: string}
     */
    private function getPresignedUrl(string $stageName, string $stagePath): array
    {
        $response = $this->startQuery('PRESIGN UPLOAD ' . $this->stageLocation($stageName, $stagePath));
        try {
            $response = $this->waitForResults($response);
            $row = $response->typedRows[0] ?? null;
            if (!is_array($row) || count($row) < 3) {
                throw new LakeException('generate presign url invalid response');
            }
            $headers = json_decode((string) $row[1], true);
            if (!is_array($headers)) {
                throw new LakeException('failed to decode presigned upload headers');
            }

            return [
                'method' => (string) $row[0],
                'headers' => array_map('strval', $headers),
                'url' => (string) $row[2],
            ];
        } finally {
            $this->closeQuery($response);
        }
    }

    private function uploadToStageByPresignedUrl(string $stageName, string $stagePath, string $file): void
    {
        $presigned = $this->getPresignedUrl($stageName, $stagePath);
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new LakeException('failed to open upload file: ' . $file);
        }
        try {
            $request = $this->requestFactory->createRequest($presigned['method'] ?: 'PUT', $presigned['url'])
                ->withBody($this->streamFactory->createStreamFromResource($stream))
                ->withHeader('Content-Length', (string) filesize($file));
            foreach ($presigned['headers'] as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $response = $this->http->sendRequest($request);
            $raw = (string) $response->getBody();
            if ($response->getStatusCode() >= 400) {
                throw new LakeException(sprintf(
                    'failed to upload to stage by presigned url, status code: %d, body: %s',
                    $response->getStatusCode(),
                    $raw,
                ));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function uploadToStageByApi(string $stageName, string $stagePath, string $file): void
    {
        $boundary = 'lake-php-' . bin2hex(random_bytes(12));
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new LakeException('failed to read upload file: ' . $file);
        }
        $body = "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="upload"; filename="' . addcslashes($stagePath, "\\\"") . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        $request = $this->requestFactory->createRequest('PUT', $this->endpoint . '/v1/upload_to_stage')
            ->withBody($this->streamFactory->createStream($body))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withHeader('X-DATABEND-STAGE-NAME', $stageName)
            ->withHeader('stage-name', $stageName)
            ->withHeader('stage_name', $stageName)
            ->withHeader('Cookie', $this->cookieHeader());
        foreach ($this->makeHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->http->sendRequest($request);
        $this->storeCookies($response);
        $raw = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            throw ApiException::fromResponse($response->getStatusCode(), $raw);
        }
    }

    /**
     * @return array<string, string>
     */
    private function defaultCsvFormatOptions(): array
    {
        return self::DEFAULT_CSV_FORMAT_OPTIONS + ['empty_field_as' => $this->config->emptyFieldAs];
    }

    private function stageLocation(string $stageName, string $stagePath): string
    {
        return '@' . $stageName . '/' . ltrim($stagePath, '/');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $hostPort): array
    {
        $pos = strrpos($hostPort, ':');
        if ($pos === false) {
            return [$hostPort, $this->config->sslMode === Config::SSL_MODE_DISABLE ? 80 : 443];
        }

        return [substr($hostPort, 0, $pos), (int) substr($hostPort, $pos + 1)];
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
