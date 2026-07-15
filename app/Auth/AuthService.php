<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\Database;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Security\IpHasher;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Services\VirtualLedgerService;
use InvalidArgumentException;

final class AuthService
{
    private const DUMMY_HASH = '$2y$12$FfRTDV9UmwZFaz2MXIG/XO8GI.71l4XYmaRX4dESxPFHkQbEA1xwK';

    public function __construct(
        private readonly Database $database,
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly SessionManager $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly RateLimiter $rateLimiter,
        private readonly IpHasher $ipHasher,
        private readonly string $appKey,
        private readonly ?VirtualLedgerService $ledger = null,
    ) {
    }

    /** @return array{user:User,verification_token:string} */
    public function register(string $email, string $username, string $password, string $ip): array
    {
        // Shared hosting and reverse proxies can legitimately place many
        // visitors behind one REMOTE_ADDR. Limit repeated attempts for the
        // same address without turning that proxy address into a site-wide
        // registration lockout.
        $registrationSubject = $ip . '|' . hash('sha256', strtolower(trim($email)));
        if (!$this->rateLimiter->allow('registration_account', $registrationSubject, 5, 3600)) {
            throw new AuthenticationException('Too many registration attempts. Please try again later.', 'rate_limited');
        }
        $hash = $this->passwords->hash($password);
        try {
            $result = $this->database->transaction(function () use ($email, $username, $hash): array {
                $user = $this->users->create($email, $username, $hash);
                $this->users->assignRole($user->id, 'member');
                if ($this->ledger !== null) {
                    $this->ledger->apply($user->id, VirtualLedgerService::COZY_COINS, 10_000, 'account.initial_grant', 'registration:coins:' . $user->id);
                    $this->ledger->apply($user->id, VirtualLedgerService::PARLOR_STARS, 100, 'account.initial_grant', 'registration:stars:' . $user->id);
                }
                $token = $this->issueEmailVerification($user->id);
                return ['user' => $this->users->findById($user->id) ?? $user, 'verification_token' => $token];
            });
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19'], true)) {
                throw new AuthenticationException('That email address or username cannot be used.', 'duplicate_account');
            }
            throw $e;
        }
        $this->securityEvent($result['user']->id, 'registration', 'info', $ip);
        return $result;
    }

    public function login(string $identifier, string $password, string $ip, string $userAgent, bool $remember = false): LoginResult
    {
        $subject = strtolower(trim($identifier)) . '|' . $ip;
        if (!$this->rateLimiter->allow('login', $subject, 10, 900)) {
            $this->securityEvent(null, 'login_rate_limited', 'warning', $ip);
            return LoginResult::failure('rate_limited');
        }
        try {
            $user = str_contains($identifier, '@') ? $this->users->findByEmail($identifier) : $this->users->findByUsername($identifier);
        } catch (InvalidArgumentException) {
            $user = null;
        }
        $verified = $this->passwords->verify($password, $user?->passwordHash ?? self::DUMMY_HASH);
        $this->recordLoginAttempt($identifier, $ip, $verified && $user !== null);
        if ($user === null || !$verified) {
            if ($user !== null) {
                $this->users->recordFailedLogin($user->id);
            }
            $this->securityEvent($user?->id, 'login_failed', 'warning', $ip);
            return LoginResult::failure();
        }
        if ($user->isLocked()) {
            $this->securityEvent($user->id, 'login_locked', 'warning', $ip);
            return LoginResult::failure('locked');
        }
        if (!in_array($user->status, ['active'], true)) {
            return LoginResult::failure($user->status === 'pending_verification' ? 'email_verification_required' : 'account_unavailable');
        }
        $rehash = $this->passwords->needsRehash($user->passwordHash) ? $this->passwords->hash($password) : '';
        $this->users->recordSuccessfulLogin($user->id, $rehash);
        if ($user->isAdministrator() && !$this->twoFactor->enabled($user->id)) {
            $this->securityEvent($user->id, 'admin_two_factor_missing', 'critical', $ip);
            // Administrators need an authenticated session in order to reach the
            // protected enrollment flow. Never create a persistent remember token
            // until two-factor authentication has been confirmed.
            $authenticated = $this->finishLogin($user, $ip, $userAgent, false, false);
            return LoginResult::twoFactorSetup($authenticated->user ?? $user);
        }
        if ($this->twoFactor->enabled($user->id)) {
            $this->sessions->beginTwoFactor($user->id);
            return LoginResult::twoFactor($user);
        }
        return $this->finishLogin($user, $ip, $userAgent, $remember, false);
    }

    public function completeTwoFactor(string $code, string $ip, string $userAgent, bool $remember = false): LoginResult
    {
        if (!$this->rateLimiter->allow('two_factor', $ip, 8, 600)) {
            return LoginResult::failure('rate_limited');
        }
        $userId = $this->sessions->pendingTwoFactorUserId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || !$this->twoFactor->verify($user->id, $code)) {
            $this->securityEvent($user?->id, 'two_factor_failed', 'warning', $ip);
            return LoginResult::failure('invalid_two_factor');
        }
        return $this->finishLogin($user, $ip, $userAgent, $remember, true);
    }

    public function currentUser(): ?User
    {
        $id = $this->sessions->authenticatedUserId();
        if ($id === null) {
            return null;
        }
        $user = $this->users->findById($id);
        if ($user === null || $user->status !== 'active') {
            $this->sessions->logout();
            return null;
        }
        return $user;
    }

    public function logout(): void
    {
        $this->sessions->logout();
    }

    public function issueEmailVerification(int $userId, int $ttlSeconds = 86400, ?string $newEmail = null): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        // Registration resends may be delivered out of order. Keep each
        // unexpired registration token usable until one of them succeeds so
        // a slower first email is not invalidated by a faster resend. Email
        // changes remain replacement-only because an older requested address
        // must never stay authorized after a newer request is made.
        if ($newEmail !== null) {
            $this->database->execute('DELETE FROM email_verifications WHERE user_id = :user AND used_at IS NULL', ['user' => $userId]);
        }
        $this->database->execute('INSERT INTO email_verifications (user_id, token_hash, new_email, expires_at, created_at) VALUES (:user, :hash, :new_email, :expires, :now)', [
            'user' => $userId, 'hash' => $hash, 'new_email' => $newEmail, 'expires' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds), 'now' => gmdate('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** @return array{email:string,verification_token:string} */
    public function requestEmailChange(int $userId, string $newEmail): array
    {
        $email = $this->users->setPendingEmail($userId, $newEmail);
        return ['email' => $email, 'verification_token' => $this->issueEmailVerification($userId, 3600, $email)];
    }

    public function verifyEmail(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        return $this->database->transaction(function (Database $db) use ($token): bool {
            $row = $db->fetchOne($db->forUpdate('SELECT id, user_id, new_email, expires_at, used_at FROM email_verifications WHERE token_hash = :hash'), ['hash' => hash('sha256', $token)]);
            if ($row === null || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
                return false;
            }
            $now = gmdate('Y-m-d H:i:s');
            if ($row['new_email'] !== null) {
                $db->execute('UPDATE email_verifications SET used_at = :now WHERE id = :id AND used_at IS NULL', ['now' => $now, 'id' => $row['id']]);
                $this->users->finalizeEmailChange((int) $row['user_id'], (string) $row['new_email']);
                $this->sessions->revokeAll((int) $row['user_id']);
            } else {
                $db->execute('UPDATE email_verifications SET used_at = :now WHERE user_id = :user AND new_email IS NULL AND used_at IS NULL', [
                    'now' => $now,
                    'user' => $row['user_id'],
                ]);
                $this->users->markVerified((int) $row['user_id']);
            }
            return true;
        });
    }

    /** Returns a token only to the trusted mail-queue caller; public responses must always be generic. */
    public function requestPasswordReset(string $email, string $ip, int $ttlSeconds = 3600): ?array
    {
        if (!$this->rateLimiter->allow('password_reset', $ip, 5, 3600)) {
            return null;
        }
        try {
            $user = $this->users->findByEmail($email);
        } catch (InvalidArgumentException) {
            $user = null;
        }
        if ($user === null || $user->status === 'deleted') {
            password_verify(bin2hex(random_bytes(16)), self::DUMMY_HASH);
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->database->transaction(function (Database $db) use ($user, $token, $ttlSeconds): void {
            $db->execute('UPDATE password_resets SET used_at = :now WHERE user_id = :user AND used_at IS NULL', ['now' => gmdate('Y-m-d H:i:s'), 'user' => $user->id]);
            $db->execute('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:user, :hash, :expires, :now)', [
                'user' => $user->id, 'hash' => hash('sha256', $token), 'expires' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds), 'now' => gmdate('Y-m-d H:i:s'),
            ]);
        });
        return ['user' => $user, 'token' => $token];
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $newHash = $this->passwords->hash($newPassword);
        return $this->database->transaction(function (Database $db) use ($token, $newHash): bool {
            $row = $db->fetchOne($db->forUpdate('SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = :hash'), ['hash' => hash('sha256', $token)]);
            if ($row === null || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
                return false;
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->execute('UPDATE password_resets SET used_at = :now WHERE id = :id AND used_at IS NULL', ['now' => $now, 'id' => $row['id']]);
            $this->users->updatePassword((int) $row['user_id'], $newHash);
            $this->sessions->revokeAll((int) $row['user_id']);
            return true;
        });
    }

    public function reauthenticate(string $password, ?string $twoFactorCode = null): bool
    {
        $user = $this->currentUser();
        if ($user === null || !$this->passwords->verify($password, $user->passwordHash)) {
            return false;
        }
        $twoFactorEnabled = $this->twoFactor->enabled($user->id);
        if ($twoFactorEnabled && ($twoFactorCode === null || !$this->twoFactor->verify($user->id, $twoFactorCode))) {
            return false;
        }
        $this->sessions->markPrivileged($twoFactorEnabled);
        return true;
    }

    public function requirePrivilegedSession(): void
    {
        if (!$this->sessions->privileged()) {
            throw new AuthenticationException('Please reauthenticate to continue.', 'reauthentication_required');
        }
    }

    private function finishLogin(User $user, string $ip, string $userAgent, bool $remember, bool $twoFactorVerified): LoginResult
    {
        // A password-authenticated login is recent privilege. Two-factor proof
        // is carried separately so a remembered identity cannot inherit it.
        $this->sessions->authenticate($user->id, $ip, $userAgent, true, $twoFactorVerified);
        $rememberToken = $remember ? $this->sessions->issueRememberToken($user->id) : null;
        $this->rateLimiter->clear('login', strtolower($user->email) . '|' . $ip);
        $this->rateLimiter->clear('login', strtolower($user->username) . '|' . $ip);
        $this->securityEvent($user->id, 'login_succeeded', 'info', $ip);
        return LoginResult::success($this->users->findById($user->id) ?? $user, $rememberToken);
    }

    private function recordLoginAttempt(string $identifier, string $ip, bool $successful): void
    {
        $this->database->execute('INSERT INTO login_attempts (email_hash, ip_hash, successful, attempted_at) VALUES (:email, :ip, :successful, :now)', [
            'email' => hash_hmac('sha256', strtolower(trim($identifier)), $this->appKey), 'ip' => $this->ipHasher->hash($ip),
            'successful' => $successful ? 1 : 0, 'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function securityEvent(?int $userId, string $type, string $severity, string $ip): void
    {
        $this->database->execute('INSERT INTO security_events (user_id, event_type, severity, ip_hash, created_at) VALUES (:user, :type, :severity, :ip, :now)', [
            'user' => $userId, 'type' => $type, 'severity' => $severity, 'ip' => $this->ipHasher->hash($ip), 'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
