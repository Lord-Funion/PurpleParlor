<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use App\Services\EntitlementService;
use DomainException;
use RuntimeException;
use Throwable;

final class PaymentManager
{
    public function __construct(
        private readonly Database $database,
        private readonly PaymentProviderFactory $providers,
        private readonly PaymentGate $gate,
        private readonly ?EntitlementService $entitlements = null,
        private readonly ?SubscriptionLifecycleService $lifecycle = null,
        private readonly ?CheckoutIntentService $checkoutIntents = null,
        private readonly ?PaymentPlanCatalog $planCatalog = null,
    ) {
    }

    public function beginProductCheckoutForIntent(int $userId, string $productKey, string $providerName, string $returnUrl, string $cancelUrl, ?string $sourceToken = null, array $metadata = []): CheckoutResult
    {
        if ($this->checkoutIntents === null) {
            throw new RuntimeException('Server checkout intents are unavailable.');
        }
        $this->gate->assertCheckoutAllowed($providerName);
        $intent = $this->checkoutIntents->acquire($userId, 'product', $productKey, '', $providerName, $this->gate->checkoutEnvironment($providerName));
        try {
            $result = $this->beginProductCheckout($userId, $productKey, $providerName, $intent->idempotencyKey, $returnUrl, $cancelUrl, $sourceToken, $metadata);
            $this->checkoutIntents->recordResult($intent->idempotencyKey, $result);
            return $result;
        } catch (DomainException $exception) {
            $this->checkoutIntents->recordValidationFailure($intent->idempotencyKey);
            throw $exception;
        } catch (Throwable $exception) {
            // A provider/network/DB interruption may have happened after the
            // provider accepted the request. Keep the same key for a safe retry.
            $this->checkoutIntents->recordAmbiguousFailure($intent->idempotencyKey);
            throw $exception;
        }
    }

    public function beginSubscriptionCheckoutForIntent(int $userId, string $planKey, string $billingPeriod, string $providerName, string $returnUrl, string $cancelUrl, array $metadata = [], ?string $paymentSourceToken = null): CheckoutResult
    {
        if ($this->checkoutIntents === null) {
            throw new RuntimeException('Server checkout intents are unavailable.');
        }
        $this->gate->assertCheckoutAllowed($providerName);
        $intent = $this->checkoutIntents->acquire($userId, 'subscription', $planKey, $billingPeriod, $providerName, $this->gate->checkoutEnvironment($providerName));
        try {
            $result = $this->beginSubscriptionCheckout($userId, $planKey, $billingPeriod, $providerName, $intent->idempotencyKey, $returnUrl, $cancelUrl, $metadata, $paymentSourceToken);
            $this->checkoutIntents->recordResult($intent->idempotencyKey, $result);
            return $result;
        } catch (DomainException $exception) {
            $this->checkoutIntents->recordValidationFailure($intent->idempotencyKey);
            throw $exception;
        } catch (Throwable $exception) {
            $this->checkoutIntents->recordAmbiguousFailure($intent->idempotencyKey);
            throw $exception;
        }
    }

    public function beginProductCheckout(int $userId, string $productKey, string $providerName, string $idempotencyKey, string $returnUrl, string $cancelUrl, ?string $sourceToken = null, array $metadata = []): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($providerName);
        $row = $this->database->fetchOne('SELECT p.id, p.product_key, p.entitlement_key, pp.amount_cents, pp.currency
            FROM products p INNER JOIN product_prices pp ON pp.product_id = p.id
            WHERE p.product_key = :product AND pp.provider = :provider AND p.active = 1 AND pp.active = 1',
            ['product' => $productKey, 'provider' => $providerName]);
        if ($row === null || (int) $row['amount_cents'] <= 0) {
            throw new DomainException('Product is unavailable from that provider.');
        }
        $this->assertNonGamblingProduct((string) $row['product_key'], (string) $row['entitlement_key']);
        $request = new PurchaseRequest($userId, $productKey, (int) $row['amount_cents'], (string) $row['currency'], $idempotencyKey, $returnUrl, $cancelUrl, $metadata, $sourceToken);
        $requestHash = hash('sha256', json_encode([$userId, $productKey, $providerName, $row['amount_cents'], $row['currency']], JSON_THROW_ON_ERROR));
        $existing = $this->reserve($idempotencyKey, 'product_checkout', $requestHash);
        if ($existing !== null) {
            return $existing;
        }
        try {
            if ($this->entitlements !== null && $this->entitlements->hasEntitlement($userId, (string) $row['entitlement_key'])) {
                throw new DomainException('This fixed entitlement is already active on the account.');
            }
            $result = $this->providers->make($providerName)->createOneTimeCheckout($request);
            $this->recordProductResult($request, (int) $row['id'], $providerName, $result);
            $this->completeReservation($idempotencyKey, $result);
            return $result;
        } catch (\Throwable $e) {
            $this->failReservation($idempotencyKey);
            throw $e;
        }
    }

    public function beginSubscriptionCheckout(int $userId, string $planKey, string $billingPeriod, string $providerName, string $idempotencyKey, string $returnUrl, string $cancelUrl, array $metadata = [], ?string $paymentSourceToken = null): CheckoutResult
    {
        $this->gate->assertCheckoutAllowed($providerName);
        $row = $this->planCatalog !== null
            ? $this->planCatalog->resolve($planKey, $billingPeriod, $providerName)
            : $this->database->fetchOne('SELECT mp.id, mp.plan_key, mpp.amount_cents, mpp.currency, mpp.provider_plan_id
                FROM membership_plans mp INNER JOIN membership_plan_prices mpp ON mpp.plan_id = mp.id
                WHERE mp.plan_key = :plan AND mpp.billing_period = :period AND mpp.provider = :provider AND mp.active = 1 AND mpp.active = 1',
                ['plan' => $planKey, 'period' => $billingPeriod, 'provider' => $providerName]);
        if ($row === null || (int) $row['amount_cents'] <= 0 || trim((string) $row['provider_plan_id']) === '') {
            throw new DomainException('Membership plan is unavailable or its provider plan mapping is missing.');
        }
        $customer = $this->database->fetchOne('SELECT email FROM users WHERE id = :user AND deleted_at IS NULL', ['user' => $userId]);
        if ($customer === null) {
            throw new DomainException('The customer account is unavailable.');
        }
        $request = new SubscriptionRequest($userId, $planKey, (string) $row['provider_plan_id'], $billingPeriod, (int) $row['amount_cents'], (string) $row['currency'], $idempotencyKey, $returnUrl, $cancelUrl, $metadata, $paymentSourceToken, (string) $customer['email']);
        $requestHash = hash('sha256', json_encode([$userId, $planKey, $billingPeriod, $providerName, $row['amount_cents'], $row['currency'], $row['provider_plan_id']], JSON_THROW_ON_ERROR));
        $existing = $this->reserve($idempotencyKey, 'subscription_checkout', $requestHash);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $existingSubscription = $this->database->fetchOne("SELECT id FROM subscriptions WHERE user_id = :user AND status IN ('pending','trialing','active','past_due','in_grace_period','paused','suspended') LIMIT 1", ['user' => $userId]);
            if ($existingSubscription !== null) {
                throw new DomainException('An existing membership must be resolved before starting another subscription checkout.');
            }
            $result = $this->providers->make($providerName)->createSubscription($request);
            $this->recordSubscriptionResult($request, (int) $row['id'], $providerName, $result);
            $this->completeReservation($idempotencyKey, $result);
            return $result;
        } catch (\Throwable $e) {
            $this->failReservation($idempotencyKey);
            throw $e;
        }
    }

    private function reserve(string $key, string $operation, string $requestHash): ?CheckoutResult
    {
        return $this->database->transaction(function (Database $db) use ($key, $operation, $requestHash): ?CheckoutResult {
            $row = $db->fetchOne($db->forUpdate('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = :key'), ['key' => $key]);
            if ($row !== null) {
                if (!hash_equals((string) $row['request_hash'], $requestHash) || (string) $row['operation'] !== $operation) {
                    throw new DomainException('Payment idempotency key was reused with different checkout data.');
                }
                if ($row['response_json'] !== null) {
                    $data = json_decode((string) $row['response_json'], true, 16, JSON_THROW_ON_ERROR);
                    return $this->hydrateResult($data);
                }
                if ((string) $row['status'] === 'failed') {
                    $db->execute("UPDATE payment_idempotency_keys SET status = 'processing', expires_at = :expires WHERE idempotency_key = :key",
                        ['expires' => gmdate('Y-m-d H:i:s', time() + 86400), 'key' => $key]);
                    return null;
                }
                throw new RuntimeException('A checkout with this idempotency key is already being processed.');
            }
            $db->execute('INSERT INTO payment_idempotency_keys (idempotency_key, operation, request_hash, status, expires_at, created_at)
                VALUES (:key, :operation, :hash, :status, :expires, :now)', ['key' => $key, 'operation' => $operation, 'hash' => $requestHash,
                'status' => 'processing', 'expires' => gmdate('Y-m-d H:i:s', time() + 86400), 'now' => gmdate('Y-m-d H:i:s')]);
            return null;
        });
    }

    private function completeReservation(string $key, CheckoutResult $result): void
    {
        $json = json_encode($this->resultArray($result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->database->execute('UPDATE payment_idempotency_keys SET response_json = :response, status = :status WHERE idempotency_key = :key',
            ['response' => $json, 'status' => $result->successful ? 'completed' : 'failed', 'key' => $key]);
    }

    private function failReservation(string $key): void
    {
        $this->database->execute("UPDATE payment_idempotency_keys SET status = 'failed' WHERE idempotency_key = :key AND status = 'processing'", ['key' => $key]);
    }

    private function recordProductResult(PurchaseRequest $request, int $productId, string $provider, CheckoutResult $result): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $db) use ($request, $productId, $provider, $result, $now): void {
            $paymentId = null;
            if ($result->externalId !== null && $result->externalId !== '') {
                $payment = $db->fetchOne($db->forUpdate('SELECT * FROM payments WHERE provider = :provider AND external_id = :external'), ['provider' => $provider, 'external' => $result->externalId]);
                if ($payment === null) {
                    $paymentId = $db->insert('INSERT INTO payments (user_id, product_id, provider, external_id, status, amount_cents, currency, provider_metadata_json, created_at, updated_at)
                        VALUES (:user, :product, :provider, :external, :status, :amount, :currency, :metadata, :now, :now)', [
                        'user' => $request->userId, 'product' => $productId, 'provider' => $provider, 'external' => $result->externalId,
                        'status' => $result->status, 'amount' => $request->amountCents, 'currency' => $request->currency,
                        'metadata' => json_encode($result->providerData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'now' => $now,
                    ]);
                } else {
                    if ((int) $payment['user_id'] !== $request->userId || (int) $payment['product_id'] !== $productId
                        || (int) $payment['amount_cents'] !== $request->amountCents || (string) $payment['currency'] !== $request->currency) {
                        throw new DomainException('Provider payment reference conflicts with the requested checkout.');
                    }
                    $paymentId = (int) $payment['id'];
                }
                if ($provider === 'demo' && $result->successful && $result->status === 'completed' && $this->entitlements !== null) {
                    $keys = $db->fetchAll('SELECT entitlement_key FROM product_entitlements WHERE product_id = :product', ['product' => $productId]);
                    foreach ($keys as $key) {
                        $this->entitlements->grant($request->userId, (string) $key['entitlement_key'], 'purchase', (string) $paymentId);
                    }
                    $db->execute('UPDATE payments SET paid_at = :now WHERE id = :id', ['now' => $now, 'id' => $paymentId]);
                }
            }
            $this->recordAttempt($db, $request->userId, $paymentId, $provider, $request->idempotencyKey, $result, $now);
        });
    }

    private function recordSubscriptionResult(SubscriptionRequest $request, int $planId, string $provider, CheckoutResult $result): void
    {
        if ($result->externalId === null || $result->externalId === '') {
            $this->recordAttempt($this->database, $request->userId, null, $provider, $request->idempotencyKey, $result, gmdate('Y-m-d H:i:s'));
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $db) use ($request, $planId, $provider, $result, $now): void {
            $subscription = $db->fetchOne($db->forUpdate('SELECT * FROM subscriptions WHERE provider = :provider AND external_id = :external'), ['provider' => $provider, 'external' => $result->externalId]);
            if ($subscription === null) {
                $db->execute('INSERT INTO subscriptions (user_id, plan_id, provider, external_id, status, billing_period, currency, amount_cents, created_at, updated_at)
                    VALUES (:user, :plan, :provider, :external, :status, :period, :currency, :amount, :now, :now)', [
                    'user' => $request->userId, 'plan' => $planId, 'provider' => $provider, 'external' => $result->externalId,
                    'status' => $result->status === 'active' ? 'pending' : $result->status, 'period' => $request->billingPeriod,
                    'currency' => $request->currency, 'amount' => $request->amountCents, 'now' => $now,
                ]);
            } elseif ((int) $subscription['user_id'] !== $request->userId || (int) $subscription['plan_id'] !== $planId
                || (string) $subscription['billing_period'] !== $request->billingPeriod || (int) $subscription['amount_cents'] !== $request->amountCents
                || (string) $subscription['currency'] !== $request->currency) {
                throw new DomainException('Provider subscription reference conflicts with the requested checkout.');
            }
            if ($provider === 'demo' && $result->successful && in_array($result->status, ['active', 'trialing'], true) && $this->lifecycle !== null) {
                $eventId = 'demo_checkout_' . hash('sha256', $result->externalId);
                if ($db->fetchOne('SELECT 1 AS found FROM subscription_events WHERE provider_event_id = :event', ['event' => $eventId]) === null) {
                    $this->lifecycle->apply(new PaymentEvent('demo', $eventId, 'SUBSCRIPTION.ACTIVATED',
                        $result->status, null, $result->externalId, null, null, null, $request->currency, gmdate('c'), []));
                }
            }
            $this->recordAttempt($db, $request->userId, null, $provider, $request->idempotencyKey, $result, $now);
        });
    }

    private function recordAttempt(Database $db, int $userId, ?int $paymentId, string $provider, string $idempotencyKey, CheckoutResult $result, string $now): void
    {
        $attempt = $db->fetchOne($db->forUpdate('SELECT * FROM payment_attempts WHERE idempotency_key = :key'), ['key' => $idempotencyKey]);
        if ($attempt === null) {
            $db->execute('INSERT INTO payment_attempts (user_id, payment_id, provider, idempotency_key, status, failure_code, created_at, updated_at)
                VALUES (:user, :payment, :provider, :key, :status, :failure, :now, :now)', [
                'user' => $userId, 'payment' => $paymentId, 'provider' => $provider, 'key' => $idempotencyKey,
                'status' => $result->status, 'failure' => $result->errorCode, 'now' => $now,
            ]);
            return;
        }
        if ((int) $attempt['user_id'] !== $userId || (string) $attempt['provider'] !== $provider) {
            throw new DomainException('Payment attempt identity conflict.');
        }
        $db->execute('UPDATE payment_attempts SET payment_id = COALESCE(payment_id, :payment), status = :status, failure_code = :failure, updated_at = :now WHERE idempotency_key = :key', [
            'payment' => $paymentId, 'status' => $result->status, 'failure' => $result->errorCode, 'now' => $now, 'key' => $idempotencyKey,
        ]);
    }

    private function assertNonGamblingProduct(string $productKey, string $entitlement): void
    {
        $joined = strtolower($productKey . ' ' . $entitlement);
        if (preg_match('/(?:coin|star|spin|wager|bet|odds|payout|loot|random|mystery|currency|chance)/', $joined)) {
            throw new DomainException('Purchasable items cannot affect virtual currency, wagering, odds, or randomized rewards.');
        }
    }

    private function resultArray(CheckoutResult $result): array
    {
        return ['successful' => $result->successful, 'status' => $result->status, 'external_id' => $result->externalId,
            'approval_url' => $result->approvalUrl, 'error_code' => $result->errorCode, 'provider_data' => $result->providerData];
    }

    private function hydrateResult(array $data): CheckoutResult
    {
        return new CheckoutResult((bool) ($data['successful'] ?? false), (string) ($data['status'] ?? 'failed'),
            isset($data['external_id']) ? (string) $data['external_id'] : null, isset($data['approval_url']) ? (string) $data['approval_url'] : null,
            isset($data['error_code']) ? (string) $data['error_code'] : null, is_array($data['provider_data'] ?? null) ? $data['provider_data'] : []);
    }
}
