<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\SystemHealthService;
use Tests\Support\TestCase;

final class SystemHealthServiceTest extends TestCase
{
    public function testCriticalSchemaAndEveryAppendOnlyTriggerAreVerified(): void
    {
        $service = new SystemHealthService($this->config, $this->database, dirname(__DIR__, 2));
        $checks = $service->check();
        $this->assertTrue($checks['database_schema']['ok'], $checks['database_schema']['detail']);
        $this->assertSame('8 of 8 append-only trigger guards verified', $checks['append_only_triggers']['detail']);
        $this->assertTrue($checks['append_only_triggers']['ok']);

        $this->database->pdo()->exec('DROP TRIGGER protect_payment_audit_logs_delete');
        $checks = $service->check();
        $this->assertFalse($checks['append_only_triggers']['ok'], 'A missing append-only guard must make health non-ready.');
        $this->assertSame('7 of 8 append-only trigger guards verified', $checks['append_only_triggers']['detail']);

        $this->database->pdo()->exec('DROP TABLE cron_locks');
        $checks = $service->check();
        $this->assertFalse($checks['database_schema']['ok'], 'A missing critical table must make health non-ready.');
    }

    public function testPrivateConfigurationAndApacheProtectionsAreCheckedWithoutExposingPaths(): void
    {
        $root = $this->protectedRoot();
        try {
            $service = new SystemHealthService($this->config, $this->database, $root);
            $checks = $service->check();
            $this->assertTrue($checks['private_config_placement']['ok'], $checks['private_config_placement']['detail']);
            $this->assertTrue($checks['private_config_permissions']['ok'], $checks['private_config_permissions']['detail']);
            $this->assertTrue($checks['apache_protection_files']['ok'], $checks['apache_protection_files']['detail']);
            foreach (['private_config_placement', 'private_config_permissions', 'apache_protection_files'] as $name) {
                $this->assertFalse(str_contains($checks[$name]['detail'], $root), 'Health details must not expose a private absolute path.');
            }

            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($root . '/.env', 0644);
                $checks = $service->check();
                $this->assertFalse($checks['private_config_permissions']['ok'], 'A group/world-readable environment file must fail on cPanel-like POSIX hosting.');
                chmod($root . '/.env', 0600);
            }

            file_put_contents($root . '/.htaccess', "Options -Indexes\n<FilesMatch \"(^\\.|\\.env)$\">\nRequire all denied\n</FilesMatch>\nRewriteRule ^(?:app|bin|config|database)(?:/|$) - [F,L,NC]\n");
            $checks = $service->check();
            $this->assertFalse($checks['apache_protection_files']['ok'], 'The unsafe single-character dotfile anchor must not satisfy root protection checks.');
            copy(dirname(__DIR__, 2) . '/.htaccess', $root . '/.htaccess');

            file_put_contents($root . '/storage/.htaccess', "Options -Indexes\n");
            $checks = $service->check();
            $this->assertFalse($checks['apache_protection_files']['ok'], 'A missing storage denial rule must fail protection checks.');

            rename($root . '/.env', $root . '/public/.env');
            $checks = $service->check();
            $this->assertFalse($checks['private_config_placement']['ok'], 'Configuration inside the document root must fail placement checks.');
            $this->assertFalse(str_contains($checks['private_config_placement']['detail'], $root));
        } finally {
            $this->removeTree($root);
        }
    }

    private function protectedRoot(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'purple-health-' . bin2hex(random_bytes(6));
        foreach (['config', 'public/uploads', 'storage/logs', 'storage/cache', 'storage/sessions', 'storage/temporary', 'storage/backups'] as $directory) {
            if (!mkdir($root . '/' . $directory, 0755, true) && !is_dir($root . '/' . $directory)) {
                throw new \RuntimeException('Could not create the private health-test tree.');
            }
        }
        $project = dirname(__DIR__, 2);
        foreach (['.htaccess', 'public/.htaccess', 'storage/.htaccess', 'public/uploads/.htaccess'] as $relative) {
            if (!copy($project . '/' . $relative, $root . '/' . $relative)) {
                throw new \RuntimeException('Could not copy a protection fixture.');
            }
        }
        file_put_contents($root . '/.env', "APP_ENV=testing\nAPP_KEY=super-secret-health-fixture\n");
        file_put_contents($root . '/config/app.php', "<?php\nreturn [];\n");
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($root . '/.env', 0600);
            chmod($root . '/config', 0755);
            chmod($root . '/config/app.php', 0644);
        }
        return $root;
    }

    private function removeTree(string $root): void
    {
        $resolved = realpath($root);
        if ($resolved === false || !str_contains(basename($resolved), 'purple-health-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($resolved);
    }
}
