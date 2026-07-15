<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\MembershipBonusService;
use App\Services\VirtualLedgerService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestCase;

final class MembershipBonusServiceTest extends TestCase
{
    public function testEligiblePlansReceiveExactlyOneConfiguredGrantPerUtcDay(): void
    {
        $service = $this->service();
        $cozyUser = $this->createUser('cozy@example.test', 'CozyBonus');
        $plusUser = $this->createUser('plus@example.test', 'PlusBonus');
        $expiredUser = $this->createUser('expired@example.test', 'ExpiredBonus');
        $this->createSubscription($cozyUser, 'cozy_club', 'active', '2026-08-14 00:00:00');
        $this->createSubscription($plusUser, 'cozy_club_plus', 'active', '2026-08-14 00:00:00');
        $this->createSubscription($expiredUser, 'cozy_club_plus', 'canceled', '2026-07-13 00:00:00', true);
        $clock = new DateTimeImmutable('2026-07-14 12:00:00', new DateTimeZone('UTC'));

        // A batch size of one proves the cursor reaches later users instead of
        // repeatedly processing only the first subscription page.
        $first = $service->grantDue($clock, 1);
        $second = $service->grantDue($clock);
        $third = $service->grantDue($clock->modify('+1 day'));

        $this->assertSame(2, $first['eligible']);
        $this->assertSame(2, $first['granted']);
        $this->assertSame(3500, $first['coins_granted']);
        $this->assertSame(0, $second['granted']);
        $this->assertSame(2, $second['already_granted']);
        $this->assertSame(2, $third['granted']);
        $this->assertSame(2000, $this->balance($cozyUser));
        $this->assertSame(5000, $this->balance($plusUser));
        $this->assertSame(0, $this->balance($expiredUser));
        $this->assertSame(4, (int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM virtual_ledger_entries WHERE reason_code = 'membership.daily_bonus'")['aggregate']);
    }

    public function testOverlappingProviderRowsUseOnlyTheHighestPlanBonus(): void
    {
        $service = $this->service();
        $userId = $this->createUser('upgrade@example.test', 'UpgradeBonus');
        $this->createSubscription($userId, 'cozy_club', 'active', '2026-08-14 00:00:00');
        $this->createSubscription($userId, 'cozy_club_plus', 'active', '2026-08-14 00:00:00');

        $result = $service->grantDue(new DateTimeImmutable('2026-07-14 12:00:00', new DateTimeZone('UTC')));

        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['granted']);
        $this->assertSame(2500, $this->balance($userId));
    }

    private function service(): MembershipBonusService
    {
        $this->config->set('membership.daily_coin_bonuses', ['cozy_club' => 1000, 'cozy_club_plus' => 2500]);
        return new MembershipBonusService(
            $this->database,
            new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY),
            $this->config,
        );
    }

    private function createUser(string $email, string $username): int
    {
        $user = (new UserRepository($this->database))->create($email, $username, (new PasswordHasher())->hash('a sufficiently long bonus password!'), 'active');
        return $user->id;
    }

    private function createSubscription(int $userId, string $planKey, string $status, ?string $periodEnd, bool $cancelAtPeriodEnd = false): int
    {
        $plan = $this->database->fetchOne('SELECT id FROM membership_plans WHERE plan_key = :key', ['key' => $planKey]);
        return $this->database->insert(
            'INSERT INTO subscriptions (user_id, plan_id, provider, external_id, status, billing_period, currency, amount_cents, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at)
             VALUES (:user,:plan,:provider,:external,:status,:period,:currency,:amount,:starts,:ends,:cancel,:created,:updated)',
            [
                'user' => $userId,
                'plan' => $plan['id'],
                'provider' => 'demo',
                'external' => 'bonus_' . $userId . '_' . $planKey . '_' . bin2hex(random_bytes(3)),
                'status' => $status,
                'period' => 'monthly',
                'currency' => 'USD',
                'amount' => $planKey === 'cozy_club_plus' ? 599 : 299,
                'starts' => '2026-07-01 00:00:00',
                'ends' => $periodEnd,
                'cancel' => $cancelAtPeriodEnd ? 1 : 0,
                'created' => '2026-07-01 00:00:00',
                'updated' => '2026-07-01 00:00:00',
            ],
        );
    }

    private function balance(int $userId): int
    {
        return (int) ($this->database->fetchOne('SELECT balance FROM virtual_wallets WHERE user_id = :user AND currency = :currency', [
            'user' => $userId,
            'currency' => VirtualLedgerService::COZY_COINS,
        ])['balance'] ?? 0);
    }
}
