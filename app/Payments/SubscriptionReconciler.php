<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use App\Services\EntitlementService;
use RuntimeException;

final class SubscriptionReconciler
{
    public function __construct(
        private readonly Database $database,
        private readonly PaymentProviderFactory $providers,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly EntitlementService $entitlements,
    ) {
    }

    public function cancelForUser(int $userId, int $subscriptionId, bool $atPeriodEnd = true): ProviderResult
    {
        $subscription = $this->database->fetchOne('SELECT * FROM subscriptions WHERE id = :id AND user_id = :user', ['id' => $subscriptionId, 'user' => $userId]);
        if ($subscription === null) {
            throw new RuntimeException('Subscription was not found.');
        }
        if (in_array((string) $subscription['status'], ['canceled', 'expired', 'refunded', 'disputed'], true)) {
            return new ProviderResult(true, (string) $subscription['status'], (string) $subscription['external_id']);
        }
        $result = $this->providers->make((string) $subscription['provider'])->cancelSubscription((string) $subscription['external_id']);
        if (!$result->successful) {
            return $result;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute('UPDATE subscriptions SET status = :status, cancel_at_period_end = :end, canceled_at = :now, updated_at = :now WHERE id = :id', [
            'status' => 'canceled', 'end' => $atPeriodEnd ? 1 : 0, 'now' => $now, 'id' => $subscriptionId,
        ]);
        $periodEnd = $subscription['current_period_end'];
        if (!$atPeriodEnd || $periodEnd === null || strtotime((string) $periodEnd) <= time()) {
            $this->entitlements->revokeSource($userId, 'subscription', (string) $subscriptionId, 'customer_canceled');
        }
        return $result;
    }

    /** @return array{checked:int,updated:int,errors:list<array{subscription_id:int,error:string}>} */
    public function reconcile(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->database->fetchAll("SELECT * FROM subscriptions WHERE status IN ('pending','trialing','active','past_due','in_grace_period','paused','suspended','canceled') ORDER BY updated_at LIMIT {$limit}");
        $updated = 0;
        $errors = [];
        foreach ($rows as $row) {
            try {
                $providerState = $this->providers->make((string) $row['provider'])->retrieveSubscription((string) $row['external_id']);
                $status = strtolower($providerState->status);
                $periodStart = $this->normalizeProviderDate($providerState->periodStart);
                $periodEnd = $this->normalizeProviderDate($providerState->periodEnd);
                $cancelAtPeriodEnd = $providerState->cancelAtPeriodEnd;
                $changed = $status !== strtolower((string) $row['status'])
                    || $periodStart !== $this->normalizeProviderDate(is_string($row['current_period_start']) ? $row['current_period_start'] : null)
                    || $periodEnd !== $this->normalizeProviderDate(is_string($row['current_period_end']) ? $row['current_period_end'] : null)
                    || $cancelAtPeriodEnd !== (bool) $row['cancel_at_period_end'];
                if ($changed) {
                    $snapshot = implode(':', [$row['provider'], $row['external_id'], $status, $periodStart ?? '-', $periodEnd ?? '-', $cancelAtPeriodEnd ? '1' : '0']);
                    $event = new PaymentEvent((string) $row['provider'], 'reconcile_' . hash('sha256', $snapshot),
                        'SUBSCRIPTION.RECONCILED', $status, null, (string) $row['external_id'], null, null, null, null, gmdate('c'),
                        ['period_start' => $periodStart, 'period_end' => $periodEnd, 'cancel_at_period_end' => $cancelAtPeriodEnd]);
                    $this->database->transaction(fn () => $this->lifecycle->apply($event));
                    $updated++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['subscription_id' => (int) $row['id'], 'error' => substr($e->getMessage(), 0, 300)];
            }
        }
        return ['checked' => count($rows), 'updated' => $updated, 'errors' => $errors];
    }

    public function expireGracePeriods(): int
    {
        $rows = $this->database->fetchAll("SELECT id, user_id FROM subscriptions WHERE status IN ('past_due','in_grace_period') AND grace_ends_at IS NOT NULL AND grace_ends_at <= :now", ['now' => gmdate('Y-m-d H:i:s')]);
        foreach ($rows as $row) {
            $this->database->transaction(function (Database $db) use ($row): void {
                $db->execute("UPDATE subscriptions SET status = 'expired', updated_at = :now WHERE id = :id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
                $this->entitlements->revokeSource((int) $row['user_id'], 'subscription', (string) $row['id'], 'grace_expired');
            });
        }
        return count($rows);
    }

    private function normalizeProviderDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
