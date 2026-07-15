<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Database;
use App\Security\Encryptor;
use App\Security\Totp;

final class TwoFactorService
{
    public function __construct(
        private readonly Database $database,
        private readonly Encryptor $encryptor,
        private readonly Totp $totp,
    ) {
    }

    /** @return array{secret:string,recovery_codes:list<string>} */
    public function beginSetup(int $userId): array
    {
        $secret = $this->totp->generateSecret();
        $encrypted = $this->encryptor->encrypt($secret, 'totp:' . $userId);
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $db) use ($userId, $encrypted, $now): void {
            $db->execute('DELETE FROM two_factor_secrets WHERE user_id = :user', ['user' => $userId]);
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :user', ['user' => $userId]);
            $db->execute('INSERT INTO two_factor_secrets (user_id, encrypted_secret, enabled, updated_at) VALUES (:user, :secret, 0, :now)', ['user' => $userId, 'secret' => $encrypted, 'now' => $now]);
        });
        return ['secret' => $secret, 'recovery_codes' => $this->createRecoveryCodes($userId)];
    }

    public function confirm(int $userId, string $code): bool
    {
        return $this->database->transaction(function (Database $db) use ($userId, $code): bool {
            $row = $db->fetchOne($db->forUpdate('SELECT encrypted_secret, enabled, last_counter FROM two_factor_secrets WHERE user_id = :user'), ['user' => $userId]);
            if ($row === null || (int) $row['enabled'] === 1) {
                return false;
            }
            $secret = $this->encryptor->decrypt((string) $row['encrypted_secret'], 'totp:' . $userId);
            $counter = $this->totp->verify($secret, $code, null, 1, $row['last_counter'] === null ? null : (int) $row['last_counter']);
            if ($counter === null) {
                return false;
            }
            $db->execute('UPDATE two_factor_secrets SET enabled = 1, confirmed_at = :now, last_counter = :counter, updated_at = :now WHERE user_id = :user', ['now' => gmdate('Y-m-d H:i:s'), 'counter' => $counter, 'user' => $userId]);
            return true;
        });
    }

    public function enabled(int $userId): bool
    {
        return $this->database->fetchOne('SELECT 1 AS enabled FROM two_factor_secrets WHERE user_id = :user AND enabled = 1', ['user' => $userId]) !== null;
    }

    public function verify(int $userId, string $code): bool
    {
        return $this->database->transaction(function (Database $db) use ($userId, $code): bool {
            $row = $db->fetchOne($db->forUpdate('SELECT encrypted_secret, enabled, last_counter FROM two_factor_secrets WHERE user_id = :user'), ['user' => $userId]);
            if ($row === null || (int) $row['enabled'] !== 1) {
                return false;
            }
            $secret = $this->encryptor->decrypt((string) $row['encrypted_secret'], 'totp:' . $userId);
            $counter = $this->totp->verify($secret, $code, null, 1, $row['last_counter'] === null ? null : (int) $row['last_counter']);
            if ($counter !== null) {
                $db->execute('UPDATE two_factor_secrets SET last_counter = :counter, updated_at = :now WHERE user_id = :user', ['counter' => $counter, 'now' => gmdate('Y-m-d H:i:s'), 'user' => $userId]);
                return true;
            }
            $recovery = $db->fetchAll('SELECT id, code_hash FROM recovery_codes WHERE user_id = :user AND used_at IS NULL', ['user' => $userId]);
            foreach ($recovery as $record) {
                if (password_verify(strtoupper(trim($code)), (string) $record['code_hash'])) {
                    $db->execute('UPDATE recovery_codes SET used_at = :now WHERE id = :id AND used_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $record['id']]);
                    return true;
                }
            }
            return false;
        });
    }

    public function disable(int $userId): void
    {
        $this->database->transaction(function (Database $db) use ($userId): void {
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :user', ['user' => $userId]);
            $db->execute('DELETE FROM two_factor_secrets WHERE user_id = :user', ['user' => $userId]);
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(int $userId): array
    {
        return $this->database->transaction(function (Database $db) use ($userId): array {
            $record = $db->fetchOne($db->forUpdate('SELECT enabled FROM two_factor_secrets WHERE user_id = :user'), ['user' => $userId]);
            if ($record === null || (int) $record['enabled'] !== 1) {
                throw new \RuntimeException('Two-factor authentication must be enabled before recovery codes can be regenerated.');
            }
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :user', ['user' => $userId]);
            return $this->createRecoveryCodes($userId);
        });
    }

    /** @return list<string> */
    private function createRecoveryCodes(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $this->database->execute('INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (:user, :hash, :now)', ['user' => $userId, 'hash' => $hash, 'now' => gmdate('Y-m-d H:i:s')]);
            $codes[] = $code;
        }
        return $codes;
    }
}
