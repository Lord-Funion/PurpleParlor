<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class AuthorizationRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function userHasPermission(int $userId, string $permission): bool
    {
        return $this->database->fetchOne(
            'SELECT 1 AS allowed FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             INNER JOIN users u ON u.id = ur.user_id
             WHERE ur.user_id = :user AND p.name = :permission AND u.status = :status AND u.deleted_at IS NULL LIMIT 1',
            ['user' => $userId, 'permission' => $permission, 'status' => 'active'],
        ) !== null;
    }
}
