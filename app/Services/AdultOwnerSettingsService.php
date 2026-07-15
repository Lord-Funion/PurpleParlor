<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Core\Config;
use App\Database\Database;
use RuntimeException;

final class AdultOwnerSettingsService
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{minimum_age:int,policy_version:string} */
    public function agePolicy(): array
    {
        $age = $this->setting('site.minimum_age', (int) $this->config->get('app.minimum_age', 18));
        $version = $this->setting('site.age_policy_version', (string) $this->config->get('app.legal_policy_version', 1));
        return ['minimum_age' => (int) $age, 'policy_version' => (string) $version];
    }

    public function updateMinimumAge(int $minimumAge, bool $explicitlyConfirmLowering, string $reason, ?string $ipHash = null): array
    {
        $owner = $this->requireOwner();
        if ($minimumAge < 18 || $minimumAge > 25) {
            throw new RuntimeException('Configured minimum age must remain between 18 and 25.');
        }
        $current = $this->agePolicy();
        if ($minimumAge < $current['minimum_age'] && !$explicitlyConfirmLowering) {
            throw new RuntimeException('Lowering the minimum age requires an explicit Adult Owner confirmation.');
        }
        $nextVersion = (string) (((int) preg_replace('/\D/', '', $current['policy_version'])) + 1);
        $this->database->transaction(function () use ($owner, $current, $minimumAge, $nextVersion, $reason, $ipHash): void {
            $this->writeSetting('site.minimum_age', $minimumAge, $owner->id);
            $this->writeSetting('site.age_policy_version', $nextVersion, $owner->id);
            $this->audit->record($owner->id, 'age_policy.updated', 'system_setting', 'site.minimum_age', $current,
                ['minimum_age' => $minimumAge, 'policy_version' => $nextVersion], $reason, $ipHash);
        });
        return ['minimum_age' => $minimumAge, 'policy_version' => $nextVersion];
    }

    public function confirmLiveActivationLock(bool $locked, string $reason, ?string $ipHash = null): void
    {
        $owner = $this->requireOwner();
        if (!$locked && ($this->config->get('payments.enabled') !== true || $this->config->get('payments.mode') !== 'live'
            || $this->config->get('payments.adult_owner_confirmed') !== true || $this->config->get('payments.live_activation_lock') !== false)) {
            throw new RuntimeException('Every environment-level production payment safeguard must be satisfied before the server lock can be released.');
        }
        $previous = (bool) $this->setting('payments.production_locked', true);
        $this->database->transaction(function () use ($owner, $locked, $reason, $previous, $ipHash): void {
            $this->writeSetting('payments.production_locked', $locked, $owner->id);
            $this->audit->record($owner->id, 'payments.live_activation_lock_changed', 'system_setting', 'payments.production_locked',
                ['locked' => $previous], ['locked' => $locked], $reason, $ipHash);
        });
    }

    private function requireOwner(): \App\Models\User
    {
        $user = $this->auth->currentUser() ?? throw new RuntimeException('Authentication required.');
        if (!in_array('adult_owner', $user->roles, true)) {
            throw new RuntimeException('Only an Adult Owner may perform this action.');
        }
        $this->auth->requirePrivilegedSession();
        return $user;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $row = $this->database->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = :key', ['key' => $key]);
        if ($row === null) {
            return $default;
        }
        $decoded = json_decode((string) $row['setting_value'], true);
        return $decoded ?? $default;
    }

    private function writeSetting(string $key, mixed $value, int $actor): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $params = ['key' => $key, 'value' => $json, 'actor' => $actor, 'now' => gmdate('Y-m-d H:i:s')];
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,:actor,:now)
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            : 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,:actor,:now)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_by=excluded.updated_by,updated_at=excluded.updated_at';
        $this->database->execute($sql, $params);
    }
}
