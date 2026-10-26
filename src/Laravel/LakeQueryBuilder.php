<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Laravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use TiDBCloud\Lake\Exception\UnsupportedFeatureException;

/**
 * Query builder for TiDB Cloud Lake.
 *
 * @property LakeConnection $connection
 */
class LakeQueryBuilder extends Builder
{
    /**
     * Lake cannot report generated keys (there is no LAST_INSERT_ID()), so
     * the key is resolved before the insert:
     *
     *  1. a key value supplied by the caller is inserted and returned;
     *  2. otherwise, when the connection's "sequences" config maps this table
     *     to a Lake sequence, NEXTVAL() of it is used as the key;
     *  3. otherwise nothing is written and an exception explains the options.
     *
     * Eloquent's create()/save() on incrementing models end up here.
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $key = $sequence ?? 'id';

        if (!isset($values[$key])) {
            $table = (string) $this->from;
            $lakeSequence = ($this->connection->getConfig('sequences') ?? [])[$table] ?? null;
            if (!is_string($lakeSequence) || $lakeSequence === '') {
                throw new UnsupportedFeatureException(sprintf(
                    'TiDB Cloud Lake cannot return generated keys: insert into "%s" needs a "%s" value. '
                    . 'Provide the key, map the table to a Lake sequence in the connection\'s "sequences" config, '
                    . 'or set $incrementing = false on the model.',
                    $table,
                    $key,
                ));
            }
            $values[$key] = $this->connection->nextSequenceValue($lakeSequence);
        } elseif ($values[$key] instanceof Expression) {
            throw new UnsupportedFeatureException(sprintf('insertGetId() cannot return the key "%s" when it is a raw expression.', $key));
        }

        $this->insert($values);

        $id = $values[$key];

        // Like MySQL, a numeric key comes back as an int, but only when that
        // is lossless: '42' does, '042', '1e3' and values beyond PHP_INT_MAX
        // are returned as given.
        return is_string($id) && (string) (int) $id === $id ? (int) $id : $id;
    }
}
