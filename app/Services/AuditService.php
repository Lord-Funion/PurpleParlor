<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\RequestContext;
use App\Database\Database;
use App\Security\SecretRedactor;

final class AuditService
{
    public function __construct(private readonly Database $database, private readonly string $appKey)
    {
    }

    public function record(?int $administratorId, string $action, string $targetType, ?string $targetId, mixed $previous, mixed $new, ?string $reason, ?string $ipHash = null): int
    {
        return $this->database->transaction(function (Database $db) use ($administratorId, $action, $targetType, $targetId, $previous, $new, $reason, $ipHash): int {
            $last = $db->fetchOne($db->forUpdate('SELECT entry_hash FROM admin_audit_logs ORDER BY id DESC LIMIT 1'));
            $previousHash = (string) ($last['entry_hash'] ?? str_repeat('0', 64));
            $now = gmdate('Y-m-d H:i:s');
            $previous = $this->sanitize($previous);
            $new = $this->sanitize($new);
            $reason = $reason === null ? null : (new SecretRedactor())->redactText($reason);
            $previousJson = $previous === null ? null : json_encode($previous, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $newJson = $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $requestId = RequestContext::id();
            $canonical = implode('|', [$administratorId ?? 0, $action, $targetType, $targetId ?? '', hash('sha256', $previousJson ?? ''), hash('sha256', $newJson ?? ''), $reason ?? '', $requestId, $ipHash ?? '', $previousHash, $now]);
            $entryHash = hash_hmac('sha256', $canonical, $this->key());
            return $db->insert('INSERT INTO admin_audit_logs (administrator_id, action, target_type, target_id, previous_json, new_json, reason, request_id, ip_hash, previous_hash, entry_hash, created_at)
                VALUES (:administrator, :action, :target_type, :target_id, :previous_json, :new_json, :reason, :request_id, :ip_hash, :previous_hash, :entry_hash, :created_at)', [
                'administrator' => $administratorId, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
                'previous_json' => $previousJson, 'new_json' => $newJson, 'reason' => $reason, 'request_id' => $requestId, 'ip_hash' => $ipHash,
                'previous_hash' => $previousHash, 'entry_hash' => $entryHash, 'created_at' => $now,
            ]);
        });
    }

    private function key(): string
    {
        if (str_starts_with($this->appKey, 'base64:')) {
            $decoded = base64_decode(substr($this->appKey, 7), true);
            return $decoded === false ? $this->appKey : $decoded;
        }
        return $this->appKey;
    }

    private function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? (new SecretRedactor())->redactText($value) : $value;
        }
        return (new SecretRedactor())->redactArray($value);
    }
}
