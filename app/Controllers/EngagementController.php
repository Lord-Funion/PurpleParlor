<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DailyRewardService;
use App\Services\VirtualLedgerService;
use DomainException;
use Throwable;

final class EngagementController extends BaseController
{
    public function __construct(\App\Http\View $view, \App\Core\Config $config, \App\Database\Database $database, \App\Auth\AuthService $auth, private readonly DailyRewardService $daily, private readonly VirtualLedgerService $ledger)
    {
        parent::__construct($view, $config, $database, $auth);
    }

    public function rewards(Request $request): Response
    {
        $userId = (int) $this->userId();
        $dailyAmount = $this->configuredDailyAmount();
        $today = gmdate('Y-m-d');
        $claims = $this->database->fetchAll('SELECT reward_date, amount FROM daily_reward_claims WHERE user_id = :user ORDER BY reward_date DESC LIMIT 7', ['user' => $userId]);
        $claimedToday = false;
        $total = 0;
        foreach ($claims as $claim) {
            $claimedToday = $claimedToday || (string) $claim['reward_date'] === $today;
            $total += (int) $claim['amount'];
        }
        $calendar = [];
        for ($day = 1; $day <= 7; $day++) {
            $calendar[] = ['day' => 'Day ' . $day, 'amount' => $dailyAmount, 'state' => $day <= count($claims) ? 'claimed' : ($day === count($claims) + 1 ? 'current' : 'upcoming')];
        }
        return $this->page($request, 'rewards/index', 'Daily rewards', [
            'calendar' => $calendar, 'claimed_today' => $claimedToday, 'current_day' => min(7, count($claims) + ($claimedToday ? 0 : 1)),
            'best_day' => min(7, max(1, count($claims))), 'coins_claimed' => $total, 'claim_key' => 'daily:' . $userId . ':' . $today,
            'server_date_label' => gmdate('F j, Y') . ' UTC', 'missions' => array_slice($this->missionRows($userId), 0, 3),
        ], true);
    }

    public function claimReward(Request $request): Response
    {
        try {
            $entry = $this->daily->claim((int) $this->userId(), $this->configuredDailyAmount());
            $this->flash('Your free daily reward is safe in the fictional ledger. Balance: ' . number_format($entry->balanceAfter) . ' Cozy Coins.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The reward could not be recorded safely. Please try again or contact support with the request reference.', 'error');
        }
        return Response::redirect('/daily-rewards');
    }

    public function missions(Request $request): Response
    {
        return $this->page($request, 'rewards/missions', 'Missions', ['missions' => $this->missionRows((int) $this->userId()), 'week_label' => 'Week of ' . gmdate('F j, Y')], true);
    }

    public function mission(Request $request): Response
    {
        $id = (string) $request->attribute('id');
        $mission = null;
        foreach ($this->missionRows((int) $this->userId()) as $row) {
            if ((string) $row['id'] === $id) {
                $mission = $row;
                break;
            }
        }
        if ($mission === null) {
            return $this->page($request, 'errors/404', 'Mission not found', [], false, 'layouts/app', 404);
        }
        return $this->page($request, 'rewards/missions', (string) $mission['name'], ['missions' => [$mission], 'week_label' => 'Mission details'], true);
    }

    public function claimMission(Request $request): Response
    {
        $userId = (int) $this->userId();
        $key = (string) $request->attribute('id');
        try {
            $this->database->transaction(function (\App\Database\Database $db) use ($userId, $key): void {
                $row = $db->fetchOne($db->forUpdate('SELECT m.id, m.target_value, m.reward_coins, m.reward_stars, mp.progress_value, mp.completed_at, mp.rewarded_at FROM missions m INNER JOIN mission_progress mp ON mp.mission_id = m.id AND mp.user_id = :user WHERE m.mission_key = :key AND m.active = 1'), ['user' => $userId, 'key' => $key]);
                if ($row === null || (int) $row['progress_value'] < (int) $row['target_value'] || $row['completed_at'] === null) {
                    throw new DomainException('This mission is not ready to claim.');
                }
                if ($row['rewarded_at'] !== null) {
                    return;
                }
                $missionId = (int) $row['id'];
                if ((int) $row['reward_coins'] > 0) {
                    $this->ledger->apply($userId, VirtualLedgerService::COZY_COINS, (int) $row['reward_coins'], 'reward.mission', 'mission:coins:' . $userId . ':' . $missionId, ['mission_id' => $missionId]);
                }
                if ((int) $row['reward_stars'] > 0) {
                    $this->ledger->apply($userId, VirtualLedgerService::PARLOR_STARS, (int) $row['reward_stars'], 'reward.mission', 'mission:stars:' . $userId . ':' . $missionId, ['mission_id' => $missionId]);
                }
                $db->execute('UPDATE mission_progress SET rewarded_at = :rewarded, updated_at = :rewarded WHERE user_id = :user AND mission_id = :mission AND rewarded_at IS NULL', ['rewarded' => gmdate('Y-m-d H:i:s'), 'user' => $userId, 'mission' => $missionId]);
            });
            $this->flash('Mission reward claimed.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The mission reward could not be recorded safely. Please try again or contact support with the request reference.', 'error');
        }
        return Response::redirect('/missions');
    }

    public function achievements(Request $request): Response
    {
        $userId = (int) $this->userId();
        $rows = $this->database->fetchAll('SELECT a.name, a.description, a.achievement_key, ua.unlocked_at FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = :user WHERE a.active = 1 ORDER BY a.id', ['user' => $userId]);
        $achievements = array_map(static fn (array $row): array => [
            'name' => $row['name'], 'description' => $row['description'], 'icon' => '♛', 'unlocked' => $row['unlocked_at'] !== null,
            'date' => $row['unlocked_at'] === null ? null : 'Earned ' . $row['unlocked_at'], 'progress' => $row['unlocked_at'] === null ? 'In progress' : null,
        ], $rows);
        return $this->page($request, 'rewards/achievements', 'Achievements', ['achievements' => $achievements, 'unlocked_count' => count(array_filter($achievements, static fn (array $row): bool => $row['unlocked']))], true);
    }

    public function leaderboards(Request $request): Response
    {
        $rows = $this->database->fetchAll('SELECT le.score, up.display_name, u.username FROM leaderboard_entries le INNER JOIN leaderboards l ON l.id = le.leaderboard_id INNER JOIN users u ON u.id = le.user_id INNER JOIN user_profiles up ON up.user_id = u.id WHERE l.active = 1 AND up.is_public = 1 AND up.stats_public = 1 ORDER BY le.score DESC LIMIT 50');
        $leaders = [];
        foreach ($rows as $index => $row) {
            $name = (string) ($row['display_name'] ?: $row['username']);
            $leaders[] = ['rank' => $index + 1, 'name' => $name, 'username' => $row['username'], 'initial' => mb_strtoupper(mb_substr($name, 0, 1)), 'metric' => (int) $row['score'], 'badge' => 'fictional points'];
        }
        return $this->page($request, 'rewards/leaderboards', 'Parlor leaderboards', ['leaders' => $leaders, 'board_name' => 'Fictional achievement points']);
    }

    /** @return list<array<string,mixed>> */
    private function missionRows(int $userId): array
    {
        $rows = $this->database->fetchAll('SELECT m.*, mp.progress_value, mp.completed_at, mp.rewarded_at FROM missions m LEFT JOIN mission_progress mp ON mp.mission_id = m.id AND mp.user_id = :user WHERE m.active = 1 AND m.starts_at <= :now AND m.ends_at >= :now ORDER BY m.id', ['user' => $userId, 'now' => gmdate('Y-m-d H:i:s')]);
        return array_map(static function (array $row): array {
            $reward = [];
            if ((int) $row['reward_coins'] > 0) $reward[] = number_format((int) $row['reward_coins']) . ' Cozy Coins';
            if ((int) $row['reward_stars'] > 0) $reward[] = number_format((int) $row['reward_stars']) . ' Parlor Stars';
            return ['id' => $row['mission_key'], 'icon' => '✦', 'name' => $row['name'], 'description' => $row['description'], 'progress' => (int) ($row['progress_value'] ?? 0), 'goal' => (int) $row['target_value'], 'reward' => implode(' + ', $reward) ?: 'Fixed cosmetic badge', 'expires' => 'Ends ' . $row['ends_at'], 'claimed' => $row['rewarded_at'] !== null];
        }, $rows);
    }

    private function configuredDailyAmount(): int
    {
        $row = $this->database->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'rewards.daily.amount'");
        $amount = $row === null ? 1000 : json_decode((string) $row['setting_value'], true);
        return is_int($amount) && $amount >= 1 && $amount <= 100_000 ? $amount : 1000;
    }
}
