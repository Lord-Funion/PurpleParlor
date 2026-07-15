<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\InsufficientVirtualBalance;
use App\Services\VirtualLedgerService;
use Tests\Support\TestCase;

final class VirtualLedgerServiceTest extends TestCase
{
    public function testGrantWagerPayoutAndDuplicateAreIdempotent(): void
    {
        [$userId, $ledger] = $this->subject();
        $ledger->apply($userId, VirtualLedgerService::COZY_COINS, 5000, 'account.initial_grant', 'initial:' . $userId . ':0001');
        $first = $ledger->debitWager($userId, 101, 750, 'wager:' . $userId . ':0101');
        $duplicate = $ledger->debitWager($userId, 101, 750, 'wager:' . $userId . ':0101');
        $this->assertSame($first->id, $duplicate->id);
        $ledger->creditPayout($userId, 101, 1000, 'payout:' . $userId . ':0101');
        $this->assertSame(5250, $ledger->balance($userId, VirtualLedgerService::COZY_COINS));
        $this->assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM virtual_ledger_entries')['count']);
    }

    public function testInsufficientBalanceAndNegativePreventionRollBack(): void
    {
        [$userId, $ledger] = $this->subject();
        $ledger->apply($userId, VirtualLedgerService::COZY_COINS, 100, 'account.initial_grant', 'initial:' . $userId . ':0002');
        $this->expectException(InsufficientVirtualBalance::class, fn () => $ledger->debitWager($userId, 102, 101, 'wager:' . $userId . ':0102'));
        $this->assertSame(100, $ledger->balance($userId, VirtualLedgerService::COZY_COINS));
    }

    public function testReconciliationAndAdminAdjustmentIntegrity(): void
    {
        [$userId, $ledger] = $this->subject();
        $ledger->apply($userId, VirtualLedgerService::PARLOR_STARS, 25, 'achievement.reward', 'achievement:' . $userId . ':01', ['achievement_id' => 1]);
        $ledger->apply($userId, VirtualLedgerService::PARLOR_STARS, 5, 'admin.adjustment', 'admin:' . $userId . ':0001', ['administrator_id' => $userId]);
        $report = $ledger->reconcile($userId);
        $this->assertSame([], $report['mismatches']);
        $this->assertSame([], $report['integrity_errors']);
        $this->expectException(\PDOException::class, fn () => $this->database->execute('UPDATE virtual_ledger_entries SET amount = 999 WHERE user_id = :user', ['user' => $userId]));
    }

    private function subject(): array
    {
        $users = new UserRepository($this->database);
        $user = $users->create('ledger' . bin2hex(random_bytes(3)) . '@example.test', 'Ledger' . bin2hex(random_bytes(3)), (new PasswordHasher())->hash('ledger secure password!'), 'active');
        return [$user->id, new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY)];
    }
}
