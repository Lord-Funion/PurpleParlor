<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackupService
{
    public function __construct(private readonly Database $database, private readonly Config $config, private readonly string $root)
    {
    }

    /** @return array{path:string,checksum:string,size:int} */
    public function create(): array
    {
        $configured = trim((string) $this->config->get('app.backup.path', ''));
        $directory = $configured !== '' ? $configured : $this->root . '/storage/backups';
        if (!$this->absolute($directory)) {
            $directory = $this->root . '/' . ltrim(str_replace('\\', '/', $directory), '/');
        }
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup directory.');
        }
        $real = realpath($directory);
        if ($real === false || !$this->writableDirectory($real)) {
            throw new RuntimeException('Backup directory is not writable.');
        }
        $extension = $this->database->driver() === 'sqlite' ? 'sqlite' : 'sql';
        $path = $real . DIRECTORY_SEPARATOR . 'purple-parlor-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            if ($this->database->driver() === 'sqlite') {
                $quoted = str_replace("'", "''", str_replace('\\', '/', $path));
                $this->database->pdo()->exec("VACUUM INTO '{$quoted}'");
            } else {
                $this->dumpMySql($path);
            }
            @chmod($path, 0600);
            $checksum = hash_file('sha256', $path);
            $size = filesize($path);
            if ($checksum === false || $size === false || $size <= 0) {
                throw new RuntimeException('Backup verification failed.');
            }
            $this->database->execute('INSERT INTO backup_records (filename, path_hash, checksum_sha256, size_bytes, status, created_at)
                VALUES (:filename, :path_hash, :checksum, :size, :status, :now)', [
                'filename' => basename($path), 'path_hash' => hash('sha256', $path), 'checksum' => $checksum, 'size' => $size,
                'status' => 'completed', 'now' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->applyRetention($real);
            return ['path' => $path, 'checksum' => $checksum, 'size' => (int) $size];
        } catch (Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $e;
        }
    }

    private function dumpMySql(string $path): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create backup file.');
        }
        try {
            fwrite($handle, "-- The Purple Parlor database backup\n-- Created UTC: " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
            $tables = $this->database->fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            foreach ($tables as $record) {
                $table = (string) array_values($record)[0];
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    throw new RuntimeException('Unsafe table name returned by database.');
                }
                $create = $this->database->fetchOne('SHOW CREATE TABLE `' . $table . '`');
                $createSql = (string) (array_values($create ?? [])[1] ?? '');
                fwrite($handle, "\nDROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n");
                $statement = $this->database->pdo()->query('SELECT * FROM `' . $table . '`', PDO::FETCH_ASSOC);
                while ($row = $statement->fetch()) {
                    $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                    $values = array_map(fn (mixed $value): string => $value === null ? 'NULL' : $this->database->pdo()->quote((string) $value), array_values($row));
                    fwrite($handle, 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                }
            }
            $triggers = $this->database->fetchAll('SHOW TRIGGERS');
            foreach ($triggers as $record) {
                $trigger = (string) ($record['Trigger'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_]+$/', $trigger)) {
                    throw new RuntimeException('Unsafe trigger name returned by database.');
                }
                $create = $this->database->fetchOne('SHOW CREATE TRIGGER `' . $trigger . '`');
                $createSql = (string) ($create['SQL Original Statement'] ?? $create['Create Trigger'] ?? '');
                if ($createSql !== '') {
                    fwrite($handle, "\nDROP TRIGGER IF EXISTS `{$trigger}`;\n{$createSql};\n");
                }
            }
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    private function applyRetention(string $directory): void
    {
        $days = max(1, (int) $this->config->get('app.backup.retention_days', 30));
        $cutoff = time() - ($days * 86400);
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'purple-parlor-*.*') ?: [] as $file) {
            $resolved = realpath($file);
            if ($resolved !== false && dirname($resolved) === $directory && is_file($resolved) && filemtime($resolved) < $cutoff) {
                @unlink($resolved);
            }
        }
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function writableDirectory(string $path): bool
    {
        $probe = $path . DIRECTORY_SEPARATOR . '.backup-write-check-' . bin2hex(random_bytes(6));
        $handle = @fopen($probe, 'xb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @unlink($probe);
        return true;
    }
}
