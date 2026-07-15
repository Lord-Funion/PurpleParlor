<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\RequestContext;
use App\Database\Database;

final class PaymentAuditService
{
    public function __construct(private readonly Database $database, private readonly string $appKey)
    {
    }

    public function record(string $action, string $targetType, ?string $targetId, array $details = [], ?int $actorUserId = null): int
    {
        return $this->database->transaction(function (Database $db) use ($action, $targetType, $targetId, $details, $actorUserId): int {
            $last = $db->fetchOne($db->forUpdate('SELECT entry_hash FROM payment_audit_logs ORDER BY id DESC LIMIT 1'));
            $previousHash = (string) ($last['entry_hash'] ?? str_repeat('0', 64));
            $json = json_encode($this->sanitize($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $now = gmdate('Y-m-d H:i:s');
            $requestId = RequestContext::id();
            $canonical = implode('|', [$actorUserId ?? 0, $action, $targetType, $targetId ?? '', hash('sha256', $json), $requestId, $previousHash, $now]);
            $hash = hash_hmac('sha256', $canonical, $this->key());
            return $db->insert('INSERT INTO payment_audit_logs (actor_user_id, action, target_type, target_id, details_json, request_id, entry_hash, previous_hash, created_at)
                VALUES (:actor, :action, :type, :target, :details, :request, :hash, :previous, :now)', [
                'actor' => $actorUserId, 'action' => $action, 'type' => $targetType, 'target' => $targetId, 'details' => $json,
                'request' => $requestId, 'hash' => $hash, 'previous' => $previousHash, 'now' => $now,
            ]);
        });
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/(?:password|secret|token|authorization|card|cvv|account|email|payer|address)/i', (string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $data[$key] = substr(str_replace(["\r", "\n"], ' ', $value), 0, 500);
            }
        }
        return $data;
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
