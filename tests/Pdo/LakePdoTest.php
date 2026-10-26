<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Pdo;

use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Exception\QueryException;
use TiDBCloud\Lake\Exception\QueryOutcomeUnknownException;
use TiDBCloud\Lake\Pdo\LakePdo;
use TiDBCloud\Lake\Pdo\LakePdoStatement;
use TiDBCloud\Lake\Tests\Support\FakeLake;

final class LakePdoTest extends TestCase
{
    private FakeLake $lake;

    protected function setUp(): void
    {
        $this->lake = new FakeLake();
    }

    public function testIsAPdo(): void
    {
        $pdo = $this->lake->pdo();

        self::assertInstanceOf(\PDO::class, $pdo);
        self::assertInstanceOf(\PDOStatement::class, $pdo->prepare('SELECT 1'));
        self::assertSame('lake', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(Client::VERSION, $pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION));
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testBoundValuesAreInterpolatedAsTypedLiterals(): void
    {
        $this->lake->result(['id' => 'Int64']);
        $pdo = $this->lake->pdo();

        // The way Laravel's Connection::bindValues() binds.
        $statement = $pdo->prepare('select * from t where id = ? and name = ? and price > ? and note = ?');
        $statement->bindValue(1, 5, \PDO::PARAM_INT);
        $statement->bindValue(2, "it's", \PDO::PARAM_STR);
        $statement->bindValue(3, 1.5, \PDO::PARAM_STR);
        $statement->bindValue(4, null, \PDO::PARAM_STR);
        self::assertTrue($statement->execute());

        self::assertSame(
            ["select * from t where id = 5 and name = 'it\\'s' and price > 1.5 and note = NULL"],
            $this->lake->sqls(),
        );
    }

    public function testNamedParamsAndExecuteArguments(): void
    {
        $this->lake->result(['a' => 'Int64']);
        $this->lake->result(['a' => 'String']);
        $pdo = $this->lake->pdo();

        $statement = $pdo->prepare('select :a as a');
        $statement->bindValue(':a', '7', \PDO::PARAM_INT);
        $statement->execute();

        $pdo->prepare('select ? as a')->execute(['x']);

        $value = 'ignored';
        $byRef = $pdo->prepare('select ? as a');
        $byRef->bindParam(1, $value);
        $value = 'late';
        $this->lake->result(['a' => 'String']);
        $byRef->execute();

        self::assertSame(['select 7 as a', "select 'x' as a", "select 'late' as a"], $this->lake->sqls());
    }

    public function testMixingNamedAndPositionalParamsFails(): void
    {
        $statement = $this->lake->pdo()->prepare('select ?, :b');
        $statement->bindValue(1, 1);
        $statement->bindValue('b', 2);

        $this->expectException(\PDOException::class);
        $statement->execute();
    }

    public function testFetchModesAndPdoStyleValues(): void
    {
        $columns = ['id' => 'Int64', 'name' => 'String', 'd' => 'Date', 'ts' => 'Timestamp', 'n' => 'Nullable(Int32)'];
        $rows = [
            ['1', 'alice', '2024-05-01', '2024-05-01 10:20:30.000000', null],
            ['2', 'bob', '2024-05-02', '2024-05-02 08:00:00.123456', '3'],
        ];
        $pdo = $this->lake->pdo();

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1');
        self::assertSame(2, $statement->rowCount());
        self::assertSame(5, $statement->columnCount());
        self::assertSame('Timestamp', $statement->getColumnMeta(3)['native_type']);
        self::assertSame(
            ['id' => 1, 0 => 1, 'name' => 'alice', 1 => 'alice', 'd' => '2024-05-01', 2 => '2024-05-01',
                'ts' => '2024-05-01 10:20:30', 3 => '2024-05-01 10:20:30', 'n' => null, 4 => null],
            $statement->fetch(),
        );
        self::assertSame([2, 'bob', '2024-05-02', '2024-05-02 08:00:00.123456', 3], $statement->fetch(\PDO::FETCH_NUM));
        self::assertFalse($statement->fetch());

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1', \PDO::FETCH_OBJ);
        $all = $statement->fetchAll();
        self::assertEquals((object) ['id' => 1, 'name' => 'alice', 'd' => '2024-05-01', 'ts' => '2024-05-01 10:20:30', 'n' => null], $all[0]);
        self::assertCount(2, $all);

        $this->lake->result($columns, $rows);
        self::assertSame(['alice', 'bob'], $pdo->query('select 1')->fetchAll(\PDO::FETCH_COLUMN, 1));

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1');
        self::assertSame(1, $statement->fetchColumn());
        self::assertSame('bob', $statement->fetchObject()->name);

        $this->lake->result($columns, $rows);
        $names = [];
        foreach ($pdo->query('select 1', \PDO::FETCH_ASSOC) as $row) {
            $names[] = $row['name'];
        }
        self::assertSame(['alice', 'bob'], $names);
    }

    public function testStringifyFetches(): void
    {
        $this->lake->result(['id' => 'Int64', 'ok' => 'Boolean', 'f' => 'Float64'], [['1', 'true', '1.5']]);
        $pdo = $this->lake->pdo([\PDO::ATTR_STRINGIFY_FETCHES => true]);

        self::assertSame(['1', '1', '1.5'], $pdo->query('select 1')->fetch(\PDO::FETCH_NUM));
    }

    public function testUnsupportedFetchModeFails(): void
    {
        $this->expectException(\PDOException::class);
        $this->lake->pdo()->prepare('select 1')->setFetchMode(\PDO::FETCH_KEY_PAIR);
    }

    public function testDmlReportsAffectedRowsWithoutResultSet(): void
    {
        $this->lake->dml(['number of rows updated' => 3]);
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        $statement = $pdo->prepare('update t set a = ?');
        $statement->execute([1]);
        self::assertSame(3, $statement->rowCount());
        self::assertSame(0, $statement->columnCount());
        self::assertSame([], $statement->fetchAll());

        self::assertSame(0, $pdo->exec('create table t (a int)'));
        self::assertSame(['update t set a = 1', 'create table t (a int)'], $this->lake->sqls());
    }

    public function testTransactions(): void
    {
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        self::assertFalse($pdo->inTransaction());
        self::assertTrue($pdo->beginTransaction());
        self::assertTrue($pdo->inTransaction());
        try {
            $pdo->beginTransaction();
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }
        self::assertTrue($pdo->commit());
        self::assertFalse($pdo->inTransaction());

        $pdo->beginTransaction();
        $pdo->rollBack();
        self::assertFalse($pdo->inTransaction());

        self::assertSame(['BEGIN', 'COMMIT', 'BEGIN', 'ROLLBACK'], $this->lake->sqls());

        $this->expectException(\PDOException::class);
        $pdo->commit();
    }

    public function testTransactionStateFollowsTheServer(): void
    {
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        // BEGIN / COMMIT sent as plain statements, e.g. DB::unprepared().
        $pdo->exec('BEGIN');
        self::assertTrue($pdo->inTransaction());
        try {
            $pdo->beginTransaction();
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }
        $pdo->exec('COMMIT');
        self::assertFalse($pdo->inTransaction());

        self::assertTrue($pdo->beginTransaction());
        self::assertTrue($pdo->commit());

        self::assertSame(['BEGIN', 'COMMIT', 'BEGIN', 'COMMIT'], $this->lake->sqls());
    }

    public function testRollbackOnlyTransaction(): void
    {
        $this->lake->ok();
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        $pdo->beginTransaction();
        $pdo->setRollbackOnly('Gone.');
        try {
            $pdo->prepare('insert into t values (1)')->execute();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame('25000', $e->errorInfo[0]);
            self::assertSame('Gone. Roll the transaction back before running other statements.', $e->getMessage());
        }
        try {
            $pdo->commit();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame('Gone. The transaction has been rolled back.', $e->getMessage());
        }
        self::assertFalse($pdo->inTransaction());

        self::assertSame(['BEGIN', 'ROLLBACK'], $this->lake->sqls());
    }

    public function testFailedStatementMakesTheTransactionRollbackOnly(): void
    {
        $this->lake->ok();
        $this->lake->error(1025, 'Unknown table');
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->error(1025, 'Unknown table');
        $pdo = $this->lake->pdo();

        $pdo->beginTransaction();
        try {
            $pdo->exec('select * from missing');
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }
        // A plain ROLLBACK still ends it, and the next transaction is clean.
        $pdo->exec('ROLLBACK');
        self::assertFalse($pdo->inTransaction());
        $pdo->exec('BEGIN');
        $pdo->exec('COMMIT');

        // Outside a transaction a failure has no lasting effect.
        try {
            $pdo->exec('select * from missing');
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }

        self::assertSame(
            ['BEGIN', 'select * from missing', 'ROLLBACK', 'BEGIN', 'COMMIT', 'select * from missing'],
            $this->lake->sqls(),
        );
    }

    public function testStatementThatMayHaveRunMakesTheTransactionRollbackOnly(): void
    {
        $this->lake->ok();
        $this->lake->http->push(static fn (RequestInterface $request) => throw new RequestException(
            'cURL error 56: Recv failure: Connection reset by peer',
            $request,
            null,
            null,
            ['request_size' => 312],
        ));
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        $pdo->beginTransaction();
        try {
            $pdo->exec('insert into t values (1)');
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertInstanceOf(QueryOutcomeUnknownException::class, $e->getPrevious());
        }
        try {
            $pdo->commit();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('may or may not have run', $e->getMessage());
        }

        self::assertSame(['BEGIN', 'insert into t values (1)', 'ROLLBACK'], $this->lake->sqls());
    }

    public function testCommitFailsWhenTheServerReportsTheTransactionAborted(): void
    {
        $this->lake->ok();
        $this->lake->error(1006, 'divided by zero', 'Fail');
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        $pdo->beginTransaction();
        try {
            // Bypasses LakePdo, so only the server's state tells.
            $pdo->connection()->execute('select 1/0');
            self::fail('expected QueryException');
        } catch (QueryException) {
        }
        self::assertSame('Fail', $pdo->connection()->transactionState());
        try {
            $pdo->commit();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertStringContainsString('A statement in this transaction failed', $e->getMessage());
        }

        self::assertSame(['BEGIN', 'select 1/0', 'ROLLBACK'], $this->lake->sqls());
    }

    public function testQuoteAndServerVersion(): void
    {
        $this->lake->result(['version' => 'String'], [['v1.2.3']]);
        $pdo = $this->lake->pdo();

        self::assertSame("'it\\'s'", $pdo->quote("it's"));
        self::assertSame('v1.2.3', $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
        self::assertSame('v1.2.3', $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
        self::assertCount(1, $this->lake->sqls());
    }

    public function testErrorsAreThrownAsPdoException(): void
    {
        $this->lake->error(1025, 'Unknown table');

        try {
            $this->lake->pdo()->prepare('select * from missing')->execute();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame(1025, $e->getCode());
            self::assertSame('HY000', $e->errorInfo[0]);
            self::assertInstanceOf(QueryException::class, $e->getPrevious());
            self::assertStringContainsString('Unknown table', $e->getMessage());
        }
    }

    public function testLastInsertIdIsNotSupported(): void
    {
        try {
            $this->lake->pdo()->lastInsertId();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame('IM001', $e->errorInfo[0]);
        }
    }

    public function testStatementKeepsItsSql(): void
    {
        $statement = $this->lake->pdo()->prepare('select 42');

        self::assertInstanceOf(LakePdoStatement::class, $statement);
        self::assertSame('select 42', $statement->sql());
        // Read by debug tools such as Debugbar and Telescope.
        self::assertSame('select 42', $statement->queryString);
    }

    public function testQueryStringIsReadOnlyLikePdo(): void
    {
        $statement = $this->lake->pdo()->prepare('select 42');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('read only');
        $statement->queryString = 'select 1';
    }

    public function testFromDsnWrapsConnectionErrors(): void
    {
        $this->expectException(\PDOException::class);
        LakePdo::fromDsn('not a dsn');
    }
}
