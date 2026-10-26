<?php

declare(strict_types=1);

namespace TiDBCloud\Lake;

/**
 * Decoded response of POST /v1/query and its follow-up page requests,
 * mirroring lake-go's QueryResponse.
 */
final class QueryResponse
{
    public string $id = '';
    public string $nodeId = '';
    public string $state = '';

    /** Raw session state returned by the server (sent back on the next request). */
    public ?array $session = null;

    /** Server settings affecting result decoding (timezone, binary_output_format, ...). */
    public ?array $settings = null;

    /** @var list<array{name: string, type: string}> */
    public array $schema = [];

    /** Raw JSON cells; cleared after materialization. @var list<list<?string>> */
    public array $data = [];

    /** Typed rows produced by TypeParser. @var list<list<mixed>> */
    public array $typedRows = [];

    /** @var array{code?: int, message?: string, kind?: string, detail?: string}|null */
    public ?array $error = null;

    public ?array $stats = null;

    public string $nextUri = '';
    public string $finalUri = '';
    public string $killUri = '';
    public string $statsUri = '';

    public static function fromArray(array $data): self
    {
        $resp = new self();
        $resp->id = (string) ($data['id'] ?? '');
        $resp->nodeId = (string) ($data['node_id'] ?? '');
        $resp->state = (string) ($data['state'] ?? '');
        $resp->session = is_array($data['session'] ?? null) ? $data['session'] : null;
        $resp->settings = is_array($data['settings'] ?? null) ? $data['settings'] : null;
        $resp->error = is_array($data['error'] ?? null) ? $data['error'] : null;
        $resp->stats = is_array($data['stats'] ?? null) ? $data['stats'] : null;
        $resp->nextUri = (string) ($data['next_uri'] ?? '');
        $resp->finalUri = (string) ($data['final_uri'] ?? '');
        $resp->killUri = (string) ($data['kill_uri'] ?? '');
        $resp->statsUri = (string) ($data['stats_uri'] ?? '');

        foreach ((array) ($data['schema'] ?? []) as $field) {
            if (is_array($field)) {
                $resp->schema[] = [
                    'name' => (string) ($field['name'] ?? ''),
                    'type' => (string) ($field['type'] ?? ''),
                ];
            }
        }
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (is_array($row)) {
                $resp->data[] = array_map(
                    static fn ($cell) => $cell === null ? null : (string) $cell,
                    array_values($row),
                );
            }
        }

        return $resp;
    }

    public function readFinished(): bool
    {
        return $this->nextUri === '' || str_contains($this->nextUri, '/final');
    }

    public function rowCount(): int
    {
        return $this->typedRows !== [] ? count($this->typedRows) : count($this->data);
    }

    /**
     * Whether the result is a DML summary ("number of rows inserted",
     * "number of rows updated", ...) rather than a regular result set.
     */
    public function isDmlSummary(): bool
    {
        return $this->schema !== [] && str_contains($this->schema[0]['name'], 'number of rows');
    }

    /**
     * Number of rows affected by a fully-read DML response.
     *
     * INSERT/UPDATE/DELETE responses report the count through "number of
     * rows ..." columns, mirroring lake-go's parseAffectedRows; MERGE reports
     * one column per action (inserted, updated, deleted), which are summed.
     * Stage-attachment INSERTs return no result schema at all; there the
     * count is only available via stats.write_progress.
     */
    public function affectedRows(): int
    {
        if ($this->isDmlSummary()) {
            $total = 0;
            foreach ($this->schema as $i => $field) {
                if (!str_contains($field['name'], 'number of rows')) {
                    continue;
                }
                $value = $this->typedRows[0][$i] ?? null;
                if (is_int($value) || (is_string($value) && is_numeric($value))) {
                    $total += (int) $value;
                }
            }

            return $total;
        }

        $written = $this->stats['write_progress']['rows'] ?? null;

        return is_numeric($written) ? (int) $written : 0;
    }
}
