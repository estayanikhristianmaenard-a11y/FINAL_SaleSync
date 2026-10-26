<?php
declare(strict_types=1);

namespace SaleSync\Core;

final class Database
{
    public readonly \PDO $pdo;

    public function __construct(string $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    }

    public function all(string $sql, array $parameters = []): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll();
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        return $this->all($sql, $parameters)[0] ?? null;
    }

    public function run(string $sql, array $parameters = []): int
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($parameters);
        return $query->rowCount();
    }

    public function id(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $work): mixed
    {
        // Acquire SQLite's write lock before reading stock or idempotency records.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }
}
