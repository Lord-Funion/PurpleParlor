<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\AuthorizationRepository;

final class AuthorizationService
{
    public function __construct(private readonly AuthorizationRepository $repository)
    {
    }

    public function can(?int $userId, string $permission): bool
    {
        return $userId !== null && $userId > 0 && $this->repository->userHasPermission($userId, $permission);
    }

    public function authorize(?int $userId, string $permission): void
    {
        if (!$this->can($userId, $permission)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
