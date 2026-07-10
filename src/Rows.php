<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

/**
 * Iterable result set. Rows fetched so far are buffered, additional pages
 * are pulled lazily via `next_uri` while iterating; `final_uri` is called
 * once the result is fully read or {@see Rows::close()} is invoked.
 *
 * @implements \Iterator<int, Row>
 */
final class Rows implements \Iterator
{
    /** @var list<list<mixed>> */
    private array $rows;

    /** @var list<string> */
    private array $columnNames;

    private QueryResponse $response;
    private int $pos = 0;
    private bool $closed = false;

    public function __construct(
        private readonly Client $client,
        QueryResponse $response,
    ) {
        $this->response = $response;
        $this->rows = $response->typedRows;
        $this->columnNames = array_column($response->schema, 'name');
        if ($response->readFinished()) {
            $this->close();
        }
    }

    /** @return list<array{name: string, type: string}> */
    public function schema(): array
    {
        return $this->response->schema;
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return $this->columnNames;
    }

    public function stats(): ?array
    {
        return $this->response->stats;
    }

    /** @return list<Row> */
    public function fetchAll(): array
    {
        $all = [];
        foreach ($this as $row) {
            $all[] = $row;
        }

        return $all;
    }

    /**
     * Releases the query on the server (calls final_uri). Safe to call
     * multiple times; called automatically once the result is fully read.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->client->closeQuery($this->response);
    }

    public function current(): Row
    {
        return new Row($this->columnNames, $this->rows[$this->pos]);
    }

    public function key(): int
    {
        return $this->pos;
    }

    public function next(): void
    {
        $this->pos++;
    }

    public function rewind(): void
    {
        // All fetched rows stay buffered, so rewinding is always possible.
        $this->pos = 0;
    }

    public function valid(): bool
    {
        while ($this->pos >= count($this->rows) && !$this->response->readFinished()) {
            $next = $this->client->pollQuery($this->response->nextUri);
            // Later pages may omit final/kill URIs; keep the known ones.
            if ($next->finalUri === '') {
                $next->finalUri = $this->response->finalUri;
            }
            if ($next->killUri === '') {
                $next->killUri = $this->response->killUri;
            }
            $this->response = $next;
            if ($next->typedRows !== []) {
                $this->rows = array_merge($this->rows, $next->typedRows);
            }
            if ($this->response->readFinished()) {
                $this->close();
            }
        }

        return $this->pos < count($this->rows);
    }
}
