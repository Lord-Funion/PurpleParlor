<?php

declare(strict_types=1);

namespace App\Services;

final class AgeConfirmationService
{
    public function __construct(private readonly string $appKey)
    {
    }

    /** Returns a signed cookie value containing only the confirmation timestamp and policy version. */
    public function issue(string $policyVersion): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $policyVersion)) {
            throw new \DomainException('Invalid age policy.');
        }
        $payload = json_encode(['confirmed_at' => time(), 'policy_version' => $policyVersion], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->key());
    }

    public function validate(?string $cookieValue, string $currentPolicyVersion): bool
    {
        if (!is_string($cookieValue)) {
            return false;
        }
        [$encoded, $signature] = array_pad(explode('.', $cookieValue, 2), 2, '');
        if ($encoded === '' || !preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals(hash_hmac('sha256', $encoded, $this->key()), $signature)) {
            return false;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        return is_array($payload)
            && is_int($payload['confirmed_at'] ?? null)
            && $payload['confirmed_at'] <= time() + 300
            && hash_equals($currentPolicyVersion, (string) ($payload['policy_version'] ?? ''));
    }

    private function key(): string
    {
        if (str_starts_with($this->appKey, 'base64:')) {
            $decoded = base64_decode(substr($this->appKey, 7), true);
            return $decoded === false ? $this->appKey : $decoded;
        }
        return $this->appKey;
    }
}
