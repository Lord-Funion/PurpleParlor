<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Security;

use InvalidArgumentException;

final class OutcomeSigner
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Game response signing key must contain at least 32 bytes.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', self::canonicalJson($payload), $this->key);
    }

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1
            && hash_equals($this->sign($payload), $signature);
    }

    /** @param mixed $value */
    private static function sortValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::sortValue($child);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalJson(array $payload): string
    {
        return json_encode(self::sortValue($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
