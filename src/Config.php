<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

/**
 * Connection configuration, mirroring lake-go's Config.
 *
 * All values can be set programmatically or parsed from a DSN via
 * {@see Config::fromDsn()} / {@see DsnParser::parse()}.
 */
final class Config
{
    public const SSL_MODE_DISABLE = 'disable';
    public const DEFAULT_DOMAIN = 'lake.tidbcloud.com';
    public const DEFAULT_HOST = self::DEFAULT_DOMAIN . ':443';

    /** Host including port, e.g. "lake.tidbcloud.com:443". */
    public string $host = self::DEFAULT_HOST;

    public string $tenant = '';
    public string $warehouse = '';
    public string $user = '';
    public string $password = '';
    public string $database = '';

    /** Lake role to use as the only effective role for the connection. */
    public string $role = '';

    /** Static bearer token; used when user/password are not set. */
    public string $accessToken = '';

    /** HTTP timeout in seconds; 0 means no explicit timeout. */
    public float $timeout = 0.0;

    /** Pagination: max seconds each HTTP request waits before returning. */
    public int $waitTimeSecs = 0;
    public int $maxRowsInBuffer = 0;
    public int $maxRowsPerPage = 0;

    /** IANA timezone name used to interpret Timestamp values (alias: location). */
    public string $timezone = 'UTC';

    /** "disable" to use plain HTTP; anything else means HTTPS. */
    public string $sslMode = '';

    /** How empty CSV fields are loaded (stage/batch insert, phase 2). */
    public string $emptyFieldAs = 'string';

    /** Whether to POST /v1/session/login when the connection opens. */
    public bool $loginEnabled = true;

    /** Extra string appended to the default User-Agent header. */
    public string $userAgent = '';

    /**
     * Extra parameters passed to the server as session settings
     * (e.g. binary_output_format, http_json_result_mode).
     *
     * @var array<string, string>
     */
    public array $params = [];

    public static function fromDsn(string $dsn): self
    {
        return DsnParser::parse($dsn);
    }
}
