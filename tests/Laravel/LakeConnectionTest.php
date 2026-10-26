<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Laravel;

use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TiDBCloud\Lake\Exception\UnsupportedFeatureException;
use TiDBCloud\Lake\Laravel\LakeConnection;
use TiDBCloud\Lake\Laravel\LakeQueryBuilder;
use TiDBCloud\Lake\Laravel\LakeQueryGrammar;
use TiDBCloud\Lake\Tests\Laravel\Fixtures\LinkUser;
use TiDBCloud\Lake\Tests\Laravel\Fixtures\UserLinkAttributionLog;
use TiDBCloud\Lake\Tests\Support\FakeLake;

final class LakeConnectionTest extends TestCase
{
    private const LOG_COLUMNS = [
        'id' => 'Int64',
        'user_id' => 'Int64',
        'link' => 'String',
        'created_at' => 'Timestamp',
        'updated_at' => 'Timestamp',
    ];

    private FakeLake $lake;
    private LakeConnection $connection;

    protected function setUp(): void
    {
        $this->lake = new FakeLake();
        $this->connection = $this->connect();
        Carbon::setTestNow('2026-01-02 03:04:05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
    }

    private function connect(array $config = []): LakeConnection
    {
        $connection = new LakeConnection($this->lake->pdo(), 'db', '', $config + ['driver' => 'lake', 'name' => 'lake']);

        $resolver = new ConnectionResolver(['lake' => $connection]);
        $resolver->setDefaultConnection('lake');
        Model::setConnectionResolver($resolver);

        return $connection;
    }

    public function testWiring(): void
    {
        self::assertSame('lake', $this->connection->getDriverName());
        self::assertInstanceOf(LakeQueryGrammar::class, $this->connection->getQueryGrammar());
        self::assertInstanceOf(LakeQueryBuilder::class, $this->connection->table('t'));
        self::assertSame('lake', $this->connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testCustomerEloquentQuery(): void
    {
        $this->lake->result(self::LOG_COLUMNS, [
            ['1', '42', 'https://example.com/a', '2025-12-31 10:00:00.000000', '2025-12-31 10:00:00.000000'],
        ]);

        $log = UserLinkAttributionLog::query()
            ->whereKey(1)
            ->where('created_at', '<', '2026-01-01')
            ->first();

        self::assertSame(
            ["select * from `user_link_attribution_logs` where `user_link_attribution_logs`.`id` = 1 and `created_at` < '2026-01-01' limit 1"],
            $this->lake->sqls(),
        );
        self::assertInstanceOf(UserLinkAttributionLog::class, $log);
        self::assertTrue($log->exists);
        self::assertSame(1, $log->id);
        self::assertSame(42, $log->user_id);
        self::assertSame('2025-12-31 10:00:00', $log->getRawOriginal('created_at'));
        self::assertInstanceOf(Carbon::class, $log->created_at);
        self::assertSame('2025-12-31 10:00:00', $log->created_at->format('Y-m-d H:i:s'));
    }

    public function testQueryBuilderReads(): void
    {
        $this->lake->result(['aggregate' => 'UInt64'], [['3']]);
        $this->lake->result(['link' => 'String'], [['a'], ['b']]);
        $this->lake->result(['exists' => 'Boolean'], [['true']]);

        self::assertSame(3, $this->connection->table('user_link_attribution_logs')->where('user_id', 42)->count());
        self::assertSame(
            ['a', 'b'],
            $this->connection->table('user_link_attribution_logs')->whereIn('id', [1, 2])->orderBy('id')->pluck('link')->all(),
        );
        self::assertTrue($this->connection->table('user_link_attribution_logs')->where('link', 'like', 'https://%')->exists());

        self::assertSame([
            'select count(*) as aggregate from `user_link_attribution_logs` where `user_id` = 42',
            'select `link` from `user_link_attribution_logs` where `id` in (1, 2) order by `id` asc',
            "select exists(select * from `user_link_attribution_logs` where `link` like 'https://%') as `exists`",
        ], $this->lake->sqls());
    }

    public function testCursorAndRawNamedBindings(): void
    {
        $this->lake->result(['id' => 'Int64'], [['1'], ['2']]);
        $this->lake->result(['id' => 'Int64'], [['5']]);

        $ids = [];
        foreach ($this->connection->cursor('select id from t') as $row) {
            $ids[] = $row->id;
        }
        self::assertSame([1, 2], $ids);

        self::assertSame(5, $this->connection->selectOne('select id from t where id = :id', ['id' => 5])->id);
        self::assertSame(['select id from t', 'select id from t where id = 5'], $this->lake->sqls());
    }

    public function testEloquentWritesWithExplicitKey(): void
    {
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->dml(['number of rows updated' => 1]);
        $this->lake->dml(['number of rows deleted' => 1]);

        $log = UserLinkAttributionLog::create(['id' => 7, 'user_id' => 42, 'link' => 'a']);
        self::assertSame(7, $log->id);

        $log->update(['link' => 'b']);
        $log->delete();

        self::assertSame([
            "insert into `user_link_attribution_logs` (`id`, `user_id`, `link`, `updated_at`, `created_at`) values (7, 42, 'a', '2026-01-02 03:04:05', '2026-01-02 03:04:05')",
            "update `user_link_attribution_logs` set `link` = 'b', `user_link_attribution_logs`.`updated_at` = '2026-01-02 03:04:05' where `id` = 7",
            'delete from `user_link_attribution_logs` where `id` = 7',
        ], $this->lake->sqls());
    }

    public function testBuilderWritesReturnAffectedRows(): void
    {
        $this->lake->dml(['number of rows inserted' => 2]);
        $this->lake->dml(['number of rows updated' => 3]);
        $this->lake->dml(['number of rows deleted' => 2]);

        $table = $this->connection->table('t');
        self::assertTrue($table->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]));
        self::assertSame(3, $this->connection->table('t')->where('id', '>', 0)->update(['name' => 'c']));
        self::assertSame(2, $this->connection->table('t')->where('id', '>', 5)->delete());

        self::assertSame([
            "insert into `t` (`id`, `name`) values (1, 'a'), (2, 'b')",
            "update `t` set `name` = 'c' where `id` > 0",
            'delete from `t` where `id` > 5',
        ], $this->lake->sqls());
    }

    public function testInsertGetIdUsesConfiguredSequence(): void
    {
        $this->connection = $this->connect(['sequences' => ['user_link_attribution_logs' => 'log_id_seq']]);
        $this->lake->result(['id' => 'UInt64'], [['100']]);
        $this->lake->dml(['number of rows inserted' => 1]);

        $log = UserLinkAttributionLog::create(['user_id' => 42, 'link' => 'a']);

        self::assertSame(100, $log->id);
        self::assertSame([
            'select nextval(log_id_seq) as id',
            "insert into `user_link_attribution_logs` (`user_id`, `link`, `updated_at`, `created_at`, `id`) values (42, 'a', '2026-01-02 03:04:05', '2026-01-02 03:04:05', 100)",
        ], $this->lake->sqls());
    }

    public function testInsertGetIdReturnsTheKeyLosslessly(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->lake->dml(['number of rows inserted' => 1]);
        }

        $table = fn () => $this->connection->table('t');
        self::assertSame(42, $table()->insertGetId(['id' => '42']));
        self::assertSame('007', $table()->insertGetId(['id' => '007']));
        self::assertSame('1e3', $table()->insertGetId(['id' => '1e3']));
        self::assertSame('18446744073709551615', $table()->insertGetId(['id' => '18446744073709551615']));
    }

    public function testInsertGetIdWithoutKeyOrSequenceWritesNothing(): void
    {
        try {
            UserLinkAttributionLog::create(['user_id' => 42]);
            self::fail('expected UnsupportedFeatureException');
        } catch (UnsupportedFeatureException $e) {
            self::assertStringContainsString('user_link_attribution_logs', $e->getMessage());
            self::assertStringContainsString('sequences', $e->getMessage());
        }

        self::assertSame([], $this->lake->http->requests);
    }

    public function testInvalidSequenceNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection->nextSequenceValue('seq; drop table t');
    }

    public function testUpsertCompilesToMerge(): void
    {
        $this->lake->dml(['number of rows inserted' => 1, 'number of rows updated' => 1]);

        $affected = $this->connection->table('t')->upsert(
            [['id' => 1, 'name' => 'a', 'n' => 1], ['id' => 2, 'name' => 'b', 'n' => 2]],
            ['id'],
            ['name'],
        );

        self::assertSame(2, $affected);
        self::assertSame([
            'merge into `t` using (select 1 as `id`, 1 as `n`, \'a\' as `name` union all select 2, 2, \'b\') as `laravel_upsert_source`'
            . ' on `t`.`id` = `laravel_upsert_source`.`id`'
            . ' when matched then update set `name` = `laravel_upsert_source`.`name`'
            . ' when not matched then insert (`id`, `n`, `name`)'
            . ' values (`laravel_upsert_source`.`id`, `laravel_upsert_source`.`n`, `laravel_upsert_source`.`name`)',
        ], $this->lake->sqls());
    }

    public function testUpsertWithLiteralUpdateValues(): void
    {
        $this->lake->dml(['number of rows inserted' => 0, 'number of rows updated' => 1]);

        $this->connection->table('t')->upsert([['id' => 1, 'n' => 1]], 'id', ['n' => 9]);

        self::assertStringContainsString(
            'when matched then update set `n` = 9 when not matched',
            $this->lake->sqls()[0],
        );
    }

    public function testDateBasedWheres(): void
    {
        $query = $this->connection->table('t')
            ->whereDate('created_at', '2025-06-15')
            ->whereTime('created_at', '>', '12:00:00');

        self::assertSame(
            "select * from `t` where date(`created_at`) = ? and date_format(`created_at`, '%H:%M:%S') > ?",
            $query->toSql(),
        );
    }

    public function testEagerLoadWithLimitUsesRowNumber(): void
    {
        if (!method_exists(Builder::class, 'groupLimit')) {
            self::markTestSkipped('Per-group limits need Laravel 11+.');
        }
        $this->lake->result(['id' => 'Int64'], [['42'], ['43']]);
        $this->lake->result(['id' => 'Int64', 'user_id' => 'Int64', 'laravel_row' => 'UInt64'], [['1', '42', '1'], ['3', '43', '1']]);

        $users = LinkUser::with(['logs' => fn ($query) => $query->orderBy('id')->limit(1)])->get();

        self::assertSame([
            'select * from `users`',
            'select * from (select *, row_number() over (partition by `user_link_attribution_logs`.`user_id` order by `id` asc) as `laravel_row`'
            . ' from `user_link_attribution_logs` where `user_link_attribution_logs`.`user_id` in (42, 43))'
            . ' as `laravel_table` where `laravel_row` <= 1 order by `laravel_row`',
        ], $this->lake->sqls());
        self::assertSame([1], $users[0]->logs->pluck('id')->all());
        self::assertSame([3], $users[1]->logs->pluck('id')->all());
    }

    public function testUnsupportedFeaturesFailBeforeSending(): void
    {
        $table = fn () => $this->connection->table('t');
        $attempts = [
            'insertOrIgnore' => fn () => $table()->insertOrIgnore(['id' => 1]),
            'lockForUpdate' => fn () => $table()->lockForUpdate()->get(),
            'sharedLock' => fn () => $table()->sharedLock()->get(),
            'JSON where' => fn () => $table()->where('meta->a', 1)->get(),
            'JSON select' => fn () => $table()->select('meta->a')->get(),
            'whereJsonContains' => fn () => $table()->whereJsonContains('tags', 'x')->get(),
            'whereJsonLength' => fn () => $table()->whereJsonLength('tags', 2)->get(),
            'JSON update' => fn () => $table()->update(['meta->a' => 1]),
        ];

        foreach ($attempts as $feature => $attempt) {
            try {
                $attempt();
                self::fail('expected UnsupportedFeatureException for ' . $feature);
            } catch (UnsupportedFeatureException) {
            }
        }

        self::assertSame([], $this->lake->http->requests);
    }

    public function testNestedTransactionsUseNoSavepoints(): void
    {
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->ok();

        $this->connection->transaction(function (LakeConnection $connection): void {
            $connection->transaction(function (LakeConnection $connection): void {
                $connection->insert('insert into t values (?)', [1]);
            });
        });

        self::assertSame(['BEGIN', 'insert into t values (1)', 'COMMIT'], $this->lake->sqls());
    }

    public function testRollingBackANestedTransactionRollsBackTheOuterOne(): void
    {
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->ok();

        try {
            $this->connection->transaction(function (LakeConnection $connection): void {
                $connection->insert('insert into t values (?)', ['a']);
                try {
                    $connection->transaction(function (LakeConnection $connection): void {
                        $connection->insert('insert into t values (?)', ['b']);
                        throw new \DomainException('inner');
                    });
                } catch (\DomainException) {
                }
            });
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('A nested transaction was rolled back', $e->getMessage());
            self::assertStringContainsString('The transaction has been rolled back.', $e->getMessage());
        }

        // On MySQL only 'b' would be undone; Lake cannot, so nothing commits.
        self::assertSame(['BEGIN', "insert into t values ('a')", "insert into t values ('b')", 'ROLLBACK'], $this->lake->sqls());
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertFalse($this->connection->getPdo()->inTransaction());
    }

    public function testFailedCommitDropsItsAfterCommitCallbacks(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();

        $calls = [];
        try {
            $this->connection->transaction(function (LakeConnection $connection) use (&$calls): void {
                $connection->afterCommit(function () use (&$calls): void {
                    $calls[] = 'afterCommit of the rolled-back transaction';
                });
                if (method_exists($connection, 'afterRollBack')) {
                    $connection->afterRollBack(function () use (&$calls): void {
                        $calls[] = 'afterRollBack';
                    });
                }
                try {
                    $connection->transaction(fn () => throw new \DomainException('inner'));
                } catch (\DomainException) {
                }
            });
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }

        $this->connection->transaction(function (LakeConnection $connection) use (&$calls): void {
            $connection->afterCommit(function () use (&$calls): void {
                $calls[] = 'afterCommit';
            });
        });

        // afterRollBack() exists on Laravel 12 only.
        $expected = method_exists($this->connection, 'afterRollBack') ? ['afterRollBack', 'afterCommit'] : ['afterCommit'];
        self::assertSame($expected, $calls);
        self::assertSame(['BEGIN', 'ROLLBACK', 'BEGIN', 'COMMIT'], $this->lake->sqls());
    }

    public function testFailedManualCommitLeavesTheConnectionUsable(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();

        $fired = [];
        $this->connection->beginTransaction();
        $this->connection->afterCommit(function () use (&$fired): void {
            $fired[] = 'afterCommit of the rolled-back transaction';
        });
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        try {
            $this->connection->commit();
            self::fail('expected PDOException');
        } catch (\PDOException) {
            // Only logged, no rollBack(), e.g. in a long-running worker.
        }
        self::assertSame(0, $this->connection->transactionLevel());

        // The next unit of work is a real transaction again.
        $this->connection->beginTransaction();
        $this->connection->insert('insert into t values (1)');
        $this->connection->rollBack();
        $this->connection->transaction(fn () => null);

        self::assertSame([], $fired);
        self::assertSame(
            ['BEGIN', 'ROLLBACK', 'BEGIN', 'insert into t values (1)', 'ROLLBACK', 'BEGIN', 'COMMIT'],
            $this->lake->sqls(),
        );
    }

    public function testRollingBackAfterAFailedManualCommitStillWorks(): void
    {
        $this->lake->ok();
        $this->lake->ok();

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        try {
            $this->connection->commit();
            self::fail('expected PDOException');
        } catch (\PDOException) {
            $this->connection->rollBack();
        }

        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame(['BEGIN', 'ROLLBACK'], $this->lake->sqls());
    }

    public function testStatementsAfterANestedRollbackAreRejected(): void
    {
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->ok();

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->rollBack();

        try {
            $this->connection->insert('insert into t values (1)');
            self::fail('expected QueryException');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('Roll the transaction back before running other statements.', $e->getMessage());
        }

        $this->connection->rollBack();
        self::assertSame(0, $this->connection->transactionLevel());

        // The next transaction starts clean.
        $this->connection->transaction(fn (LakeConnection $connection) => $connection->insert('insert into t values (2)'));

        self::assertSame(['BEGIN', 'ROLLBACK', 'BEGIN', 'insert into t values (2)', 'COMMIT'], $this->lake->sqls());
    }

    public function testCommitFailsAfterAFailedStatement(): void
    {
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->error(1025, 'Unknown table');
        $this->lake->ok();

        try {
            // A tolerated failure: on Lake it aborts the transaction, and
            // COMMIT would otherwise silently roll back the insert.
            $this->connection->transaction(function (LakeConnection $connection): void {
                $connection->insert('insert into t values (1)');
                try {
                    $connection->select('select * from missing');
                } catch (\Illuminate\Database\QueryException) {
                }
            });
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('A statement in this transaction failed', $e->getMessage());
        }

        self::assertSame(['BEGIN', 'insert into t values (1)', 'select * from missing', 'ROLLBACK'], $this->lake->sqls());
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->lake->ok();
        $this->lake->ok();

        try {
            $this->connection->transaction(function (): void {
                throw new \DomainException('boom');
            });
            self::fail('expected DomainException');
        } catch (\DomainException) {
        }

        self::assertSame(['BEGIN', 'ROLLBACK'], $this->lake->sqls());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function testLaravelDoesNotRerunAWriteThatMayHaveRun(): void
    {
        // The connection drops after the INSERT was sent (cURL error 56).
        $this->lake->http->push(static fn (RequestInterface $request) => throw new RequestException(
            'cURL error 56: Recv failure: Connection reset by peer',
            $request,
            null,
            null,
            ['request_size' => 312],
        ));
        $this->lake->dml(['number of rows inserted' => 1]);
        // Laravel re-runs a query after an error that looks like a lost connection.
        $this->connection->setReconnector(fn (LakeConnection $connection) => $connection->setPdo($this->lake->pdo()));

        try {
            $this->connection->insert('insert into t values (?)', [7]);
            self::fail('expected QueryException');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('may or may not have run', $e->getMessage());
        }

        self::assertSame(['insert into t values (7)'], $this->lake->sqls());
    }

    public function testQueryErrorsBecomeLaravelQueryExceptions(): void
    {
        $this->lake->error(1025, 'Unknown table');

        try {
            $this->connection->table('missing')->get();
            self::fail('expected QueryException');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame(1025, $e->getCode());
            self::assertStringContainsString('Unknown table', $e->getMessage());
            self::assertStringContainsString('select * from `missing`', $e->getMessage());
        }
    }

    public function testSchemaBuilderIsNotSupported(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->connection->getSchemaBuilder();
    }
}
