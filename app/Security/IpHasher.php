<?php

declare(strict_types=1);

namespace App\Security;

final class IpHasher
{
    public function __construct(private readonly string $appKey)
    {
    }

    public function hash(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            $packed = 'invalid';
        }
        return hash_hmac('sha256', $packed, $this->key());
    }

    private function key(): string
    {
        if (str_starts_with($this->appKey, 'base64:')) {
            $decoded = base64_decode(substr($this->appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $this->appKey;
    }
}
