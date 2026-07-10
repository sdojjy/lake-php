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
        $params = array_values($params);
        if ($params === []) {
            return $sql;
        }

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
