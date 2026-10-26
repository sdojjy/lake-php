<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Laravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use TiDBCloud\Lake\Exception\UnsupportedFeatureException;

/**
 * Query grammar for TiDB Cloud Lake.
 *
 * Lake accepts MySQL-style backtick identifiers and most of the SQL Laravel's
 * MySQL grammar generates, so only the statements Lake lacks are changed.
 */
class LakeQueryGrammar extends MySqlGrammar
{
    /**
     * Lake has no SAVEPOINT, so nested DB::transaction() calls join the
     * outer transaction; see {@see LakeConnection::performRollBack()} for
     * what rolling one back does.
     */
    public function supportsSavepoints()
    {
        return false;
    }

    /**
     * Per-group limits (Laravel 11+, e.g. eager loading with limit()) always
     * use row_number(), which Lake supports. MySQL's check would call
     * isMaria(), which only MySqlConnection has, and would pick the MySQL
     * 5.7 fallback built on user variables, which Lake lacks.
     */
    public function useLegacyGroupLimit(Builder $query)
    {
        return false;
    }

    /**
     * Compiles upsert() to MERGE INTO, keeping MySQL's semantics: matched
     * rows only get the $update columns, unmatched rows are inserted.
     *
     * The bindings Laravel passes are the insert values row by row followed
     * by the values of associative $update entries; the placeholders below
     * appear in that same order.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        $table = $this->wrapTable($query->from);
        $source = $this->wrap('laravel_upsert_source');
        $columns = array_keys(reset($values));

        $rows = [];
        foreach ($values as $i => $record) {
            $fields = [];
            foreach ($columns as $column) {
                $parameter = $this->parameter($record[$column]);
                $fields[] = $i === array_key_first($values) ? $parameter . ' as ' . $this->wrap($column) : $parameter;
            }
            $rows[] = 'select ' . implode(', ', $fields);
        }

        $on = implode(' and ', array_map(
            fn (string $column): string => $table . '.' . $this->wrap($column) . ' = ' . $source . '.' . $this->wrap($column),
            $uniqueBy,
        ));

        $assignments = [];
        foreach ($update as $key => $value) {
            $assignments[] = is_numeric($key)
                ? $this->wrap($value) . ' = ' . $source . '.' . $this->wrap($value)
                : $this->wrap($key) . ' = ' . $this->parameter($value);
        }

        $sourceColumns = implode(', ', array_map(fn (string $column): string => $source . '.' . $this->wrap($column), $columns));

        return "merge into {$table} using (" . implode(' union all ', $rows) . ") as {$source} on {$on}"
            . ' when matched then update set ' . implode(', ', $assignments)
            . ' when not matched then insert (' . $this->columnize($columns) . ") values ({$sourceColumns})";
    }

    /**
     * Lake has no TIME() function; compare the formatted time of day instead
     * (zero-padded 'HH:MM:SS' strings order like times).
     */
    protected function whereTime(Builder $query, $where)
    {
        return 'date_format(' . $this->wrap($where['column']) . ", '%H:%M:%S') "
            . $where['operator'] . ' ' . $this->parameter($where['value']);
    }

    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new UnsupportedFeatureException('insertOrIgnore() is not supported by TiDB Cloud Lake.');
    }

    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        throw new UnsupportedFeatureException('insertOrIgnoreUsing() is not supported by TiDB Cloud Lake.');
    }

    protected function compileLock(Builder $query, $value)
    {
        throw new UnsupportedFeatureException('Row locks (lockForUpdate(), sharedLock(), lock()) are not supported by TiDB Cloud Lake.');
    }

    /**
     * Every MySQL JSON operation (col->path selectors, whereJson*(), JSON
     * updates) compiles through here, and Lake has none of the MySQL JSON
     * functions they use.
     */
    protected function wrapJsonFieldAndPath($column)
    {
        throw new UnsupportedFeatureException(sprintf(
            'MySQL JSON operations (on "%s") are not supported by TiDB Cloud Lake; use Lake\'s JSON functions in a raw expression instead.',
            $column,
        ));
    }
}
