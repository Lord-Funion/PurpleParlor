<?php

declare(strict_types=1);

namespace Tests\Payments;

use App\Core\Config;
use App\Payments\CheckoutIntentService;
use App\Payments\CheckoutResult;
use App\Payments\HttpClient;
use App\Payments\PaymentEvent;
use App\Payments\PaymentGate;
use App\Payments\PaymentManager;
use App\Payments\PaymentPlanCatalog;
use App\Payments\PayPalPaymentProvider;
use App\Payments\PaymentProviderFactory;
use App\Payments\PaymentProviderInterface;
use App\Payments\ProviderResult;
use App\Payments\ProviderSubscription;
use App\Payments\PurchaseRequest;
use App\Payments\SubscriptionLifecycleService;
use App\Payments\SubscriptionReconciler;
use App\Payments\SubscriptionRequest;
use App\Payments\SquarePaymentProvider;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\EntitlementService;
use Tests\Support\TestCase;

final class PaymentReliabilityTest extends TestCase
{
    public function testExplicitProductionDemoIsNoChargeAndFailsClosed(): void
    {
        $config = new Config([
            'app' => ['env' => 'production', 'key' => self::KEY, 'name' => 'The Purple Parlor'],
            'payments' => [
                'enabled' => false, 'mode' => 'sandbox', 'provider' => 'demo',
                'adult_owner_confirmed' => false, 'live_activation_lock' => true,
                'currency' => 'USD', 'paypal' => ['plans' => []],
            ],
        ]);
        $gate = new PaymentGate($config, $this->database);
        $this->assertTrue($gate->checkoutAllowed('demo'));

        $entitlements = new EntitlementService($this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $factory = new PaymentProviderFactory($config, $gate, new HttpClient(), $this->database);
        $manager = new PaymentManager($this->database, $factory, $gate, $entitlements, $lifecycle,
            new CheckoutIntentService($this->database), new PaymentPlanCatalog($this->database, $config));
        $result = $manager->beginSubscriptionCheckoutForIntent($this->user(), 'cozy_club', 'monthly', 'demo',
            'https://example.test/billing/return/demo', 'https://example.test/billing/cancel/demo');
        $this->assertTrue($result->successful);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM payments')['count'], 'Demo membership must not create a charged payment record.');

        $config->set('payments.live_activation_lock', false);
        $this->assertFalse($gate->checkoutAllowed('demo'));
        $config->set('payments.live_activation_lock', true);
        $config->set('payments.mode', 'live');
        $this->assertFalse($gate->checkoutAllowed('demo'));
        $config->set('payments.mode', 'sandbox');
        $config->set('payments.provider', 'paypal');
        $this->assertFalse($gate->checkoutAllowed('demo'));
    }

    public function testServerCheckoutIntentReusesKeyAndPreventsDuplicateChargeRecord(): void
    {
        $userId = $this->user();
        $gate = new PaymentGate($this->config, $this->database);
        $factory = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database);
        $entitlements = new EntitlementService($this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $intents = new CheckoutIntentService($this->database);
        $manager = new PaymentManager($this->database, $factory, $gate, $entitlements, $lifecycle, $intents,
            new PaymentPlanCatalog($this->database, $this->config));

        $first = $manager->beginProductCheckoutForIntent($userId, 'royal_onion_profile_pack', 'demo',
            'http://localhost/return', 'http://localhost/cancel');
        $second = $manager->beginProductCheckoutForIntent($userId, 'royal_onion_profile_pack', 'demo',
            'http://localhost/return', 'http://localhost/cancel');
        $this->assertSame($first->externalId, $second->externalId);
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM payments WHERE user_id = :user', ['user' => $userId])['count']);
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM payment_attempts WHERE user_id = :user', ['user' => $userId])['count']);

        $ambiguous = $intents->acquire($userId, 'product', 'cozy_fireplace_soundtrack', '', 'demo', 'demo');
        $intents->recordAmbiguousFailure($ambiguous->idempotencyKey);
        $retry = $intents->acquire($userId, 'product', 'cozy_fireplace_soundtrack', '', 'demo', 'demo');
        $this->assertSame($ambiguous->idempotencyKey, $retry->idempotencyKey, 'Ambiguous retries must reuse the provider idempotency key.');
        $this->database->execute('UPDATE checkout_intents SET expires_at = :past WHERE idempotency_key = :key', ['past' => '2000-01-01 00:00:00', 'key' => $ambiguous->idempotencyKey]);
        $this->expectException(\DomainException::class, fn () => $intents->acquire($userId, 'product', 'cozy_fireplace_soundtrack', '', 'demo', 'demo'));
        $blocked = $this->database->fetchOne("SELECT idempotency_key, status FROM checkout_intents WHERE item_key = 'cozy_fireplace_soundtrack' AND user_id = :user", ['user' => $userId]);
        $this->assertSame($ambiguous->idempotencyKey, $blocked['idempotency_key'], 'Expired ambiguous intents must preserve the original provider key.');
        $this->assertSame('ambiguous', $blocked['status']);

        $sandbox = $intents->acquire($userId, 'subscription', 'cozy_club', 'monthly', 'paypal', 'sandbox');
        $live = $intents->acquire($userId, 'subscription', 'cozy_club', 'monthly', 'paypal', 'live');
        $this->assertNotSame($sandbox->idempotencyKey, $live->idempotencyKey, 'Sandbox and live provider intents must never share a key.');
    }

    public function testVerifiedLifecycleCompletionClosesPendingCheckoutIntent(): void
    {
        $userId = $this->user();
        $gate = new PaymentGate($this->config, $this->database);
        $factory = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database);
        $entitlements = new EntitlementService($this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $manager = new PaymentManager($this->database, $factory, $gate, $entitlements, $lifecycle,
            new CheckoutIntentService($this->database), new PaymentPlanCatalog($this->database, $this->config));
        $checkout = $manager->beginProductCheckoutForIntent($userId, 'founder_supporter_badge', 'demo',
            'http://localhost/return', 'http://localhost/cancel', null, ['demo_scenario' => 'pending']);
        $this->assertSame('pending', $checkout->status);
        $intent = $this->database->fetchOne("SELECT status FROM checkout_intents WHERE user_id = :user AND item_key = 'founder_supporter_badge'", ['user' => $userId]);
        $this->assertSame('pending', $intent['status']);

        $lifecycle->apply(new PaymentEvent('demo', 'pending_checkout_completed', 'PAYMENT.COMPLETED', 'completed', $checkout->externalId,
            null, null, null, 499, 'USD', gmdate('c')));
        $intent = $this->database->fetchOne("SELECT status, terminal_at FROM checkout_intents WHERE user_id = :user AND item_key = 'founder_supporter_badge'", ['user' => $userId]);
        $this->assertSame('completed', $intent['status']);
        $this->assertTrue($intent['terminal_at'] !== null);
        $this->assertTrue($entitlements->hasEntitlement($userId, 'founder.badge'));
    }

    public function testPayPalPlanCatalogUsesCurrentEnvironmentConfigurationWithoutSeedSync(): void
    {
        $this->assertSame(0, (int) $this->database->fetchOne("SELECT COUNT(*) AS count FROM membership_plan_prices WHERE provider = 'paypal'")['count']);
        $this->config->set('payments.enabled', true);
        $this->config->set('payments.provider', 'paypal');
        $this->config->set('payments.mode', 'sandbox');
        $this->config->set('payments.paypal.enabled', true);
        $this->config->set('payments.paypal.environment', 'sandbox');
        $this->config->set('payments.paypal.client_id', 'sandbox-client');
        $this->config->set('payments.paypal.client_secret', 'sandbox-secret');
        $this->config->set('payments.paypal.webhook_id', 'sandbox-webhook');
        $this->config->set('payments.paypal.plans', ['cozy.monthly' => 'P-SANDBOX_A1']);
        $catalog = new PaymentPlanCatalog($this->database, $this->config);
        $this->assertSame('P-SANDBOX_A1', $catalog->configuredPlanId('cozy_club', 'monthly', 'paypal'));

        $this->config->set('payments.paypal.plans', ['cozy.monthly' => 'P-SANDBOX_B2']);
        $this->assertSame('P-SANDBOX_B2', $catalog->configuredPlanId('cozy_club', 'monthly', 'paypal'));
        $this->assertTrue((new PaymentGate($this->config, $this->database))->checkoutAllowed('paypal'));
        $this->config->set('payments.paypal.environment', 'live');
        $this->assertFalse((new PaymentGate($this->config, $this->database))->checkoutAllowed('paypal'));
    }

    public function testSquareSubscriptionUsesCustomerCardPlanAndEnvironmentScopedMapping(): void
    {
        $userId = $this->user();
        $this->config->set('payments.enabled', true);
        $this->config->set('payments.mode', 'sandbox');
        $this->config->set('payments.provider', 'square');
        $this->config->set('payments.square.enabled', true);
        $this->config->set('payments.square.environment', 'sandbox');
        $this->config->set('payments.square.application_id', 'sandbox-square-app');
        $this->config->set('payments.square.access_token', 'sandbox-square-token');
        $this->config->set('payments.square.location_id', 'SANDBOX_LOCATION');
        $this->config->set('payments.square.signature_key', 'sandbox-signature');
        $this->config->set('payments.square.webhook_url', 'https://example.test/api/webhooks/square');
        $this->config->set('payments.square.plans', ['cozy.monthly' => 'SQ_PLAN_VARIATION_MONTHLY']);

        $catalog = new PaymentPlanCatalog($this->database, $this->config);
        $this->assertSame('SQ_PLAN_VARIATION_MONTHLY', $catalog->configuredPlanId('cozy_club', 'monthly', 'square'));
        $http = new FakeSquareHttpClient([
            ['status' => 200, 'json' => ['customer' => ['id' => 'SQ_CUSTOMER_1']]],
            ['status' => 200, 'json' => ['card' => ['id' => 'SQ_CARD_1', 'customer_id' => 'SQ_CUSTOMER_1']]],
            ['status' => 200, 'json' => ['subscription' => ['id' => 'SQ_SUB_1', 'status' => 'ACTIVE', 'customer_id' => 'SQ_CUSTOMER_1', 'card_id' => 'SQ_CARD_1']]],
            ['status' => 200, 'json' => ['subscription' => ['id' => 'SQ_SUB_1', 'status' => 'ACTIVE', 'charged_through_date' => '2026-08-14', 'canceled_date' => '2026-08-14']]],
            ['status' => 200, 'json' => ['subscription' => ['id' => 'SQ_SUB_1', 'status' => 'ACTIVE', 'start_date' => '2026-07-14', 'charged_through_date' => '2026-08-14', 'canceled_date' => '2026-08-14']]],
        ]);
        $provider = new SquarePaymentProvider($this->config, new PaymentGate($this->config, $this->database), $http, $this->database);
        $checkout = $provider->createSubscription(new SubscriptionRequest($userId, 'cozy_club', 'SQ_PLAN_VARIATION_MONTHLY', 'monthly', 299, 'USD',
            'square_subscription_checkout_001', 'https://example.test/return', 'https://example.test/cancel', [], 'sandbox-card-token', 'buyer@example.test'));
        $this->assertTrue($checkout->successful);
        $this->assertSame('active', $checkout->status);
        $this->assertSame('SQ_SUB_1', $checkout->externalId);
        $mapping = $this->database->fetchOne('SELECT provider, external_customer_id FROM payment_provider_customers WHERE user_id = :user', ['user' => $userId]);
        $this->assertSame('square_sandbox', $mapping['provider']);
        $this->assertSame('SQ_CUSTOMER_1', $mapping['external_customer_id']);

        $cardPayload = json_decode((string) $http->requests[1]['body'], true, 16, JSON_THROW_ON_ERROR);
        $subscriptionPayload = json_decode((string) $http->requests[2]['body'], true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('sandbox-card-token', $cardPayload['source_id']);
        $this->assertSame('SQ_CUSTOMER_1', $cardPayload['card']['customer_id']);
        $this->assertSame('SQ_PLAN_VARIATION_MONTHLY', $subscriptionPayload['plan_variation_id']);
        $this->assertSame('SQ_CARD_1', $subscriptionPayload['card_id']);

        $cancel = $provider->cancelSubscription('SQ_SUB_1');
        $this->assertTrue($cancel->successful);
        $snapshot = $provider->retrieveSubscription('SQ_SUB_1');
        $this->assertSame('active', $snapshot->status);
        $this->assertSame('2026-07-14 00:00:00', $snapshot->periodStart);
        $this->assertSame('2026-08-14 00:00:00', $snapshot->periodEnd);
        $this->assertTrue($snapshot->cancelAtPeriodEnd);
    }

    public function testReconciliationRepairsActiveRenewalPeriodAndEntitlementEnd(): void
    {
        $userId = $this->user();
        $planId = (int) $this->database->fetchOne("SELECT id FROM membership_plans WHERE plan_key = 'cozy_club'")['id'];
        $oldStart = '2026-06-01 00:00:00';
        $oldEnd = '2026-07-01 00:00:00';
        $newStart = '2026-07-01 00:00:00';
        $newEnd = '2026-08-01 00:00:00';
        $subscriptionId = $this->database->insert('INSERT INTO subscriptions (user_id, plan_id, provider, external_id, status, billing_period, currency, amount_cents, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at) VALUES (:user, :plan, :provider, :external, :status, :period, :currency, :amount, :start, :end, 0, :now, :now)', [
            'user' => $userId, 'plan' => $planId, 'provider' => 'renewal_stub', 'external' => 'stub_subscription_1',
            'status' => 'active', 'period' => 'monthly', 'currency' => 'USD', 'amount' => 299,
            'start' => $oldStart, 'end' => $oldEnd, 'now' => '2026-06-01 00:00:00',
        ]);
        $entitlements = new EntitlementService($this->database);
        $entitlements->grant($userId, 'ads.disabled', 'subscription', (string) $subscriptionId, $oldStart, $oldEnd);
        $provider = new FixedRenewalProvider(new ProviderSubscription('stub_subscription_1', 'active', $newStart, $newEnd, true));
        $gate = new PaymentGate($this->config, $this->database);
        $factory = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database, ['renewal_stub' => $provider]);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $reconciler = new SubscriptionReconciler($this->database, $factory, $lifecycle, $entitlements);

        $result = $reconciler->reconcile();
        $this->assertSame(1, $result['updated']);
        $subscription = $this->database->fetchOne('SELECT status, current_period_start, current_period_end, cancel_at_period_end FROM subscriptions WHERE id = :id', ['id' => $subscriptionId]);
        $this->assertSame('active', $subscription['status']);
        $this->assertSame($newStart, $subscription['current_period_start']);
        $this->assertSame($newEnd, $subscription['current_period_end']);
        $this->assertSame(1, (int) $subscription['cancel_at_period_end']);
        $entitlement = $this->database->fetchOne("SELECT ends_at FROM user_entitlements WHERE user_id = :user AND entitlement_key = 'ads.disabled' AND source_type = 'subscription' AND source_id = :source", ['user' => $userId, 'source' => (string) $subscriptionId]);
        $this->assertSame($newEnd, $entitlement['ends_at']);
        $this->assertSame(0, $reconciler->reconcile()['updated']);
    }

    public function testLifecycleHandlesOutOfOrderCancellationExpirationRefundDisputeAndUnknownReferences(): void
    {
        $userId = $this->user();
        $planId = (int) $this->database->fetchOne("SELECT id FROM membership_plans WHERE plan_key = 'cozy_club'")['id'];
        $subscriptionId = $this->subscription($userId, $planId, 'lifecycle_primary');
        $entitlements = new EntitlementService($this->database);
        $entitlements->grant($userId, 'ads.disabled', 'subscription', (string) $subscriptionId, '2026-06-01 00:00:00', '2026-07-01 00:00:00');
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);

        $lifecycle->apply(new PaymentEvent('demo', 'lifecycle_newer', 'SUBSCRIPTION.RENEWED', 'active', null, 'lifecycle_primary',
            null, null, null, 'USD', '2026-07-10T12:00:00Z', ['period_start' => '2026-07-01T00:00:00Z', 'period_end' => '2026-08-01T00:00:00Z']));
        $lifecycle->apply(new PaymentEvent('demo', 'lifecycle_stale', 'SUBSCRIPTION.PAYMENT.FAILED', 'past_due', null, 'lifecycle_primary',
            null, null, null, 'USD', '2026-07-01T12:00:00Z', ['period_end' => '2026-07-15T00:00:00Z']));
        $row = $this->database->fetchOne('SELECT status, current_period_end FROM subscriptions WHERE id = :id', ['id' => $subscriptionId]);
        $this->assertSame('active', $row['status']);
        $this->assertSame('2026-08-01 00:00:00', $row['current_period_end']);
        $stale = $this->database->fetchOne("SELECT event_json FROM subscription_events WHERE provider_event_id = 'lifecycle_stale'");
        $this->assertTrue((bool) (json_decode((string) $stale['event_json'], true)['_out_of_order'] ?? false));

        $lifecycle->apply(new PaymentEvent('demo', 'lifecycle_cancel', 'SUBSCRIPTION.CANCELED', 'canceled', null, 'lifecycle_primary',
            null, null, null, 'USD', '2026-07-11T12:00:00Z', ['period_end' => '2026-08-01T00:00:00Z', 'cancel_at_period_end' => true]));
        $this->assertTrue($entitlements->hasEntitlement($userId, 'ads.disabled'), 'Paid-through cancellation must retain access until period end.');
        $lifecycle->apply(new PaymentEvent('demo', 'lifecycle_expired', 'SUBSCRIPTION.EXPIRED', 'expired', null, 'lifecycle_primary',
            null, null, null, 'USD', '2026-08-02T00:00:00Z'));
        $primaryEntitlement = $this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE source_type = 'subscription' AND source_id = :source AND entitlement_key = 'ads.disabled'", ['source' => (string) $subscriptionId]);
        $this->assertTrue($primaryEntitlement['revoked_at'] !== null);

        foreach (['refunded', 'disputed'] as $terminal) {
            $external = 'lifecycle_' . $terminal;
            $id = $this->subscription($userId, $planId, $external);
            $entitlements->grant($userId, 'supporter.badge', 'subscription', (string) $id, '2026-07-01 00:00:00', '2026-08-01 00:00:00');
            $lifecycle->apply(new PaymentEvent('demo', 'lifecycle_' . $terminal . '_event', 'SUBSCRIPTION.' . strtoupper($terminal), $terminal,
                null, $external, null, null, null, 'USD', '2026-07-12T00:00:00Z'));
            $revoked = $this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE source_type = 'subscription' AND source_id = :source AND entitlement_key = 'supporter.badge'", ['source' => (string) $id]);
            $this->assertTrue($revoked['revoked_at'] !== null, ucfirst($terminal) . ' must revoke subscription entitlements.');
        }

        $this->expectException(\RuntimeException::class, fn () => $lifecycle->apply(new PaymentEvent('demo', 'unknown_subscription_event',
            'SUBSCRIPTION.RENEWED', 'active', null, 'missing_provider_subscription', null, null, null, 'USD', gmdate('c'))));
    }

    public function testProviderWebhookFixturesNormalizeIdentifiersStatusesAndMoney(): void
    {
        $paypal = new PayPalPaymentProvider($this->config, new PaymentGate($this->config, $this->database), new HttpClient());
        $paypalBody = json_encode([
            'id' => 'WH-PAYPAL-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => '2026-07-13T12:00:00Z',
            'resource' => ['id' => 'CAPTURE-123', 'status' => 'COMPLETED', 'amount' => ['value' => '12.34', 'currency_code' => 'USD']],
        ], JSON_THROW_ON_ERROR);
        $paypalEvent = $paypal->parseWebhook($paypalBody, []);
        $this->assertSame('WH-PAYPAL-1', $paypalEvent->eventId);
        $this->assertSame('CAPTURE-123', $paypalEvent->externalPaymentId);
        $this->assertSame('active', $paypalEvent->status);
        $this->assertSame(1234, $paypalEvent->amountCents);
        $this->assertSame('USD', $paypalEvent->currency);

        $square = new SquarePaymentProvider($this->config, new PaymentGate($this->config, $this->database), new HttpClient());
        $squareBody = json_encode([
            'event_id' => 'SQ-EVENT-1', 'type' => 'payment.updated', 'created_at' => '2026-07-13T12:00:00Z',
            'data' => ['object' => ['payment' => ['id' => 'SQ-PAYMENT-1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1234, 'currency' => 'USD']]]],
        ], JSON_THROW_ON_ERROR);
        $squareEvent = $square->parseWebhook($squareBody, []);
        $this->assertSame('SQ-EVENT-1', $squareEvent->eventId);
        $this->assertSame('SQ-PAYMENT-1', $squareEvent->externalPaymentId);
        $this->assertSame('completed', $squareEvent->status);
        $this->assertSame(1234, $squareEvent->amountCents);
        $this->assertSame('USD', $squareEvent->currency);

        $subscriptionBody = json_encode([
            'event_id' => 'SQ-SUB-EVENT-1', 'type' => 'subscription.updated', 'created_at' => '2026-07-14T12:00:00Z',
            'data' => ['object' => ['subscription' => ['id' => 'SQ-SUBSCRIPTION-1', 'status' => 'ACTIVE', 'start_date' => '2026-07-14',
                'charged_through_date' => '2026-08-14', 'canceled_date' => '2026-08-14']]],
        ], JSON_THROW_ON_ERROR);
        $subscriptionEvent = $square->parseWebhook($subscriptionBody, []);
        $this->assertSame('SQ-SUBSCRIPTION-1', $subscriptionEvent->externalSubscriptionId);
        $this->assertSame('active', $subscriptionEvent->status);
        $this->assertSame('2026-08-14', $subscriptionEvent->data['period_end']);
        $this->assertTrue($subscriptionEvent->data['cancel_at_period_end']);

        $invoiceBody = json_encode([
            'event_id' => 'SQ-INVOICE-EVENT-1', 'type' => 'invoice.scheduled_charge_failed', 'created_at' => '2026-08-14T12:00:00Z',
            'data' => ['object' => ['invoice' => ['id' => 'SQ-INVOICE-1', 'subscription_id' => 'SQ-SUBSCRIPTION-1']]],
        ], JSON_THROW_ON_ERROR);
        $invoiceEvent = $square->parseWebhook($invoiceBody, []);
        $this->assertSame('SQ-SUBSCRIPTION-1', $invoiceEvent->externalSubscriptionId);
        $this->assertSame('past_due', $invoiceEvent->status);
    }

    public function testLiveProviderRequiresEnabledAdultOwnerAndBothLocks(): void
    {
        $config = new Config(['app' => ['env' => 'production'], 'payments' => [
            'enabled' => true, 'mode' => 'live', 'provider' => 'paypal', 'adult_owner_confirmed' => false, 'live_activation_lock' => false,
            'paypal' => ['enabled' => true, 'environment' => 'live', 'client_id' => 'live-client', 'client_secret' => 'live-secret', 'webhook_id' => 'live-webhook'],
        ]]);
        $this->database->execute("UPDATE system_settings SET setting_value = 'false' WHERE setting_key = 'payments.production_locked'");
        $gate = new PaymentGate($config, $this->database);
        $this->assertFalse($gate->checkoutAllowed('paypal'));
        $config->set('payments.adult_owner_confirmed', true);
        $this->assertTrue($gate->checkoutAllowed('paypal'));
        $config->set('payments.enabled', false);
        $this->assertFalse($gate->checkoutAllowed('paypal'));
        $config->set('payments.enabled', true);
        $config->set('payments.live_activation_lock', true);
        $this->assertFalse($gate->checkoutAllowed('paypal'));
        $config->set('payments.live_activation_lock', false);
        $this->database->execute("UPDATE system_settings SET setting_value = 'true' WHERE setting_key = 'payments.production_locked'");
        $this->assertFalse($gate->checkoutAllowed('paypal'));
    }

    private function subscription(int $userId, int $planId, string $externalId): int
    {
        return $this->database->insert('INSERT INTO subscriptions (user_id, plan_id, provider, external_id, status, billing_period, currency, amount_cents, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at) VALUES (:user, :plan, :provider, :external, :status, :period, :currency, :amount, :start, :end, 0, :now, :now)', [
            'user' => $userId, 'plan' => $planId, 'provider' => 'demo', 'external' => $externalId,
            'status' => 'active', 'period' => 'monthly', 'currency' => 'USD', 'amount' => 299,
            'start' => '2026-06-01 00:00:00', 'end' => '2026-07-01 00:00:00', 'now' => '2026-06-01 00:00:00',
        ]);
    }

    private function user(): int
    {
        return (new UserRepository($this->database))->create('reliable' . bin2hex(random_bytes(3)) . '@example.test',
            'Reliable' . bin2hex(random_bytes(3)), (new PasswordHasher())->hash('payment reliability password!'), 'active')->id;
    }
}

final class FixedRenewalProvider implements PaymentProviderInterface
{
    public function __construct(private readonly ProviderSubscription $subscription) {}
    public function name(): string { return 'renewal_stub'; }
    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult { return new CheckoutResult(false, 'failed'); }
    public function createSubscription(SubscriptionRequest $request): CheckoutResult { return new CheckoutResult(false, 'failed'); }
    public function cancelSubscription(string $externalId): ProviderResult { return new ProviderResult(false, 'failed', $externalId); }
    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult { return new ProviderResult(false, 'failed', $externalId); }
    public function retrieveSubscription(string $externalId): ProviderSubscription { return $this->subscription; }
    public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
    public function parseWebhook(string $rawBody, array $headers): PaymentEvent { throw new \RuntimeException('Not used by this test provider.'); }
}

final class FakeSquareHttpClient extends HttpClient
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = [];

    /** @param list<array{status:int,json:array<string,mixed>}> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = array_shift($this->responses);
        if (!is_array($response)) {
            throw new \RuntimeException('No fake Square response remains.');
        }
        return ['status' => $response['status'], 'headers' => [], 'body' => json_encode($response['json'], JSON_THROW_ON_ERROR), 'json' => $response['json']];
    }
}
