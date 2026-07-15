<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use DateTimeImmutable;
use DateTimeZone;

final class MembershipBonusService
{
    /** @var array<string,int> */
    private const DEFAULT_DAILY_COINS = [
        'cozy_club' => 1000,
        'cozy_club_plus' => 2500,
    ];

    private const MAX_DAILY_COINS = 100_000;

    public function __construct(
        private readonly Database $database,
        private readonly VirtualLedgerService $ledger,
        private readonly Config $config,
    ) {
    }

    public function amountForPlan(string $planKey): int
    {
        if (!array_key_exists($planKey, self::DEFAULT_DAILY_COINS)) {
            return 0;
        }
        $configured = $this->config->get('membership.daily_coin_bonuses', []);
        $amount = is_array($configured) && array_key_exists($planKey, $configured)
            ? (int) $configured[$planKey]
            : self::DEFAULT_DAILY_COINS[$planKey];
        return max(0, min(self::MAX_DAILY_COINS, $amount));
    }

    /** @return array<string,int> */
    public function configuredBonuses(): array
    {
        $bonuses = [];
        foreach (array_keys(self::DEFAULT_DAILY_COINS) as $planKey) {
            $bonuses[$planKey] = $this->amountForPlan($planKey);
        }
        return $bonuses;
    }

    /** @return array{checked:int,eligible:int,granted:int,already_granted:int,coins_granted:int,reward_date:string} */
    public function grantDue(?DateTimeImmutable $clock = null, int $limit = 1000): array
    {
        $clock = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $now = $clock->format('Y-m-d H:i:s');
        $rewardDate = $clock->format('Y-m-d');
        $limit = max(1, min(5000, $limit));
        $rows = [];
        $cursorUser = 0;
        $cursorSubscription = 0;
        do {
            $batch = $this->database->fetchAll(
                "SELECT s.id AS subscription_id, s.user_id, mp.plan_key
                 FROM subscriptions s
                 INNER JOIN membership_plans mp ON mp.id = s.plan_id
                 WHERE (
                     (s.status IN ('active','trialing') AND (s.current_period_end IS NULL OR s.current_period_end > :active_now))
                     OR (s.status = 'in_grace_period' AND s.grace_ends_at IS NOT NULL AND s.grace_ends_at > :grace_now)
                     OR (s.status = 'canceled' AND s.cancel_at_period_end = 1 AND s.current_period_end IS NOT NULL AND s.current_period_end > :canceled_now)
                 )
                 AND (s.user_id > :cursor_user OR (s.user_id = :cursor_same_user AND s.id > :cursor_subscription))
                 ORDER BY s.user_id, s.id
                 LIMIT {$limit}",
                [
                    'active_now' => $now,
                    'grace_now' => $now,
                    'canceled_now' => $now,
                    'cursor_user' => $cursorUser,
                    'cursor_same_user' => $cursorUser,
                    'cursor_subscription' => $cursorSubscription,
                ],
            );
            foreach ($batch as $row) {
                $rows[] = $row;
            }
            if ($batch !== []) {
                $last = $batch[array_key_last($batch)];
                $cursorUser = (int) $last['user_id'];
                $cursorSubscription = (int) $last['subscription_id'];
            }
        } while (count($batch) === $limit);

        // A user can briefly have overlapping provider records during a plan
        // change. Award only the highest eligible plan amount, never one grant
        // per row, so overlap cannot multiply the daily balance adjustment.
        $eligible = [];
        foreach ($rows as $row) {
            $amount = $this->amountForPlan((string) $row['plan_key']);
            $userId = (int) $row['user_id'];
            if ($userId <= 0 || $amount <= 0) {
                continue;
            }
            if (!isset($eligible[$userId]) || $amount > $eligible[$userId]['amount']) {
                $eligible[$userId] = [
                    'amount' => $amount,
                    'plan_key' => (string) $row['plan_key'],
                    'subscription_id' => (int) $row['subscription_id'],
                ];
            }
        }

        $granted = 0;
        $alreadyGranted = 0;
        $coinsGranted = 0;
        foreach ($eligible as $userId => $bonus) {
            $idempotencyKey = 'membership:daily:' . $userId . ':' . $rewardDate;
            if ($this->database->fetchOne('SELECT id FROM virtual_ledger_entries WHERE idempotency_key = :key', ['key' => $idempotencyKey]) !== null) {
                $alreadyGranted++;
                continue;
            }
            $this->ledger->apply(
                $userId,
                VirtualLedgerService::COZY_COINS,
                $bonus['amount'],
                'membership.daily_bonus',
                $idempotencyKey,
                ['metadata' => [
                    'plan_key' => $bonus['plan_key'],
                    'subscription_id' => $bonus['subscription_id'],
                    'reward_date' => $rewardDate,
                ]],
            );
            $granted++;
            $coinsGranted += $bonus['amount'];
        }

        return [
            'checked' => count($rows),
            'eligible' => count($eligible),
            'granted' => $granted,
            'already_granted' => $alreadyGranted,
            'coins_granted' => $coinsGranted,
            'reward_date' => $rewardDate,
        ];
    }
}
