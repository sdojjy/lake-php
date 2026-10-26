<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Pdo;

use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Connection;
use TiDBCloud\Lake\Exception\ApiException;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Exception\QueryException;
use TiDBCloud\Lake\Exception\QueryOutcomeUnknownException;
use TiDBCloud\Lake\QueryResponse;
use TiDBCloud\Lake\SqlEncoder;

/**
 * PDO-compatible facade over a Lake {@see Connection}, so code written
 * against the PDO API (notably Laravel's database layer) can talk to Lake.
 *
 * This is a userland subclass: there is no native PDO driver behind it, the
 * parent constructor is never called and every PDO method used by such code
 * is reimplemented on top of the Lake HTTP client (the same approach as
 * yajra/pdo-via-oci8). Statements are interpolated client-side, exactly like
 * {@see Connection::query()}.
 *
 * Errors are always thrown (PDO::ERRMODE_EXCEPTION) as \PDOException whose
 * previous exception is the original {@see LakeException}.
 */
final class LakePdo extends \PDO
{
    public const DRIVER_NAME = 'lake';

    /** @var array<int, mixed> */
    private array $attributes = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_BOTH,
        \PDO::ATTR_STRINGIFY_FETCHES => false,
        \PDO::ATTR_CASE => \PDO::CASE_NATURAL,
        \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
    ];

    private const ABORTED = 'A statement in this transaction failed, which aborts the transaction on TiDB Cloud Lake.';
    private const OUTCOME_UNKNOWN = 'A statement in this transaction may or may not have run: the connection failed after it was sent.';

    /** Why the open transaction can no longer commit, see {@see setRollbackOnly()}. */
    private ?string $rollbackOnly = null;

    private ?string $serverVersion = null;

    /**
     * @param array<int, mixed> $options PDO attributes (PDO::ATTR_*)
     */
    public function __construct(private readonly Connection $connection, array $options = [])
    {
        // parent::__construct() is intentionally not called.
        foreach ($options as $attribute => $value) {
            $this->setAttribute($attribute, $value);
        }
    }

    /**
     * Connects (session login) and wraps the connection.
     *
     * @param array<int, mixed> $options PDO attributes (PDO::ATTR_*)
     */
    public static function fromDsn(string $dsn, array $options = []): self
    {
        return new self(self::wrap(static fn (): Connection => Client::fromDsn($dsn)->connect()), $options);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function prepare(string $query, array $options = []): LakePdoStatement
    {
        return new LakePdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): LakePdoStatement
    {
        $statement = $this->prepare($query);
        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }
        $statement->execute();

        return $statement;
    }

    public function exec(string $statement): int
    {
        return $this->run($statement)->affectedRows();
    }

    /**
     * Runs a statement to completion, rethrowing Lake errors as \PDOException.
     *
     * @internal used by {@see LakePdoStatement::execute()}
     * @param array<int|string, mixed>|null $params
     */
    public function run(string $sql, ?array $params = null): QueryResponse
    {
        if (!$this->inTransaction()) {
            // The transaction ended, e.g. through a plain ROLLBACK.
            $this->rollbackOnly = null;
        } elseif ($this->rollbackOnly !== null && preg_match('/^\s*ROLLBACK\b/i', $sql) !== 1) {
            throw self::error('25000', $this->rollbackOnly . ' Roll the transaction back before running other statements.');
        }

        try {
            return self::wrap(fn (): QueryResponse => $this->connection->run($sql, $params));
        } catch (\PDOException $e) {
            // Lake aborts a transaction when one of its statements fails, and
            // a later COMMIT then silently rolls everything back. The server
            // does not report "Fail" for errors raised before execution (an
            // unknown table, a syntax error), so this cannot rely on it.
            if ($e->getPrevious() instanceof QueryException) {
                $this->setRollbackOnly(self::ABORTED);
            } elseif ($e->getPrevious() instanceof QueryOutcomeUnknownException) {
                $this->setRollbackOnly(self::OUTCOME_UNKNOWN);
            }

            throw $e;
        }
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction()) {
            throw self::error('HY000', 'There is already an active transaction');
        }
        self::wrap(fn () => $this->connection->begin());
        $this->rollbackOnly = null;

        return true;
    }

    /**
     * Commits, unless the transaction was marked rollback-only or the server
     * reports it aborted: then it is rolled back and a \PDOException
     * explains why.
     */
    public function commit(): bool
    {
        if (!$this->inTransaction()) {
            throw self::error('HY000', 'There is no active transaction');
        }
        $reason = $this->rollbackOnly ?? ($this->connection->transactionState() === 'Fail' ? self::ABORTED : null);
        if ($reason !== null) {
            $this->rollBack();

            throw self::error('25000', $reason . ' The transaction has been rolled back.');
        }
        self::wrap(fn () => $this->connection->commit());

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction()) {
            throw self::error('HY000', 'There is no active transaction');
        }
        try {
            self::wrap(fn () => $this->connection->rollback());
        } finally {
            $this->rollbackOnly = null;
        }

        return true;
    }

    /**
     * Follows the server's transaction state (like pdo_mysql), so BEGIN /
     * COMMIT / ROLLBACK sent as plain statements are taken into account.
     */
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    /**
     * Marks the open transaction as unable to commit: other statements are
     * rejected, commit() rolls back and throws, rollBack() works as usual.
     * For when part of a transaction must be undone but Lake has no
     * savepoints to undo only that part.
     */
    public function setRollbackOnly(string $reason): void
    {
        if ($this->inTransaction()) {
            $this->rollbackOnly ??= $reason;
        }
    }

    /**
     * Lake has no LAST_INSERT_ID(); generated keys cannot be read back.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        throw self::error('IM001', 'Driver does not support this function: driver does not support lastInsertId()');
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string
    {
        return "'" . SqlEncoder::escapeString($string) . "'";
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            \PDO::ATTR_DRIVER_NAME => self::DRIVER_NAME,
            \PDO::ATTR_CLIENT_VERSION => Client::VERSION,
            \PDO::ATTR_SERVER_VERSION => $this->serverVersion
                ??= self::wrap(fn (): string => $this->connection->version()),
            default => $this->attributes[$attribute] ?? null,
        };
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    public function errorCode(): ?string
    {
        return '00000';
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    /**
     * Logs the Lake session out (best-effort).
     */
    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * Runs $fn, rethrowing Lake errors as \PDOException.
     *
     * @internal
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (LakeException $e) {
            $code = match (true) {
                $e instanceof QueryException => $e->errorCode,
                $e instanceof ApiException => $e->statusCode,
                default => (int) $e->getCode(),
            };

            throw self::error('HY000', $e->getMessage(), $code, $e);
        }
    }

    /**
     * @internal
     */
    public static function error(
        string $sqlState,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ): \PDOException {
        $e = new \PDOException($message, $code, $previous);
        $e->errorInfo = [$sqlState, $code === 0 ? null : $code, $message];

        return $e;
    }
}
