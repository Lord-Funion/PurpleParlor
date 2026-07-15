<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Mail\MailQueue;
use App\Payments\SubscriptionReconciler;
use App\Payments\WebhookProcessor;
use App\Core\Config;
use App\Security\SecretRedactor;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class CronDispatcher
{
    public function __construct(
        private readonly Database $database,
        private readonly MailQueue $mail,
        private readonly SubscriptionReconciler $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly MembershipBonusService $membershipBonuses,
        private readonly WebhookProcessor $webhooks,
        private readonly DatabaseBackupService $backups,
        private readonly SystemHealthService $health,
        private readonly Config $config,
        private readonly string $root,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(?DateTimeImmutable $clock = null): array
    {
        $clock = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $owner = bin2hex(random_bytes(16));
        if (!$this->acquire('main_dispatcher', $owner, 540)) {
            return ['status' => 'skipped', 'reason' => 'already_running'];
        }
        $runId = $this->database->insert('INSERT INTO cron_runs (run_key, status, started_at) VALUES (:key, :status, :now)',
            ['key' => 'main_dispatcher', 'status' => 'running', 'now' => gmdate('Y-m-d H:i:s')]);
        try {
            $details = [
                'mail' => $this->mail->process(25),
                'subscriptions' => $this->subscriptions->reconcile(50),
                'expired_grace_periods' => $this->subscriptions->expireGracePeriods(),
                'expired_entitlements' => $this->entitlements->expireDue(),
                'membership_bonuses' => $this->membershipBonuses->grantDue($clock),
                'retried_webhooks' => $this->webhooks->retryFailed(25),
                'missions' => $this->rotateMissions($clock),
                'backup' => $this->maybeBackup(),
                'cleanup' => $this->cleanup(),
            ];
            $details['health'] = $this->recordSystemHealth($clock);
            $this->database->execute("UPDATE cron_runs SET status = 'completed', finished_at = :now, details_json = :details WHERE id = :id", [
                'now' => gmdate('Y-m-d H:i:s'), 'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'id' => $runId,
            ]);
            return ['status' => 'completed'] + $details;
        } catch (Throwable $e) {
            $this->database->execute("UPDATE cron_runs SET status = 'failed', finished_at = :now, details_json = :details WHERE id = :id", [
                'now' => gmdate('Y-m-d H:i:s'),
                'details' => json_encode(['error' => 'Cron task failed; inspect private logs.', 'error_type' => $this->safeErrorType($e)], JSON_THROW_ON_ERROR),
                'id' => $runId,
            ]);
            throw $e;
        } finally {
            $this->release('main_dispatcher', $owner);
        }
    }

    private function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        try {
            return $this->database->transaction(function (Database $db) use ($key, $owner, $ttlSeconds): bool {
                $db->execute('DELETE FROM cron_locks WHERE lock_key = :key AND expires_at <= :now', ['key' => $key, 'now' => gmdate('Y-m-d H:i:s')]);
                $db->execute('INSERT INTO cron_locks (lock_key, owner_token, expires_at, acquired_at) VALUES (:key, :owner, :expires, :now)', [
                    'key' => $key, 'owner' => $owner, 'expires' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds), 'now' => gmdate('Y-m-d H:i:s'),
                ]);
                return true;
            });
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19'], true)) {
                return false;
            }
            throw $e;
        }
    }

    private function release(string $key, string $owner): void
    {
        $this->database->execute('DELETE FROM cron_locks WHERE lock_key = :key AND owner_token = :owner', ['key' => $key, 'owner' => $owner]);
    }

    /** @return array<string,int> */
    private function cleanup(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $counts = [
            'sessions' => $this->database->execute('DELETE FROM sessions WHERE expires_at <= :now OR (revoked_at IS NOT NULL AND revoked_at <= :old)', ['now' => $now, 'old' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
            'remember_tokens' => $this->database->execute('DELETE FROM remember_tokens WHERE expires_at <= :now', ['now' => $now]),
            'rate_limits' => $this->database->execute('DELETE FROM rate_limits WHERE expires_at <= :now', ['now' => $now]),
            'idempotency' => $this->database->execute('DELETE FROM payment_idempotency_keys WHERE expires_at <= :now', ['now' => $now]),
            'webhook_payloads' => $this->database->execute('UPDATE webhook_events SET payload_json = NULL WHERE expires_at IS NOT NULL AND expires_at <= :now AND status = :status', ['now' => $now, 'status' => 'processed']),
            'analytics' => $this->database->execute('DELETE FROM analytics_events WHERE expires_at <= :now', ['now' => $now]),
        ];
        $counts['temporary_files'] = $this->cleanTemporaryFiles();
        return $counts;
    }

    /** @return array{expired:int,created:int,daily_created:int,weekly_created:int} */
    private function rotateMissions(DateTimeImmutable $clock): array
    {
        $clock = $clock->setTimezone(new DateTimeZone('UTC'));
        $now = $clock->format('Y-m-d H:i:s');
        $expired = $this->database->execute('UPDATE missions SET active = 0 WHERE active = 1 AND ends_at <= :now', ['now' => $now]);
        $dayStart = $clock->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $weekStart = $clock->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = $weekStart->modify('+1 week');
        $week = $clock->format('o-\WW');

        $daily = [
            'daily_rounds_' . $clock->format('Y-m-d'),
            'Daily Parlor Visit',
            'Complete three play-money rounds today.',
            3,
            150,
            5,
            $dayStart->format('Y-m-d H:i:s'),
            $dayEnd->format('Y-m-d H:i:s'),
        ];
        $weekly = [
            ['weekly_variety_' . $week, 'Parlor Sampler', 'Complete ten rounds across the parlor.', 10, 500, 20, $weekStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s')],
            ['weekly_rules_' . $week, 'Know the Tables', 'Visit five rules pages.', 5, 250, 15, $weekStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s')],
        ];

        $dailyCreated = $this->insertMissionIfMissing($daily);
        $weeklyCreated = 0;
        foreach ($weekly as $mission) {
            $weeklyCreated += $this->insertMissionIfMissing($mission);
        }
        return [
            'expired' => $expired,
            'created' => $dailyCreated + $weeklyCreated,
            'daily_created' => $dailyCreated,
            'weekly_created' => $weeklyCreated,
        ];
    }

    /** @param array{0:string,1:string,2:string,3:int,4:int,5:int,6:string,7:string} $mission */
    private function insertMissionIfMissing(array $mission): int
    {
        [$key, $name, $description, $target, $coins, $stars, $starts, $ends] = $mission;
        $insert = $this->database->driver() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        return $this->database->execute($insert . ' INTO missions (mission_key, name, description, target_value, reward_coins, reward_stars, starts_at, ends_at, active)
            VALUES (:key,:name,:description,:target,:coins,:stars,:starts,:ends,1)', [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'target' => $target,
            'coins' => $coins,
            'stars' => $stars,
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }

    /** @return array{status:string,passed:int,total:int,failed:list<string>,persisted:bool,checked_at:string} */
    private function recordSystemHealth(DateTimeImmutable $clock): array
    {
        $checks = $this->health->check();
        $redactor = new SecretRedactor();
        $safeChecks = [];
        foreach (array_slice($checks, 0, 64, true) as $name => $check) {
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string) $name) !== 1 || !is_array($check)) {
                continue;
            }
            $detail = $redactor->redactText((string) ($check['detail'] ?? 'not reported'));
            foreach (array_unique([$this->root, str_replace('\\', '/', $this->root), str_replace('/', '\\', $this->root)]) as $privatePath) {
                if ($privatePath !== '') {
                    $detail = str_ireplace($privatePath, '[private]', $detail);
                }
            }
            $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail) ?? 'not reported';
            $detail = function_exists('mb_substr') ? mb_substr($detail, 0, 160) : substr($detail, 0, 160);
            $safeChecks[(string) $name] = ['ok' => (bool) ($check['ok'] ?? false), 'detail' => $detail];
        }

        $passed = count(array_filter($safeChecks, static fn (array $check): bool => $check['ok']));
        $snapshot = [
            'checked_at' => $clock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'source' => 'cron',
            'passed' => $passed,
            'total' => count($safeChecks),
            'checks' => $safeChecks,
        ];
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 16_384) {
            foreach ($snapshot['checks'] as &$check) {
                unset($check['detail']);
            }
            unset($check);
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $params = ['key' => 'system.health.last_snapshot', 'value' => $encoded, 'now' => gmdate('Y-m-d H:i:s')];
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,NULL,:now)
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_sensitive=0,updated_by=NULL,updated_at=VALUES(updated_at)'
            : 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,NULL,:now)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,is_sensitive=0,updated_by=NULL,updated_at=excluded.updated_at';
        $this->database->execute($sql, $params);

        $failed = array_keys(array_filter($safeChecks, static fn (array $check): bool => !$check['ok']));
        return [
            'status' => $passed === count($safeChecks) ? 'healthy' : 'review',
            'passed' => $passed,
            'total' => count($safeChecks),
            'failed' => array_slice($failed, 0, 12),
            'persisted' => true,
            'checked_at' => (string) $snapshot['checked_at'],
        ];
    }

    private function safeErrorType(Throwable $error): string
    {
        $type = str_replace('\\', '.', $error::class);
        return preg_match('/^[A-Za-z0-9_.]{1,120}$/', $type) === 1 ? $type : 'runtime_error';
    }

    /** @return array<string,mixed> */
    private function maybeBackup(): array
    {
        $hour = max(0, min(23, (int) $this->config->get('app.backup.cron_hour_utc', 5)));
        if ((int) gmdate('G') !== $hour) {
            return ['status' => 'not_due'];
        }
        $latest = $this->database->fetchOne("SELECT created_at FROM backup_records WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");
        if ($latest !== null && strtotime((string) $latest['created_at']) > time() - 20 * 3600) {
            return ['status' => 'fresh'];
        }
        try {
            return ['status' => 'created'] + $this->backups->create();
        } catch (Throwable $e) {
            return [
                'status' => 'failed',
                'error' => 'Backup task failed; inspect private logs.',
                'error_type' => $this->safeErrorType($e),
            ];
        }
    }

    private function cleanTemporaryFiles(): int
    {
        $directory = realpath($this->root . '/storage/temporary');
        if ($directory === false) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $path = $item->getRealPath();
            if ($path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if ($item->isFile() && $item->getMTime() < time() - 86400 && @unlink($path)) {
                $count++;
            } elseif ($item->isDir()) {
                @rmdir($path);
            }
        }
        return $count;
    }
}
