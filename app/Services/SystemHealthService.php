<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use Throwable;

final class SystemHealthService
{
    /**
     * A deliberately small but release-critical contract. A migration may add
     * more columns, but none of these tables or columns may disappear without
     * the health check making the deployment non-ready.
     *
     * @var array<string,list<string>>
     */
    private const CRITICAL_SCHEMA = [
        'users' => ['id', 'email_normalized', 'password_hash', 'status', 'deleted_at'],
        'roles' => ['id', 'name'],
        'permissions' => ['id', 'name'],
        'user_roles' => ['user_id', 'role_id'],
        'sessions' => ['id', 'user_id', 'selector', 'token_hash', 'expires_at', 'revoked_at'],
        'virtual_wallets' => ['user_id', 'currency', 'balance'],
        'virtual_ledger_entries' => ['id', 'user_id', 'currency', 'amount', 'balance_before', 'balance_after', 'idempotency_key', 'entry_hash'],
        'game_definitions' => ['id', 'slug', 'active'],
        'game_rounds' => ['id', 'user_id', 'game_id', 'status', 'idempotency_key', 'server_seed_hash'],
        'game_seed_queue' => ['owner_key', 'commit_hash', 'server_seed_encrypted', 'consumed_at'],
        'missions' => ['id', 'mission_key', 'target_value', 'starts_at', 'ends_at', 'active'],
        'mission_progress' => ['user_id', 'mission_id', 'progress_value', 'rewarded_at'],
        'membership_plans' => ['id', 'plan_key', 'active'],
        'subscriptions' => ['id', 'user_id', 'provider', 'external_id', 'status', 'current_period_end'],
        'subscription_events' => ['id', 'subscription_id', 'provider_event_id', 'status_after', 'occurred_at'],
        'products' => ['id', 'product_key', 'entitlement_key', 'active'],
        'payments' => ['id', 'user_id', 'provider', 'external_id', 'status', 'amount_cents', 'currency'],
        'checkout_intents' => ['intent_key', 'user_id', 'idempotency_key', 'status', 'expires_at'],
        'refund_requests' => ['id', 'payment_id', 'requested_amount_cents', 'status'],
        'refund_executions' => ['id', 'refund_request_id', 'payment_id', 'idempotency_key', 'status'],
        'user_entitlements' => ['id', 'user_id', 'entitlement_key', 'source_type', 'source_id', 'revoked_at'],
        'webhook_events' => ['id', 'provider', 'provider_event_id', 'signature_valid', 'status', 'payload_hash'],
        'payment_audit_logs' => ['id', 'action', 'entry_hash', 'previous_hash', 'created_at'],
        'admin_audit_logs' => ['id', 'action', 'request_id', 'entry_hash', 'previous_hash', 'created_at'],
        'system_settings' => ['setting_key', 'setting_value', 'is_sensitive', 'updated_at'],
        'cron_runs' => ['id', 'run_key', 'status', 'started_at', 'finished_at', 'details_json'],
        'cron_locks' => ['lock_key', 'owner_token', 'expires_at'],
        'backup_records' => ['id', 'filename', 'path_hash', 'checksum_sha256', 'status'],
    ];

    /** @var array<string,array{table:string,operation:string}> */
    private const APPEND_ONLY_TRIGGERS = [
        'protect_virtual_ledger_entries_update' => ['table' => 'virtual_ledger_entries', 'operation' => 'UPDATE'],
        'protect_virtual_ledger_entries_delete' => ['table' => 'virtual_ledger_entries', 'operation' => 'DELETE'],
        'protect_admin_audit_logs_update' => ['table' => 'admin_audit_logs', 'operation' => 'UPDATE'],
        'protect_admin_audit_logs_delete' => ['table' => 'admin_audit_logs', 'operation' => 'DELETE'],
        'protect_payment_audit_logs_update' => ['table' => 'payment_audit_logs', 'operation' => 'UPDATE'],
        'protect_payment_audit_logs_delete' => ['table' => 'payment_audit_logs', 'operation' => 'DELETE'],
        'protect_subscription_events_update' => ['table' => 'subscription_events', 'operation' => 'UPDATE'],
        'protect_subscription_events_delete' => ['table' => 'subscription_events', 'operation' => 'DELETE'],
    ];

    /** @var array<string,list<string>> */
    private const APACHE_PROTECTION_RULES = [
        '.htaccess' => ['options -indexes', '<filesmatch "(?i)^(?:\..*|', 'require all denied', 'rewriterule ^(?:app|bin|config|database'],
        'public/.htaccess' => ['options -indexes', 'filesmatch', 'rewriterule ^ index.php'],
        'storage/.htaccess' => ['options -indexes', 'require all denied', 'deny from all'],
        'public/uploads/.htaccess' => ['options -indexes', 'removehandler .php', 'require all denied'],
    ];

    public function __construct(private readonly Config $config, private readonly ?Database $database, private readonly string $root)
    {
    }

    /** @return array<string,array{ok:bool,detail:string}> */
    public function check(): array
    {
        $checks = [];
        $checks['php_version'] = ['ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION];
        foreach (['pdo', 'openssl', 'json', 'filter', 'session', 'mbstring'] as $extension) {
            $checks['extension_' . $extension] = ['ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'loaded' : 'missing'];
        }
        $connection = (string) $this->config->get('app.database.connection', 'mysql');
        $driverExtension = $connection === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        $checks['extension_' . $driverExtension] = ['ok' => extension_loaded($driverExtension), 'detail' => extension_loaded($driverExtension) ? 'loaded' : 'missing'];
        $providerHttpRequired = $this->config->get('payments.enabled') === true
            || $this->config->get('payments.paypal.enabled') === true
            || $this->config->get('payments.square.enabled') === true;
        $checks[$providerHttpRequired ? 'extension_curl' : 'recommended_curl'] = [
            'ok' => extension_loaded('curl'),
            'detail' => extension_loaded('curl') ? 'loaded' : ($providerHttpRequired ? 'missing' : 'recommended'),
        ];
        foreach (['fileinfo', 'intl', 'sodium'] as $extension) {
            $checks['recommended_' . $extension] = ['ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'loaded' : 'recommended'];
        }

        try {
            $this->database?->fetchOne('SELECT 1 AS healthy');
            $checks['database'] = ['ok' => $this->database !== null, 'detail' => $this->database?->driver() ?? 'not configured'];
        } catch (Throwable) {
            $checks['database'] = ['ok' => false, 'detail' => 'connection failed'];
        }

        if ($this->database === null) {
            $checks['database_schema'] = ['ok' => false, 'detail' => 'not configured'];
            $checks['append_only_triggers'] = ['ok' => false, 'detail' => 'not configured'];
        } else {
            try {
                $checks['database_schema'] = $this->criticalSchemaHealth();
            } catch (Throwable) {
                $checks['database_schema'] = ['ok' => false, 'detail' => 'critical schema metadata unavailable'];
            }
            try {
                $checks['append_only_triggers'] = $this->appendOnlyTriggerHealth();
            } catch (Throwable) {
                $checks['append_only_triggers'] = ['ok' => false, 'detail' => 'trigger metadata unavailable'];
            }
        }

        foreach (['storage/logs', 'storage/cache', 'storage/sessions', 'storage/temporary', 'storage/backups'] as $directory) {
            $path = $this->root . '/' . $directory;
            $checks['writable_' . str_replace('/', '_', $directory)] = ['ok' => $this->writableDirectory($path), 'detail' => $directory];
        }

        $checks['private_config_placement'] = $this->privateConfigPlacementHealth();
        $checks['private_config_permissions'] = $this->privateConfigPermissionHealth();
        $checks['apache_protection_files'] = $this->apacheProtectionHealth();

        $key = (string) $this->config->get('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }
        $checks['app_key'] = ['ok' => strlen($key) >= 32, 'detail' => strlen($key) >= 32 ? 'configured' : 'missing or weak'];
        $checks['debug'] = [
            'ok' => $this->config->get('app.env') !== 'production' || $this->config->get('app.debug') === false,
            'detail' => $this->config->get('app.debug') ? 'enabled' : 'disabled',
        ];
        $productionHttps = $this->config->get('app.env') === 'production' && str_starts_with((string) $this->config->get('app.url', ''), 'https://');
        $checks['secure_session_cookie'] = [
            'ok' => !$productionHttps || $this->config->get('app.session.secure') === true,
            'detail' => $this->config->get('app.session.secure') ? 'secure' : 'not secure',
        ];
        $mailDriver = (string) $this->config->get('app.mail.driver', 'smtp');
        $smtpReady = $mailDriver !== 'smtp' || (trim((string) $this->config->get('app.mail.host', '')) !== ''
            && trim((string) $this->config->get('app.mail.username', '')) !== ''
            && trim((string) $this->config->get('app.mail.password', '')) !== '');
        $checks['mail_configuration'] = ['ok' => $this->config->get('app.env') !== 'production' || $smtpReady, 'detail' => $smtpReady ? $mailDriver . ' configured' : 'SMTP incomplete'];

        if ($this->database !== null) {
            try {
                $cron = $this->database->fetchOne("SELECT status, started_at, finished_at FROM cron_runs WHERE status IN ('running','completed') ORDER BY started_at DESC LIMIT 1");
                $cronTime = $cron === null ? false : strtotime((string) ($cron['finished_at'] ?? $cron['started_at']));
                $cronFresh = $cronTime !== false && $cronTime > time() - 20 * 60;
                $checks['cron_freshness'] = [
                    'ok' => $this->config->get('app.env') !== 'production' || $cronFresh,
                    'detail' => $cron === null ? 'never' : (string) ($cron['finished_at'] ?? $cron['started_at']),
                ];
                $backup = $this->database->fetchOne("SELECT created_at FROM backup_records WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");
                $backupFresh = $backup !== null && strtotime((string) $backup['created_at']) > time() - 48 * 3600;
                $checks['backup_freshness'] = ['ok' => $this->config->get('app.env') !== 'production' || $backupFresh, 'detail' => $backup['created_at'] ?? 'never'];
            } catch (Throwable) {
                $checks['cron_freshness'] = ['ok' => false, 'detail' => 'schema unavailable'];
                $checks['backup_freshness'] = ['ok' => false, 'detail' => 'schema unavailable'];
            }
        }

        $free = @disk_free_space($this->root);
        $checks['disk_space'] = [
            'ok' => $free === false || $free >= 256 * 1024 * 1024,
            'detail' => $free === false ? 'unavailable' : round($free / 1024 / 1024) . ' MiB free',
        ];
        $gate = new \App\Payments\PaymentGate($this->config, $this->database);
        $checks['payments_live_lock'] = [
            'ok' => !$gate->liveReady(),
            'detail' => $gate->liveReady() ? 'LIVE READY - Adult Owner review required' : 'locked/non-live',
        ];
        return $checks;
    }

    /** @return array{ok:bool,detail:string} */
    private function criticalSchemaHealth(): array
    {
        $actual = [];
        if ($this->database?->driver() === 'sqlite') {
            foreach ($this->database->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
                $table = strtolower((string) ($row['name'] ?? ''));
                if (!array_key_exists($table, self::CRITICAL_SCHEMA)) {
                    continue;
                }
                $actual[$table] = [];
                foreach ($this->database->fetchAll('PRAGMA table_info("' . $table . '")') as $column) {
                    $actual[$table][] = strtolower((string) ($column['name'] ?? ''));
                }
            }
        } elseif ($this->database?->driver() === 'mysql') {
            foreach ($this->database->fetchAll('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $row) {
                $table = strtolower((string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? ''));
                if (!array_key_exists($table, self::CRITICAL_SCHEMA)) {
                    continue;
                }
                $actual[$table][] = strtolower((string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? ''));
            }
        } else {
            return ['ok' => false, 'detail' => 'unsupported database metadata driver'];
        }

        $missingTables = 0;
        $missingColumns = 0;
        $expectedColumns = 0;
        foreach (self::CRITICAL_SCHEMA as $table => $columns) {
            $expectedColumns += count($columns);
            if (!array_key_exists($table, $actual)) {
                $missingTables++;
                $missingColumns += count($columns);
                continue;
            }
            foreach ($columns as $column) {
                if (!in_array(strtolower($column), $actual[$table], true)) {
                    $missingColumns++;
                }
            }
        }
        $ok = $missingTables === 0 && $missingColumns === 0;
        return [
            'ok' => $ok,
            'detail' => $ok
                ? count(self::CRITICAL_SCHEMA) . " critical tables and {$expectedColumns} critical columns verified"
                : "incomplete: {$missingTables} critical tables and {$missingColumns} critical columns missing",
        ];
    }

    /** @return array{ok:bool,detail:string} */
    private function appendOnlyTriggerHealth(): array
    {
        $actual = [];
        if ($this->database?->driver() === 'sqlite') {
            $rows = $this->database->fetchAll("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger'");
            foreach ($rows as $row) {
                $actual[strtolower((string) ($row['name'] ?? ''))] = [
                    'table' => strtolower((string) ($row['tbl_name'] ?? '')),
                    'operation' => strtoupper((string) ($row['sql'] ?? '')),
                    'timing' => strtoupper((string) ($row['sql'] ?? '')),
                    'guard' => strtoupper((string) ($row['sql'] ?? '')),
                ];
            }
        } elseif ($this->database?->driver() === 'mysql') {
            $rows = $this->database->fetchAll('SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
            foreach ($rows as $row) {
                $actual[strtolower((string) ($row['TRIGGER_NAME'] ?? $row['trigger_name'] ?? ''))] = [
                    'table' => strtolower((string) ($row['EVENT_OBJECT_TABLE'] ?? $row['event_object_table'] ?? '')),
                    'operation' => strtoupper((string) ($row['EVENT_MANIPULATION'] ?? $row['event_manipulation'] ?? '')),
                    'timing' => strtoupper((string) ($row['ACTION_TIMING'] ?? $row['action_timing'] ?? '')),
                    'guard' => strtoupper((string) ($row['ACTION_STATEMENT'] ?? $row['action_statement'] ?? '')),
                ];
            }
        } else {
            return ['ok' => false, 'detail' => 'unsupported database metadata driver'];
        }

        $verified = 0;
        foreach (self::APPEND_ONLY_TRIGGERS as $name => $expected) {
            $trigger = $actual[$name] ?? null;
            if ($trigger === null || $trigger['table'] !== $expected['table']) {
                continue;
            }
            if ($this->database->driver() === 'sqlite') {
                $operationOk = str_contains($trigger['operation'], 'BEFORE ' . $expected['operation']);
                $timingOk = $operationOk;
                $guardOk = str_contains($trigger['guard'], 'RAISE(ABORT');
            } else {
                $operationOk = $trigger['operation'] === $expected['operation'];
                $timingOk = $trigger['timing'] === 'BEFORE';
                $guardOk = str_contains($trigger['guard'], 'SIGNAL SQLSTATE');
            }
            if ($operationOk && $timingOk && $guardOk) {
                $verified++;
            }
        }
        $expected = count(self::APPEND_ONLY_TRIGGERS);
        return ['ok' => $verified === $expected, 'detail' => "{$verified} of {$expected} append-only trigger guards verified"];
    }

    /** @return array{ok:bool,detail:string} */
    private function privateConfigPlacementHealth(): array
    {
        $environment = realpath($this->root . '/.env');
        $configuration = realpath($this->root . '/config');
        $public = realpath($this->root . '/public');
        $ok = is_file($this->root . '/.env')
            && $environment !== false
            && $configuration !== false
            && is_dir($configuration)
            && $public !== false
            && is_dir($public)
            && !$this->pathInside($environment, $public)
            && !$this->pathInside($configuration, $public);
        return [
            'ok' => $ok,
            'detail' => $ok ? 'environment and configuration are outside the public document root' : 'private configuration placement requires review',
        ];
    }

    /** @return array{ok:bool,detail:string} */
    private function privateConfigPermissionHealth(): array
    {
        $environment = $this->root . '/.env';
        $configuration = $this->root . '/config';
        if (!is_file($environment) || !is_readable($environment) || !is_dir($configuration)) {
            return ['ok' => false, 'detail' => 'private configuration permissions could not be verified'];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return ['ok' => true, 'detail' => 'local ACL is non-POSIX; verify mode 600 for the environment file in cPanel'];
        }

        $environmentMode = @fileperms($environment);
        $ok = $environmentMode !== false && (($environmentMode & 0077) === 0);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($configuration, \FilesystemIterator::SKIP_DOTS));
        $paths = array_merge([$configuration], iterator_to_array($iterator, false));
        foreach ($paths as $path) {
            $mode = @fileperms((string) $path);
            if ($mode === false || (($mode & 0022) !== 0)) {
                $ok = false;
                break;
            }
        }
        return [
            'ok' => $ok,
            'detail' => $ok ? 'private configuration permissions are restrictive' : 'use mode 600 for the environment file and remove group/other write access from configuration',
        ];
    }

    /** @return array{ok:bool,detail:string} */
    private function apacheProtectionHealth(): array
    {
        $verified = 0;
        foreach (self::APACHE_PROTECTION_RULES as $relative => $required) {
            $path = $this->root . '/' . $relative;
            if (!is_file($path) || (filesize($path) ?: 0) > 131_072) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $normalized = strtolower($contents);
            $valid = true;
            foreach ($required as $fragment) {
                if (!str_contains($normalized, $fragment)) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $verified++;
            }
        }
        $expected = count(self::APACHE_PROTECTION_RULES);
        return ['ok' => $verified === $expected, 'detail' => "{$verified} of {$expected} required Apache protection files verified"];
    }

    private function pathInside(string $candidate, string $directory): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $directory = strtolower($directory);
        }
        return $candidate === $directory || str_starts_with($candidate, $directory . '/');
    }

    private function writableDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }
        $probe = $path . DIRECTORY_SEPARATOR . '.write-check-' . bin2hex(random_bytes(6));
        $handle = @fopen($probe, 'xb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @unlink($probe);
        return true;
    }
}
