<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

use TiDBCloud\Lake\Exception\LakeException;

/**
 * A single result row. Values are accessible by column index or column name:
 *
 *   $row[0];            // by index
 *   $row['name'];       // by column name
 *   $row->get('name');
 *   $row->toArray();    // ['col' => value, ...]
 *
 * @implements \ArrayAccess<int|string, mixed>
 * @implements \IteratorAggregate<int, mixed>
 */
final class Row implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var array<string, int> */
    private array $nameIndex;

    /**
     * @param list<string> $names
     * @param list<mixed> $values
     */
    public function __construct(
        private readonly array $names,
        private readonly array $values,
    ) {
        $this->nameIndex = array_flip($names);
    }

    public function get(int|string $key): mixed
    {
        return $this->values[$this->index($key)];
    }

    public function has(int|string $key): bool
    {
        if (is_int($key)) {
            return array_key_exists($key, $this->values);
        }

        return isset($this->nameIndex[$key]);
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return $this->names;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_combine($this->names, $this->values);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LakeException('Row is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LakeException('Row is read-only');
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function index(int|string $key): int
    {
        if (is_int($key)) {
            if (!array_key_exists($key, $this->values)) {
                throw new LakeException('column index out of range: ' . $key);
            }

            return $key;
        }
        if (!isset($this->nameIndex[$key])) {
            throw new LakeException('unknown column: ' . $key);
        }

        return $this->nameIndex[$key];
    }
}
