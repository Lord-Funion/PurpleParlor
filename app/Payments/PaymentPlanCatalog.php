<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Config;
use App\Database\Database;

/**
 * Resolves membership provider mappings from trusted server configuration.
 * Provider plan IDs are deliberately not accepted from browser or admin form
 * input and are read afresh on every checkout after an environment change.
 */
final class PaymentPlanCatalog
{
    private const PLAN_MAP = [
        'cozy_club' => ['monthly' => 'cozy.monthly', 'annual' => 'cozy.annual'],
        'cozy_club_plus' => ['monthly' => 'plus.monthly', 'annual' => 'plus.annual'],
    ];

    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    /** @return array{id:int,plan_key:string,amount_cents:int,currency:string,provider_plan_id:string}|null */
    public function resolve(string $planKey, string $billingPeriod, string $provider): ?array
    {
        if (!isset(self::PLAN_MAP[$planKey][$billingPeriod]) || !in_array($provider, ['demo', 'paypal', 'square'], true)) {
            return null;
        }
        $priceProvider = in_array($provider, ['paypal', 'square'], true) ? 'demo' : $provider;
        $row = $this->database->fetchOne('SELECT mp.id, mp.plan_key, mpp.amount_cents, mpp.currency, mpp.provider_plan_id
            FROM membership_plans mp INNER JOIN membership_plan_prices mpp ON mpp.plan_id = mp.id
            WHERE mp.plan_key = :plan AND mpp.billing_period = :period AND mpp.provider = :provider AND mp.active = 1 AND mpp.active = 1
            ORDER BY mpp.id DESC LIMIT 1', ['plan' => $planKey, 'period' => $billingPeriod, 'provider' => $priceProvider]);
        if ($row === null || (int) $row['amount_cents'] <= 0) {
            return null;
        }
        $mappingKey = self::PLAN_MAP[$planKey][$billingPeriod];
        $providerPlans = (array) $this->config->get("payments.{$provider}.plans", []);
        $providerPlanId = in_array($provider, ['paypal', 'square'], true)
            ? trim((string) ($providerPlans[$mappingKey] ?? ''))
            : trim((string) $row['provider_plan_id']);
        if (!preg_match('/^[A-Za-z0-9_-]{3,191}$/', $providerPlanId)) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'plan_key' => (string) $row['plan_key'],
            'amount_cents' => (int) $row['amount_cents'], 'currency' => (string) $row['currency'],
            'provider_plan_id' => $providerPlanId,
        ];
    }

    public function configuredPlanId(string $planKey, string $billingPeriod, string $provider): string
    {
        return (string) ($this->resolve($planKey, $billingPeriod, $provider)['provider_plan_id'] ?? '');
    }
}
