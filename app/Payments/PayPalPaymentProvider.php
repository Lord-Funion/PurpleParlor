<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use JsonException;
use RuntimeException;

final class PayPalPaymentProvider implements PaymentProviderInterface
{
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly Config $config,
        private readonly PaymentGate $gate,
        private readonly HttpClient $http,
    ) {
    }

    public function name(): string { return 'paypal'; }

    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->idempotencyKey,
                'custom_id' => (string) $request->userId,
                'description' => substr($request->productKey, 0, 127),
                'amount' => ['currency_code' => $request->currency, 'value' => number_format($request->amountCents / 100, 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $request->returnUrl, 'cancel_url' => $request->cancelUrl, 'user_action' => 'PAY_NOW']]],
        ];
        $response = $this->api('POST', '/v2/checkout/orders', $payload, ['PayPal-Request-Id: ' . $request->idempotencyKey]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->checkoutFailure($response);
        }
        return new CheckoutResult(true, strtolower((string) ($response['json']['status'] ?? 'created')), (string) ($response['json']['id'] ?? ''),
            $this->link($response['json'], 'payer-action') ?? $this->link($response['json'], 'approve'), null, $this->safeProviderData($response['json']));
    }

    public function createSubscription(SubscriptionRequest $request): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $payload = [
            'plan_id' => $request->providerPlanId,
            'custom_id' => (string) $request->userId,
            'application_context' => ['brand_name' => (string) $this->config->get('app.name', 'The Purple Parlor'), 'user_action' => 'SUBSCRIBE_NOW', 'return_url' => $request->returnUrl, 'cancel_url' => $request->cancelUrl],
        ];
        $response = $this->api('POST', '/v1/billing/subscriptions', $payload, ['PayPal-Request-Id: ' . $request->idempotencyKey]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->checkoutFailure($response);
        }
        return new CheckoutResult(true, strtolower((string) ($response['json']['status'] ?? 'approval_pending')), (string) ($response['json']['id'] ?? ''),
            $this->link($response['json'], 'approve'), null, $this->safeProviderData($response['json']));
    }

    public function cancelSubscription(string $externalId): ProviderResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $this->assertExternalId($externalId);
        $response = $this->api('POST', '/v1/billing/subscriptions/' . rawurlencode($externalId) . '/cancel', ['reason' => 'Customer requested cancellation.']);
        return $response['status'] >= 200 && $response['status'] < 300
            ? new ProviderResult(true, 'canceled', $externalId)
            : new ProviderResult(false, 'failed', $externalId, (string) ($response['json']['name'] ?? 'provider_error'));
    }

    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $this->assertExternalId($externalId);
        if ($amountCents <= 0) {
            return new ProviderResult(false, 'failed', $externalId, 'invalid_amount');
        }
        $currency = (string) $this->config->get('payments.currency', 'USD');
        $response = $this->api('POST', '/v2/payments/captures/' . rawurlencode($externalId) . '/refund', [
            'amount' => ['value' => number_format($amountCents / 100, 2, '.', ''), 'currency_code' => $currency],
        ], ['PayPal-Request-Id: refund-' . substr(hash('sha256', $idempotencyKey ?: $externalId . ':' . $amountCents), 0, 31)]);
        return $response['status'] >= 200 && $response['status'] < 300
            ? new ProviderResult(true, strtolower((string) ($response['json']['status'] ?? 'pending')), (string) ($response['json']['id'] ?? ''), null, $this->safeProviderData($response['json']))
            : new ProviderResult(false, 'failed', $externalId, (string) ($response['json']['name'] ?? 'provider_error'));
    }

    public function retrieveSubscription(string $externalId): ProviderSubscription
    {
        $this->assertExternalId($externalId);
        $response = $this->api('GET', '/v1/billing/subscriptions/' . rawurlencode($externalId));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('PayPal subscription could not be retrieved.');
        }
        $billing = (array) ($response['json']['billing_info'] ?? []);
        return new ProviderSubscription($externalId, $this->mapSubscriptionStatus((string) ($response['json']['status'] ?? 'EXPIRED')),
            $response['json']['start_time'] ?? null, $billing['next_billing_time'] ?? null, false, $this->safeProviderData($response['json']));
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $webhookId = trim((string) $this->config->get('payments.paypal.webhook_id', ''));
        $transmissionId = $this->header($headers, 'paypal-transmission-id');
        $transmissionTime = $this->header($headers, 'paypal-transmission-time');
        $transmissionSig = $this->header($headers, 'paypal-transmission-sig');
        $certUrl = $this->header($headers, 'paypal-cert-url');
        $authAlgo = $this->header($headers, 'paypal-auth-algo');
        if ($webhookId === '' || in_array(null, [$transmissionId, $transmissionTime, $transmissionSig, $certUrl, $authAlgo], true)) {
            return false;
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return false;
        }
        $response = $this->api('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $authAlgo, 'cert_url' => $certUrl, 'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig, 'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId, 'webhook_event' => $event,
        ]);
        return $response['status'] >= 200 && $response['status'] < 300
            && hash_equals('SUCCESS', strtoupper((string) ($response['json']['verification_status'] ?? '')));
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event) || !isset($event['resource']) || !is_array($event['resource'])) {
            throw new RuntimeException('Malformed PayPal webhook event.');
        }
        $type = (string) ($event['event_type'] ?? '');
        $resource = $event['resource'];
        $subscriptionId = null;
        $paymentId = null;
        $refundId = null;
        $disputeId = null;
        if (str_starts_with($type, 'BILLING.SUBSCRIPTION.')) {
            $subscriptionId = (string) ($resource['id'] ?? '');
        } elseif (str_contains($type, 'REFUND')) {
            $refundId = (string) ($resource['id'] ?? '');
            $paymentId = $resource['links'][0]['href'] ?? ($resource['sale_id'] ?? $resource['capture_id'] ?? null);
            if (is_string($paymentId) && str_contains($paymentId, '/')) {
                $paymentId = basename(parse_url($paymentId, PHP_URL_PATH) ?: '');
            }
        } elseif (str_contains($type, 'DISPUTE')) {
            $disputeId = (string) ($resource['dispute_id'] ?? $resource['id'] ?? '');
            $paymentId = $resource['disputed_transactions'][0]['seller_transaction_id'] ?? null;
        } else {
            $paymentId = (string) ($resource['id'] ?? '');
            $subscriptionId = $resource['billing_agreement_id'] ?? $resource['supplementary_data']['related_ids']['subscription_id'] ?? null;
        }
        $amount = $resource['amount'] ?? $resource['seller_payable_breakdown']['gross_amount'] ?? null;
        $amountCents = is_array($amount) && isset($amount['value']) ? $this->moneyToCents((string) $amount['value']) : null;
        return new PaymentEvent('paypal', (string) ($event['id'] ?? ''), $type, $this->mapEventStatus($type, (string) ($resource['status'] ?? '')),
            $paymentId === '' ? null : $paymentId, $subscriptionId === '' ? null : $subscriptionId,
            $refundId === '' ? null : $refundId, $disputeId === '' ? null : $disputeId,
            $amountCents, is_array($amount) ? ($amount['currency_code'] ?? null) : null, $event['create_time'] ?? null, $event);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>} */
    private function api(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $headers = ['Authorization: Bearer ' . $this->accessToken(), 'Accept: application/json'];
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        return $this->http->request($method, $this->baseUrl() . $path, array_merge($headers, $extraHeaders), $body);
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time() + 30) {
            return $this->accessToken;
        }
        $id = trim((string) $this->config->get('payments.paypal.client_id', ''));
        $secret = trim((string) $this->config->get('payments.paypal.client_secret', ''));
        if ($id === '' || $secret === '') {
            throw new PaymentsDisabled('PayPal credentials are not configured.');
        }
        $response = $this->http->request('POST', $this->baseUrl() . '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode($id . ':' . $secret),
            'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_string($response['json']['access_token'] ?? null)) {
            throw new RuntimeException('PayPal authentication failed.');
        }
        $this->accessToken = $response['json']['access_token'];
        $this->accessTokenExpiresAt = time() + max(60, (int) ($response['json']['expires_in'] ?? 300));
        return $this->accessToken;
    }

    private function baseUrl(): string
    {
        return $this->config->get('payments.paypal.environment') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }
        return null;
    }

    private function link(array $data, string $relation): ?string
    {
        foreach ((array) ($data['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === $relation && isset($link['href'])) {
                return (string) $link['href'];
            }
        }
        return null;
    }

    private function checkoutFailure(array $response): CheckoutResult
    {
        return new CheckoutResult(false, 'failed', null, null, (string) ($response['json']['name'] ?? 'provider_error'), $this->safeProviderData($response['json']));
    }

    private function safeProviderData(array $data): array
    {
        unset($data['access_token'], $data['client_secret']);
        return $data;
    }

    private function assertExternalId(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{3,191}$/', $id)) {
            throw new RuntimeException('Invalid PayPal resource identifier.');
        }
    }

    private function mapSubscriptionStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVAL_PENDING' => 'pending', 'APPROVED', 'ACTIVE' => 'active', 'SUSPENDED' => 'suspended',
            'CANCELLED' => 'canceled', 'EXPIRED' => 'expired', default => 'pending',
        };
    }

    private function mapEventStatus(string $type, string $resourceStatus): string
    {
        return match ($type) {
            'BILLING.SUBSCRIPTION.ACTIVATED', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED' => 'active',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'past_due', 'BILLING.SUBSCRIPTION.SUSPENDED' => 'suspended',
            'BILLING.SUBSCRIPTION.CANCELLED' => 'canceled', 'BILLING.SUBSCRIPTION.EXPIRED' => 'expired',
            'PAYMENT.SALE.REFUNDED', 'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.SALE.REVERSED', 'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED' => 'disputed',
            default => $resourceStatus === '' ? 'pending' : strtolower($resourceStatus),
        };
    }

    private function moneyToCents(string $value): int
    {
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Provider returned an invalid monetary amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (str_starts_with($value, '-') ? -1 : 1) * (int) str_pad($fraction, 2, '0');
    }
}
