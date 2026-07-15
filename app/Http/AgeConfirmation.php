<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;

final class AgeConfirmation
{
    private const COOKIE = 'purple_parlor_age';

    public function __construct(private readonly Config $config, private readonly string $key)
    {
    }

    public function confirmed(): bool
    {
        $version = (int) $this->config->get('app.legal_policy_version', 1);
        $minimumAge = (int) $this->config->get('app.minimum_age', 18);
        $session = $_SESSION['age_confirmation'] ?? null;
        if (is_array($session)
            && (int) ($session['policy_version'] ?? 0) === $version
            && (int) ($session['minimum_age'] ?? 0) >= $minimumAge) {
            return true;
        }
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, '.')) {
            return false;
        }
        [$encoded, $signature] = explode('.', $cookie, 2);
        $expected = hash_hmac('sha256', $encoded, $this->decodedKey());
        if (!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals($expected, $signature)) {
            return false;
        }
        $json = $this->decode($encoded);
        $record = $json === null ? null : json_decode($json, true);
        if (!is_array($record)
            || (int) ($record['policy_version'] ?? 0) !== $version
            || (int) ($record['minimum_age'] ?? 0) < $minimumAge
            || (int) ($record['confirmed_at'] ?? 0) < time() - 31_536_000) {
            return false;
        }
        $_SESSION['age_confirmation'] = $record;
        return true;
    }

    public function confirm(): void
    {
        $record = [
            'confirmed_at' => time(),
            'policy_version' => (int) $this->config->get('app.legal_policy_version', 1),
            'minimum_age' => (int) $this->config->get('app.minimum_age', 18),
        ];
        $_SESSION['age_confirmation'] = $record;
        $encoded = $this->encode(json_encode($record, JSON_THROW_ON_ERROR));
        $value = $encoded . '.' . hash_hmac('sha256', $encoded, $this->decodedKey());
        setcookie(self::COOKIE, $value, [
            'expires' => time() + 31_536_000,
            'path' => '/',
            'secure' => (bool) $this->config->get('app.session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function decodedKey(): string
    {
        if (str_starts_with($this->key, 'base64:')) {
            $decoded = base64_decode(substr($this->key, 7), true);
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return $decoded;
            }
        }
        return $this->key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
