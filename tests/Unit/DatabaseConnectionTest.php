<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Database;
use Tests\Support\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function testRepeatedNamedParametersRemainNativePrepareCompatible(): void
    {
        $row = $this->database->fetchOne(
            "SELECT :value AS first_value, :value AS second_value, ':value' AS literal_value",
            ['value' => 7],
        );

        $this->assertSame(7, (int) ($row['first_value'] ?? 0));
        $this->assertSame(7, (int) ($row['second_value'] ?? 0));
        $this->assertSame(':value', $row['literal_value'] ?? null);
    }

    public function testSqliteAcceptsAnAbsoluteFilesystemPath(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'purple-parlor-db-tests';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'absolute-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $database = Database::connect(['connection' => 'sqlite', 'database' => $path]);
            $database->fetchOne('SELECT 1 AS healthy');
            $this->assertTrue(is_file($path), 'SQLite did not create the requested absolute-path database.');
        } finally {
            unset($database);
            gc_collect_cycles();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
