<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use App\Services\EntitlementService;
use RuntimeException;

final class SubscriptionLifecycleService
{
    public function __construct(
        private readonly Database $database,
        private readonly EntitlementService $entitlements,
        private readonly int $graceDays = 7,
    ) {
    }

    public function apply(PaymentEvent $event): void
    {
        if ($event->externalSubscriptionId !== null) {
            $this->applySubscription($event);
        }
        if ($event->externalPaymentId !== null) {
            $this->applyPayment($event);
        }
    }

    private function applySubscription(PaymentEvent $event): void
    {
        $row = $this->database->fetchOne($this->database->forUpdate('SELECT * FROM subscriptions WHERE provider = :provider AND external_id = :external'),
            ['provider' => $event->provider, 'external' => $event->externalSubscriptionId]);
        if ($row === null) {
            throw new RuntimeException('Webhook refers to an unknown local subscription.');
        }
        $latest = $this->database->fetchOne('SELECT occurred_at FROM subscription_events WHERE subscription_id = :id ORDER BY occurred_at DESC, id DESC LIMIT 1', ['id' => $row['id']]);
        $occurredAt = $this->normalizeTime($event->occurredAt);
        $terminalSafetyEvent = in_array(strtolower($event->status), ['refunded', 'disputed'], true);
        $outOfOrder = !$terminalSafetyEvent && $latest !== null && strtotime((string) $latest['occurred_at']) > strtotime($occurredAt);
        $before = (string) $row['status'];
        $after = $outOfOrder ? $before : $this->normalizeStatus($event->status);
        $eventData = $event->data;
        $eventData['_out_of_order'] = $outOfOrder;
        $this->database->execute('INSERT INTO subscription_events (subscription_id, provider_event_id, event_type, status_before, status_after, event_json, occurred_at, created_at)
            VALUES (:subscription, :event, :type, :before, :after, :json, :occurred, :now)', [
            'subscription' => $row['id'], 'event' => $event->eventId, 'type' => $event->type, 'before' => $before, 'after' => $after,
            'json' => json_encode($eventData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'occurred' => $occurredAt, 'now' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($outOfOrder) {
            return;
        }
        $periodStart = $this->extractDate($event->data, ['period_start'])
            ?? $this->extractDate($event->data, ['resource', 'billing_info', 'last_payment', 'time'])
            ?? $row['current_period_start'];
        $periodEnd = $this->extractDate($event->data, ['period_end'])
            ?? $this->extractDate($event->data, ['resource', 'billing_info', 'next_billing_time'])
            ?? $row['current_period_end'];
        $cancelAtPeriodEnd = is_bool($event->data['cancel_at_period_end'] ?? null)
            ? ($event->data['cancel_at_period_end'] ? 1 : 0)
            : (int) $row['cancel_at_period_end'];
        $graceEnd = $after === 'past_due' ? gmdate('Y-m-d H:i:s', time() + max(0, $this->graceDays) * 86400) : ($after === 'in_grace_period' ? $row['grace_ends_at'] : null);
        $this->database->execute('UPDATE subscriptions SET status = :status, current_period_start = :period_start, current_period_end = :period_end,
            grace_ends_at = :grace, cancel_at_period_end = :cancel_at_period_end, canceled_at = :canceled, updated_at = :now WHERE id = :id', [
            'status' => $after, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'grace' => $graceEnd,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'canceled' => $after === 'canceled' ? gmdate('Y-m-d H:i:s') : $row['canceled_at'], 'now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id'],
        ]);
        $this->syncCheckoutIntent($event->provider, (string) $event->externalSubscriptionId, $after);
        if (in_array($after, ['active', 'trialing', 'in_grace_period'], true)) {
            $this->grantPlanEntitlements($row, $periodEnd ?? $graceEnd);
        } elseif ($after === 'past_due' && $graceEnd !== null) {
            $this->grantPlanEntitlements($row, $graceEnd);
        } elseif ($after === 'canceled' && $periodEnd !== null && strtotime((string) $periodEnd) > time()) {
            $this->grantPlanEntitlements($row, (string) $periodEnd);
        } elseif (in_array($after, ['canceled', 'expired', 'refunded', 'disputed', 'suspended'], true)) {
            $this->entitlements->revokeSource((int) $row['user_id'], 'subscription', (string) $row['id'], 'subscription_' . $after);
        }
    }

    private function applyPayment(PaymentEvent $event): void
    {
        $payment = $this->database->fetchOne($this->database->forUpdate('SELECT * FROM payments WHERE provider = :provider AND external_id = :external'),
            ['provider' => $event->provider, 'external' => $event->externalPaymentId]);
        if ($payment === null && $event->externalSubscriptionId !== null) {
            $subscription = $this->database->fetchOne('SELECT * FROM subscriptions WHERE provider = :provider AND external_id = :external',
                ['provider' => $event->provider, 'external' => $event->externalSubscriptionId]);
            if ($subscription !== null) {
                $this->database->execute('INSERT INTO payments (user_id, subscription_id, provider, external_id, status, amount_cents, currency, created_at, updated_at)
                    VALUES (:user, :subscription, :provider, :external, :status, :amount, :currency, :now, :now)', [
                    'user' => $subscription['user_id'], 'subscription' => $subscription['id'], 'provider' => $event->provider,
                    'external' => $event->externalPaymentId, 'status' => 'pending', 'amount' => $event->amountCents ?? $subscription['amount_cents'],
                    'currency' => $event->currency ?? $subscription['currency'], 'now' => gmdate('Y-m-d H:i:s'),
                ]);
                $payment = $this->database->fetchOne($this->database->forUpdate('SELECT * FROM payments WHERE provider = :provider AND external_id = :external'),
                    ['provider' => $event->provider, 'external' => $event->externalPaymentId]);
            }
        }
        if ($payment === null) {
            throw new RuntimeException('Webhook refers to an unknown local payment.');
        }
        $status = $this->normalizePaymentStatus($event->status);
        $effectiveStatus = $status;
        if ($event->externalRefundId !== null) {
            $refundAmount = $event->amountCents ?? (int) $payment['amount_cents'];
            if ($refundAmount <= 0 || $refundAmount > (int) $payment['amount_cents']) {
                throw new RuntimeException('Provider refund amount is outside the local payment bounds.');
            }
            $existingRefund = $this->database->fetchOne('SELECT id, payment_id, amount_cents FROM refunds WHERE provider = :provider AND external_id = :external', [
                'provider' => $event->provider, 'external' => $event->externalRefundId,
            ]);
            $now = gmdate('Y-m-d H:i:s');
            if ($existingRefund === null) {
                $this->database->execute('INSERT INTO refunds (payment_id, provider, external_id, amount_cents, status, created_at, updated_at) VALUES (:payment, :provider, :external, :amount, :status, :now, :now)', [
                    'payment' => $payment['id'], 'provider' => $event->provider, 'external' => $event->externalRefundId,
                    'amount' => $refundAmount, 'status' => $status, 'now' => $now,
                ]);
            } elseif ((int) $existingRefund['payment_id'] !== (int) $payment['id'] || (int) $existingRefund['amount_cents'] !== $refundAmount) {
                throw new RuntimeException('Provider refund reference conflicts with the local payment history.');
            } else {
                $this->database->execute('UPDATE refunds SET status = :status, updated_at = :updated WHERE id = :id', [
                    'status' => $status, 'updated' => $now, 'id' => $existingRefund['id'],
                ]);
            }
            if ($status === 'refunded') {
                $total = $this->database->fetchOne("SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM refunds WHERE payment_id = :payment AND status IN ('refunded','completed','succeeded','success','approved')", ['payment' => $payment['id']]);
                if ((int) ($total['aggregate'] ?? 0) > (int) $payment['amount_cents']) {
                    throw new RuntimeException('Confirmed provider refunds exceed the local payment total.');
                }
                // A partial refund is recorded permanently, but the payment and
                // its fixed entitlement remain active until cumulative confirmed
                // refunds equal the complete original payment amount.
                if ((int) ($total['aggregate'] ?? 0) < (int) $payment['amount_cents']) {
                    $effectiveStatus = (string) $payment['status'];
                }
            }
        }
        if ($event->externalDisputeId !== null) {
            $sql = $this->database->driver() === 'mysql'
                ? 'INSERT IGNORE INTO disputes (payment_id, provider, external_id, status, amount_cents, created_at, updated_at) VALUES (:payment, :provider, :external, :status, :amount, :now, :now)'
                : 'INSERT OR IGNORE INTO disputes (payment_id, provider, external_id, status, amount_cents, created_at, updated_at) VALUES (:payment, :provider, :external, :status, :amount, :now, :now)';
            $this->database->execute($sql, ['payment' => $payment['id'], 'provider' => $event->provider, 'external' => $event->externalDisputeId,
                'status' => 'disputed', 'amount' => $event->amountCents, 'now' => gmdate('Y-m-d H:i:s')]);
        }
        $this->database->execute('UPDATE payments SET status = :status, paid_at = :paid, updated_at = :now WHERE id = :id', [
            'status' => $effectiveStatus, 'paid' => $effectiveStatus === 'completed' ? ($payment['paid_at'] ?? gmdate('Y-m-d H:i:s')) : $payment['paid_at'],
            'now' => gmdate('Y-m-d H:i:s'), 'id' => $payment['id'],
        ]);
        $this->syncCheckoutIntent($event->provider, (string) $event->externalPaymentId, $effectiveStatus);
        if ($payment['product_id'] !== null && $effectiveStatus === 'completed') {
            $keys = $this->database->fetchAll('SELECT entitlement_key FROM product_entitlements WHERE product_id = :product', ['product' => $payment['product_id']]);
            foreach ($keys as $key) {
                $this->entitlements->grant((int) $payment['user_id'], (string) $key['entitlement_key'], 'purchase', (string) $payment['id']);
            }
        } elseif ($payment['product_id'] !== null && in_array($effectiveStatus, ['refunded', 'disputed', 'reversed'], true)) {
            $this->entitlements->revokeSource((int) $payment['user_id'], 'purchase', (string) $payment['id'], 'payment_' . $effectiveStatus);
        }
    }

    private function grantPlanEntitlements(array $subscription, ?string $endsAt): void
    {
        $plan = $this->database->fetchOne('SELECT benefits_json FROM membership_plans WHERE id = :id', ['id' => $subscription['plan_id']]);
        $benefits = $plan === null ? [] : json_decode((string) $plan['benefits_json'], true);
        if (!is_array($benefits)) {
            $benefits = [];
        }
        foreach ($benefits as $key => $value) {
            $entitlement = is_int($key) ? $value : ($value ? $key : null);
            if (is_string($entitlement)) {
                $this->entitlements->grant((int) $subscription['user_id'], $entitlement, 'subscription', (string) $subscription['id'], null, $endsAt);
            }
        }
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        return in_array($status, \App\Models\Subscription::STATUSES, true) ? $status : 'pending';
    }

    private function normalizePaymentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'paid', 'approved', 'completed' => 'completed', 'refunded' => 'refunded', 'disputed' => 'disputed',
            'reversed' => 'reversed', 'failed', 'declined', 'canceled' => 'failed', default => 'pending',
        };
    }

    private function normalizeTime(?string $value): string
    {
        $timestamp = $value === null ? false : strtotime($value);
        return gmdate('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    private function syncCheckoutIntent(string $provider, string $externalId, string $status): void
    {
        $intentStatus = match (strtolower($status)) {
            'active', 'trialing', 'completed', 'paid', 'approved' => 'completed',
            'canceled', 'expired', 'refunded', 'disputed', 'reversed' => 'canceled',
            'failed' => 'failed',
            default => 'pending',
        };
        $terminal = in_array($intentStatus, ['completed', 'canceled', 'failed'], true) ? gmdate('Y-m-d H:i:s') : null;
        $this->database->execute('UPDATE checkout_intents SET status = :status, terminal_at = :terminal, updated_at = :now WHERE provider = :provider AND provider_external_id = :external', [
            'status' => $intentStatus, 'terminal' => $terminal, 'now' => gmdate('Y-m-d H:i:s'),
            'provider' => $provider, 'external' => $externalId,
        ]);
    }

    private function extractDate(array $data, array $path): ?string
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        if (!is_string($value) || strtotime($value) === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', strtotime($value));
    }
}
