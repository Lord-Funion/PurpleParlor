<?php

declare(strict_types=1);

namespace Tests\Admin;

use App\Http\GuestWallet;
use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\Encryptor;
use App\Services\AdminMutationService;
use App\Services\AuditService;
use App\Services\EngagementProgressService;
use App\Services\GamePlayService;
use App\Services\VirtualLedgerService;
use DomainException;
use Tests\Support\TestCase;

final class AdminMutationServiceTest extends TestCase
{
    public function testGameControlsDriveRuntimeCatalogAndRemainFictional(): void
    {
        $actor = $this->user('game-admin');
        $game = $this->database->fetchOne("SELECT id FROM game_definitions WHERE slug = 'plinko'");
        $this->mutations()->updateGame($actor, (int) $game['id'], 'active', 5, 50, 5, 'Validated fictional limits', null);

        $catalog = $this->gamePlay()->catalog();
        $plinko = array_values(array_filter($catalog, static fn (array $row): bool => $row['slug'] === 'plinko'))[0] ?? null;
        $this->assertSame(5, $plinko['wager']['minimum'] ?? null);
        $this->assertSame(50, $plinko['wager']['maximum'] ?? null);
        $this->assertSame('cozy_coins', $plinko['wager']['currency'] ?? null);
        $this->assertFalse((bool) ($plinko['wager']['cash_value'] ?? true));

        $this->mutations()->updateGame($actor, (int) $game['id'], 'disabled', 5, 50, 5, 'Temporarily removed from catalog', null);
        $slugs = array_column($this->gamePlay()->catalog(), 'slug');
        $this->assertFalse(in_array('plinko', $slugs, true));
    }

    public function testRoleSafeguardsRequireOwnerAuthorityTwoFactorAndPreserveLastOwner(): void
    {
        $actor = $this->user('role-operator');
        $owner = $this->user('sole-owner');
        $target = $this->user('role-target');
        $users = new UserRepository($this->database);
        $users->assignRole($owner, 'adult_owner', $actor);

        $this->expectException(DomainException::class, fn () => $this->mutations()->updateUserRole(
            $actor, $owner, 'adult_owner', 'revoke', 'Attempted unsafe owner removal', null, true,
        ));
        $this->expectException(DomainException::class, fn () => $this->mutations()->updateUserRole(
            $actor, $target, 'moderator', 'grant', 'Administrative access requested', null, false,
        ));
        $this->expectException(DomainException::class, fn () => $this->mutations()->updateUserRole(
            $actor, $target, 'moderator', 'grant', 'Administrative access requested', null, true,
        ));

        $this->database->execute('INSERT INTO two_factor_secrets (user_id, encrypted_secret, enabled, confirmed_at, updated_at) VALUES (:user, :secret, 1, :now, :now)', [
            'user' => $target, 'secret' => 'test-encrypted-secret', 'now' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->mutations()->updateUserRole($actor, $target, 'moderator', 'grant', 'Approved verified moderator access', null, true);
        $this->assertTrue(in_array('moderator', $users->roles($target), true));

        $this->expectException(DomainException::class, fn () => $this->mutations()->updateRolePermission(
            $actor, 'moderator', 'payments.live.configure', 'grant', 'Unsafe owner permission delegation', null, true,
        ));
        $this->expectException(DomainException::class, fn () => $this->mutations()->updateRolePermission(
            $actor, 'member', 'admin.access', 'grant', 'Unsafe public role escalation', null, true,
        ));
    }

    public function testRewardConfigurationIsRealButNeverRewritesCommittedLedger(): void
    {
        $actor = $this->user('reward-admin');
        $player = $this->user('reward-player');
        $ledger = $this->ledger();
        $engagement = new EngagementProgressService($this->database, $ledger);
        $engagement->unlockAchievement($player, 'first_round');
        $before = (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM virtual_ledger_entries WHERE user_id = :user', ['user' => $player])['aggregate'];
        $achievement = $this->database->fetchOne("SELECT id FROM achievements WHERE achievement_key = 'first_round'");

        $this->mutations()->updateAchievement($actor, (int) $achievement['id'], [
            'name' => 'First Settled Round', 'description' => 'Complete one settled fictional round.',
            'reward_coins' => '333', 'reward_stars' => '12', 'active' => 'active',
        ], 'Reviewed future achievement award', null);
        $this->mutations()->updateDailyReward($actor, '1500', 'Reviewed free daily amount', null);

        $after = (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM virtual_ledger_entries WHERE user_id = :user', ['user' => $player])['aggregate'];
        $stored = $this->database->fetchOne('SELECT reward_coins, reward_stars FROM achievements WHERE id = :id', ['id' => $achievement['id']]);
        $daily = json_decode((string) $this->database->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'rewards.daily.amount'")['setting_value'], true);
        $this->assertSame($before, $after);
        $this->assertSame(333, (int) $stored['reward_coins']);
        $this->assertSame(12, (int) $stored['reward_stars']);
        $this->assertSame(1500, $daily);
    }

    public function testCatalogEntitlementsAndRefundTrackingStayLocalAndAudited(): void
    {
        $actor = $this->user('commerce-owner');
        $player = $this->user('commerce-player');
        $product = $this->database->fetchOne("SELECT * FROM products WHERE product_key = 'purple_theme_pack'");
        $this->mutations()->updateCatalogItem($actor, 'product', (int) $product['id'], [
            'name' => 'Purple Parlor Theme Collection', 'description' => 'A reviewed fixed collection of permanent premium purple themes.',
            'entitlements' => 'theme.purple_premium', 'refund_policy_reference' => 'refund-policy-v2', 'active' => 'disabled',
        ], 'Paused product while catalog copy is reviewed', null, true);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT active FROM products WHERE id = :id', ['id' => $product['id']])['active']);

        $this->database->execute("INSERT INTO user_entitlements (user_id, entitlement_key, source_type, source_id, starts_at, created_at, updated_at) VALUES (:user, 'theme.purple_premium', 'purchase', 'payment:existing', :now, :now, :now)", ['user' => $player, 'now' => gmdate('Y-m-d H:i:s')]);
        $this->mutations()->updateEntitlement($actor, $player, 'theme.purple_premium', 'grant', null, 'Approved support courtesy entitlement', null, true);
        $this->mutations()->updateEntitlement($actor, $player, 'theme.purple_premium', 'revoke', null, 'Courtesy entitlement no longer required', null, true);
        $purchase = $this->database->fetchOne("SELECT revoked_at FROM user_entitlements WHERE user_id = :user AND source_type = 'purchase'", ['user' => $player]);
        $this->assertSame(null, $purchase['revoked_at']);

        $paymentId = $this->database->insert("INSERT INTO payments (user_id, provider, external_id, status, amount_cents, currency, created_at, updated_at) VALUES (:user, 'demo', :external, 'completed', 500, 'USD', :now, :now)", [
            'user' => $player, 'external' => 'demo_admin_test_' . bin2hex(random_bytes(4)), 'now' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->mutations()->trackRefundRequest($actor, 0, $paymentId, '300', 'requested', 'Customer request recorded for review', '', null, true);
        $request = $this->database->fetchOne('SELECT id, status FROM refund_requests WHERE payment_id = :payment', ['payment' => $paymentId]);
        $this->mutations()->trackRefundRequest($actor, (int) $request['id'], $paymentId, 300, 'under_review', 'Owner began internal evidence review', '', null, true);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM refunds')['aggregate']);
        $this->assertSame('completed', (string) $this->database->fetchOne('SELECT status FROM payments WHERE id = :id', ['id' => $paymentId])['status']);
    }

    public function testWorkflowRegistryRequiresLegalApprovalBeforePublication(): void
    {
        $actor = $this->user('legal-owner');
        $this->expectException(DomainException::class, fn () => $this->mutations()->updateWorkflow(
            $actor, 'legal', 'privacy', 'published', 'v1', 'Attempted premature publication', null, true,
        ));
        $this->mutations()->updateWorkflow($actor, 'legal', 'privacy', 'owner_approved', 'v1', 'Adult Owner approved reviewed privacy policy', null, true);
        $this->mutations()->updateWorkflow($actor, 'legal', 'privacy', 'published', 'v1', 'Published the approved privacy policy registry state', null, true);
        $this->assertSame('published', $this->mutations()->workflow('legal', 'privacy')['status']);
    }

    private function mutations(): AdminMutationService
    {
        return new AdminMutationService($this->database, new AuditService($this->database, self::KEY));
    }

    private function ledger(): VirtualLedgerService
    {
        return new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY);
    }

    private function gamePlay(): GamePlayService
    {
        $ledger = $this->ledger();
        return new GamePlayService(
            $this->database, $this->config, $ledger, new GuestWallet(), new Encryptor(self::KEY),
            new EngagementProgressService($this->database, $ledger),
        );
    }

    private function user(string $name): int
    {
        $users = new UserRepository($this->database);
        $user = $users->create($name . '@example.test', $name, password_hash('ValidPassword-123!', PASSWORD_DEFAULT), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'member');
        return $user->id;
    }
}
