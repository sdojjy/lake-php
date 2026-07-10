<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

use TiDBCloud\Lake\Exception\LakeException;

/**
 * Converts JSON string cells into PHP values based on the column type
 * reported in `schema[].type`, mirroring lake-go's typeparser.go and
 * columntype.go.
 *
 * Mapping:
 *   NULL cell (JSON null)                -> null
 *   Boolean                              -> bool
 *   Int8/16/32, UInt8/16/32              -> int
 *   Int64/UInt64                         -> int, or string when out of PHP int range
 *   Float32/Float64                      -> float
 *   Decimal                              -> string
 *   Date/Timestamp/Timestamp_Tz          -> DateTimeImmutable
 *   Binary                               -> binary string (per binary_output_format)
 *   String/Variant/Array/Map/Tuple/...   -> string (raw server representation)
 */
final class TypeParser
{
    public const BINARY_FORMAT_HEX = 'hex';
    public const BINARY_FORMAT_BASE64 = 'base64';
    public const BINARY_FORMAT_UTF8 = 'utf8';

    public const JSON_MODE_DRIVER = 'driver';
    public const JSON_MODE_DISPLAY = 'display';

    private \DateTimeZone $timezone;
    private string $binaryOutputFormat;
    private string $httpJsonResultMode;

    /** @var array<string, array{name: string, nullable: bool, args: list<array>}> */
    private array $descCache = [];

    public function __construct(
        ?\DateTimeZone $timezone = null,
        string $binaryOutputFormat = self::BINARY_FORMAT_HEX,
        string $httpJsonResultMode = self::JSON_MODE_DRIVER,
    ) {
        $this->timezone = $timezone ?? new \DateTimeZone('UTC');
        $this->binaryOutputFormat = self::normalizeBinaryOutputFormat($binaryOutputFormat);
        $this->httpJsonResultMode = strtolower(trim($httpJsonResultMode)) === self::JSON_MODE_DISPLAY
            ? self::JSON_MODE_DISPLAY
            : self::JSON_MODE_DRIVER;
    }

    /**
     * Builds a parser from the `settings` object of a query response,
     * falling back to config-level session params, then defaults.
     *
     * @param array<string, mixed>|null $settings
     */
    public static function fromSettings(?array $settings, ?Config $config = null): self
    {
        $tzName = (string) ($settings['timezone'] ?? $config?->timezone ?? 'UTC');
        try {
            $tz = new \DateTimeZone($tzName !== '' ? $tzName : 'UTC');
        } catch (\Exception $e) {
            throw new LakeException('invalid timezone in response settings: ' . $tzName, 0, $e);
        }

        $binaryFormat = (string) ($settings['binary_output_format']
            ?? $config?->params['binary_output_format']
            ?? self::BINARY_FORMAT_HEX);
        $jsonMode = (string) ($settings['http_json_result_mode']
            ?? $config?->params['http_json_result_mode']
            ?? self::JSON_MODE_DRIVER);

        return new self($tz, $binaryFormat, $jsonMode);
    }

    public function parse(string $type, ?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $desc = $this->descCache[$type] ??= self::normalize(self::parseTypeDesc($type));

        return $this->parseWithDesc($desc, $value);
    }

    /**
     * @param array{name: string, nullable: bool, args: list<array>} $desc
     */
    private function parseWithDesc(array $desc, string $value): mixed
    {
        $name = $desc['name'];
        $nullable = $desc['nullable'];

        switch ($name) {
            case 'String':
                return $value;
            case 'Boolean':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return in_array(strtolower($value), ['true', '1'], true);
            case 'Int8':
            case 'Int16':
            case 'Int32':
            case 'UInt8':
            case 'UInt16':
            case 'UInt32':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return (int) $value;
            case 'Int64':
            case 'UInt64':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return self::intOrString($value);
            case 'Float32':
            case 'Float64':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return self::parseFloat($value);
            case 'Decimal':
                return $value;
            case 'Timestamp':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return $this->parseDateTime($value, $this->timezone);
            case 'Timestamp_Tz':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return $this->parseDateTime($value, null);
            case 'Date':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return $this->parseDateTime($value, new \DateTimeZone('UTC'));
            case 'Binary':
                if ($nullable && $value === 'NULL') {
                    return null;
                }

                return $this->parseBinary($value);
            default:
                // Variant, Bitmap, Array, Tuple, Map, Geometry, Geography, ...
                // are kept as the raw server string representation.
                return $value;
        }
    }

    /**
     * Parses a Lake type descriptor like "Nullable(Decimal(10, 2))" or
     * "Int32 NULL" into {name, nullable, args}. Port of lake-go ParseTypeDesc.
     *
     * @return array{name: string, nullable: bool, args: list<array>}
     */
    public static function parseTypeDesc(string $s): array
    {
        $name = '';
        $args = [];
        $depth = 0;
        $start = 0;
        $nullable = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            switch ($s[$i]) {
                case '(':
                    if ($depth === 0) {
                        $name = substr($s, $start, $i - $start);
                        $start = $i + 1;
                    }
                    $depth++;
                    break;
                case ')':
                    $depth--;
                    if ($depth === 0) {
                        $sub = substr($s, $start, $i - $start);
                        if ($sub !== '') {
                            $args[] = self::parseTypeDesc($sub);
                        }
                        $start = $i + 1;
                    }
                    break;
                case ',':
                    if ($depth === 1) {
                        $sub = substr($s, $start, $i - $start);
                        if ($sub !== '') {
                            $args[] = self::parseTypeDesc($sub);
                        }
                        $start = $i + 1;
                    }
                    break;
                case ' ':
                    if ($depth === 0) {
                        $sub = substr($s, $start, $i - $start);
                        if ($sub !== '') {
                            $name = $sub;
                        }
                        $start = $i + 1;
                    }
                    break;
            }
        }
        if ($depth !== 0) {
            throw new LakeException('invalid type desc: ' . $s);
        }
        if ($start < $len) {
            $sub = substr($s, $start);
            if ($sub !== '') {
                if ($name === '') {
                    $name = $sub;
                } elseif ($sub === 'NULL') {
                    $nullable = true;
                } else {
                    throw new LakeException(sprintf('invalid type arg for %s: %s', $name, $sub));
                }
            }
        }

        return ['name' => $name, 'nullable' => $nullable, 'args' => $args];
    }

    /**
     * Unwraps Nullable(...) and renames DateTime -> Timestamp,
     * port of lake-go TypeDesc.Normalize.
     *
     * @param array{name: string, nullable: bool, args: list<array>} $desc
     * @return array{name: string, nullable: bool, args: list<array>}
     */
    public static function normalize(array $desc): array
    {
        if ($desc['name'] === 'Nullable' && $desc['args'] !== []) {
            $sub = self::normalize($desc['args'][0]);
            $sub['nullable'] = true;

            return $sub;
        }
        if ($desc['name'] === 'DateTime') {
            $desc['name'] = 'Timestamp';
        }

        return $desc;
    }

    /**
     * Returns int when the decimal string fits into PHP's int range,
     * the original string otherwise (Int64/UInt64 overflow safety).
     */
    public static function intOrString(string $value): int|string
    {
        $trimmed = ltrim($value, '+');
        if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
            return $value;
        }

        $negative = str_starts_with($trimmed, '-');
        $digits = ltrim($negative ? substr($trimmed, 1) : $trimmed, '0');
        if ($digits === '') {
            return 0;
        }
        $canonical = ($negative ? '-' : '') . $digits;

        // (int) saturates on overflow, so a round-trip mismatch means overflow.
        $int = (int) $canonical;
        if ((string) $int === $canonical) {
            return $int;
        }

        return $value;
    }

    private static function parseFloat(string $value): float
    {
        return match (strtolower(trim($value))) {
            'nan' => NAN,
            'inf', 'infinity', '+inf', '+infinity' => INF,
            '-inf', '-infinity' => -INF,
            default => (float) $value,
        };
    }

    private function parseDateTime(string $value, ?\DateTimeZone $tz): \DateTimeImmutable
    {
        try {
            // Timestamp_Tz values carry their own offset ("... +0800"),
            // in which case $tz must not override it (pass null).
            return $tz === null ? new \DateTimeImmutable($value) : new \DateTimeImmutable($value, $tz);
        } catch (\Exception $e) {
            throw new LakeException('failed to parse datetime value: ' . $value, 0, $e);
        }
    }

    private function parseBinary(string $value): string
    {
        // In driver mode the server always returns hex regardless of
        // binary_output_format; the setting only matters in display mode.
        $format = $this->httpJsonResultMode === self::JSON_MODE_DISPLAY
            ? $this->binaryOutputFormat
            : self::BINARY_FORMAT_HEX;

        switch ($format) {
            case self::BINARY_FORMAT_BASE64:
                $raw = base64_decode($value, true);
                if ($raw === false) {
                    throw new LakeException('failed to decode binary base64 value');
                }

                return $raw;
            case self::BINARY_FORMAT_UTF8:
                return $value;
            default:
                $raw = @hex2bin($value);
                if ($raw === false) {
                    throw new LakeException('failed to decode binary hex value');
                }

                return $raw;
        }
    }

    private static function normalizeBinaryOutputFormat(string $s): string
    {
        return match (strtoupper(trim($s))) {
            'BASE64' => self::BINARY_FORMAT_BASE64,
            'UTF-8', 'UTF8', 'UTF-8-LOSSY', 'UTF8-LOSSY' => self::BINARY_FORMAT_UTF8,
            default => self::BINARY_FORMAT_HEX,
        };
    }
}
