<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

use TiDBCloud\Lake\Exception\LakeException;

/**
 * Parses Lake DSN strings into {@see Config}, mirroring lake-go's dsn.go.
 *
 * Examples:
 *   lake://user:password@lake.tidbcloud.com:443/default?warehouse=my-warehouse
 *   http://user:password@localhost:8000/default            (plain HTTP)
 *   lake+http://user:password@localhost/default            (plain HTTP, port 80)
 */
final class DsnParser
{
    /** Options rejected outright, same as lake-go. */
    private const FORBIDDEN_OPTIONS = ['default_format', 'query', 'database'];

    /** Options accepted for lake-go DSN compatibility but ignored by this driver. */
    private const IGNORED_OPTIONS = [
        'debug',
        'enable_http_compression',
        'enable_otel',
        'tls_config',
    ];

    public static function parse(string $dsn): Config
    {
        $encoded = self::autoEncodeUserInfo($dsn);
        $url = parse_url($encoded);
        if ($url === false || !isset($url['scheme']) || !isset($url['host'])) {
            throw new LakeException('invalid DSN: ' . $dsn);
        }

        $cfg = new Config();

        if (str_ends_with(strtolower($url['scheme']), 'http')) {
            $cfg->sslMode = Config::SSL_MODE_DISABLE;
        }

        if (isset($url['user'])) {
            $cfg->user = rawurldecode($url['user']);
        }
        if (isset($url['pass'])) {
            $cfg->password = rawurldecode($url['pass']);
        }
        if (isset($url['path']) && strlen($url['path']) > 1) {
            $cfg->database = ltrim($url['path'], '/');
        }

        $params = [];
        if (isset($url['query']) && $url['query'] !== '') {
            parse_str($url['query'], $params);
        }
        self::applyParams($cfg, $params);

        if (isset($url['port'])) {
            $cfg->host = $url['host'] . ':' . $url['port'];
        } else {
            $port = $cfg->sslMode === Config::SSL_MODE_DISABLE ? 80 : 443;
            $cfg->host = $url['host'] . ':' . $port;
        }

        return $cfg;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function applyParams(Config $cfg, array $params): void
    {
        // treat location as an alias of timezone
        $location = $params['location'] ?? null;
        $timezone = $params['timezone'] ?? null;
        if ($location !== null && $timezone !== null && $location !== $timezone) {
            throw new LakeException(sprintf('bad DSN: location(%s) conflict with timezone(%s)', $location, $timezone));
        }
        if ($location !== null || $timezone !== null) {
            $params['timezone'] = $timezone ?? $location;
            unset($params['location']);
        }

        foreach ($params as $key => $value) {
            $value = (string) $value;
            switch ($key) {
                case 'timeout':
                    $cfg->timeout = self::parseDuration($value);
                    break;
                case 'wait_time_secs':
                    $cfg->waitTimeSecs = self::parseInt($key, $value);
                    break;
                case 'max_rows_in_buffer':
                    $cfg->maxRowsInBuffer = self::parseInt($key, $value);
                    break;
                case 'max_rows_per_page':
                    $cfg->maxRowsPerPage = self::parseInt($key, $value);
                    break;
                case 'timezone':
                    try {
                        new \DateTimeZone($value);
                    } catch (\Exception $e) {
                        throw new LakeException('invalid timezone: ' . $value, 0, $e);
                    }
                    $cfg->timezone = $value;
                    break;
                case 'tenant':
                    $cfg->tenant = $value;
                    break;
                case 'warehouse':
                    $cfg->warehouse = $value;
                    break;
                case 'role':
                    $cfg->role = $value;
                    break;
                case 'access_token':
                    $cfg->accessToken = $value;
                    break;
                case 'access_token_file':
                    $cfg->accessTokenFile = $value;
                    break;
                case 'presigned_url_disabled':
                    $cfg->presignedUrlDisabled = self::parseBool($key, $value);
                    break;
                case 'sslmode':
                    $cfg->sslMode = $value;
                    break;
                case 'empty_field_as':
                    $cfg->emptyFieldAs = $value;
                    break;
                case 'login':
                    switch (strtolower(trim($value))) {
                        case '':
                        case 'enable':
                            $cfg->loginEnabled = true;
                            break;
                        case 'disable':
                            $cfg->loginEnabled = false;
                            break;
                        default:
                            throw new LakeException('invalid login: ' . $value);
                    }
                    break;
                case 'query_result_format':
                    $format = strtolower(trim($value));
                    if ($format === 'arrow') {
                        throw new LakeException('query_result_format=arrow is not supported by lake-php; only json is available');
                    }
                    if ($format !== '' && $format !== 'json') {
                        throw new LakeException('invalid query_result_format: ' . $value);
                    }
                    break;
                default:
                    if (in_array($key, self::FORBIDDEN_OPTIONS, true)) {
                        throw new LakeException(sprintf("unknown option '%s'", $key));
                    }
                    if (in_array($key, self::IGNORED_OPTIONS, true)) {
                        break;
                    }
                    // Anything else (binary_output_format, http_json_result_mode, ...)
                    // becomes a session setting, same as lake-go.
                    $cfg->params[(string) $key] = $value;
            }
        }
    }

    /**
     * Parses a Go-style duration ("30s", "1m30s", "500ms") or a plain number
     * of seconds into float seconds.
     */
    public static function parseDuration(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            throw new LakeException('invalid timeout: empty value');
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $units = ['ns' => 1e-9, 'us' => 1e-6, 'µs' => 1e-6, 'ms' => 1e-3, 's' => 1.0, 'm' => 60.0, 'h' => 3600.0];
        if (preg_match_all('/(\d+(?:\.\d+)?)(ns|us|µs|ms|s|m|h)/u', $value, $matches, PREG_SET_ORDER) === 0) {
            throw new LakeException('invalid timeout: ' . $value);
        }
        $matched = implode('', array_column($matches, 0));
        if ($matched !== ltrim($value, '+')) {
            throw new LakeException('invalid timeout: ' . $value);
        }

        $seconds = 0.0;
        foreach ($matches as $m) {
            $seconds += ((float) $m[1]) * $units[$m[2]];
        }

        return $seconds;
    }

    private static function parseInt(string $key, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new LakeException(sprintf('invalid %s: %s', $key, $value));
        }

        return (int) $value;
    }

    private static function parseBool(string $key, string $value): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new LakeException(sprintf('invalid %s: %s', $key, $value));
        }

        return $parsed;
    }

    /**
     * URL-encodes user/password in the DSN when they contain characters that
     * would break parse_url(), mirroring lake-go's autoEncodeUserPassInDSN.
     */
    private static function autoEncodeUserInfo(string $dsn): string
    {
        $schemeEnd = strpos($dsn, '://');
        if ($schemeEnd === false) {
            return $dsn;
        }
        $rest = substr($dsn, $schemeEnd + 3);
        $atIdx = strpos($rest, '@');
        if ($atIdx === false) {
            return $dsn;
        }
        $userinfo = substr($rest, 0, $atIdx);
        $user = $userinfo;
        $pass = '';
        $colonIdx = strpos($userinfo, ':');
        if ($colonIdx !== false) {
            $user = substr($userinfo, 0, $colonIdx);
            $pass = substr($userinfo, $colonIdx + 1);
        }

        $encUser = self::needsEscape($user) ? rawurlencode($user) : $user;
        $encPass = self::needsEscape($pass) ? rawurlencode($pass) : $pass;
        $encUserinfo = $pass !== '' ? $encUser . ':' . $encPass : $encUser;

        return substr($dsn, 0, $schemeEnd + 3) . $encUserinfo . substr($rest, $atIdx);
    }

    private static function needsEscape(string $s): bool
    {
        return rawurlencode(rawurldecode($s)) !== $s;
    }
}
