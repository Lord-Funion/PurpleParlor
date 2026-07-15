<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\EngagementProgressService;
use App\Services\VirtualLedgerService;
use Tests\Support\TestCase;

final class EngagementProgressServiceTest extends TestCase
{
    public function testAchievementsMissionsRulesAndLeaderboardProgressAreServerRecorded(): void
    {
        $users = new UserRepository($this->database);
        $user = $users->create('progress@example.test', 'ProgressPlayer', (new PasswordHasher())->hash('a safe testing password!'), 'active');
        $users->markVerified($user->id);
        $ledger = new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY);
        $ledger->apply($user->id, VirtualLedgerService::COZY_COINS, 1000, 'account.initial_grant', 'progress:coins:initial');
        $ledger->apply($user->id, VirtualLedgerService::PARLOR_STARS, 10, 'account.initial_grant', 'progress:stars:initial');
        $service = new EngagementProgressService($this->database, $ledger);

        $this->assertTrue($service->unlockAchievement($user->id, 'first_round'));
        $this->assertFalse($service->unlockAchievement($user->id, 'first_round'), 'Achievement rewards must be one-time.');
        $this->assertSame(1250, $ledger->balance($user->id, VirtualLedgerService::COZY_COINS));
        $this->assertSame(20, $ledger->balance($user->id, VirtualLedgerService::PARLOR_STARS));

        $service->recordRulesRead($user->id, 'blackjack');
        $service->recordRulesRead($user->id, 'blackjack');
        $rulesMission = $this->database->fetchOne("SELECT mp.progress_value FROM mission_progress mp INNER JOIN missions m ON m.id = mp.mission_id WHERE mp.user_id = :user AND m.mission_key = 'weekly_rules'", ['user' => $user->id]);
        $this->assertSame(1, (int) ($rulesMission['progress_value'] ?? 0), 'Reading one rule page repeatedly must not duplicate progress.');

        $this->database->execute('INSERT INTO missions (mission_key, name, description, target_value, reward_coins, reward_stars, starts_at, ends_at, active)
            VALUES (:key,:name,:description,3,150,5,:starts,:ends,1)', [
            'key' => 'daily_rounds_' . gmdate('Y-m-d'),
            'name' => 'Daily Parlor Visit',
            'description' => 'Complete three play-money rounds today.',
            'starts' => gmdate('Y-m-d H:i:s', time() - 60),
            'ends' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $service->recordCompletedRound($user->id, 1, 75);
        $variety = $this->database->fetchOne("SELECT mp.progress_value FROM mission_progress mp INNER JOIN missions m ON m.id = mp.mission_id WHERE mp.user_id = :user AND m.mission_key = 'weekly_variety'", ['user' => $user->id]);
        $daily = $this->database->fetchOne("SELECT mp.progress_value FROM mission_progress mp INNER JOIN missions m ON m.id = mp.mission_id WHERE mp.user_id = :user AND m.mission_key LIKE 'daily_rounds_%'", ['user' => $user->id]);
        $score = $this->database->fetchOne('SELECT score FROM leaderboard_entries WHERE user_id = :user', ['user' => $user->id]);
        $this->assertSame(1, (int) ($variety['progress_value'] ?? 0));
        $this->assertSame(1, (int) ($daily['progress_value'] ?? 0), 'A completed round must advance the active cron-created daily mission.');
        $this->assertSame(75, (int) ($score['score'] ?? 0));
    }
}
