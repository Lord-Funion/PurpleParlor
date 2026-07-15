<?php

declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\PaymentAuditHistoryService;
use App\Payments\PaymentAuditService;
use App\Security\SecretRedactor;
use Tests\Support\TestCase;

final class PaymentAuditHistoryTest extends TestCase
{
    public function testUnauthorizedViewerGetsOnlyAggregateStatusAndAdultOwnerProjectionIsRedacted(): void
    {
        (new PaymentAuditService($this->database, self::KEY))->record('payment.test', 'payment', '77', [
            'client_secret' => 'audit-secret-value',
            'note' => 'Authorization: Bearer audit-bearer-value',
        ]);
        $this->database->execute('INSERT INTO webhook_events
            (provider, provider_event_id, event_type, signature_valid, status, payload_hash, payload_json, error_message, received_at, processed_at, expires_at)
            VALUES (:provider, :event, :type, 1, :status, :hash, :payload, :error, :received, :processed, :expires)', [
            'provider' => 'demo', 'event' => 'evt_safe_1', 'type' => 'PAYMENT.TEST', 'status' => 'failed',
            'hash' => hash('sha256', 'payload-never-rendered'),
            'payload' => json_encode(['client_secret' => 'payload-never-rendered'], JSON_THROW_ON_ERROR),
            'error' => 'Bearer webhook-bearer-value client_secret=webhook-secret customer@example.test',
            'received' => '2026-07-13 10:00:00', 'processed' => '2026-07-13 10:00:01', 'expires' => '2026-08-13 10:00:00',
        ]);
        $history = new PaymentAuditHistoryService($this->database, new SecretRedactor());

        $aggregate = $history->snapshot(false);
        $this->assertFalse($aggregate['detailed']);
        $this->assertSame([], $aggregate['payment_events']);
        $this->assertSame([], $aggregate['webhook_events']);
        $this->assertSame(1, $aggregate['summary']['webhook_events']);

        $owner = $history->snapshot(true);
        $encoded = json_encode($owner, JSON_THROW_ON_ERROR);
        $this->assertTrue($owner['detailed']);
        $this->assertFalse(str_contains($encoded, 'audit-secret-value'));
        $this->assertFalse(str_contains($encoded, 'audit-bearer-value'));
        $this->assertFalse(str_contains($encoded, 'payload-never-rendered'));
        $this->assertFalse(str_contains($encoded, 'webhook-bearer-value'));
        $this->assertFalse(str_contains($encoded, 'webhook-secret'));
        $this->assertFalse(str_contains($encoded, 'customer@example.test'));
        $this->assertFalse(array_key_exists('payload_json', $owner['webhook_events'][0]));
        $this->assertSame('[REDACTED]', $owner['payment_events'][0]['details']['client_secret']);
    }
}
