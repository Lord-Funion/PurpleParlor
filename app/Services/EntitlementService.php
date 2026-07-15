<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DomainException;

final class EntitlementService
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function hasEntitlement(int $userId, string $entitlement): bool
    {
        $this->assertKey($entitlement);
        $cacheKey = $userId . ':' . $entitlement;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }
        $row = $this->database->fetchOne(
            'SELECT 1 AS owned FROM user_entitlements WHERE user_id = :user AND entitlement_key = :entitlement
             AND revoked_at IS NULL AND starts_at <= :starts_now AND (ends_at IS NULL OR ends_at > :ends_now) LIMIT 1',
            ['user' => $userId, 'entitlement' => $entitlement, 'starts_now' => gmdate('Y-m-d H:i:s'), 'ends_now' => gmdate('Y-m-d H:i:s')],
        );
        return $this->cache[$cacheKey] = $row !== null;
    }

    public function grant(int $userId, string $entitlement, string $sourceType, string $sourceId, ?string $startsAt = null, ?string $endsAt = null): void
    {
        $this->assertKey($entitlement);
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $sourceType) || $sourceId === '' || strlen($sourceId) > 191) {
            throw new DomainException('Invalid entitlement source.');
        }
        $existing = $this->database->fetchOne('SELECT id FROM user_entitlements WHERE user_id = :user AND entitlement_key = :entitlement AND source_type = :type AND source_id = :source',
            ['user' => $userId, 'entitlement' => $entitlement, 'type' => $sourceType, 'source' => $sourceId]);
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $this->database->execute('INSERT INTO user_entitlements (user_id, entitlement_key, source_type, source_id, starts_at, ends_at, created_at, updated_at)
                VALUES (:user, :entitlement, :type, :source, :starts, :ends, :created_at, :updated_at)', [
                'user' => $userId, 'entitlement' => $entitlement, 'type' => $sourceType, 'source' => $sourceId,
                'starts' => $startsAt ?? $now, 'ends' => $endsAt, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $this->database->execute('UPDATE user_entitlements SET starts_at = :starts, ends_at = :ends, revoked_at = NULL, revocation_reason = NULL, updated_at = :now WHERE id = :id',
                ['starts' => $startsAt ?? $now, 'ends' => $endsAt, 'now' => $now, 'id' => $existing['id']]);
        }
        $this->invalidate($userId);
    }

    public function revokeSource(int $userId, string $sourceType, string $sourceId, string $reason): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $count = $this->database->execute('UPDATE user_entitlements SET revoked_at = :revoked_at, revocation_reason = :reason, updated_at = :updated_at
            WHERE user_id = :user AND source_type = :type AND source_id = :source AND revoked_at IS NULL', [
            'revoked_at' => $now, 'updated_at' => $now, 'reason' => substr($reason, 0, 100), 'user' => $userId, 'type' => $sourceType, 'source' => $sourceId,
        ]);
        $this->invalidate($userId);
        return $count;
    }

    public function expireDue(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $count = $this->database->execute(
            "UPDATE user_entitlements SET revoked_at = :revoked_at, revocation_reason = 'expired', updated_at = :updated_at WHERE revoked_at IS NULL AND ends_at IS NOT NULL AND ends_at <= :cutoff",
            ['revoked_at' => $now, 'updated_at' => $now, 'cutoff' => $now],
        );
        $this->cache = [];
        return $count;
    }

    public function invalidate(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
            return;
        }
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->cache[$key]);
            }
        }
    }

    private function assertKey(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $key)) {
            throw new DomainException('Invalid entitlement key.');
        }
    }
}
