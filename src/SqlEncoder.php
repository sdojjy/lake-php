<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

use TiDBCloud\Lake\Exception\LakeException;

/**
 * Interpolates positional `?` placeholders into SQL literals, mirroring
 * lake-go's interpolate.go / encoder.go.
 *
 * There is no server-side prepared statement in the Lake HTTP protocol, so
 * every value is encoded as a properly escaped SQL literal.
 */
final class SqlEncoder
{
    public static function interpolate(string $sql, array|object|null $params): string
    {
        if ($params === null) {
            return $sql;
        }
        if (is_object($params)) {
            $params = get_object_vars($params);
        }
        if ($params === []) {
            return $sql;
        }

        // Named mode requires associative params: Lake SQL uses ":" for
        // VARIANT path access (v:name), so a list of params must never make
        // those tokens act as placeholders.
        if (!array_is_list($params)) {
            $named = self::namedPlaceholders($sql);
            if ($named !== []) {
                return self::interpolateNamed($sql, $params, $named);
            }
        }

        $params = array_values($params);
        $positions = self::placeholders($sql);
        if (count($positions) !== count($params)) {
            throw new LakeException(sprintf('expected %d parameters, got %d', count($positions), count($params)));
        }

        $out = '';
        $prev = 0;
        foreach ($positions as $i => $pos) {
            $out .= substr($sql, $prev, $pos - $prev) . self::encodeValue($params[$i]);
            $prev = $pos + 1;
        }

        return $out . substr($sql, $prev);
    }

    /**
     * Byte offsets of `?` placeholders outside single-quoted strings.
     *
     * @return list<int>
     */
    public static function placeholders(string $sql): array
    {
        $positions = [];
        $inQuote = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            switch ($sql[$i]) {
                case '\\':
                    $i++;
                    break;
                case "'":
                    $inQuote = !$inQuote;
                    break;
                case '?':
                    if (!$inQuote) {
                        $positions[] = $i;
                    }
                    break;
            }
        }

        return $positions;
    }

    /**
     * @return list<array{pos: int, len: int, name: string}>
     */
    public static function namedPlaceholders(string $sql): array
    {
        $positions = [];
        $inQuote = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            switch ($sql[$i]) {
                case '\\':
                    $i++;
                    break;
                case "'":
                    $inQuote = !$inQuote;
                    break;
                case ':':
                    if ($inQuote || ($i > 0 && $sql[$i - 1] === ':')) {
                        break;
                    }
                    $next = $sql[$i + 1] ?? '';
                    if ($next === '' || preg_match('/[A-Za-z_]/', $next) !== 1) {
                        break;
                    }
                    $j = $i + 2;
                    while ($j < $len && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                        $j++;
                    }
                    $positions[] = ['pos' => $i, 'len' => $j - $i, 'name' => substr($sql, $i + 1, $j - $i - 1)];
                    $i = $j - 1;
                    break;
            }
        }

        return $positions;
    }

    /**
     * @param array<string|int, mixed> $params
     * @param list<array{pos: int, len: int, name: string}> $positions
     */
    private static function interpolateNamed(string $sql, array $params, array $positions): string
    {
        if (self::placeholders($sql) !== []) {
            throw new LakeException('cannot mix named and positional SQL parameters');
        }

        $out = '';
        $prev = 0;
        foreach ($positions as $placeholder) {
            $name = $placeholder['name'];
            if (!array_key_exists($name, $params)) {
                throw new LakeException('missing named SQL parameter: ' . $name);
            }
            $out .= substr($sql, $prev, $placeholder['pos'] - $prev) . self::encodeValue($params[$name]);
            $prev = $placeholder['pos'] + $placeholder['len'];
        }

        return $out . substr($sql, $prev);
    }

    public static function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }

            // var_export uses serialize_precision=-1 (shortest round-trip).
            return var_export($value, true);
        }
        if (is_string($value)) {
            return self::quote($value);
        }
        if ($value instanceof \DateTimeInterface) {
            // Same layout as lake-go: 2006-01-02 15:04:05.000000-07:00
            return self::quote($value->format('Y-m-d H:i:s.u') . $value->format('P'));
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new LakeException('associative arrays are not supported as SQL parameters; use a list');
            }
            $parts = array_map(self::encodeValue(...), $value);

            return '[' . implode(',', $parts) . ']';
        }
        if ($value instanceof \Stringable) {
            return self::quote((string) $value);
        }

        throw new LakeException('unsupported SQL parameter type: ' . get_debug_type($value));
    }

    public static function escapeString(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', "'" => "\\'"]);
    }

    private static function quote(string $s): string
    {
        return "'" . self::escapeString($s) . "'";
    }
}
