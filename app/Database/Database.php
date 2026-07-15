<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function connect(Config|array $config): self
    {
        $values = $config instanceof Config ? (array) $config->get('app.database', []) : $config;
        $driver = strtolower((string) ($values['connection'] ?? 'mysql'));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($driver === 'sqlite') {
            $path = str_replace('\\', '/', (string) ($values['database'] ?? ':memory:'));
            $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\//', $path) === 1 || str_starts_with($path, '//');
            if ($path !== ':memory:' && !$absolute) {
                $path = dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $path), '/');
            }
            if ($path !== ':memory:') {
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create the SQLite database directory.');
                }
            }
            return new self(new PDO('sqlite:' . $path, null, null, $options));
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Only mysql and sqlite database connections are supported.');
        }
        $host = (string) ($values['host'] ?? '127.0.0.1');
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $host)) {
            throw new RuntimeException('Database host contains unsupported characters.');
        }
        $port = (int) ($values['port'] ?? 3306);
        $database = (string) ($values['database'] ?? '');
        $charset = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($values['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        if ($database === '' || !preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new RuntimeException('A valid database name is required.');
        }
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        return new self(new PDO($dsn, (string) ($values['username'] ?? ''), (string) ($values['password'] ?? ''), $options));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $params = []): int
    {
        [$sql, $params] = $this->normalizeNamedParameters($sql, $params);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        [$sql, $params] = $this->normalizeNamedParameters($sql, $params);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        [$sql, $params] = $this->normalizeNamedParameters($sql, $params);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function forUpdate(string $sql): string
    {
        return $this->driver() === 'mysql' ? rtrim($sql, " \t\n\r;") . ' FOR UPDATE' : $sql;
    }

    /** @template T @param callable(self):T $callback @return T */
    public function transaction(callable $callback, int $attempts = 3): mixed
    {
        if ($this->transactionDepth > 0) {
            $savepoint = 'sp_' . $this->transactionDepth;
            $this->pdo->exec("SAVEPOINT {$savepoint}");
            $this->transactionDepth++;
            try {
                $result = $callback($this);
                $this->transactionDepth--;
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
                return $result;
            } catch (Throwable $e) {
                $this->transactionDepth--;
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                throw $e;
            }
        }

        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            $sqliteTransaction = $this->driver() === 'sqlite';
            try {
                if ($sqliteTransaction) {
                    // PDO SQLite does not mark a transaction opened through a
                    // raw BEGIN IMMEDIATE as active, so it must also be committed
                    // and rolled back through SQL. IMMEDIATE keeps local tests
                    // and CLI jobs deterministic under concurrent writers.
                    $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                } else {
                    $this->pdo->beginTransaction();
                }
                $this->transactionDepth = 1;
                $result = $callback($this);
                $this->transactionDepth = 0;
                if ($sqliteTransaction) {
                    $this->pdo->exec('COMMIT');
                } else {
                    $this->pdo->commit();
                }
                return $result;
            } catch (Throwable $e) {
                $this->transactionDepth = 0;
                if ($sqliteTransaction) {
                    try {
                        $this->pdo->exec('ROLLBACK');
                    } catch (Throwable) {
                        // The failing statement may already have ended it.
                    }
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt >= $attempts || !$this->isRetryable($e)) {
                    throw $e;
                }
                usleep(random_int(20_000, 80_000) * $attempt);
            }
        }
        throw new RuntimeException('Database transaction could not be completed.');
    }

    private function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());
        return in_array($code, ['40001', 'HY000'], true)
            && (str_contains($message, 'deadlock') || str_contains($message, 'locked') || str_contains($message, 'lock wait'));
    }

    /**
     * Native MySQL prepared statements do not permit one named placeholder to
     * appear more than once. Keep native prepares enabled and transparently
     * give repeated occurrences unique names while preserving their values.
     * Quoted strings, identifiers, and comments are deliberately skipped.
     *
     * @return array{0:string,1:array<mixed>}
     */
    private function normalizeNamedParameters(string $sql, array $params): array
    {
        if ($params === [] || !str_contains($sql, ':')) {
            return [$sql, $params];
        }
        $counts = [];
        $pattern = <<<'REGEX'
~(?:'(?:''|\\.|[^'\\])*'|"(?:""|\\.|[^"\\])*"|`(?:``|[^`])*`|--[^\r\n]*|\#[^\r\n]*|/\*.*?\*/)(*SKIP)(*F)|:([A-Za-z_][A-Za-z0-9_]*)~s
REGEX;
        $normalized = preg_replace_callback($pattern, function (array $match) use (&$counts, &$params): string {
            $name = $match[1];
            $sourceKey = array_key_exists($name, $params) ? $name
                : (array_key_exists(':' . $name, $params) ? ':' . $name : null);
            if ($sourceKey === null) {
                return $match[0];
            }
            $occurrence = ($counts[$name] ?? 0) + 1;
            $counts[$name] = $occurrence;
            if ($occurrence === 1) {
                return $match[0];
            }
            $candidate = $name . '__pp' . $occurrence;
            while (array_key_exists($candidate, $params) || array_key_exists(':' . $candidate, $params)) {
                $occurrence++;
                $candidate = $name . '__pp' . $occurrence;
            }
            $params[$candidate] = $params[$sourceKey];
            return ':' . $candidate;
        }, $sql);
        if (!is_string($normalized)) {
            throw new RuntimeException('Unable to prepare named database parameters.');
        }
        return [$normalized, $params];
    }
}
