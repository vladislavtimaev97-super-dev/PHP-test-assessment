<?php

declare(strict_types=1);

namespace App\Infra;

use App\Support\Env;
use PDO;
use PDOException;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    /** SQLSTATEs that are safe to retry: serialization failure, deadlock. */
    private const RETRYABLE = ['40001', '40P01'];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = Env::get('DB_DSN', 'pgsql:host=db;port=5432;dbname=gamestore');
            self::$pdo = new PDO(
                (string) $dsn,
                Env::get('DB_USER', 'gamestore'),
                Env::get('DB_PASSWORD', 'gamestore'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            self::$pdo->exec("SET application_name = 'gamestore'");
            // Lock waits must not pile up forever behind a stuck transaction.
            self::$pdo->exec('SET lock_timeout = 10000');
            self::$pdo->exec('SET idle_in_transaction_session_timeout = 30000');
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @param array<string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);

        return $st;
    }

    /**
     * @param  array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string,mixed>       $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();

        return $v === false ? null : $v;
    }

    /**
     * Run $fn inside a transaction, retrying the whole closure on
     * serialization failures / deadlocks. $fn must therefore be side-effect
     * free outside the database.
     *
     * @template T
     * @param  callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn, int $attempts = 5): mixed
    {
        $pdo = self::pdo();

        if ($pdo->inTransaction()) {           // nested call: join the outer txn
            return $fn($pdo);
        }

        for ($i = 1; ; $i++) {
            $pdo->beginTransaction();
            try {
                $result = $fn($pdo);
                $pdo->commit();

                return $result;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $sqlState = $e->errorInfo[0] ?? null;
                if ($i < $attempts && in_array((string) $sqlState, self::RETRYABLE, true)) {
                    usleep(random_int(5_000, 40_000) * $i);
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    /**
     * Session-scoped mutex. Used to serialise delivery of one order across
     * workers WITHOUT holding a database transaction open across HTTP calls
     * to a supplier (which could hang for seconds).
     */
    public static function tryAdvisoryLock(string $name): bool
    {
        return (bool) self::value('SELECT pg_try_advisory_lock(hashtext(:n))', ['n' => $name]);
    }

    public static function advisoryUnlock(string $name): void
    {
        self::run('SELECT pg_advisory_unlock(hashtext(:n))', ['n' => $name]);
    }

    public static function isUniqueViolation(\Throwable $e): bool
    {
        return $e instanceof PDOException && ($e->errorInfo[0] ?? null) === '23505';
    }
}
