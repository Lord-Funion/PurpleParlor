<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use App\Security\SecretRedactor;

/**
 * Read-only, deliberately narrow projection for the commerce audit panel.
 * Webhook payloads and audit-chain hashes are never selected from storage.
 */
final class PaymentAuditHistoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly SecretRedactor $redactor,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(bool $allowDetailedHistory): array
    {
        $webhookSummary = $this->database->fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END), 0) AS verified,
                    COALESCE(SUM(CASE WHEN status IN ('failed','error','rejected') THEN 1 ELSE 0 END), 0) AS failed
             FROM webhook_events"
        ) ?? [];
        $auditSummary = $this->database->fetchOne('SELECT COUNT(*) AS total, MAX(created_at) AS latest_at FROM payment_audit_logs') ?? [];
        $latestWebhook = $this->database->fetchOne('SELECT received_at, processed_at, status FROM webhook_events ORDER BY id DESC LIMIT 1');

        $snapshot = [
            'detailed' => $allowDetailedHistory,
            'summary' => [
                'audit_events' => (int) ($auditSummary['total'] ?? 0),
                'latest_audit_at' => $auditSummary['latest_at'] === null ? null : (string) $auditSummary['latest_at'],
                'webhook_events' => (int) ($webhookSummary['total'] ?? 0),
                'verified_webhooks' => (int) ($webhookSummary['verified'] ?? 0),
                'failed_webhooks' => (int) ($webhookSummary['failed'] ?? 0),
                'latest_webhook_at' => $latestWebhook === null ? null : (string) $latestWebhook['received_at'],
                'latest_webhook_status' => $latestWebhook === null ? null : $this->safeLabel((string) $latestWebhook['status']),
            ],
            'payment_events' => [],
            'webhook_events' => [],
        ];
        if (!$allowDetailedHistory) {
            return $snapshot;
        }

        foreach ($this->database->fetchAll(
            'SELECT id, actor_user_id, action, target_type, target_id, details_json, request_id, created_at
             FROM payment_audit_logs ORDER BY id DESC LIMIT 100'
        ) as $row) {
            $decoded = json_decode((string) ($row['details_json'] ?? ''), true);
            $details = is_array($decoded) ? $this->redactor->redactArray($decoded) : [];
            $snapshot['payment_events'][] = [
                'id' => (int) $row['id'],
                'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                'action' => $this->safeLabel((string) $row['action']),
                'target' => $this->redactor->redactText((string) $row['target_type'] . ($row['target_id'] === null ? '' : ':' . (string) $row['target_id'])),
                'details' => $details,
                'request_id' => $this->safeLabel((string) ($row['request_id'] ?? '')),
                'created_at' => (string) $row['created_at'],
            ];
        }

        // Intentionally omit payload_json and the full payload hash. The short
        // fingerprint supports correlation without exposing captured content.
        foreach ($this->database->fetchAll(
            'SELECT id, provider, provider_event_id, event_type, signature_valid, status, payload_hash,
                    error_message, received_at, processed_at, expires_at
             FROM webhook_events ORDER BY id DESC LIMIT 100'
        ) as $row) {
            $hash = strtolower((string) $row['payload_hash']);
            $snapshot['webhook_events'][] = [
                'id' => (int) $row['id'],
                'provider' => $this->safeLabel((string) $row['provider']),
                'provider_event_id' => $this->redactor->redactText((string) $row['provider_event_id']),
                'event_type' => $this->safeLabel((string) $row['event_type']),
                'signature_valid' => (bool) $row['signature_valid'],
                'status' => $this->safeLabel((string) $row['status']),
                'payload_fingerprint' => preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? substr($hash, 0, 12) . '...' : 'Unavailable',
                // Stored diagnostics may contain provider or database detail.
                // The web UI exposes only a fixed notice; operators correlate
                // by timestamp/reference and inspect protected logs directly.
                'error' => $row['error_message'] === null ? null : 'Processing failed; inspect private application logs.',
                'received_at' => (string) $row['received_at'],
                'processed_at' => $row['processed_at'] === null ? null : (string) $row['processed_at'],
                'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            ];
        }
        return $snapshot;
    }

    private function safeLabel(string $value): string
    {
        return mb_substr($this->redactor->redactText($value), 0, 191);
    }

}
