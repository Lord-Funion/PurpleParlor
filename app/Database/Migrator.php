<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $completed = [];
        $files = glob(rtrim($this->directory, '/\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException("Could not checksum migration {$name}.");
            }
            if (isset($applied[$name])) {
                if (!hash_equals($applied[$name], $checksum)) {
                    throw new RuntimeException("Applied migration {$name} has been modified.");
                }
                continue;
            }
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration {$name} must return a callable.");
            }
            $apply = function (Database $db) use ($migration, $name, $checksum): void {
                $migration($db);
                $db->execute(
                    'INSERT INTO schema_migrations (migration, checksum, applied_at) VALUES (:migration, :checksum, :applied_at)',
                    ['migration' => $name, 'checksum' => $checksum, 'applied_at' => gmdate('Y-m-d H:i:s')],
                );
            };
            // MySQL/MariaDB implicitly commit many DDL statements; SQLite can apply atomically.
            if ($this->database->driver() === 'sqlite') {
                $this->database->transaction($apply);
            } else {
                $apply($this->database);
            }
            $completed[] = $name;
        }
        return $completed;
    }

    private function ensureTable(): void
    {
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) PRIMARY KEY,
            checksum VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL
        )');
    }

    /** @return array<string, string> */
    private function applied(): array
    {
        $rows = $this->database->fetchAll('SELECT migration, checksum FROM schema_migrations');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['migration']] = (string) $row['checksum'];
        }
        return $result;
    }
}
