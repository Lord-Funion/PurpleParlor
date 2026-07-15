<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use App\Database\Database;
use JsonException;
use PDOException;
use Throwable;

final class WebhookProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly PaymentProviderFactory $providers,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly PaymentAuditService $audit,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, string|list<string>> $headers */
    public function process(string $providerName, string $rawBody, array $headers): WebhookResult
    {
        if ($rawBody === '' || strlen($rawBody) > 1_048_576) {
            return new WebhookResult(false, false, 'malformed');
        }
        $provider = $this->providers->make($providerName);
        if (!$provider->verifyWebhook($rawBody, $headers)) {
            return new WebhookResult(false, false, 'invalid_signature');
        }
        try {
            $event = $provider->parseWebhook($rawBody, $headers);
        } catch (Throwable) {
            return new WebhookResult(false, false, 'malformed');
        }
        if ($event->provider !== $provider->name()) {
            return new WebhookResult(false, false, 'provider_mismatch');
        }
        $payloadHash = hash('sha256', $rawBody);
        $existing = $this->database->fetchOne('SELECT status, payload_hash FROM webhook_events WHERE provider = :provider AND provider_event_id = :event',
            ['provider' => $event->provider, 'event' => $event->eventId]);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                $this->audit->record('webhook.replay_payload_mismatch', 'webhook', $event->eventId, ['provider' => $event->provider]);
                return new WebhookResult(false, true, 'payload_mismatch', $event->eventId);
            }
            return new WebhookResult(true, true, (string) $existing['status'], $event->eventId);
        }

        $normalized = $this->serializeEvent($event);
        try {
            $this->database->transaction(function (Database $db) use ($event, $payloadHash, $normalized): void {
                $now = gmdate('Y-m-d H:i:s');
                $retention = max(1, (int) $this->config->get('payments.webhook_retention_days', 90));
                $db->execute('INSERT INTO webhook_events (provider, provider_event_id, event_type, signature_valid, status, payload_hash, payload_json, received_at, expires_at)
                    VALUES (:provider, :event, :type, 1, :status, :hash, :payload, :received, :expires)', [
                    'provider' => $event->provider, 'event' => $event->eventId, 'type' => $event->type, 'status' => 'processing',
                    'hash' => $payloadHash, 'payload' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'received' => $now, 'expires' => gmdate('Y-m-d H:i:s', time() + $retention * 86400),
                ]);
                $this->lifecycle->apply($event);
                $this->audit->record('webhook.processed', 'webhook', $event->eventId, ['provider' => $event->provider, 'type' => $event->type, 'status' => $event->status]);
                $db->execute("UPDATE webhook_events SET status = 'processed', processed_at = :now WHERE provider = :provider AND provider_event_id = :event", ['now' => $now, 'provider' => $event->provider, 'event' => $event->eventId]);
            });
            return new WebhookResult(true, false, 'processed', $event->eventId);
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19'], true)) {
                return new WebhookResult(true, true, 'duplicate', $event->eventId);
            }
            $this->recordFailed($event, $payloadHash, $normalized, $e);
            return new WebhookResult(false, false, 'processing_failed', $event->eventId);
        } catch (Throwable $e) {
            $this->recordFailed($event, $payloadHash, $normalized, $e);
            return new WebhookResult(false, false, 'processing_failed', $event->eventId);
        }
    }

    public function retryFailed(int $limit = 25): int
    {
        $events = $this->database->fetchAll("SELECT * FROM webhook_events WHERE status = 'failed' ORDER BY received_at LIMIT " . max(1, min(100, $limit)));
        $processed = 0;
        foreach ($events as $record) {
            try {
                $data = json_decode((string) $record['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                $event = $this->hydrateEvent($data);
                $this->database->transaction(function (Database $db) use ($event, $record): void {
                    $this->lifecycle->apply($event);
                    $this->audit->record('webhook.retry_processed', 'webhook', $event->eventId, ['provider' => $event->provider, 'type' => $event->type]);
                    $db->execute("UPDATE webhook_events SET status = 'processed', error_message = NULL, processed_at = :now WHERE id = :id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $record['id']]);
                });
                $processed++;
            } catch (Throwable $e) {
                $this->database->execute('UPDATE webhook_events SET error_message = :error WHERE id = :id', ['error' => substr($e->getMessage(), 0, 500), 'id' => $record['id']]);
            }
        }
        return $processed;
    }

    private function recordFailed(PaymentEvent $event, string $payloadHash, array $normalized, Throwable $e): void
    {
        try {
            $existing = $this->database->fetchOne('SELECT id FROM webhook_events WHERE provider = :provider AND provider_event_id = :event', ['provider' => $event->provider, 'event' => $event->eventId]);
            if ($existing === null) {
                $retention = max(1, (int) $this->config->get('payments.webhook_retention_days', 90));
                $this->database->execute('INSERT INTO webhook_events (provider, provider_event_id, event_type, signature_valid, status, payload_hash, payload_json, error_message, received_at, expires_at)
                    VALUES (:provider, :event, :type, 1, :status, :hash, :payload, :error, :received, :expires)', [
                    'provider' => $event->provider, 'event' => $event->eventId, 'type' => $event->type, 'status' => 'failed', 'hash' => $payloadHash,
                    'payload' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'error' => substr($e->getMessage(), 0, 500),
                    'received' => gmdate('Y-m-d H:i:s'), 'expires' => gmdate('Y-m-d H:i:s', time() + $retention * 86400),
                ]);
            }
        } catch (Throwable) {
            // The endpoint still fails closed; private application logging handles database outages.
        }
    }

    private function serializeEvent(PaymentEvent $event): array
    {
        return [
            'provider' => $event->provider, 'event_id' => $event->eventId, 'type' => $event->type, 'status' => $event->status,
            'payment_id' => $event->externalPaymentId, 'subscription_id' => $event->externalSubscriptionId,
            'refund_id' => $event->externalRefundId, 'dispute_id' => $event->externalDisputeId,
            'amount_cents' => $event->amountCents, 'currency' => $event->currency, 'occurred_at' => $event->occurredAt,
            // Lifecycle fields only; raw provider payload is intentionally not retained.
            'data' => $this->lifecycleData($event->data),
        ];
    }

    private function lifecycleData(array $data): array
    {
        $allowed = ['resource', 'data', 'type', 'event_type', 'create_time', 'created_at', 'period_start', 'period_end', 'cancel_at_period_end'];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $this->redact($data[$key]);
            }
        }
        return $result;
    }

    private function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[TRUNCATED]';
        }
        if (!is_array($value)) {
            return is_string($value) ? substr($value, 0, 500) : $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (preg_match('/(?:payer|email|name|address|phone|card|bank|account|token|secret|authorization)/i', (string) $key)) {
                continue;
            }
            $result[$key] = $this->redact($item, $depth + 1);
        }
        return $result;
    }

    private function hydrateEvent(array $data): PaymentEvent
    {
        return new PaymentEvent((string) ($data['provider'] ?? ''), (string) ($data['event_id'] ?? ''), (string) ($data['type'] ?? ''),
            (string) ($data['status'] ?? ''), $data['payment_id'] ?? null, $data['subscription_id'] ?? null, $data['refund_id'] ?? null,
            $data['dispute_id'] ?? null, isset($data['amount_cents']) ? (int) $data['amount_cents'] : null, $data['currency'] ?? null,
            $data['occurred_at'] ?? null, is_array($data['data'] ?? null) ? $data['data'] : []);
    }
}
