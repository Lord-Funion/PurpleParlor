<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public function __construct(private readonly int $ttlSeconds = 7200)
    {
    }

    public function token(string $scope = 'default'): string
    {
        $this->ensureSession();
        $signed = $this->signedToken($scope, $this->timeBucket());
        if ($signed !== null) {
            return $signed;
        }

        $record = $_SESSION['_csrf'][$scope] ?? null;
        if (!is_array($record) || (int) ($record['expires'] ?? 0) < time()) {
            $record = ['token' => bin2hex(random_bytes(32)), 'expires' => time() + $this->ttlSeconds];
            $_SESSION['_csrf'][$scope] = $record;
        }
        return (string) $record['token'];
    }

    public function validate(?string $token, string $scope = 'default', bool $rotate = false): bool
    {
        $this->ensureSession();

        // Prefer a stateless, session-bound token in configured environments.
        // This avoids false 419 responses on shared hosts whose PHP session
        // handler can delay or skip a session-file rewrite between the form GET
        // and its POST. The token remains unforgeable without APP_KEY and is
        // valid only for this PHP session and a short rolling time window.
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            $bucket = $this->timeBucket();
            foreach ([$bucket, $bucket - 1] as $candidateBucket) {
                $expected = $this->signedToken($scope, $candidateBucket);
                if ($expected !== null && hash_equals($expected, $token)) {
                    return true;
                }
            }
        }

        $record = $_SESSION['_csrf'][$scope] ?? null;
        $valid = is_string($token) && is_array($record)
            && (int) ($record['expires'] ?? 0) >= time()
            && hash_equals((string) ($record['token'] ?? ''), $token);
        if ($valid && $rotate) {
            unset($_SESSION['_csrf'][$scope]);
        }
        return $valid;
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('A secure PHP session must be started before using CSRF protection.');
        }
    }

    private function timeBucket(): int
    {
        return (int) floor(time() / max(300, $this->ttlSeconds));
    }

    private function signedToken(string $scope, int $bucket): ?string
    {
        // Authenticated sessions already carry a high-entropy credential that
        // SessionManager verifies against the database on every request. Use
        // it as the stable binding when available: some shared-hosting session
        // handlers can replace the PHP session identifier while preserving the
        // authenticated session payload. Guests continue to bind to session_id.
        $sessionId = is_string($_SESSION['auth_token'] ?? null) && $_SESSION['auth_token'] !== ''
            ? 'auth:' . $_SESSION['auth_token']
            : 'guest:' . session_id();
        $appKey = function_exists('env') ? trim((string) env('APP_KEY', '')) : '';
        if ($sessionId === '' || $appKey === '') {
            return null;
        }

        return hash_hmac('sha256', $sessionId . '|' . $scope . '|' . $bucket, $appKey);
    }
}
