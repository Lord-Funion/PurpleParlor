<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Database\Database;
use App\Security\IpHasher;
use RuntimeException;

final class SessionManager
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly IpHasher $ipHasher,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $sameSite = ucfirst(strtolower((string) $this->config->get('app.session.same_site', 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }
        $secure = (bool) $this->config->get('app.session.secure', false);
        if ($sameSite === 'None' && !$secure) {
            throw new RuntimeException('SameSite=None requires secure session cookies.');
        }
        session_name((string) $this->config->get('app.session.cookie', 'purple_parlor_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) $this->config->get('app.session.path', '/'),
            'domain' => (string) $this->config->get('app.session.domain', ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        $storage = trim((string) $this->config->get('app.session.storage_path', ''));
        if ($storage !== '') {
            if (!is_dir($storage)) {
                throw new RuntimeException('Private session storage is unavailable.');
            }
            // is_writable() returns false for some valid Windows/OneDrive
            // directories and ACL combinations. session_start() is the
            // authoritative write test and still fails closed below.
            session_save_path($storage);
        }
        if (!session_start()) {
            throw new RuntimeException('Unable to start a secure session.');
        }
    }

    public function authenticate(
        int $userId,
        string $ip,
        string $userAgent,
        bool $privileged = false,
        bool $twoFactorVerified = false,
    ): int
    {
        $this->start();
        session_regenerate_id(true);
        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + ((int) $this->config->get('app.session.lifetime_minutes', 120) * 60));
        $id = $this->database->insert(
            'INSERT INTO sessions (user_id, selector, token_hash, php_session_id, ip_hash, user_agent, last_seen_at, expires_at, created_at)
             VALUES (:user, :selector, :hash, :php_session_id, :ip_hash, :agent, :last_seen_at, :expires, :created_at)',
            ['user' => $userId, 'selector' => $selector, 'hash' => hash('sha256', $token), 'php_session_id' => session_id(),
                'ip_hash' => $this->ipHasher->hash($ip), 'agent' => substr($userAgent, 0, 255),
                'last_seen_at' => $now, 'expires' => $expires, 'created_at' => $now],
        );
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_session_id'] = $id;
        $_SESSION['auth_selector'] = $selector;
        $_SESSION['auth_token'] = $token;
        $_SESSION['authenticated_at'] = time();
        if ($privileged) {
            $_SESSION['privileged_at'] = time();
        } else {
            unset($_SESSION['privileged_at']);
        }
        if ($twoFactorVerified) {
            $_SESSION['two_factor_verified_at'] = time();
        } else {
            unset($_SESSION['two_factor_verified_at']);
        }
        unset($_SESSION['two_factor_user_id'], $_SESSION['two_factor_started_at']);
        return $id;
    }

    public function authenticatedUserId(): ?int
    {
        $this->start();
        $id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $sessionId = isset($_SESSION['auth_session_id']) ? (int) $_SESSION['auth_session_id'] : 0;
        $selector = (string) ($_SESSION['auth_selector'] ?? '');
        $token = (string) ($_SESSION['auth_token'] ?? '');
        if ($id <= 0 || $sessionId <= 0 || $selector === '' || $token === '') {
            return null;
        }
        $row = $this->database->fetchOne(
            'SELECT user_id, token_hash, php_session_id, expires_at, revoked_at FROM sessions WHERE id = :id AND selector = :selector',
            ['id' => $sessionId, 'selector' => $selector],
        );
        if ($row === null || (int) $row['user_id'] !== $id || $row['revoked_at'] !== null
            || strtotime((string) $row['expires_at']) <= time()
            || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            $this->clearLocal();
            return null;
        }
        // Some shared-hosting handlers legitimately replace the PHP session
        // identifier while preserving the protected payload. Once every
        // database-backed credential above has been verified, rebind the row
        // instead of discarding the valid login and restoring it from the
        // remember-me cookie on every request.
        if ((string) ($row['php_session_id'] ?? '') !== session_id()) {
            $this->database->execute(
                'UPDATE sessions SET php_session_id = :php_session_id WHERE id = :id',
                ['php_session_id' => session_id(), 'id' => $sessionId],
            );
        }
        if (!isset($_SESSION['_last_touch']) || (int) $_SESSION['_last_touch'] < time() - 300) {
            $this->database->execute('UPDATE sessions SET last_seen_at = :now WHERE id = :id', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $sessionId]);
            $_SESSION['_last_touch'] = time();
        }
        return $id;
    }

    public function beginTwoFactor(int $userId): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION = [
            'two_factor_user_id' => $userId,
            'two_factor_started_at' => time(),
            '_csrf' => $_SESSION['_csrf'] ?? [],
        ];
    }

    public function pendingTwoFactorUserId(): ?int
    {
        $this->start();
        $started = (int) ($_SESSION['two_factor_started_at'] ?? 0);
        if ($started < time() - 300) {
            unset($_SESSION['two_factor_user_id'], $_SESSION['two_factor_started_at']);
            return null;
        }
        $id = (int) ($_SESSION['two_factor_user_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public function markPrivileged(bool $twoFactorVerified = false): void
    {
        $this->start();
        $_SESSION['privileged_at'] = time();
        if ($twoFactorVerified) {
            $_SESSION['two_factor_verified_at'] = time();
        }
    }

    public function privileged(): bool
    {
        $this->start();
        $ttl = (int) $this->config->get('app.session.privileged_minutes', 15) * 60;
        return (int) ($_SESSION['privileged_at'] ?? 0) >= time() - $ttl;
    }

    public function issuePrivilegedProof(int $userId, string $userAgent): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('A valid user is required for privileged access.');
        }
        $ttl = max(60, (int) $this->config->get('app.session.privileged_minutes', 15) * 60);
        $payload = $userId . '|' . (time() + $ttl) . '|' . hash('sha256', substr($userAgent, 0, 500));
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->privilegedSigningKey());
    }

    public function validatePrivilegedProof(?string $proof, int $userId, string $userAgent): bool
    {
        if ($userId <= 0 || !is_string($proof) || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $proof, $matches) !== 1) {
            return false;
        }
        $encoded = $matches[1];
        if (!hash_equals(hash_hmac('sha256', $encoded, $this->privilegedSigningKey()), $matches[2])) {
            return false;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if (!is_string($decoded)) {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return false;
        }
        $expires = (int) $parts[1];
        $ttl = max(60, (int) $this->config->get('app.session.privileged_minutes', 15) * 60);
        return (int) $parts[0] === $userId
            && $expires >= time()
            && $expires <= time() + $ttl + 60
            && hash_equals($parts[2], hash('sha256', substr($userAgent, 0, 500)));
    }

    private function privilegedSigningKey(): string
    {
        $key = (string) $this->config->get('app.key', '');
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY must be configured before privileged access can be granted.');
        }
        return $key;
    }

    public function twoFactorVerified(): bool
    {
        $this->start();
        return (int) ($_SESSION['two_factor_verified_at'] ?? 0) > 0;
    }

    public function logout(): void
    {
        $this->start();
        if (isset($_SESSION['auth_session_id'])) {
            $this->database->execute('UPDATE sessions SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'id' => (int) $_SESSION['auth_session_id']]);
        }
        $this->clearLocal();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => $params['samesite'] ?? 'Lax']);
        }
        session_destroy();
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->database->fetchAll('SELECT id, user_agent, last_seen_at, expires_at, created_at, revoked_at FROM sessions WHERE user_id = :user ORDER BY last_seen_at DESC', ['user' => $userId]);
    }

    public function revoke(int $userId, int $sessionId): bool
    {
        return $this->database->execute('UPDATE sessions SET revoked_at = :now WHERE id = :id AND user_id = :user AND revoked_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $sessionId, 'user' => $userId]) === 1;
    }

    public function revokeAll(int $userId): void
    {
        $this->database->execute('UPDATE sessions SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'user' => $userId]);
        $this->database->execute('DELETE FROM remember_tokens WHERE user_id = :user', ['user' => $userId]);
    }

    public function issueRememberToken(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $days = max(1, (int) $this->config->get('app.session.remember_days', 30));
        $this->database->execute('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, created_at) VALUES (:user, :selector, :hash, :expires, :now)', [
            'user' => $userId, 'selector' => $selector, 'hash' => hash('sha256', $validator),
            'expires' => gmdate('Y-m-d H:i:s', time() + ($days * 86400)), 'now' => gmdate('Y-m-d H:i:s'),
        ]);
        return $selector . ':' . $validator;
    }

    public function consumeRememberToken(string $cookie, string $ip, string $userAgent): ?string
    {
        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return null;
        }
        return $this->database->transaction(function (Database $db) use ($selector, $validator, $ip, $userAgent): ?string {
            $row = $db->fetchOne($db->forUpdate('SELECT id, user_id, token_hash, expires_at FROM remember_tokens WHERE selector = :selector'), ['selector' => $selector]);
            if ($row === null || strtotime((string) $row['expires_at']) <= time() || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
                if ($row !== null) {
                    $db->execute('DELETE FROM remember_tokens WHERE user_id = :user', ['user' => $row['user_id']]);
                }
                return null;
            }
            $db->execute('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            // A persistent cookie restores identity only. It must never restore
            // recent-password privilege or proof that this browser session
            // completed two-factor authentication.
            $this->authenticate((int) $row['user_id'], $ip, $userAgent, false, false);
            return $this->issueRememberToken((int) $row['user_id']);
        });
    }

    private function clearLocal(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_session_id'],
            $_SESSION['auth_selector'],
            $_SESSION['auth_token'],
            $_SESSION['authenticated_at'],
            $_SESSION['privileged_at'],
            $_SESSION['two_factor_verified_at'],
        );
        session_regenerate_id(true);
    }
}
