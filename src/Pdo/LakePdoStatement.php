<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Pdo;

use TiDBCloud\Lake\QueryResponse;
use TiDBCloud\Lake\TypeParser;

/**
 * PDOStatement-compatible statement returned by {@see LakePdo::prepare()}.
 *
 * Bound values are interpolated client-side as typed SQL literals (the Lake
 * HTTP API has no server-side prepared statements). execute() runs the query
 * to completion and buffers the whole result, like pdo_mysql's default
 * buffered mode.
 *
 * Fetched values follow PDO conventions rather than {@see TypeParser}'s:
 * Date/Timestamp columns come back as 'Y-m-d' / 'Y-m-d H:i:s[.u]' strings in
 * the server's timezone, so the stored wall-clock value round-trips.
 */
final class LakePdoStatement extends \PDOStatement
{
    /** @var array<int|string, mixed> values from bindValue(), keyed by 1-based position or name */
    private array $values = [];

    /** @var array<int|string, array{0: mixed, 1: int}> references from bindParam() */
    private array $references = [];

    private int $fetchMode;

    /** @var list<mixed> */
    private array $fetchArgs = [];

    /** @var list<array{name: string, type: string}> */
    private array $schema = [];

    /** @var list<string> */
    private array $columns = [];

    /** @var list<list<mixed>> */
    private array $rows = [];

    private int $cursor = 0;
    private int $affectedRows = 0;
    private bool $executed = false;

    public function __construct(
        private readonly LakePdo $pdo,
        string $sql,
    ) {
        // parent::__construct() does not exist, so PDOStatement::$queryString
        // must be set here: reading it uninitialized is a fatal error. As
        // with PDO, it cannot be written again afterwards.
        $this->queryString = $sql;
        $this->fetchMode = (int) $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
    }

    public function sql(): string
    {
        return $this->queryString;
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->values[self::paramKey($param)] = self::coerce($value, $type);

        return true;
    }

    public function bindParam(
        int|string $param,
        mixed &$var,
        int $type = \PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        $this->references[self::paramKey($param)] = [&$var, $type];

        return true;
    }

    public function bindColumn(
        int|string $column,
        mixed &$var,
        int $type = \PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        throw LakePdo::error('IM001', 'Driver does not support this function: bindColumn()');
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->values = [];
            $this->references = [];
            foreach ($params as $key => $value) {
                $this->values[is_int($key) ? $key + 1 : self::paramKey($key)] = $value;
            }
        }

        $values = $this->values;
        foreach ($this->references as $key => $reference) {
            $values[$key] = self::coerce($reference[0], $reference[1]);
        }

        $this->load($this->pdo->run($this->queryString, self::sqlParams($values)));

        return true;
    }

    /**
     * Like PDO, this mirrors the fetch mode set by setFetchMode() and returns
     * true. Supported modes: FETCH_ASSOC, FETCH_NUM, FETCH_BOTH, FETCH_OBJ,
     * FETCH_COLUMN.
     */
    #[\ReturnTypeWillChange]
    public function setFetchMode(int $mode, mixed ...$args)
    {
        self::assertFetchMode($mode);
        $this->fetchMode = $mode;
        $this->fetchArgs = $args;

        return true;
    }

    public function fetch(
        int $mode = \PDO::FETCH_DEFAULT,
        int $cursorOrientation = \PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        [$mode, $args] = $this->resolveMode($mode, []);

        return $this->shape($this->rows[$this->cursor++], $mode, $args);
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        [$mode, $args] = $this->resolveMode($mode, $args);

        $all = [];
        while ($this->cursor < count($this->rows)) {
            $all[] = $this->shape($this->rows[$this->cursor++], $mode, $args);
        }

        return $all;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }

        return $this->shape($this->rows[$this->cursor++], \PDO::FETCH_COLUMN, [$column]);
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        $row = $this->shape($this->rows[$this->cursor++], \PDO::FETCH_ASSOC, []);
        if ($class === null || $class === 'stdClass') {
            return (object) $row;
        }

        $object = new $class(...$constructorArgs);
        foreach ($row as $name => $value) {
            $object->{$name} = $value;
        }

        return $object;
    }

    /**
     * Affected rows for DML, number of rows for result sets.
     */
    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function getColumnMeta(int $column): array|false
    {
        if (!isset($this->schema[$column])) {
            return false;
        }

        return [
            'name' => $this->schema[$column]['name'],
            'native_type' => $this->schema[$column]['type'],
            'pdo_type' => \PDO::PARAM_STR,
            'flags' => [],
            'table' => '',
            'len' => -1,
            'precision' => 0,
        ];
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;

        return true;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function errorCode(): ?string
    {
        return $this->executed ? '00000' : null;
    }

    public function errorInfo(): array
    {
        return [$this->executed ? '00000' : '', null, null];
    }

    public function getAttribute(int $name): mixed
    {
        return null;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function debugDumpParams(): ?bool
    {
        echo 'SQL: [', strlen($this->queryString), '] ', $this->queryString, PHP_EOL;

        return null;
    }

    public function getIterator(): \Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }

    private function load(QueryResponse $response): void
    {
        $this->executed = true;
        $this->cursor = 0;

        // INSERT/UPDATE/DELETE/MERGE return a summary row, not a result set.
        if ($response->isDmlSummary() || $response->schema === []) {
            $this->schema = [];
            $this->columns = [];
            $this->rows = [];
            $this->affectedRows = $response->affectedRows();

            return;
        }

        $this->schema = $response->schema;
        $this->columns = array_column($response->schema, 'name');
        $this->rows = $this->convertRows($response);
        $this->affectedRows = count($this->rows);
    }

    /**
     * @return list<list<mixed>>
     */
    private function convertRows(QueryResponse $response): array
    {
        $stringify = (bool) $this->pdo->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES);
        $types = array_map(
            static fn (array $field): string => TypeParser::normalize(TypeParser::parseTypeDesc($field['type']))['name'],
            $response->schema,
        );

        $rows = [];
        foreach ($response->typedRows as $row) {
            foreach ($row as $i => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $row[$i] = self::formatDateTime($value, $types[$i]);
                } elseif ($stringify && $value !== null && !is_string($value)) {
                    $row[$i] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function formatDateTime(\DateTimeInterface $value, string $type): string
    {
        if ($type === 'Date') {
            return $value->format('Y-m-d');
        }
        $format = $value->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u';

        return $type === 'Timestamp_Tz' ? $value->format($format . 'P') : $value->format($format);
    }

    /**
     * @param list<mixed> $row
     * @param list<mixed> $args
     */
    private function shape(array $row, int $mode, array $args): mixed
    {
        switch ($mode) {
            case \PDO::FETCH_NUM:
                return $row;
            case \PDO::FETCH_ASSOC:
                return $this->assoc($row);
            case \PDO::FETCH_OBJ:
                return (object) $this->assoc($row);
            case \PDO::FETCH_BOTH:
                $both = [];
                foreach ($row as $i => $value) {
                    $both[$this->columns[$i]] = $value;
                    $both[$i] = $value;
                }

                return $both;
            case \PDO::FETCH_COLUMN:
                $column = (int) ($args[0] ?? 0);
                if (!array_key_exists($column, $row)) {
                    throw LakePdo::error('HY000', 'Invalid column index');
                }

                return $row[$column];
        }

        throw LakePdo::error('HY000', 'Unsupported fetch mode: ' . $mode);
    }

    /**
     * @param list<mixed> $row
     * @return array<string, mixed>
     */
    private function assoc(array $row): array
    {
        // Duplicate column names: the last one wins, as with PDO.
        $assoc = [];
        foreach ($row as $i => $value) {
            $assoc[$this->columns[$i]] = $value;
        }

        return $assoc;
    }

    /**
     * @param list<mixed> $args
     * @return array{0: int, 1: list<mixed>}
     */
    private function resolveMode(int $mode, array $args): array
    {
        if ($mode === \PDO::FETCH_DEFAULT) {
            return [$this->fetchMode, $this->fetchArgs];
        }
        self::assertFetchMode($mode);

        return [$mode, $args];
    }

    private static function assertFetchMode(int $mode): void
    {
        if (!in_array($mode, [\PDO::FETCH_ASSOC, \PDO::FETCH_NUM, \PDO::FETCH_BOTH, \PDO::FETCH_OBJ, \PDO::FETCH_COLUMN], true)) {
            throw LakePdo::error('HY000', 'Unsupported fetch mode: ' . $mode);
        }
    }

    private static function paramKey(int|string $param): int|string
    {
        return is_int($param) ? $param : ltrim($param, ':');
    }

    /**
     * Applies the PDO::PARAM_* type. PARAM_STR keeps the PHP type so that
     * numbers are interpolated as numeric literals, not quoted strings.
     */
    private static function coerce(mixed $value, int $type): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($type & ~\PDO::PARAM_INPUT_OUTPUT) {
            case \PDO::PARAM_NULL:
                return null;
            case \PDO::PARAM_BOOL:
                return (bool) $value;
            case \PDO::PARAM_INT:
                if (is_bool($value)) {
                    return (int) $value;
                }
                if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                    return TypeParser::intOrString($value);
                }

                return $value;
            case \PDO::PARAM_LOB:
                return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
            default:
                return $value;
        }
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int|string, mixed>|null
     */
    private static function sqlParams(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $positional = array_filter(array_keys($values), 'is_int');
        if ($positional === []) {
            return $values;
        }
        if (count($positional) !== count($values)) {
            throw LakePdo::error('HY093', 'Invalid parameter number: mixed named and positional parameters');
        }
        ksort($values);

        return array_values($values);
    }
}
