<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use App\Database\Database;
use DomainException;
use RuntimeException;
use Throwable;

final class SquarePaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PaymentGate $gate,
        private readonly HttpClient $http,
        private readonly ?Database $database = null,
    ) {
    }

    public function name(): string { return 'square'; }

    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        if ($request->paymentSourceToken !== null && $request->paymentSourceToken !== '') {
            $payload = [
                'source_id' => $request->paymentSourceToken,
                'idempotency_key' => $request->idempotencyKey,
                'amount_money' => ['amount' => $request->amountCents, 'currency' => $request->currency],
                'location_id' => (string) $this->config->get('payments.square.location_id'),
                'reference_id' => $request->productKey,
                'note' => 'Fixed cosmetic or entertainment feature; no virtual currency.',
            ];
            $response = $this->api('POST', '/v2/payments', $payload);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json']['payment'] ?? null)) {
                return $this->failure($response);
            }
            $payment = $response['json']['payment'];
            return new CheckoutResult(true, strtolower((string) ($payment['status'] ?? 'pending')), (string) ($payment['id'] ?? ''),
                null, null, $this->safeProviderData($payment));
        }

        // Hosted Payment Links are the cPanel-friendly fallback when Web Payments SDK is unavailable.
        $payload = [
            'idempotency_key' => $request->idempotencyKey,
            'quick_pay' => [
                'name' => substr($request->productKey, 0, 255),
                'price_money' => ['amount' => $request->amountCents, 'currency' => $request->currency],
                'location_id' => (string) $this->config->get('payments.square.location_id'),
            ],
            'checkout_options' => ['redirect_url' => $request->returnUrl, 'ask_for_shipping_address' => false],
            'pre_populated_data' => [],
            'payment_note' => 'Fixed cosmetic or entertainment feature; no virtual currency.',
        ];
        $response = $this->api('POST', '/v2/online-checkout/payment-links', $payload);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json']['payment_link'] ?? null)) {
            return $this->failure($response);
        }
        $link = $response['json']['payment_link'];
        return new CheckoutResult(true, 'pending', (string) ($link['id'] ?? ''), (string) ($link['url'] ?? ''), null, $this->safeProviderData($link));
    }

    public function createSubscription(SubscriptionRequest $request): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        if ($this->database === null) {
            throw new RuntimeException('Square customer storage is unavailable.');
        }
        if ($request->paymentSourceToken === null || $request->paymentSourceToken === '') {
            return new CheckoutResult(false, 'failed', null, null, 'card_token_required');
        }
        if ($request->customerEmail === null || filter_var($request->customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new CheckoutResult(false, 'failed', null, null, 'customer_email_required');
        }

        $customerId = $this->customerId($request->userId, $request->customerEmail);
        $cardResponse = $this->api('POST', '/v2/cards', [
            'idempotency_key' => 'card_' . substr(hash('sha256', $request->idempotencyKey), 0, 36),
            'source_id' => $request->paymentSourceToken,
            'card' => [
                'customer_id' => $customerId,
                'reference_id' => substr('purple-parlor-user-' . $request->userId, 0, 40),
            ],
        ]);
        $card = is_array($cardResponse['json']['card'] ?? null) ? $cardResponse['json']['card'] : null;
        if ($cardResponse['status'] < 200 || $cardResponse['status'] >= 300 || $card === null || trim((string) ($card['id'] ?? '')) === '') {
            return $this->failure($cardResponse);
        }

        $response = $this->api('POST', '/v2/subscriptions', [
            'idempotency_key' => $request->idempotencyKey,
            'location_id' => (string) $this->config->get('payments.square.location_id'),
            'plan_variation_id' => $request->providerPlanId,
            'customer_id' => $customerId,
            'card_id' => (string) $card['id'],
            'timezone' => (string) $this->config->get('app.timezone', 'America/New_York'),
            'source' => ['name' => substr('The Purple Parlor ' . $request->planKey, 0, 255)],
        ]);
        $subscription = is_array($response['json']['subscription'] ?? null) ? $response['json']['subscription'] : null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $subscription === null || trim((string) ($subscription['id'] ?? '')) === '') {
            return $this->failure($response);
        }
        return new CheckoutResult(true, $this->subscriptionStatus((string) ($subscription['status'] ?? 'PENDING')),
            (string) $subscription['id'], null, null, $this->safeProviderData($subscription));
    }

    public function cancelSubscription(string $externalId): ProviderResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $this->assertExternalId($externalId);
        $response = $this->api('POST', '/v2/subscriptions/' . rawurlencode($externalId) . '/cancel', []);
        $subscription = is_array($response['json']['subscription'] ?? null) ? $response['json']['subscription'] : null;
        return $response['status'] >= 200 && $response['status'] < 300 && $subscription !== null
            ? new ProviderResult(true, $this->subscriptionStatus((string) ($subscription['status'] ?? 'CANCELED')), $externalId, null, $this->safeProviderData($subscription))
            : new ProviderResult(false, 'failed', $externalId, $this->errorCode($response));
    }

    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $this->assertExternalId($externalId);
        if ($amountCents <= 0) {
            return new ProviderResult(false, 'failed', $externalId, 'invalid_amount');
        }
        $idempotency = 'refund_' . substr(hash('sha256', $idempotencyKey ?: $externalId . ':' . $amountCents), 0, 36);
        $response = $this->api('POST', '/v2/refunds', [
            'idempotency_key' => $idempotency,
            'payment_id' => $externalId,
            'amount_money' => ['amount' => $amountCents, 'currency' => (string) $this->config->get('payments.currency', 'USD')],
            'reason' => 'Approved customer refund',
        ]);
        $refund = is_array($response['json']['refund'] ?? null) ? $response['json']['refund'] : null;
        return $response['status'] >= 200 && $response['status'] < 300 && $refund !== null
            ? new ProviderResult(true, strtolower((string) ($refund['status'] ?? 'pending')), (string) ($refund['id'] ?? ''), null, $this->safeProviderData($refund))
            : new ProviderResult(false, 'failed', $externalId, $this->errorCode($response));
    }

    public function retrieveSubscription(string $externalId): ProviderSubscription
    {
        $this->gate->assertCheckoutAllowed($this->name());
        $this->assertExternalId($externalId);
        $response = $this->api('GET', '/v2/subscriptions/' . rawurlencode($externalId));
        $subscription = is_array($response['json']['subscription'] ?? null) ? $response['json']['subscription'] : null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $subscription === null) {
            throw new RuntimeException('Square subscription lookup failed: ' . $this->errorCode($response));
        }
        $periodEnd = $this->dateValue($subscription['charged_through_date'] ?? null);
        $canceledDate = $this->dateValue($subscription['canceled_date'] ?? null);
        return new ProviderSubscription(
            (string) ($subscription['id'] ?? $externalId),
            $this->subscriptionStatus((string) ($subscription['status'] ?? 'PENDING')),
            $this->dateValue($subscription['start_date'] ?? null),
            $periodEnd,
            $canceledDate !== null,
            $this->safeProviderData($subscription),
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = $this->header($headers, 'x-square-hmacsha256-signature');
        $key = (string) $this->config->get('payments.square.signature_key', '');
        $url = (string) $this->config->get('payments.square.webhook_url', '');
        if ($signature === null || $key === '' || !str_starts_with($url, 'https://')) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $url . $rawBody, $key, true));
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentEvent
    {
        $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event) || !is_array($event['data']['object'] ?? null)) {
            throw new RuntimeException('Malformed Square webhook event.');
        }
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'];
        $payment = is_array($object['payment'] ?? null) ? $object['payment'] : null;
        $refund = is_array($object['refund'] ?? null) ? $object['refund'] : null;
        $dispute = is_array($object['dispute'] ?? null) ? $object['dispute'] : null;
        $subscription = is_array($object['subscription'] ?? null) ? $object['subscription'] : null;
        $invoice = is_array($object['invoice'] ?? null) ? $object['invoice'] : null;
        $money = $payment['amount_money'] ?? $refund['amount_money'] ?? $dispute['amount_money'] ?? null;
        $status = match (true) {
            $refund !== null => match (strtoupper((string) ($refund['status'] ?? ''))) {
                'COMPLETED' => 'refunded', 'FAILED', 'REJECTED' => 'failed', default => 'pending',
            },
            str_contains($type, 'dispute') => 'disputed',
            $subscription !== null => $this->subscriptionStatus((string) ($subscription['status'] ?? 'PENDING')),
            $invoice !== null && $type === 'invoice.payment_made' => 'active',
            $invoice !== null && $type === 'invoice.scheduled_charge_failed' => 'past_due',
            $invoice !== null && $type === 'invoice.refunded' => 'active',
            $payment !== null => match (strtoupper((string) ($payment['status'] ?? ''))) {
                'COMPLETED' => 'completed', 'CANCELED', 'FAILED' => 'failed', default => 'pending',
            },
            default => 'unknown',
        };
        $subscriptionId = $subscription['id'] ?? $invoice['subscription_id'] ?? null;
        $lifecycleData = $event;
        if ($subscription !== null) {
            $lifecycleData['period_start'] = $subscription['start_date'] ?? null;
            $lifecycleData['period_end'] = $subscription['charged_through_date'] ?? null;
            $lifecycleData['cancel_at_period_end'] = !empty($subscription['canceled_date']);
        }
        $externalPaymentId = $payment['id'] ?? $refund['payment_id'] ?? $dispute['disputed_payment']['payment_id'] ?? null;
        $externalPaymentId = is_string($externalPaymentId) ? $this->relevantPaymentId($externalPaymentId, $payment) : null;
        return new PaymentEvent('square', (string) ($event['event_id'] ?? ''), $type, $status,
            $externalPaymentId,
            is_string($subscriptionId) && $subscriptionId !== '' ? $subscriptionId : null, $refund['id'] ?? null, $dispute['id'] ?? null,
            is_array($money) && isset($money['amount']) ? (int) $money['amount'] : null,
            is_array($money) ? ($money['currency'] ?? null) : null, $event['created_at'] ?? null, $lifecycleData);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>} */
    private function api(string $method, string $path, ?array $payload = null): array
    {
        $token = trim((string) $this->config->get('payments.square.access_token', ''));
        if ($token === '') {
            throw new PaymentsDisabled('Square credentials are not configured.');
        }
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Square-Version: ' . (string) $this->config->get('payments.square.api_version', '2026-05-20')];
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        return $this->http->request($method, $this->baseUrl() . $path, $headers, $body);
    }

    private function baseUrl(): string
    {
        return $this->config->get('payments.square.environment') === 'live' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
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

    private function failure(array $response): CheckoutResult
    {
        return new CheckoutResult(false, 'failed', null, null, $this->errorCode($response));
    }

    private function errorCode(array $response): string
    {
        return (string) ($response['json']['errors'][0]['code'] ?? 'provider_error');
    }

    private function safeProviderData(array $data): array
    {
        unset($data['card_details'], $data['source_id'], $data['access_token'], $data['card_id'], $data['customer_id']);
        return $data;
    }

    private function customerId(int $userId, string $email): string
    {
        if ($this->database === null) {
            throw new RuntimeException('Square customer storage is unavailable.');
        }
        $providerKey = 'square_' . strtolower((string) $this->config->get('payments.square.environment', 'sandbox'));
        $existing = $this->database->fetchOne('SELECT external_customer_id FROM payment_provider_customers WHERE user_id = :user AND provider = :provider', [
            'user' => $userId, 'provider' => $providerKey,
        ]);
        if ($existing !== null) {
            return (string) $existing['external_customer_id'];
        }
        $response = $this->api('POST', '/v2/customers', [
            'idempotency_key' => 'customer_' . substr(hash('sha256', $providerKey . ':' . $userId), 0, 32),
            'email_address' => $email,
            'reference_id' => substr('purple-parlor-user-' . $userId, 0, 40),
        ]);
        $customer = is_array($response['json']['customer'] ?? null) ? $response['json']['customer'] : null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $customer === null || trim((string) ($customer['id'] ?? '')) === '') {
            throw new DomainException('Square could not create the customer profile: ' . $this->errorCode($response));
        }
        $customerId = (string) $customer['id'];
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->database->execute('INSERT INTO payment_provider_customers (user_id, provider, external_customer_id, created_at, updated_at) VALUES (:user, :provider, :external, :now, :now)', [
                'user' => $userId, 'provider' => $providerKey, 'external' => $customerId, 'now' => $now,
            ]);
        } catch (Throwable $exception) {
            $concurrent = $this->database->fetchOne('SELECT external_customer_id FROM payment_provider_customers WHERE user_id = :user AND provider = :provider', [
                'user' => $userId, 'provider' => $providerKey,
            ]);
            if ($concurrent === null) {
                throw $exception;
            }
            return (string) $concurrent['external_customer_id'];
        }
        return $customerId;
    }

    private function subscriptionStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'active',
            'CANCELED' => 'canceled',
            'DEACTIVATED' => 'expired',
            'PAUSED' => 'paused',
            default => 'pending',
        };
    }

    private function relevantPaymentId(string $externalId, ?array $payment): ?string
    {
        if ($externalId === '' || $this->database === null) {
            return $externalId === '' ? null : $externalId;
        }
        $known = $this->database->fetchOne('SELECT id FROM payments WHERE provider = :provider AND external_id = :external', [
            'provider' => 'square', 'external' => $externalId,
        ]);
        if ($known !== null) {
            return $externalId;
        }
        $reference = is_array($payment) ? trim((string) ($payment['reference_id'] ?? '')) : '';
        if ($reference !== '') {
            $product = $this->database->fetchOne('SELECT id FROM products WHERE product_key = :key AND active = 1', ['key' => $reference]);
            if ($product !== null) {
                return $externalId;
            }
        }
        // A Square seller account can receive unrelated Dashboard/POS charges.
        // They must not enter this application's payment lifecycle.
        return null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (!is_string($value) || strtotime($value) === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', strtotime($value));
    }

    private function assertExternalId(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{3,191}$/', $id)) {
            throw new RuntimeException('Invalid Square resource identifier.');
        }
    }
}
