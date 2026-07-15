<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/**
 * Idempotent, server-side progress updates for achievements, missions and
 * fictional leaderboards. Client input never supplies progress or rewards.
 */
final class EngagementProgressService
{
    public function __construct(
        private readonly Database $database,
        private readonly VirtualLedgerService $ledger,
    ) {
    }

    public function recordCompletedRound(int $userId, int $roundId, int $payout): void
    {
        $this->unlockAchievement($userId, 'first_round');
        $this->incrementMission($userId, 'weekly_variety');
        $this->incrementActiveMissionsWithPrefix($userId, 'weekly_variety_');
        $this->incrementActiveMissionsWithPrefix($userId, 'daily_rounds_');
        $this->incrementLeaderboard($userId, 'fictional_achievement_points', max(1, $payout));
    }

    public function recordRulesRead(int $userId, string $gameSlug): void
    {
        $this->database->transaction(function (Database $db) use ($userId, $gameSlug): void {
            $game = $db->fetchOne('SELECT id FROM game_definitions WHERE slug = :slug AND active = 1', ['slug' => $gameSlug]);
            if ($game === null) {
                return;
            }
            $existing = $db->fetchOne('SELECT id FROM player_statistics WHERE user_id = :user AND game_id = :game AND stat_key = :key', [
                'user' => $userId, 'game' => $game['id'], 'key' => 'rules_read',
            ]);
            if ($existing !== null) {
                return;
            }
            $db->execute('INSERT INTO player_statistics (user_id, game_id, stat_key, stat_value, updated_at) VALUES (:user, :game, :key, 1, :updated)', [
                'user' => $userId, 'game' => $game['id'], 'key' => 'rules_read', 'updated' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->incrementMission($userId, 'weekly_rules');
            $this->incrementActiveMissionsWithPrefix($userId, 'weekly_rules_');
        });
        $this->unlockAchievement($userId, 'rules_reader');
    }

    public function unlockAchievement(int $userId, string $achievementKey): bool
    {
        return $this->database->transaction(function (Database $db) use ($userId, $achievementKey): bool {
            $achievement = $db->fetchOne('SELECT id, reward_coins, reward_stars FROM achievements WHERE achievement_key = :key AND active = 1', ['key' => $achievementKey]);
            if ($achievement === null) {
                return false;
            }
            $achievementId = (int) $achievement['id'];
            $existing = $db->fetchOne($db->forUpdate('SELECT unlocked_at FROM user_achievements WHERE user_id = :user AND achievement_id = :achievement'), [
                'user' => $userId, 'achievement' => $achievementId,
            ]);
            if ($existing !== null) {
                return false;
            }
            $db->execute('INSERT INTO user_achievements (user_id, achievement_id, unlocked_at) VALUES (:user, :achievement, :unlocked)', [
                'user' => $userId, 'achievement' => $achievementId, 'unlocked' => gmdate('Y-m-d H:i:s'),
            ]);
            if ((int) $achievement['reward_coins'] > 0) {
                $this->ledger->apply($userId, VirtualLedgerService::COZY_COINS, (int) $achievement['reward_coins'], 'reward.achievement',
                    'achievement:coins:' . $userId . ':' . $achievementId, ['achievement_id' => $achievementId]);
            }
            if ((int) $achievement['reward_stars'] > 0) {
                $this->ledger->apply($userId, VirtualLedgerService::PARLOR_STARS, (int) $achievement['reward_stars'], 'reward.achievement',
                    'achievement:stars:' . $userId . ':' . $achievementId, ['achievement_id' => $achievementId]);
            }
            return true;
        });
    }

    public function incrementMission(int $userId, string $missionKey, int $increment = 1): void
    {
        if ($increment <= 0) {
            return;
        }
        $this->database->transaction(function (Database $db) use ($userId, $missionKey, $increment): void {
            $now = gmdate('Y-m-d H:i:s');
            $mission = $db->fetchOne('SELECT id, target_value FROM missions WHERE mission_key = :key AND active = 1 AND starts_at <= :now AND ends_at >= :now', [
                'key' => $missionKey, 'now' => $now,
            ]);
            if ($mission === null) {
                return;
            }
            $missionId = (int) $mission['id'];
            $progress = $db->fetchOne($db->forUpdate('SELECT progress_value, completed_at FROM mission_progress WHERE user_id = :user AND mission_id = :mission'), [
                'user' => $userId, 'mission' => $missionId,
            ]);
            if ($progress === null) {
                $next = min((int) $mission['target_value'], $increment);
                $completed = $next >= (int) $mission['target_value'] ? $now : null;
                $db->execute('INSERT INTO mission_progress (user_id, mission_id, progress_value, completed_at, rewarded_at, updated_at) VALUES (:user, :mission, :progress, :completed, NULL, :updated)', [
                    'user' => $userId, 'mission' => $missionId, 'progress' => $next, 'completed' => $completed, 'updated' => $now,
                ]);
                return;
            }
            if ($progress['completed_at'] !== null) {
                return;
            }
            $next = min((int) $mission['target_value'], (int) $progress['progress_value'] + $increment);
            $completed = $next >= (int) $mission['target_value'] ? $now : null;
            $db->execute('UPDATE mission_progress SET progress_value = :progress, completed_at = :completed, updated_at = :updated WHERE user_id = :user AND mission_id = :mission', [
                'progress' => $next, 'completed' => $completed, 'updated' => $now, 'user' => $userId, 'mission' => $missionId,
            ]);
        });
    }

    private function incrementActiveMissionsWithPrefix(int $userId, string $prefix): void
    {
        if (!in_array($prefix, ['daily_rounds_', 'weekly_variety_', 'weekly_rules_'], true)) {
            return;
        }
        $rows = $this->database->fetchAll(
            'SELECT mission_key FROM missions WHERE mission_key LIKE :prefix AND active = 1 AND starts_at <= :now AND ends_at >= :now ORDER BY mission_key',
            ['prefix' => $prefix . '%', 'now' => gmdate('Y-m-d H:i:s')],
        );
        foreach ($rows as $row) {
            $this->incrementMission($userId, (string) $row['mission_key']);
        }
    }

    private function incrementLeaderboard(int $userId, string $leaderboardKey, int $amount): void
    {
        $this->database->transaction(function (Database $db) use ($userId, $leaderboardKey, $amount): void {
            $now = gmdate('Y-m-d H:i:s');
            $board = $db->fetchOne('SELECT id FROM leaderboards WHERE leaderboard_key = :key AND active = 1 AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now)', [
                'key' => $leaderboardKey, 'now' => $now,
            ]);
            if ($board === null) {
                return;
            }
            $existing = $db->fetchOne($db->forUpdate('SELECT score FROM leaderboard_entries WHERE leaderboard_id = :board AND user_id = :user'), [
                'board' => $board['id'], 'user' => $userId,
            ]);
            if ($existing === null) {
                $db->execute('INSERT INTO leaderboard_entries (leaderboard_id, user_id, score, updated_at) VALUES (:board, :user, :score, :updated)', [
                    'board' => $board['id'], 'user' => $userId, 'score' => $amount, 'updated' => $now,
                ]);
            } else {
                $db->execute('UPDATE leaderboard_entries SET score = score + :amount, updated_at = :updated WHERE leaderboard_id = :board AND user_id = :user', [
                    'amount' => $amount, 'updated' => $now, 'board' => $board['id'], 'user' => $userId,
                ]);
            }
        });
    }
}
