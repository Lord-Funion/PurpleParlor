<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Mail\LogMailTransport;
use App\Mail\MailQueue;
use App\Payments\HttpClient;
use App\Payments\PaymentAuditService;
use App\Payments\PaymentGate;
use App\Payments\PaymentProviderFactory;
use App\Payments\SubscriptionLifecycleService;
use App\Payments\SubscriptionReconciler;
use App\Payments\WebhookProcessor;
use App\Services\CronDispatcher;
use App\Services\DatabaseBackupService;
use App\Services\EntitlementService;
use App\Services\MembershipBonusService;
use App\Services\SystemHealthService;
use App\Services\VirtualLedgerService;
use App\Repositories\LedgerRepository;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use Tests\Support\TestCase;

final class CronDispatcherTest extends TestCase
{
    public function testDailyAndWeeklyMissionRotationIsDeterministicAndIdempotent(): void
    {
        $root = $this->protectedRoot();
        try {
            $dispatcher = $this->dispatcher($root);
            $clock = new DateTimeImmutable('2026-07-13 12:34:56', new DateTimeZone('UTC'));

            $first = $dispatcher->run($clock);
            $second = $dispatcher->run($clock);

            $this->assertSame('completed', $first['status']);
            $this->assertSame(1, $first['missions']['daily_created']);
            $this->assertSame(2, $first['missions']['weekly_created']);
            $this->assertSame(0, $second['missions']['daily_created']);
            $this->assertSame(0, $second['missions']['weekly_created']);

            $daily = $this->database->fetchOne("SELECT mission_key, starts_at, ends_at FROM missions WHERE mission_key = 'daily_rounds_2026-07-13'");
            $this->assertTrue($daily !== null, 'The one deterministic daily mission was not created.');
            $this->assertSame('2026-07-13 00:00:00', $daily['starts_at']);
            $this->assertSame('2026-07-14 00:00:00', $daily['ends_at']);
            $this->assertSame(1, (int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM missions WHERE mission_key LIKE 'daily_rounds_%'")['aggregate']);
            $this->assertTrue($this->database->fetchOne("SELECT id FROM missions WHERE mission_key = 'weekly_variety_2026-W29'") !== null);
            $this->assertTrue($this->database->fetchOne("SELECT id FROM missions WHERE mission_key = 'weekly_rules_2026-W29'") !== null);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testCronPersistsAndReportsABoundedRedactedHealthSnapshot(): void
    {
        $root = $this->protectedRoot();
        try {
            $result = $this->dispatcher($root)->run(new DateTimeImmutable('2026-07-13 12:34:56', new DateTimeZone('UTC')));
            $this->assertSame(true, $result['health']['persisted']);
            $this->assertSame('2026-07-13 12:34:56', $result['health']['checked_at']);
            $this->assertTrue($result['health']['total'] > 0);

            $record = $this->database->fetchOne("SELECT setting_value, is_sensitive, updated_by FROM system_settings WHERE setting_key = 'system.health.last_snapshot'");
            $this->assertTrue($record !== null, 'Cron did not persist its health snapshot.');
            $this->assertTrue(strlen((string) $record['setting_value']) <= 16_384, 'The persisted health snapshot exceeded its hard size bound.');
            $this->assertSame(0, (int) $record['is_sensitive']);
            $this->assertSame(null, $record['updated_by']);

            $snapshot = json_decode((string) $record['setting_value'], true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('cron', $snapshot['source']);
            $this->assertSame('2026-07-13 12:34:56', $snapshot['checked_at']);
            $this->assertSame(true, $snapshot['checks']['database_schema']['ok']);
            $this->assertSame(true, $snapshot['checks']['append_only_triggers']['ok']);
            $this->assertSame(true, $snapshot['checks']['private_config_placement']['ok']);
            $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->assertFalse(str_contains($serialized, $root), 'A private absolute path leaked into the health snapshot.');
            $this->assertFalse(str_contains($serialized, 'super-secret-cron-fixture'), 'Configuration content leaked into the health snapshot.');

            $cron = $this->database->fetchOne("SELECT details_json FROM cron_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
            $details = json_decode((string) ($cron['details_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(true, $details['health']['persisted']);
            $this->assertSame($result['health']['total'], $details['health']['total']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testBackupFailureReportCannotExposeAPrivatePath(): void
    {
        $root = $this->protectedRoot();
        try {
            $dispatcher = $this->dispatcher($root);
            $blocked = $root . '/not-a-backup-directory';
            file_put_contents($blocked, 'fixture');
            $this->config->set('app.backup.path', $blocked);
            $this->config->set('app.backup.cron_hour_utc', (int) gmdate('G'));

            $method = (new ReflectionClass(CronDispatcher::class))->getMethod('maybeBackup');
            set_error_handler(static fn (): bool => true);
            try {
                $result = $method->invoke($dispatcher);
            } finally {
                restore_error_handler();
            }
            $this->assertSame('failed', $result['status']);
            $this->assertSame('Backup task failed; inspect private logs.', $result['error']);
            $this->assertFalse(str_contains(json_encode($result, JSON_THROW_ON_ERROR), $root));
        } finally {
            $this->removeTree($root);
        }
    }

    private function dispatcher(string $root): CronDispatcher
    {
        $this->config->set('app.backup.cron_hour_utc', ((int) gmdate('G') + 1) % 24);
        $entitlements = new EntitlementService($this->database);
        $gate = new PaymentGate($this->config, $this->database);
        $providers = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $subscriptions = new SubscriptionReconciler($this->database, $providers, $lifecycle, $entitlements);
        $webhooks = new WebhookProcessor(
            $this->database,
            $providers,
            $lifecycle,
            new PaymentAuditService($this->database, self::KEY),
            $this->config,
        );
        $health = new SystemHealthService($this->config, $this->database, $root);
        return new CronDispatcher(
            $this->database,
            new MailQueue($this->database, new LogMailTransport($root . '/storage/logs'), $this->config),
            $subscriptions,
            $entitlements,
            new MembershipBonusService(
                $this->database,
                new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY),
                $this->config,
            ),
            $webhooks,
            new DatabaseBackupService($this->database, $this->config, $root),
            $health,
            $this->config,
            $root,
        );
    }

    private function protectedRoot(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'purple-cron-' . bin2hex(random_bytes(6));
        foreach (['config', 'public/uploads', 'storage/logs', 'storage/cache', 'storage/sessions', 'storage/temporary', 'storage/backups'] as $directory) {
            if (!mkdir($root . '/' . $directory, 0755, true) && !is_dir($root . '/' . $directory)) {
                throw new \RuntimeException('Could not create the private cron-test tree.');
            }
        }
        $project = dirname(__DIR__, 2);
        foreach (['.htaccess', 'public/.htaccess', 'storage/.htaccess', 'public/uploads/.htaccess'] as $relative) {
            if (!copy($project . '/' . $relative, $root . '/' . $relative)) {
                throw new \RuntimeException('Could not copy a cron protection fixture.');
            }
        }
        file_put_contents($root . '/.env', "APP_ENV=testing\nAPP_KEY=super-secret-cron-fixture\n");
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
        if ($resolved === false || !str_contains(basename($resolved), 'purple-cron-')) {
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
