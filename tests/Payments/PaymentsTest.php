<?php

declare(strict_types=1);

namespace Tests\Payments;

use App\Core\Config;
use App\Payments\DemoPaymentProvider;
use App\Payments\HttpClient;
use App\Payments\PaymentAuditService;
use App\Payments\PaymentGate;
use App\Payments\PaymentManager;
use App\Payments\PaymentProviderFactory;
use App\Payments\SquarePaymentProvider;
use App\Payments\SubscriptionLifecycleService;
use App\Payments\WebhookProcessor;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\EntitlementService;
use Tests\Support\TestCase;

final class PaymentsTest extends TestCase
{
    public function testDemoPurchaseRequiresVerifiedWebhookAndDuplicateIsSafe(): void
    {
        $userId = $this->user();
        [$factory, $manager, $webhooks, $entitlements] = $this->services();
        $checkout = $manager->beginProductCheckout($userId, 'purple_theme_pack', 'demo', 'purchase-demo-0000001', 'http://localhost/return', 'http://localhost/cancel');
        $this->assertTrue($checkout->successful);
        $this->assertTrue($entitlements->hasEntitlement($userId, 'theme.purple_premium'), 'The local demo provider response is authoritative server confirmation.');
        $delivery = $factory->make('demo')->lifecycleEvent($checkout->externalId, 'success', 'payment');
        $first = $webhooks->process('demo', $delivery['body'], $delivery['headers']);
        $second = $webhooks->process('demo', $delivery['body'], $delivery['headers']);
        $this->assertTrue($first->accepted);
        $this->assertTrue($second->duplicate);
        $this->assertTrue($entitlements->hasEntitlement($userId, 'theme.purple_premium'));
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM user_entitlements WHERE user_id = :user', ['user' => $userId])['count']);
    }

    public function testDemoSubscriptionPersistsAcrossProviderInstancesAndLifecycle(): void
    {
        $userId = $this->user();
        [$factory, $manager, $webhooks, $entitlements] = $this->services();
        $checkout = $manager->beginSubscriptionCheckout($userId, 'cozy_club', 'monthly', 'demo', 'subscription-demo-001', 'http://localhost/return', 'http://localhost/cancel');
        $secondFactory = new PaymentProviderFactory($this->config, new PaymentGate($this->config), new HttpClient(), $this->database);
        $this->assertSame('active', $secondFactory->make('demo')->retrieveSubscription($checkout->externalId)->status);
        $delivery = $secondFactory->make('demo')->lifecycleEvent($checkout->externalId, 'success', 'subscription');
        $this->assertTrue($webhooks->process('demo', $delivery['body'], $delivery['headers'])->accepted);
        $this->assertTrue($entitlements->hasEntitlement($userId, 'ads.disabled'));
        $cancel = $secondFactory->make('demo')->cancelSubscription($checkout->externalId);
        $this->assertTrue($cancel->successful);
        $this->assertSame('canceled', $factory->make('demo')->retrieveSubscription($checkout->externalId)->status);
    }

    public function testInvalidWebhookAndPaymentSafetyGate(): void
    {
        [$factory, , $webhooks] = $this->services();
        $invalid = $factory->make('demo')->invalidlySignedEvent(['id' => 'demo_evt_invalid_00001', 'type' => 'PAYMENT.COMPLETED', 'status' => 'completed', 'payment_id' => 'unknown']);
        $this->assertSame('invalid_signature', $webhooks->process('demo', $invalid['body'], $invalid['headers'])->status);

        $live = new Config(['app' => ['env' => 'production'], 'payments' => ['enabled' => true, 'mode' => 'live', 'adult_owner_confirmed' => true,
            'live_activation_lock' => false, 'paypal' => ['enabled' => true, 'environment' => 'live', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'webhook']]]);
        $this->assertTrue((new PaymentGate($live))->liveReady());
        $this->assertFalse((new PaymentGate($live, $this->database))->liveReady(), 'Persistent Adult Owner lock must fail closed even when environment gates are open.');
        $this->database->execute("UPDATE system_settings SET setting_value = 'false' WHERE setting_key = 'payments.production_locked'");
        $this->assertTrue((new PaymentGate($live, $this->database))->liveReady());
        $live->set('payments.live_activation_lock', true);
        $this->assertFalse((new PaymentGate($live))->liveReady());
        $live->set('payments.live_activation_lock', false);
        $live->set('payments.paypal.webhook_id', '');
        $this->assertFalse((new PaymentGate($live))->checkoutAllowed('paypal'));
    }

    public function testSquareSignatureUsesExactNotificationUrlAndBody(): void
    {
        $this->config->set('payments.square.signature_key', 'square-test-signature-key');
        $this->config->set('payments.square.webhook_url', 'https://example.test/api/webhooks/square');
        $provider = new SquarePaymentProvider($this->config, new PaymentGate($this->config), new HttpClient());
        $body = '{"event_id":"square-event-1","type":"payment.updated","data":{"object":{"payment":{"id":"p1","status":"COMPLETED","amount_money":{"amount":100,"currency":"USD"}}}}}';
        $signature = base64_encode(hash_hmac('sha256', 'https://example.test/api/webhooks/square' . $body, 'square-test-signature-key', true));
        $this->assertTrue($provider->verifyWebhook($body, ['x-square-hmacsha256-signature' => $signature]));
        $this->assertFalse($provider->verifyWebhook($body . ' ', ['x-square-hmacsha256-signature' => $signature]));
    }

    private function services(): array
    {
        $gate = new PaymentGate($this->config);
        $factory = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database);
        $entitlements = new EntitlementService($this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, $entitlements, 7);
        $webhooks = new WebhookProcessor($this->database, $factory, $lifecycle, new PaymentAuditService($this->database, self::KEY), $this->config);
        return [$factory, new PaymentManager($this->database, $factory, $gate, $entitlements, $lifecycle), $webhooks, $entitlements];
    }

    private function user(): int
    {
        $user = (new UserRepository($this->database))->create('pay' . bin2hex(random_bytes(3)) . '@example.test', 'Pay' . bin2hex(random_bytes(3)), (new PasswordHasher())->hash('payment test password!'), 'active');
        return $user->id;
    }
}
