<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use RuntimeException;

final class DemoPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly string $signingKey, private readonly ?Database $database = null)
    {
        if (strlen($signingKey) < 32) {
            throw new RuntimeException('Demo payment signing requires a strong APP_KEY.');
        }
    }

    public function name(): string { return 'demo'; }

    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult
    {
        $scenario = strtolower((string) ($request->metadata['demo_scenario'] ?? 'success'));
        if (in_array($scenario, ['failed', 'cancelled', 'canceled'], true)) {
            $status = str_starts_with($scenario, 'cancel') ? 'canceled' : 'failed';
            return new CheckoutResult(false, $status, null, null, $status === 'canceled' ? 'demo_customer_canceled' : 'demo_declined');
        }
        $external = 'demo_pay_' . substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $status = $scenario === 'pending' ? 'pending' : 'completed';
        return new CheckoutResult(true, $status, $external, $request->returnUrl . (str_contains($request->returnUrl, '?') ? '&' : '?') . 'demo_reference=' . rawurlencode($external), null,
            ['scenario' => $scenario, 'confirmation_required' => true]);
    }

    public function createSubscription(SubscriptionRequest $request): CheckoutResult
    {
        $scenario = strtolower((string) ($request->metadata['demo_scenario'] ?? 'active'));
        if (in_array($scenario, ['failed', 'cancelled', 'canceled'], true)) {
            $status = str_starts_with($scenario, 'cancel') ? 'canceled' : 'failed';
            return new CheckoutResult(false, $status, null, null, $status === 'canceled' ? 'demo_customer_canceled' : 'demo_subscription_declined');
        }
        $external = 'demo_sub_' . substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $status = in_array($scenario, ['pending', 'trialing', 'active', 'past_due', 'in_grace_period', 'paused', 'suspended', 'expired', 'refunded', 'disputed'], true) ? $scenario : 'active';
        return new CheckoutResult(true, $status, $external, $request->returnUrl . (str_contains($request->returnUrl, '?') ? '&' : '?') . 'demo_reference=' . rawurlencode($external), null,
            ['scenario' => $scenario, 'confirmation_required' => true]);
    }

    public function cancelSubscription(string $externalId): ProviderResult
    {
        $row = $this->database?->fetchOne("SELECT id FROM subscriptions WHERE provider = 'demo' AND external_id = :external", ['external' => $externalId]);
        if ($row === null) {
            return new ProviderResult(false, 'not_found', $externalId, 'subscription_not_found');
        }
        $this->database?->execute("UPDATE subscriptions SET status = 'canceled', canceled_at = :now, updated_at = :now WHERE id = :id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
        return new ProviderResult(true, 'canceled', $externalId);
    }

    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult
    {
        if ($amountCents <= 0) {
            return new ProviderResult(false, 'failed', $externalId, 'invalid_amount');
        }
        $operation = $idempotencyKey === null || $idempotencyKey === '' ? $externalId . ':' . $amountCents : $idempotencyKey;
        return new ProviderResult(true, 'refunded', 'demo_ref_' . substr(hash('sha256', $operation), 0, 24));
    }

    public function retrieveSubscription(string $externalId): ProviderSubscription
    {
        $row = $this->database?->fetchOne("SELECT status, current_period_start, current_period_end, cancel_at_period_end FROM subscriptions WHERE provider = 'demo' AND external_id = :external", ['external' => $externalId]);
        return $row === null
            ? new ProviderSubscription($externalId, 'expired')
            : new ProviderSubscription($externalId, (string) $row['status'], $row['current_period_start'], $row['current_period_end'], (bool) $row['cancel_at_period_end']);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = $this->header($headers, 'x-purple-demo-signature');
        return $signature !== null && hash_equals(hash_hmac('sha256', $rawBody, $this->key()), $signature);
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Malformed demo payment event.');
        }
        return new PaymentEvent('demo', (string) ($data['id'] ?? ''), (string) ($data['type'] ?? ''), (string) ($data['status'] ?? 'unknown'),
            isset($data['payment_id']) ? (string) $data['payment_id'] : null, isset($data['subscription_id']) ? (string) $data['subscription_id'] : null,
            isset($data['refund_id']) ? (string) $data['refund_id'] : null, isset($data['dispute_id']) ? (string) $data['dispute_id'] : null,
            isset($data['amount_cents']) ? (int) $data['amount_cents'] : null, isset($data['currency']) ? (string) $data['currency'] : null,
            isset($data['occurred_at']) ? (string) $data['occurred_at'] : gmdate('c'), $data);
    }

    /** @return array{body:string,headers:array<string,string>} */
    public function signedEvent(array $event): array
    {
        $event['id'] ??= 'demo_evt_' . bin2hex(random_bytes(12));
        $event['occurred_at'] ??= gmdate('c');
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return ['body' => $body, 'headers' => ['x-purple-demo-signature' => hash_hmac('sha256', $body, $this->key())]];
    }

    /**
     * Produces deterministic lifecycle deliveries for the local-only demo controller/test harness.
     * Calling it twice with the same arguments intentionally produces a duplicate provider event ID.
     * @return array{body:string,headers:array<string,string>}
     */
    public function lifecycleEvent(string $externalId, string $scenario, string $kind = 'subscription'): array
    {
        $scenario = strtolower($scenario);
        $map = [
            'success' => ['status' => 'completed', 'type' => 'PAYMENT.COMPLETED'],
            'renewal' => ['status' => 'active', 'type' => 'SUBSCRIPTION.RENEWED'],
            'failed_renewal' => ['status' => 'past_due', 'type' => 'SUBSCRIPTION.PAYMENT.FAILED'],
            'grace' => ['status' => 'in_grace_period', 'type' => 'SUBSCRIPTION.GRACE.STARTED'],
            'cancelled' => ['status' => 'canceled', 'type' => 'SUBSCRIPTION.CANCELED'],
            'canceled' => ['status' => 'canceled', 'type' => 'SUBSCRIPTION.CANCELED'],
            'expiration' => ['status' => 'expired', 'type' => 'SUBSCRIPTION.EXPIRED'],
            'refund' => ['status' => 'refunded', 'type' => 'PAYMENT.REFUNDED'],
            'dispute' => ['status' => 'disputed', 'type' => 'PAYMENT.DISPUTED'],
            'pending' => ['status' => 'pending', 'type' => 'PAYMENT.PENDING'],
            'failed' => ['status' => 'failed', 'type' => 'PAYMENT.FAILED'],
        ];
        $state = $map[$scenario] ?? $map['success'];
        if ($kind === 'subscription' && $scenario === 'success') {
            $state = ['status' => 'active', 'type' => 'SUBSCRIPTION.ACTIVATED'];
        }
        $event = [
            'id' => 'demo_evt_' . substr(hash('sha256', $kind . ':' . $externalId . ':' . $scenario), 0, 24),
            'type' => $state['type'], 'status' => $state['status'], 'occurred_at' => gmdate('c'),
        ];
        if ($kind === 'subscription') {
            $event['subscription_id'] = $externalId;
            if (in_array($scenario, ['renewal', 'refund', 'dispute'], true)) {
                $event['payment_id'] = 'demo_pay_' . substr(hash('sha256', $externalId . ':' . $scenario), 0, 24);
            }
        } else {
            $event['payment_id'] = $externalId;
        }
        if ($scenario === 'refund') {
            $event['refund_id'] = 'demo_ref_' . substr(hash('sha256', $externalId), 0, 24);
        }
        if ($scenario === 'dispute') {
            $event['dispute_id'] = 'demo_dsp_' . substr(hash('sha256', $externalId), 0, 24);
        }
        return $this->signedEvent($event);
    }

    /** @return array{body:string,headers:array<string,string>} */
    public function invalidlySignedEvent(array $event): array
    {
        $signed = $this->signedEvent($event);
        $signed['headers']['x-purple-demo-signature'] = str_repeat('0', 64);
        return $signed;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }
        return null;
    }

    private function key(): string
    {
        if (str_starts_with($this->signingKey, 'base64:')) {
            $decoded = base64_decode(substr($this->signingKey, 7), true);
            return $decoded === false ? $this->signingKey : $decoded;
        }
        return $this->signingKey;
    }
}
