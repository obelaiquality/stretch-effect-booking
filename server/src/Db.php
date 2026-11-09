<?php
declare(strict_types=1);

namespace StretchEffect;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use StretchEffect\Contracts\Database;

final class Db implements Database
{
    private PDO $pdo;
    private string $driver;
    private int $depth = 0;
    private ?DateTimeImmutable $frozen = null;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->driver = str_starts_with($dsn, 'sqlite:') ? 'sqlite' : 'mysql';
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET NAMES utf8mb4");
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Tests: freeze the clock (null to unfreeze). */
    public function freeze(DateTimeImmutable|string|null $at): void
    {
        $this->frozen = is_string($at) ? new DateTimeImmutable($at, new DateTimeZone('UTC')) : $at?->setTimezone(new DateTimeZone('UTC'));
    }

    public function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }
        // A MySQL deadlock (SQLSTATE 40001 / error 1213) rolls the whole transaction back and is safe to run again.
        for ($attempt = 1;; $attempt++) {
            try {
                return $this->runTransaction($fn);
            } catch (\PDOException $e) {
                if ($attempt >= self::DEADLOCK_ATTEMPTS || !self::isDeadlock($e)) {
                    throw $e;
                }
                usleep(random_int(20000, 80000));
            }
        }
    }

    private const DEADLOCK_ATTEMPTS = 3;

    public static function isDeadlock(\PDOException $e): bool
    {
        $info = $e->errorInfo;
        return (string) $e->getCode() === '40001' || (is_array($info) && (($info[0] ?? '') === '40001' || (int) ($info[1] ?? 0) === 1213));
    }

    private function runTransaction(callable $fn): mixed
    {
        // SQLite: BEGIN IMMEDIATE takes the write lock up front (serialises bookings).
        $this->pdo->exec($this->driver === 'sqlite' ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
        $this->depth = 1;
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** ' FOR UPDATE' on MySQL, '' on SQLite. */
    public function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params */
    public function val(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param list<mixed> $params @return int affected rows */
    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
