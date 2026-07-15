<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Database\Database;
use App\Http\View;
use App\Payments\PaymentEvent;
use App\Payments\PaymentGate;
use App\Payments\PaymentManager;
use App\Payments\PaymentPlanCatalog;
use App\Payments\PaymentProviderFactory;
use App\Payments\SubscriptionLifecycleService;
use App\Services\EntitlementService;
use App\Services\LegalAcceptanceService;
use App\Services\MembershipBonusService;
use DomainException;
use Throwable;

final class BillingController extends BaseController
{
    public function __construct(
        View $view,
        Config $config,
        Database $database,
        AuthService $auth,
        private readonly PaymentManager $payments,
        private readonly PaymentGate $gate,
        private readonly PaymentProviderFactory $providers,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly EntitlementService $entitlements,
        private readonly MembershipBonusService $membershipBonuses,
        private readonly PaymentPlanCatalog $planCatalog,
        private readonly Logger $logger,
        private readonly LegalAcceptanceService $legalAcceptances,
    ) {
        parent::__construct($view, $config, $database, $auth);
    }

    public function plans(Request $request): Response
    {
        $period = (string) $request->input('billing', 'monthly');
        $period = in_array($period, ['monthly', 'annual'], true) ? $period : 'monthly';
        // Aggregate in PHP so this remains portable when cPanel MySQL enables
        // ONLY_FULL_GROUP_BY (the usual production-safe default).
        $rows = $this->database->fetchAll('SELECT mp.plan_key, mp.name, mp.description, mp.benefits_json, mp.sort_order, mpp.billing_period, mpp.amount_cents FROM membership_plans mp LEFT JOIN membership_plan_prices mpp ON mpp.plan_id = mp.id AND mpp.active = 1 WHERE mp.active = 1 ORDER BY mp.sort_order, mp.id, mpp.amount_cents');
        $plans = [];
        foreach ($rows as $row) {
            $key = (string) $row['plan_key'];
            if (!isset($plans[$key])) {
                $bonus = $this->membershipBonuses->amountForPlan($key);
                $benefits = $this->benefitLabels((string) $row['benefits_json']);
                if ($bonus > 0) {
                    array_unshift($benefits, number_format($bonus) . ' extra Cozy Coins every UTC day');
                }
                $plans[$key] = [
                    'slug' => $key,
                    'name' => $row['name'],
                    'monthly_cents' => 0,
                    'annual_cents' => 0,
                    'description' => $row['description'],
                    'featured' => $key === 'cozy_club',
                    'benefits' => $benefits,
                    'daily_bonus_coins' => $bonus,
                ];
            }
            if (in_array((string) $row['billing_period'], ['monthly', 'annual'], true)) {
                $priceKey = (string) $row['billing_period'] . '_cents';
                $candidate = (int) $row['amount_cents'];
                if ($plans[$key][$priceKey] === 0 || $candidate < $plans[$key][$priceKey]) {
                    $plans[$key][$priceKey] = $candidate;
                }
            }
        }
        unset($plans['free']);
        array_unshift($plans, ['slug' => 'free', 'name' => 'Free', 'monthly_cents' => 0, 'annual_cents' => 0, 'description' => 'The complete standard play-money arcade.', 'featured' => false, 'benefits' => ['Every standard game', 'Daily free Cozy Coins', 'Standard themes and profiles', 'Cloud saving'], 'daily_bonus_coins' => 0]);
        return $this->page($request, 'membership/index', 'Membership plans', ['plans' => array_values($plans), 'billing_period' => $period]);
    }

    public function shop(Request $request): Response
    {
        $userId = $this->userId();
        $rows = $this->database->fetchAll('SELECT p.id, p.product_key, p.name, p.description, p.entitlement_key, pp.amount_cents FROM products p LEFT JOIN product_prices pp ON pp.product_id = p.id AND pp.active = 1 WHERE p.active = 1 ORDER BY p.id, pp.amount_cents');
        $lowest = [];
        foreach ($rows as $row) {
            $key = (string) $row['product_key'];
            if (!isset($lowest[$key]) || (int) $row['amount_cents'] < (int) $lowest[$key]['amount_cents']) {
                $lowest[$key] = $row;
            }
        }
        $products = array_map(function (array $row) use ($userId): array {
            return [
                'slug' => $row['product_key'], 'name' => $row['name'], 'description' => $row['description'], 'price_cents' => (int) $row['amount_cents'],
                'icon' => '✦', 'owned' => $userId !== null && $this->entitlements->hasEntitlement($userId, (string) $row['entitlement_key']),
            ];
        }, array_values($lowest));
        return $this->page($request, 'shop/index', 'Cosmetic shop', ['products' => $products]);
    }

    public function billing(Request $request): Response
    {
        $membership = $this->membershipData((int) $this->userId());
        $recent = array_slice($this->transactionRows((int) $this->userId()), 0, 5);
        $owned = (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM user_entitlements WHERE user_id = :user AND source_type = :type AND revoked_at IS NULL AND (ends_at IS NULL OR ends_at > :now)', ['user' => (int) $this->userId(), 'type' => 'purchase', 'now' => gmdate('Y-m-d H:i:s')])['total'] ?? 0);
        return $this->page($request, 'billing/dashboard', 'Billing', ['membership' => $membership, 'recent_purchases' => $recent, 'owned_product_count' => $owned], true);
    }

    public function checkoutForm(Request $request): Response
    {
        $key = trim((string) $request->input('product'));
        $period = in_array((string) $request->input('period'), ['monthly', 'annual'], true) ? (string) $request->input('period') : 'monthly';
        $product = $this->checkoutProduct($key, $period);
        if ($product === null) {
            return $this->page($request, 'errors/404', 'Checkout item not found', [], true, 'layouts/app', 404);
        }
        return $this->page($request, 'billing/checkout', 'Review checkout', [
            'product' => $product,
            'payments' => $this->publicPaymentConfiguration($product),
            'customer_email' => (string) ($this->database->fetchOne('SELECT email FROM users WHERE id = :user', ['user' => (int) $this->userId()])['email'] ?? ''),
            'csrf_token' => csrf_token(),
        ], true);
    }

    public function checkout(Request $request): Response
    {
        $provider = strtolower((string) $request->input('provider', ''));
        $key = (string) $request->input('product_id', $request->input('product', ''));
        $period = in_array((string) $request->input('period'), ['monthly', 'annual'], true) ? (string) $request->input('period') : 'monthly';
        $product = $this->checkoutProduct($key, $period);
        if ($product === null) {
            return $this->checkoutError($request, 'That checkout item is unavailable.', 404);
        }
        $terms = $request->input('terms_accepted') === true || in_array((string) $request->input('terms_accepted'), ['1', 'on', 'true'], true);
        if (!$terms) {
            return $this->checkoutError($request, 'Accept the applicable terms before checkout.', 422);
        }
        if (!in_array($provider, ['demo', 'paypal', 'square'], true)) {
            return $this->checkoutError($request, 'Select an available checkout provider.', 422);
        }
        $base = rtrim((string) $this->config->get('app.url'), '/');
        try {
            $documents = $product['kind'] === 'subscription' ? ['terms', 'subscription-terms'] : ['terms'];
            $this->legalAcceptances->accept((int) $this->userId(), $documents);
            $metadata = ['demo_scenario' => (string) $request->input('scenario', 'success')];
            $source = ($token = trim((string) $request->input('source_token'))) === '' ? null : $token;
            if ($product['kind'] === 'subscription') {
                $result = $this->payments->beginSubscriptionCheckoutForIntent((int) $this->userId(), (string) $product['id'], $period, $provider, $base . '/billing/return/' . rawurlencode($provider), $base . '/billing/cancel/' . rawurlencode($provider), $metadata, $source);
            } else {
                $result = $this->payments->beginProductCheckoutForIntent((int) $this->userId(), (string) $product['id'], $provider, $base . '/billing/return/' . rawurlencode($provider), $base . '/billing/cancel/' . rawurlencode($provider), $source, $metadata);
            }
            if (!$result->successful) {
                $this->logger->warning('A checkout provider returned an unsuccessful result.', [
                    'provider' => $provider, 'status' => $result->status, 'error_code' => $result->errorCode,
                    'user_id' => $this->userId(),
                ]);
                return $this->checkoutError($request, $this->genericCheckoutFailure(), 422);
            }
            $redirect = $result->approvalUrl ?: '/billing?checkout=' . rawurlencode($result->status);
            if ($request->expectsJson()) {
                return Response::json(['status' => $result->status, 'redirect_url' => $redirect, 'external_reference' => $result->externalId]);
            }
            return Response::redirect($redirect);
        } catch (DomainException $exception) {
            return $this->checkoutError($request, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->checkoutError($request, $this->unexpectedBillingFailure($exception, 'checkout'), 422);
        }
    }

    public function subscribe(Request $request): Response
    {
        return $this->checkout($request);
    }

    public function purchases(Request $request): Response
    {
        return $this->page($request, 'billing/history', 'Purchase history', ['transactions' => $this->transactionRows((int) $this->userId())], true);
    }

    public function subscription(Request $request): Response
    {
        $row = $this->subscriptionRow((int) $this->userId());
        $subscription = $row === null ? ['plan' => 'Free', 'status' => 'active', 'provider' => 'None', 'started' => '—', 'renews' => '—', 'paid_through' => '—', 'cancel_at_period_end' => false, 'provider_reference' => '—', 'daily_bonus_coins' => 0] : [
            'plan' => $row['name'], 'status' => $row['status'], 'provider' => ucfirst((string) $row['provider']), 'started' => $row['created_at'],
            'renews' => $row['cancel_at_period_end'] ? 'Cancellation scheduled' : ($row['current_period_end'] ?? 'Provider pending'),
            'paid_through' => $row['current_period_end'] ?? 'Provider pending', 'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'provider_reference' => $this->maskReference((string) $row['external_id']),
            'daily_bonus_coins' => $this->membershipBonuses->amountForPlan((string) $row['plan_key']),
        ];
        return $this->page($request, 'billing/subscriptions', 'Subscription', ['subscription' => $subscription], true);
    }

    public function cancel(Request $request): Response
    {
        $row = $this->subscriptionRow((int) $this->userId());
        if ($row === null || !in_array((string) $row['status'], ['active','trialing','in_grace_period','past_due'], true)) {
            $this->flash('There is no cancellable subscription.', 'error');
            return Response::redirect('/billing/subscriptions');
        }
        try {
            $result = $this->providers->make((string) $row['provider'])->cancelSubscription((string) $row['external_id']);
            if (!$result->successful) {
                throw new DomainException('The provider did not confirm cancellation. Contact billing support with the safe reference.');
            }
            $periodEnd = $result->providerData['charged_through_date']
                ?? $result->providerData['billing_info']['next_billing_time']
                ?? $row['current_period_end']
                ?? null;
            $eventData = ['cancel_at_period_end' => true];
            if (is_string($periodEnd) && strtotime($periodEnd) !== false) {
                $eventData['period_end'] = $periodEnd;
            }
            $this->lifecycle->apply(new PaymentEvent((string) $row['provider'], 'manual_cancel_' . bin2hex(random_bytes(12)), 'SUBSCRIPTION.CANCELED', 'canceled', null, (string) $row['external_id'], null, null, null, null, gmdate('c'), $eventData));
            $this->database->execute('UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = :updated WHERE id = :id', ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
            $this->flash('Cancellation was confirmed. Eligible access follows the paid-through date shown.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable $exception) {
            $this->flash($this->unexpectedBillingFailure($exception, 'subscription_cancel'), 'error');
        }
        return Response::redirect('/billing/subscriptions');
    }

    public function reactivate(Request $request): Response
    {
        $row = $this->subscriptionRow((int) $this->userId());
        if ($row !== null && (string) $row['provider'] === 'demo' && (bool) $row['cancel_at_period_end']) {
            $this->database->execute("UPDATE subscriptions SET status = 'active', cancel_at_period_end = 0, canceled_at = NULL, updated_at = :updated WHERE id = :id", ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
            $this->lifecycle->apply(new PaymentEvent('demo', 'manual_reactivate_' . bin2hex(random_bytes(12)), 'SUBSCRIPTION.ACTIVATED', 'active', null, (string) $row['external_id'], null, null, null, null, gmdate('c')));
            $this->flash('Demo membership reactivated.', 'success');
        } else {
            $this->flash('Provider subscriptions must be reactivated through a new provider-approved checkout.', 'info');
        }
        return Response::redirect('/billing/subscriptions');
    }

    public function support(Request $request): Response
    {
        return $this->page($request, 'billing/support', 'Billing support', [], true);
    }

    public function return(Request $request): Response
    {
        $provider = (string) $request->attribute('provider');
        $this->flash(ucfirst($provider) . ' returned control to the Parlor. Access activates only after server-side confirmation or a verified webhook.', 'success');
        return Response::redirect('/billing');
    }

    public function providerCancel(Request $request): Response
    {
        $this->flash('Checkout was canceled. No new entitlement was granted.', 'info');
        return Response::redirect('/billing');
    }

    /** @return array<string,mixed>|null */
    private function checkoutProduct(string $key, string $period): ?array
    {
        $plan = $this->database->fetchOne('SELECT mp.plan_key AS id, mp.name, mp.description, mpp.amount_cents, mpp.currency FROM membership_plans mp INNER JOIN membership_plan_prices mpp ON mpp.plan_id = mp.id WHERE mp.plan_key = :key AND mpp.billing_period = :period AND mp.active = 1 AND mpp.active = 1 ORDER BY mpp.amount_cents ASC LIMIT 1', ['key' => $key, 'period' => $period]);
        if ($plan !== null) {
            return ['id' => $plan['id'], 'name' => $plan['name'], 'description' => $plan['description'], 'amount_cents' => (int) $plan['amount_cents'], 'currency' => (string) $plan['currency'], 'price_label' => '$' . number_format((int) $plan['amount_cents'] / 100, 2) . ' / ' . ($period === 'annual' ? 'year' : 'month'), 'kind' => 'subscription', 'period' => $period, 'daily_bonus_coins' => $this->membershipBonuses->amountForPlan((string) $plan['id'])];
        }
        $product = $this->database->fetchOne('SELECT p.product_key AS id, p.name, p.description, pp.amount_cents, pp.currency FROM products p INNER JOIN product_prices pp ON pp.product_id = p.id WHERE p.product_key = :key AND p.active = 1 AND pp.active = 1 ORDER BY pp.amount_cents ASC LIMIT 1', ['key' => $key]);
        return $product === null ? null : ['id' => $product['id'], 'name' => $product['name'], 'description' => $product['description'], 'amount_cents' => (int) $product['amount_cents'], 'currency' => (string) $product['currency'], 'price_label' => '$' . number_format((int) $product['amount_cents'] / 100, 2), 'kind' => 'product'];
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function publicPaymentConfiguration(array $product): array
    {
        $demoAllowed = $this->gate->checkoutAllowed('demo');
        $mode = $demoAllowed && !$this->gate->checkoutAllowed('paypal') && !$this->gate->checkoutAllowed('square') ? 'demo' : (string) $this->config->get('payments.mode', 'sandbox');
        $paypalPlanId = '';
        $squarePlanId = '';
        if ($product['kind'] === 'subscription') {
            $paypalPlanId = $this->planCatalog->configuredPlanId((string) $product['id'], (string) $product['period'], 'paypal');
            $squarePlanId = $this->planCatalog->configuredPlanId((string) $product['id'], (string) $product['period'], 'square');
        }
        return [
            'mode' => $mode,
            'paypal' => ['enabled' => $this->gate->checkoutAllowed('paypal') && $product['kind'] === 'subscription' && $paypalPlanId !== '', 'client_id' => (string) $this->config->get('payments.paypal.client_id', ''), 'plan_id' => $paypalPlanId],
            'square' => ['enabled' => $this->gate->checkoutAllowed('square') && ($product['kind'] === 'product' || $squarePlanId !== ''), 'application_id' => (string) $this->config->get('payments.square.application_id', ''), 'location_id' => (string) $this->config->get('payments.square.location_id', ''), 'plan_id' => $squarePlanId],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function transactionRows(int $userId): array
    {
        $rows = $this->database->fetchAll('SELECT pay.external_id, pay.created_at, pay.provider, pay.amount_cents, pay.currency, pay.status, pay.receipt_url, COALESCE(p.name, mp.name, :fallback) AS description FROM payments pay LEFT JOIN products p ON p.id = pay.product_id LEFT JOIN subscriptions s ON s.id = pay.subscription_id LEFT JOIN membership_plans mp ON mp.id = s.plan_id WHERE pay.user_id = :user ORDER BY pay.id DESC', ['fallback' => 'Membership or fixed product', 'user' => $userId]);
        return array_map(fn (array $row): array => ['id' => $this->maskReference((string) $row['external_id']), 'date' => $row['created_at'], 'description' => $row['description'], 'provider' => ucfirst((string) $row['provider']), 'total' => strtoupper((string) $row['currency']) . ' ' . number_format((int) $row['amount_cents'] / 100, 2), 'status' => ucfirst((string) $row['status']), 'receipt_url' => $this->safeReceiptUrl($row['receipt_url'])], $rows);
    }

    /** @return array<string,mixed> */
    private function membershipData(int $userId): array
    {
        $row = $this->subscriptionRow($userId);
        return $row === null ? ['name' => 'Free', 'status' => 'active', 'provider' => 'None', 'renewal' => 'Not applicable', 'paid_through' => 'Not applicable', 'cancel_at_period_end' => false, 'daily_bonus_coins' => 0] : [
            'name' => $row['name'], 'status' => $row['status'], 'provider' => ucfirst((string) $row['provider']),
            'renewal' => $row['cancel_at_period_end'] ? 'Cancellation scheduled' : ($row['current_period_end'] ?? 'Provider pending'),
            'paid_through' => $row['current_period_end'] ?? 'Provider pending', 'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'daily_bonus_coins' => $this->membershipBonuses->amountForPlan((string) $row['plan_key']),
        ];
    }

    /** @return array<string,mixed>|null */
    private function subscriptionRow(int $userId): ?array
    {
        return $this->database->fetchOne("SELECT s.*, mp.name, mp.plan_key FROM subscriptions s INNER JOIN membership_plans mp ON mp.id = s.plan_id WHERE s.user_id = :user AND s.status NOT IN ('expired','refunded','disputed') ORDER BY s.id DESC LIMIT 1", ['user' => $userId]);
    }

    /** @return list<string> */
    private function benefitLabels(string $json): array
    {
        $values = json_decode($json, true);
        if (!is_array($values)) return [];
        $known = [
            'ads.disabled' => 'Advertisement-free experience',
            'theme.purple_premium' => 'Royal Plum premium theme',
            'theme.fireplace' => 'Cozy Fireplace premium theme',
            'statistics.advanced' => 'Private per-game statistics',
            'statistics.export' => 'Downloadable statistics export',
            'layout.expanded' => 'Expanded lounge layout',
            'profile.animated_crown' => 'Animated profile crown',
            'supporter.badge' => 'Club supporter profile badge',
        ];
        $labels = [];
        foreach ($values as $key => $value) {
            $item = is_int($key) ? $value : ($value ? $key : null);
            if (is_string($item) && isset($known[$item])) $labels[] = $known[$item];
        }
        return $labels;
    }

    private function checkoutError(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) return Response::json(['error' => $message, 'message' => $message], $status);
        $this->flash($message, 'error');
        return Response::redirect('/billing');
    }

    private function genericCheckoutFailure(): string
    {
        return 'Checkout could not be completed. No charge or entitlement was confirmed. Support reference: ' . RequestContext::id() . '.';
    }

    private function unexpectedBillingFailure(Throwable $exception, string $operation): string
    {
        $this->logger->error('An unexpected billing operation failure was contained.', [
            'operation' => $operation, 'user_id' => $this->userId(), 'exception' => $exception,
        ]);
        return 'The billing request could not be completed safely. No result was assumed. Support reference: ' . RequestContext::id() . '.';
    }

    private function maskReference(string $reference): string
    {
        return strlen($reference) <= 12 ? $reference : substr($reference, 0, 6) . '…' . substr($reference, -6);
    }

    private function safeReceiptUrl(mixed $url): ?string
    {
        $url = is_string($url) ? $url : '';
        return preg_match('#^https://#i', $url) ? $url : null;
    }
}
