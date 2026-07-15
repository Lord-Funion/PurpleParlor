<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

final readonly class LoginResult
{
    private function __construct(
        public bool $successful,
        public string $status,
        public ?User $user = null,
        public ?string $rememberToken = null,
    ) {
    }

    public static function success(User $user, ?string $rememberToken = null): self
    {
        return new self(true, 'authenticated', $user, $rememberToken);
    }

    public static function twoFactor(User $user): self
    {
        return new self(false, 'two_factor_required', $user);
    }

    public static function twoFactorSetup(User $user): self
    {
        return new self(true, 'two_factor_setup_required', $user);
    }

    public static function failure(string $status = 'invalid_credentials'): self
    {
        return new self(false, $status);
    }
}
