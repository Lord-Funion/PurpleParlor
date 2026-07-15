<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Database\Database;
use App\Repositories\UserRepository;
use RuntimeException;

final class AccountService
{
    public function __construct(
        private readonly Database $database,
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function exportCurrent(): array
    {
        $user = $this->auth->currentUser() ?? throw new RuntimeException('Authentication required.');
        $id = $user->id;
        $account = $this->database->fetchOne('SELECT id, email, username, status, email_verified_at, last_login_at, created_at, updated_at FROM users WHERE id = :id', ['id' => $id]);
        $settings = $this->database->fetchOne('SELECT settings_json, updated_at FROM user_settings WHERE user_id = :id', ['id' => $id]);
        $settingsValues = json_decode((string) ($settings['settings_json'] ?? '{}'), true);
        $settingsValues = is_array($settingsValues) ? $settingsValues : [];
        return [
            'generated_at_utc' => gmdate('c'),
            'account' => $account,
            'profile' => $this->database->fetchOne('SELECT display_name, bio, avatar_key, is_public, stats_public, created_at, updated_at FROM user_profiles WHERE user_id = :id', ['id' => $id]),
            'settings' => $settings,
            'notification_preferences' => $settingsValues['notifications'] ?? [],
            'roles' => $this->users->roles($id),
            'virtual_wallets' => $this->database->fetchAll('SELECT currency, balance, created_at, updated_at FROM virtual_wallets WHERE user_id = :id', ['id' => $id]),
            'virtual_ledger' => $this->database->fetchAll('SELECT currency, amount, balance_before, balance_after, reason_code, related_game_round_id, related_achievement_id, related_mission_id, created_at FROM virtual_ledger_entries WHERE user_id = :id ORDER BY id', ['id' => $id]),
            'game_rounds' => $this->database->fetchAll('SELECT public_id, status, wager_amount, payout_amount, currency, started_at, settled_at FROM game_rounds WHERE user_id = :id ORDER BY id', ['id' => $id]),
            'achievements' => $this->database->fetchAll('SELECT a.achievement_key, a.name, ua.unlocked_at FROM user_achievements ua INNER JOIN achievements a ON a.id = ua.achievement_id WHERE ua.user_id = :id', ['id' => $id]),
            'mission_progress' => $this->database->fetchAll('SELECT m.mission_key, m.name, mp.progress_value, mp.completed_at, mp.rewarded_at, mp.updated_at FROM mission_progress mp INNER JOIN missions m ON m.id = mp.mission_id WHERE mp.user_id = :id ORDER BY m.mission_key', ['id' => $id]),
            'favorite_games' => $this->database->fetchAll('SELECT gd.slug, gd.name, gf.created_at FROM game_favorites gf INNER JOIN game_definitions gd ON gd.id = gf.game_id WHERE gf.user_id = :id ORDER BY gf.created_at', ['id' => $id]),
            'recent_games' => $this->database->fetchAll('SELECT gd.slug, gd.name, rg.last_played_at FROM recent_games rg INNER JOIN game_definitions gd ON gd.id = rg.game_id WHERE rg.user_id = :id ORDER BY rg.last_played_at DESC', ['id' => $id]),
            'player_statistics' => $this->database->fetchAll('SELECT gd.slug AS game_slug, gd.name AS game_name, ps.stat_key, ps.stat_value, ps.updated_at FROM player_statistics ps LEFT JOIN game_definitions gd ON gd.id = ps.game_id WHERE ps.user_id = :id ORDER BY gd.slug, ps.stat_key', ['id' => $id]),
            'leaderboard_entries' => $this->database->fetchAll('SELECT l.leaderboard_key, l.name, le.score, le.updated_at FROM leaderboard_entries le INNER JOIN leaderboards l ON l.id = le.leaderboard_id WHERE le.user_id = :id ORDER BY l.leaderboard_key', ['id' => $id]),
            'daily_rewards' => $this->database->fetchAll('SELECT reward_date, amount, claimed_at FROM daily_reward_claims WHERE user_id = :id ORDER BY claimed_at', ['id' => $id]),
            'subscriptions' => $this->database->fetchAll('SELECT provider, external_id, status, billing_period, currency, amount_cents, current_period_start, current_period_end, canceled_at, created_at FROM subscriptions WHERE user_id = :id', ['id' => $id]),
            'payments' => $this->database->fetchAll('SELECT provider, external_id, status, amount_cents, currency, receipt_url, paid_at, created_at FROM payments WHERE user_id = :id', ['id' => $id]),
            'entitlements' => $this->database->fetchAll('SELECT entitlement_key, source_type, starts_at, ends_at, revoked_at, revocation_reason FROM user_entitlements WHERE user_id = :id', ['id' => $id]),
            'legal_acceptances' => $this->database->fetchAll('SELECT document_key, document_version, accepted_at FROM legal_acceptances WHERE user_id = :id', ['id' => $id]),
            'notifications' => $this->database->fetchAll('SELECT type, data_json, read_at, created_at FROM notifications WHERE user_id = :id ORDER BY created_at', ['id' => $id]),
            'responsible_play_settings' => $this->database->fetchOne('SELECT session_reminder_minutes, daily_limit_minutes, cooldown_until, self_excluded_until, autoplay_allowed, updated_at FROM responsible_play_controls WHERE user_id = :id', ['id' => $id]),
            'contact_messages' => $this->database->fetchAll('SELECT name, email, subject, message, status, submitted_at FROM contact_messages WHERE user_id = :id ORDER BY submitted_at', ['id' => $id]),
            'support_tickets' => $this->database->fetchAll('SELECT subject, status, priority, created_at, updated_at FROM support_tickets WHERE user_id = :id ORDER BY created_at', ['id' => $id]),
            'licensing_inquiries' => $this->database->fetchAll('SELECT name, business_name, email, service_requested, budget_range, message, consent, status, submitted_at FROM licensing_inquiries WHERE LOWER(email) = :email ORDER BY submitted_at', ['email' => strtolower($user->email)]),
        ];
    }

    public function changeUsername(string $newUsername): void
    {
        $user = $this->auth->currentUser() ?? throw new RuntimeException('Authentication required.');
        $this->users->changeUsername($user->id, $newUsername, 30);
        $this->audit->record($user->id, 'account.username_changed', 'user', (string) $user->id, ['username' => $user->username], ['username' => $newUsername], null);
    }

    public function deleteCurrent(string $password, ?string $twoFactorCode, string $reason = 'user_requested'): void
    {
        $user = $this->auth->currentUser() ?? throw new RuntimeException('Authentication required.');
        if (!$this->auth->reauthenticate($password, $twoFactorCode)) {
            throw new RuntimeException('Reauthentication failed.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $db) use ($user, $reason, $now): void {
            $this->audit->record($user->id, 'account.deleted', 'user', (string) $user->id, ['status' => $user->status], ['status' => 'deleted'], $reason);
            $db->execute('UPDATE sessions SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL', ['now' => $now, 'id' => $user->id]);
            $db->execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM email_verifications WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM password_resets WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM two_factor_secrets WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM user_roles WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM user_profiles WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM user_settings WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM notifications WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('DELETE FROM payment_provider_customers WHERE user_id = :id', ['id' => $user->id]);
            $db->execute('UPDATE analytics_events SET user_id = NULL WHERE user_id = :id', ['id' => $user->id]);
            $suffix = $user->id . '-' . bin2hex(random_bytes(6));
            $db->execute("UPDATE users SET email = :email, email_normalized = :email, username = :username, username_normalized = :username,
                password_hash = :password, pending_email = NULL, status = 'deleted', deleted_at = :now, updated_at = :now WHERE id = :id", [
                'email' => 'deleted-' . $suffix . '@example.invalid', 'username' => 'DeletedUser-' . $suffix,
                'password' => password_hash(bin2hex(random_bytes(64)), PASSWORD_DEFAULT), 'now' => $now, 'id' => $user->id,
            ]);
        });
        $this->auth->logout();
    }
}
