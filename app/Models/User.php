<?php

declare(strict_types=1);

namespace App\Models;

final readonly class User
{
    /** @param list<string> $roles */
    public function __construct(
        public int $id,
        public string $email,
        public string $username,
        public string $passwordHash,
        public string $status,
        public ?string $emailVerifiedAt,
        public ?string $lockedUntil,
        public int $failedLoginCount,
        public array $roles = [],
    ) {
    }

    public function isLocked(): bool
    {
        return $this->lockedUntil !== null && strtotime($this->lockedUntil) > time();
    }

    public function isAdministrator(): bool
    {
        return array_intersect($this->roles, ['adult_owner', 'developer_admin', 'support_admin', 'content_manager', 'moderator']) !== [];
    }
}
