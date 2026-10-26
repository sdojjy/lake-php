<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Laravel\LakeConnection;
use TiDBCloud\Lake\Laravel\LakeServiceProvider;
use TiDBCloud\Lake\Pdo\LakePdo;
use TiDBCloud\Lake\Tests\Support\ResponseLossInjector;

/**
 * Live tests of the Laravel driver: Eloquent and the query builder against a
 * real Lake server. Gated by LAKE_DSN like {@see LakeIntegrationTest}.
 *
 * @group integration
 */
final class LaravelIntegrationTest extends TestCase
{
    private Capsule $capsule;
    private LakeConnection $db;
    private string $dsn;
    private string $table;
    private string $usersTable;
    private string $sequence;

    protected function setUp(): void
    {
        $dsn = getenv('LAKE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('LAKE_DSN is not set');
        }
        $this->dsn = $dsn;

        $suffix = bin2hex(random_bytes(4));
        $this->table = 'lake_php_it_logs_' . $suffix;
        $this->usersTable = 'lake_php_it_users_' . $suffix;
        $this->sequence = 'lake_php_it_seq_' . $suffix;

        $this->capsule = new Capsule();
        LakeServiceProvider::registerDriver($this->capsule->getContainer());
        $this->addConnection('lake', $dsn);
        $this->capsule->getDatabaseManager()->setDefaultConnection('lake');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->db = $this->capsule->getConnection('lake');
        $this->db->statement(
            "CREATE TABLE {$this->table} (id BIGINT, user_id BIGINT, link STRING NULL, flag BOOLEAN NULL,"
            . ' score DOUBLE NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)',
        );
        AttributionLog::$tableName = $this->table;
        LinkOwner::$tableName = $this->usersTable;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->statement("DROP TABLE IF EXISTS {$this->table}");
            $this->db->statement("DROP TABLE IF EXISTS {$this->usersTable}");
            $this->db->statement("DROP SEQUENCE IF EXISTS {$this->sequence}");
            $this->db->disconnect();
        }
        Carbon::setTestNow();
    }

    private function addConnection(string $name, string $dsn): void
    {
        $this->capsule->addConnection([
            'driver' => 'lake',
            'dsn' => $dsn,
            // Laravel's ConnectionFactory always reads "database".
            'database' => Config::fromDsn($dsn)->database,
            'sequences' => [$this->table => $this->sequence],
        ], $name);
    }

    public function testCustomerQuery(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 42, 'link' => 'a', 'created_at' => '2025-12-31 10:00:00']);
        AttributionLog::create(['id' => 2, 'user_id' => 42, 'link' => 'b', 'created_at' => '2026-02-01 08:30:00']);

        $log = AttributionLog::query()->whereKey(1)->where('created_at', '<', '2026-01-01')->first();

        self::assertNotNull($log);
        self::assertSame(1, $log->id);
        self::assertSame(42, $log->user_id);
        self::assertSame('a', $log->link);
        self::assertSame('2025-12-31 10:00:00', $log->getRawOriginal('created_at'));
        self::assertSame('2025-12-31 10:00:00', $log->created_at->format('Y-m-d H:i:s'));

        self::assertNull(AttributionLog::query()->whereKey(2)->where('created_at', '<', '2026-01-01')->first());
    }

    public function testEloquentCrud(): void
    {
        Carbon::setTestNow('2026-03-04 05:06:07');

        $log = AttributionLog::create(['id' => 10, 'user_id' => 1, 'link' => 'x', 'score' => 1.5]);
        self::assertTrue($log->exists);
        self::assertSame('2026-03-04 05:06:07', AttributionLog::find(10)->getRawOriginal('created_at'));

        // String binding against a BIGINT column, as with request input.
        $found = AttributionLog::where('id', '10')->firstOrFail();
        $found->link = 'y';
        $found->save();
        $found->increment('user_id', 5);

        $fresh = $found->fresh();
        self::assertSame('y', $fresh->link);
        self::assertSame(6, $fresh->user_id);
        self::assertSame(1.5, $fresh->score);

        $fresh->delete();
        self::assertNull(AttributionLog::find(10));

        AttributionLog::create(['id' => 11, 'user_id' => 1]);
        AttributionLog::create(['id' => 12, 'user_id' => 1]);
        self::assertSame(2, AttributionLog::destroy([11, 12]));
        self::assertSame(0, AttributionLog::count());
    }

    public function testQueryBuilderReads(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['id' => $i, 'user_id' => $i % 3, 'link' => "l{$i}", 'score' => $i / 2, 'created_at' => '2025-06-15 12:00:00'];
        }
        self::assertTrue($this->db->table($this->table)->insert($rows));

        $query = fn () => $this->db->table($this->table);
        self::assertSame(25, $query()->count());
        self::assertSame(25, $query()->max('id'));
        self::assertEquals(162.5, $query()->sum('score'));
        self::assertTrue($query()->where('link', 'l7')->exists());
        self::assertFalse($query()->where('link', 'nope')->exists());
        self::assertSame(['l1', 'l2', 'l3'], $query()->whereIn('id', [1, 2, 3])->orderBy('id')->pluck('link')->all());
        self::assertSame([21, 22], $query()->orderBy('id')->offset(20)->limit(2)->pluck('id')->all());
        self::assertSame(9, $query()->where('user_id', 1)->where(fn ($q) => $q->where('id', '<', 100)->orWhereNull('link'))->count());

        $page = $query()->orderBy('id')->paginate(10, ['*'], 'page', 3);
        self::assertSame(25, $page->total());
        self::assertSame([21, 22, 23, 24, 25], array_column($page->items(), 'id'));

        $ids = [];
        foreach ($query()->where('id', '<=', 5)->orderBy('id')->cursor() as $row) {
            $ids[] = $row->id;
        }
        self::assertSame([1, 2, 3, 4, 5], $ids);

        $chunked = [];
        $query()->chunkById(10, function ($chunk) use (&$chunked): void {
            $chunked[] = count($chunk);
        });
        self::assertSame([10, 10, 5], $chunked);

        $grouped = $query()->select('user_id', $this->db->raw('count(*) as c'))->groupBy('user_id')->orderBy('user_id')->get();
        self::assertSame([8, 9, 8], $grouped->pluck('c')->all());
    }

    public function testDateBasedWheres(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 1, 'created_at' => '2025-06-15 12:34:56']);
        AttributionLog::create(['id' => 2, 'user_id' => 1, 'created_at' => '2024-01-02 03:04:05']);

        self::assertSame([1], AttributionLog::whereDate('created_at', '2025-06-15')->pluck('id')->all());
        self::assertSame([1], AttributionLog::whereYear('created_at', 2025)->pluck('id')->all());
        self::assertSame([2], AttributionLog::whereMonth('created_at', 1)->pluck('id')->all());
        self::assertSame([2], AttributionLog::whereDay('created_at', 2)->pluck('id')->all());
        self::assertSame([1], AttributionLog::whereTime('created_at', '>', '12:00:00')->pluck('id')->all());
        self::assertSame([1], AttributionLog::where('created_at', '>=', Carbon::parse('2025-01-01'))->pluck('id')->all());
    }

    public function testBooleanColumn(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 1, 'flag' => true]);
        AttributionLog::create(['id' => 2, 'user_id' => 1, 'flag' => false]);

        self::assertSame([1], AttributionLog::where('flag', true)->pluck('id')->all());
        self::assertTrue(AttributionLog::find(1)->flag);
    }

    public function testTransactions(): void
    {
        $this->db->transaction(function (): void {
            AttributionLog::create(['id' => 1, 'user_id' => 1]);
            $this->db->transaction(fn () => AttributionLog::create(['id' => 2, 'user_id' => 1]));
        });
        self::assertSame(2, AttributionLog::count());

        try {
            $this->db->transaction(function (): void {
                AttributionLog::create(['id' => 3, 'user_id' => 1]);
                throw new \DomainException('rollback');
            });
        } catch (\DomainException) {
        }
        self::assertSame(2, AttributionLog::count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testRollingBackANestedTransactionRollsBackTheOuterOne(): void
    {
        try {
            $this->db->transaction(function (): void {
                AttributionLog::create(['id' => 1, 'user_id' => 1]);
                try {
                    $this->db->transaction(function (): void {
                        AttributionLog::create(['id' => 2, 'user_id' => 1]);
                        throw new \DomainException('inner');
                    });
                } catch (\DomainException) {
                }
            });
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('A nested transaction was rolled back', $e->getMessage());
        }

        self::assertSame(0, AttributionLog::count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testCommitFailsAfterAFailedStatement(): void
    {
        // Both abort the transaction, but the server reports "Fail" only for
        // the second: the first is rejected before execution.
        foreach (['SELECT * FROM lake_php_it_no_such_table', 'SELECT 1 / 0'] as $failing) {
            try {
                // Without the driver's check, COMMIT would succeed and
                // silently drop the row.
                $this->db->transaction(function () use ($failing): void {
                    AttributionLog::create(['id' => 1, 'user_id' => 1]);
                    try {
                        $this->db->select($failing);
                    } catch (QueryException) {
                    }
                });
                self::fail('expected PDOException after ' . $failing);
            } catch (\PDOException $e) {
                self::assertStringContainsString('A statement in this transaction failed', $e->getMessage());
            }

            self::assertSame(0, AttributionLog::count());
        }
    }

    public function testEagerLoadWithLimit(): void
    {
        if (!method_exists(QueryBuilder::class, 'groupLimit')) {
            self::markTestSkipped('Per-group limits need Laravel 11+.');
        }
        $this->db->statement("CREATE TABLE {$this->usersTable} (id BIGINT, name STRING NULL)");
        $this->db->table($this->usersTable)->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
        $this->db->table($this->table)->insert([
            ['id' => 1, 'user_id' => 1],
            ['id' => 2, 'user_id' => 1],
            ['id' => 3, 'user_id' => 1],
            ['id' => 4, 'user_id' => 2],
            ['id' => 5, 'user_id' => 2],
        ]);

        $users = LinkOwner::with(['logs' => fn ($query) => $query->orderByDesc('id')->limit(2)])->orderBy('id')->get();

        self::assertSame([[3, 2], [5, 4]], $users->map(fn (LinkOwner $user) => $user->logs->pluck('id')->all())->all());
    }

    public function testSessionTimezoneFollowsTheConnectionConfig(): void
    {
        $this->addConnection('lake_tz', $this->dsn . (str_contains($this->dsn, '?') ? '&' : '?') . 'timezone=Asia/Shanghai');
        $db = $this->capsule->getConnection('lake_tz');
        // The app timezone, in which Laravel writes wall-clock values.
        $appTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');

        try {
            $db->table($this->table)->insert(['id' => 1, 'user_id' => 1, 'created_at' => Carbon::parse('2026-01-02 03:04:05')]);

            $row = $db->selectOne("SELECT created_at, to_unix_timestamp(created_at) AS epoch FROM {$this->table} WHERE id = 1");
            self::assertSame('2026-01-02 03:04:05', $row->created_at);
            self::assertSame(Carbon::parse('2026-01-02 03:04:05', 'Asia/Shanghai')->getTimestamp(), $row->epoch);

            // Server-side now() reads in the same timezone as the app clock.
            $serverNow = Carbon::parse($db->selectOne('SELECT now() AS n')->n);
            self::assertLessThan(60, abs($serverNow->getTimestamp() - time()));
        } finally {
            date_default_timezone_set($appTimezone);
            $db->disconnect();
        }
    }

    public function testEloquentWriteWhoseResponseIsLostIsNotRunAgain(): void
    {
        $injector = new ResponseLossInjector();
        $this->db->setPdo(new LakePdo(Client::fromDsn($this->dsn, $injector)->connect()));
        $injector->loseResponseTo('insert into');

        try {
            AttributionLog::create(['id' => 1, 'user_id' => 1]);
            self::fail('expected QueryException');
        } catch (QueryException $e) {
            self::assertStringContainsString('may or may not have run', $e->getMessage());
        }

        // The server ran it once: neither lake-php's retry nor Laravel's
        // reconnect-and-retry after a lost connection sent it again.
        self::assertSame(1, $injector->timesSent('insert into'));
        self::assertSame(1, AttributionLog::count());
    }

    public function testUpsertUsesMerge(): void
    {
        $this->db->table($this->table)->insert([
            ['id' => 1, 'user_id' => 10, 'link' => 'old'],
            ['id' => 2, 'user_id' => 20, 'link' => 'keep'],
        ]);

        $affected = $this->db->table($this->table)->upsert(
            [
                ['id' => 1, 'user_id' => 99, 'link' => 'new'],
                ['id' => 3, 'user_id' => 30, 'link' => 'inserted'],
            ],
            ['id'],
            ['link'],
        );

        self::assertSame(2, $affected);
        $rows = $this->db->table($this->table)->orderBy('id')->get(['id', 'user_id', 'link'])->map(fn ($r) => (array) $r)->all();
        self::assertSame([
            ['id' => 1, 'user_id' => 10, 'link' => 'new'],
            ['id' => 2, 'user_id' => 20, 'link' => 'keep'],
            ['id' => 3, 'user_id' => 30, 'link' => 'inserted'],
        ], $rows);
    }

    public function testInsertGetIdFromSequence(): void
    {
        $this->db->statement("CREATE SEQUENCE {$this->sequence}");

        $first = AttributionLog::create(['user_id' => 1]);
        $second = AttributionLog::create(['user_id' => 2]);

        self::assertIsInt($first->id);
        self::assertGreaterThan($first->id, $second->id);
        self::assertSame(2, AttributionLog::find($second->id)->user_id);
    }

    public function testInsertGetIdWithoutKeyFailsBeforeWriting(): void
    {
        $this->expectException(LakeException::class);

        try {
            WithoutSequenceLog::create(['user_id' => 1]);
        } finally {
            self::assertSame(0, AttributionLog::count());
        }
    }

    public function testRawStatementsWithNamedBindings(): void
    {
        $this->db->insert("INSERT INTO {$this->table} (id, user_id, link) VALUES (:id, :user, :link)", [
            'id' => 1,
            'user' => 5,
            'link' => "it's",
        ]);

        $row = $this->db->selectOne("SELECT id, link FROM {$this->table} WHERE id = :id", ['id' => 1]);
        self::assertSame("it's", $row->link);
        self::assertSame(1, $this->db->update("UPDATE {$this->table} SET link = ? WHERE id = ?", ['z', 1]));
        self::assertSame(1, $this->db->delete("DELETE FROM {$this->table} WHERE id = ?", [1]));
    }
}

class AttributionLog extends Model
{
    public static string $tableName = '';

    protected $guarded = [];

    public function getTable()
    {
        return static::$tableName;
    }
}

/**
 * Same table under a name that has no sequence mapping.
 */
class WithoutSequenceLog extends AttributionLog
{
    public function getTable()
    {
        return parent::getTable() . '_no_sequence';
    }
}

class LinkOwner extends Model
{
    public static string $tableName = '';

    protected $guarded = [];

    public function getTable()
    {
        return static::$tableName;
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AttributionLog::class, 'user_id');
    }
}
