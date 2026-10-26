<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Laravel;

use Illuminate\Database\Connectors\ConnectorInterface;
use TiDBCloud\Lake\Pdo\LakePdo;

/**
 * Creates the {@see LakePdo} for a "lake" connection from its Laravel config.
 *
 *   'lake' => [
 *       'driver' => 'lake',
 *       'host' => 'lake.tidbcloud.com',
 *       'port' => 443,
 *       'database' => 'default',
 *       'username' => '...',
 *       'password' => '...',
 *       'warehouse' => 'my-warehouse',
 *       'settings' => [],         // extra Lake session settings
 *   ],
 *
 * or a full DSN: ['driver' => 'lake', 'dsn' => 'lake://user:pass@host:443/db?warehouse=wh'].
 */
final class LakeConnector implements ConnectorInterface
{
    /** Top-level config keys forwarded as DSN parameters (see README "DSN"). */
    private const DSN_OPTIONS = [
        'warehouse',
        'tenant',
        'role',
        'access_token',
        'access_token_file',
        'timeout',
        'timezone',
        'sslmode',
        'login',
        'wait_time_secs',
        'max_rows_in_buffer',
        'max_rows_per_page',
        'presigned_url_disabled',
        'empty_field_as',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config)
    {
        return LakePdo::fromDsn($this->dsn($config), $config['options'] ?? []);
    }

    /**
     * Builds the Lake DSN for a connection config.
     *
     * @param array<string, mixed> $config
     */
    public function dsn(array $config): string
    {
        if (($config['dsn'] ?? '') !== '') {
            return (string) $config['dsn'];
        }
        if (($config['host'] ?? '') === '') {
            throw new \InvalidArgumentException('The lake connection needs a "host" (or a full "dsn").');
        }

        $userInfo = '';
        $username = (string) ($config['username'] ?? '');
        if ($username !== '') {
            $password = (string) ($config['password'] ?? '');
            $userInfo = rawurlencode($username) . ($password !== '' ? ':' . rawurlencode($password) : '') . '@';
        }

        $host = (string) $config['host'];
        if (($config['port'] ?? '') !== '') {
            $host .= ':' . $config['port'];
        }

        $params = [];
        foreach (self::DSN_OPTIONS as $option) {
            if (isset($config[$option]) && $config[$option] !== '') {
                $params[$option] = self::paramValue($config[$option]);
            }
        }
        foreach ($config['settings'] ?? [] as $name => $value) {
            $params[$name] = self::paramValue($value);
        }

        $dsn = 'lake://' . $userInfo . $host . '/' . ($config['database'] ?? '');

        return $params === [] ? $dsn : $dsn . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function paramValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
