<?php

declare(strict_types=1);

namespace Tests\Payments;

use App\Security\PasswordHasher;
use App\Payments\CheckoutResult;
use App\Payments\HttpClient;
use App\Payments\PaymentAuditService;
use App\Payments\PaymentEvent;
use App\Payments\PaymentGate;
use App\Payments\PaymentProviderFactory;
use App\Payments\PaymentProviderInterface;
use App\Payments\ProviderResult;
use App\Payments\ProviderSubscription;
use App\Payments\PurchaseRequest;
use App\Payments\RefundExecutionService;
use App\Payments\SubscriptionRequest;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\EntitlementService;
use DomainException;
use RuntimeException;
use Tests\Support\TestCase;

final class AdminRefundExecutionTest extends TestCase
{
    public function testDemoFullRefundIsExecutedOnceAndPermanentlyAudited(): void
    {
        $user = $this->user();
        $payment = $this->payment($user, 500, true);
        $request = $this->refundRequest($user, $payment, 500);
        $service = $this->service();

        $result = $service->execute($user, $request, 'REFUND REQUEST #' . $request, 'Customer approved complete provider refund.');
        $duplicate = $service->execute($user, $request, 'REFUND REQUEST #' . $request, 'Confirm prior provider refund without duplication.');

        $this->assertSame('confirmed', $result['status']);
        $this->assertTrue($result['full_refund']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM refunds WHERE payment_id = :payment', ['payment' => $payment])['aggregate']);
        $execution = $this->database->fetchOne('SELECT status, attempt_count FROM refund_executions WHERE refund_request_id = :request', ['request' => $request]);
        $this->assertSame('confirmed', $execution['status']);
        $this->assertSame(1, (int) $execution['attempt_count']);
        $this->assertSame('refunded', $this->database->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment])['status']);
        $this->assertTrue($this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE source_type = 'purchase' AND source_id = :source", ['source' => (string) $payment])['revoked_at'] !== null);
        $this->assertTrue((int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM admin_audit_logs WHERE action LIKE 'refund.provider_submission_%'")['aggregate'] >= 2);
        $this->assertTrue((int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM payment_audit_logs WHERE action LIKE 'refund.provider_submission_%'")['aggregate'] >= 2);
    }

    public function testPartialThenCumulativeFullRefundControlsEntitlementBoundary(): void
    {
        $user = $this->user();
        $payment = $this->payment($user, 500, true);
        $service = $this->service();

        $first = $this->refundRequest($user, $payment, 200);
        $partial = $service->execute($user, $first, 'REFUND REQUEST #' . $first, 'Approved partial customer refund of two dollars.');
        $this->assertFalse($partial['full_refund']);
        $this->assertSame('completed', $this->database->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment])['status']);
        $this->assertSame(null, $this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE source_type = 'purchase' AND source_id = :source", ['source' => (string) $payment])['revoked_at']);

        $second = $this->refundRequest($user, $payment, 300);
        $full = $service->execute($user, $second, 'REFUND REQUEST #' . $second, 'Approved remaining customer refund of three dollars.');
        $this->assertTrue($full['full_refund']);
        $this->assertSame('refunded', $this->database->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $payment])['status']);
        $this->assertTrue($this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE source_type = 'purchase' AND source_id = :source", ['source' => (string) $payment])['revoked_at'] !== null);
    }

    public function testAmbiguousProviderOutcomeRetriesWithSameIdempotencyKey(): void
    {
        $user = $this->user();
        $payment = $this->payment($user, 500, false);
        $request = $this->refundRequest($user, $payment, 200);
        $provider = new AmbiguousThenSuccessfulRefundProvider();
        $service = $this->service($provider);

        $this->expectException(RuntimeException::class, fn () => $service->execute(
            $user, $request, 'REFUND REQUEST #' . $request, 'First reviewed attempt may have reached provider.'
        ));
        $this->assertSame('ambiguous', $this->database->fetchOne('SELECT status FROM refund_executions WHERE refund_request_id = :request', ['request' => $request])['status']);
        $result = $service->execute($user, $request, 'REFUND REQUEST #' . $request, 'Retry reviewed provider request using same operation key.');

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame(2, $provider->calls);
        $this->assertSame($provider->keys[0], $provider->keys[1]);
        $this->assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM refunds WHERE payment_id = :payment', ['payment' => $payment])['aggregate']);
        $this->assertSame(2, (int) $this->database->fetchOne('SELECT attempt_count FROM refund_executions WHERE refund_request_id = :request', ['request' => $request])['attempt_count']);
    }

    public function testInvalidTransitionConfirmationAndOverRefundAreRejected(): void
    {
        $user = $this->user();
        $payment = $this->payment($user, 500, false);
        $service = $this->service();
        $underReview = $this->refundRequest($user, $payment, 100, 'under_review');
        $this->expectException(DomainException::class, fn () => $service->execute(
            $user, $underReview, 'REFUND REQUEST #' . $underReview, 'Attempt from an unapproved internal workflow state.'
        ));

        $badConfirmation = $this->refundRequest($user, $payment, 100);
        $this->expectException(DomainException::class, fn () => $service->execute(
            $user, $badConfirmation, 'refund request', 'Attempt without the exact reviewed confirmation phrase.'
        ));

        $tooLarge = $this->refundRequest($user, $payment, 501);
        $this->expectException(DomainException::class, fn () => $service->execute(
            $user, $tooLarge, 'REFUND REQUEST #' . $tooLarge, 'Attempt exceeds original local payment amount.'
        ));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM refunds WHERE payment_id = :payment', ['payment' => $payment])['aggregate']);
    }

    private function service(?PaymentProviderInterface $provider = null): RefundExecutionService
    {
        $overrides = $provider === null ? [] : ['demo' => $provider];
        $factory = new PaymentProviderFactory($this->config, new PaymentGate($this->config, $this->database), new HttpClient(), $this->database, $overrides);
        return new RefundExecutionService(
            $this->database,
            $factory,
            new AuditService($this->database, self::KEY),
            new PaymentAuditService($this->database, self::KEY),
            new EntitlementService($this->database),
        );
    }

    private function user(): int
    {
        return (new UserRepository($this->database))->create(
            'refund' . bin2hex(random_bytes(3)) . '@example.test',
            'Refund' . bin2hex(random_bytes(3)),
            (new PasswordHasher())->hash('refund execution password!'),
            'active',
        )->id;
    }

    private function payment(int $userId, int $amount, bool $withEntitlement): int
    {
        $product = $withEntitlement ? $this->database->fetchOne('SELECT id FROM products ORDER BY id LIMIT 1') : null;
        $now = gmdate('Y-m-d H:i:s');
        $payment = $this->database->insert('INSERT INTO payments (user_id, product_id, provider, external_id, status, amount_cents, currency, paid_at, created_at, updated_at)
            VALUES (:user, :product, :provider, :external, :status, :amount, :currency, :paid, :created, :updated)', [
            'user' => $userId, 'product' => $product['id'] ?? null, 'provider' => 'demo',
            'external' => 'demo_pay_refund_' . bin2hex(random_bytes(8)), 'status' => 'completed',
            'amount' => $amount, 'currency' => 'USD', 'paid' => $now, 'created' => $now, 'updated' => $now,
        ]);
        if ($withEntitlement) {
            $key = $this->database->fetchOne('SELECT entitlement_key FROM product_entitlements WHERE product_id = :product ORDER BY entitlement_key LIMIT 1', ['product' => $product['id']]);
            (new EntitlementService($this->database))->grant($userId, (string) $key['entitlement_key'], 'purchase', (string) $payment);
        }
        return $payment;
    }

    private function refundRequest(int $userId, int $paymentId, int $amount, string $status = 'provider_action_required'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return $this->database->insert('INSERT INTO refund_requests (payment_id, requested_amount_cents, status, request_reason, requested_by, updated_by, created_at, updated_at)
            VALUES (:payment, :amount, :status, :reason, :actor, :actor, :created, :updated)', [
            'payment' => $paymentId, 'amount' => $amount, 'status' => $status,
            'reason' => 'Reviewed refund request fixture.', 'actor' => $userId, 'created' => $now, 'updated' => $now,
        ]);
    }
}

final class AmbiguousThenSuccessfulRefundProvider implements PaymentProviderInterface
{
    public int $calls = 0;
    /** @var list<string> */
    public array $keys = [];

    public function name(): string { return 'demo'; }
    public function createOneTimeCheckout(PurchaseRequest $request): CheckoutResult { return new CheckoutResult(false, 'failed'); }
    public function createSubscription(SubscriptionRequest $request): CheckoutResult { return new CheckoutResult(false, 'failed'); }
    public function cancelSubscription(string $externalId): ProviderResult { return new ProviderResult(false, 'failed'); }
    public function refundPayment(string $externalId, int $amountCents, ?string $idempotencyKey = null): ProviderResult
    {
        $this->calls++;
        $this->keys[] = (string) $idempotencyKey;
        if ($this->calls === 1) {
            throw new RuntimeException('Simulated provider timeout containing secret=never-display-this.');
        }
        return new ProviderResult(true, 'refunded', 'stub_ref_' . substr(hash('sha256', (string) $idempotencyKey), 0, 24));
    }
    public function retrieveSubscription(string $externalId): ProviderSubscription { return new ProviderSubscription($externalId, 'active'); }
    public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
    public function parseWebhook(string $rawBody, array $headers): PaymentEvent { throw new RuntimeException('Not used.'); }
}
