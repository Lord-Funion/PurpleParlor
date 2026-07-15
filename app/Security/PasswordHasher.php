<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $this->assertAcceptable($password);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }

    public function assertAcceptable(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new RuntimeException('Password must be between 12 and 1024 bytes.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password)) {
            throw new RuntimeException('Password contains an unsupported control character.');
        }
    }
}
