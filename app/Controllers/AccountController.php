<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Core\RequestContext;
use App\Http\Application;
use App\Http\GuestWallet;
use App\Http\View;
use App\Mail\MailMessage;
use App\Mail\MailQueue;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AccountService;
use App\Services\AgeConfirmationService;
use App\Services\ResponsiblePlayService;
use App\Services\EntitlementService;
use App\Services\ThemeAccessService;
use App\Services\VirtualLedgerService;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class AccountController extends BaseController
{
    public function __construct(
        View $view,
        Config $config,
        Database $database,
        AuthService $auth,
        private readonly AgeConfirmationService $age,
        private readonly GuestWallet $guests,
        private readonly SessionManager $sessionsManager,
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly TwoFactorService $twoFactor,
        private readonly ResponsiblePlayService $responsiblePlay,
        private readonly VirtualLedgerService $ledger,
        private readonly AccountService $accounts,
        private readonly MailQueue $mail,
    ) {
        parent::__construct($view, $config, $database, $auth);
    }

    public function confirmAge(Request $request): Response
    {
        if ((string) $request->input('confirmation') !== 'yes') {
            return Response::redirect((string) $this->config->get('app.age_exit_url', 'https://www.google.com'), 303);
        }
        $version = (string) $this->config->get('app.legal_policy_version', 1);
        $cookie = $this->age->issue($version);
        $this->database->execute('INSERT INTO age_confirmations (policy_version, confirmed_at) VALUES (:version, :confirmed)', ['version' => $version, 'confirmed' => gmdate('Y-m-d H:i:s')]);
        $target = \App\Security\UrlGuard::localRedirect((string) $request->input('return', $request->query['return'] ?? ''), '/');
        return Response::redirect($target)->withHeader('Set-Cookie', Application::cookie('purple_parlor_age', $cookie, time() + 31536000, $request, 'Lax'));
    }

    public function startGuest(Request $request): Response
    {
        $this->guests->start();
        $_SESSION['_parlor_started_at'] ??= time();
        return Response::redirect('/games');
    }

    public function profile(Request $request): Response
    {
        $userId = (int) $this->userId();
        $rounds = (int) ($this->database->fetchOne("SELECT COUNT(*) AS total FROM game_rounds WHERE user_id = :user AND status = 'settled'", ['user' => $userId])['total'] ?? 0);
        $achievements = (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM user_achievements WHERE user_id = :user', ['user' => $userId])['total'] ?? 0);
        return $this->page($request, 'profile/show', 'Profile', ['games_played' => $rounds, 'achievements_unlocked' => $achievements, 'recent_games' => $this->recentGames($userId)], true);
    }

    public function publicProfile(Request $request): Response
    {
        try {
            $account = $this->users->findByUsername((string) $request->attribute('username'));
        } catch (Throwable) {
            $account = null;
        }
        if ($account === null) {
            return $this->page($request, 'errors/404', 'Profile not found', [], false, 'layouts/app', 404);
        }
        $profile = $this->database->fetchOne('SELECT * FROM user_profiles WHERE user_id = :user', ['user' => $account->id]) ?? [];
        $isCurrent = $this->userId() === $account->id;
        if (!(bool) ($profile['is_public'] ?? false) && !$isCurrent) {
            return $this->page($request, 'errors/404', 'Profile not found', [], false, 'layouts/app', 404);
        }
        $settingsRow = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $account->id]);
        $settings = json_decode((string) ($settingsRow['settings_json'] ?? '{}'), true) ?: [];
        $achievements = !empty($settings['show_achievements']) ? (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM user_achievements WHERE user_id = :user', ['user' => $account->id])['total'] ?? 0) : 0;
        $favorites = !empty($settings['show_favorites']) ? (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM game_favorites WHERE user_id = :user', ['user' => $account->id])['total'] ?? 0) : 0;
        $cosmetics = (new ThemeAccessService(new EntitlementService($this->database)))->cosmetics($account->id);
        return $this->page($request, 'profile/public', (string) ($profile['display_name'] ?? $account->username), ['profile' => [
            'display_name' => (string) ($profile['display_name'] ?? $account->username), 'username' => $account->username,
            'bio' => (string) ($profile['bio'] ?? ''), 'membership_name' => 'Parlor member',
            'joined_label' => 'in ' . gmdate('F Y', strtotime((string) ($this->database->fetchOne('SELECT created_at FROM users WHERE id = :id', ['id' => $account->id])['created_at'] ?? 'now'))),
            'public_achievements' => $achievements, 'puzzle_points' => 0, 'favorite_count' => $favorites, 'is_current_user' => $isCurrent,
            'cosmetics' => $cosmetics,
        ]]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $display = trim((string) $request->input('display_name'));
        $bio = trim((string) $request->input('bio'));
        $username = trim((string) $request->input('username', $user->username));
        try {
            $normalized = UserRepository::normalizeUsername($username);
            if ($display === '' || mb_strlen($display) > 40 || mb_strlen($bio) > 240) {
                throw new DomainException('Profile details exceed the permitted length.');
            }
            if (preg_match('/[\x00-\x1F\x7F]/u', $display . $bio) === 1) {
                throw new DomainException('Profile text cannot contain control characters.');
            }
            $this->database->transaction(function (Database $db) use ($user, $username, $normalized, $display, $bio): void {
                $row = $db->fetchOne($db->forUpdate('SELECT username_changed_at, username_normalized FROM users WHERE id = :id'), ['id' => $user->id]);
                if (!hash_equals((string) ($row['username_normalized'] ?? ''), $normalized)) {
                    $changed = strtotime((string) ($row['username_changed_at'] ?? '1970-01-01')) ?: 0;
                    if ($changed > time() - (30 * 86400)) {
                        throw new DomainException('Username changes are limited to once every 30 days.');
                    }
                    $db->execute('UPDATE users SET username = :username, username_normalized = :normalized, username_changed_at = :changed, updated_at = :changed WHERE id = :id', ['username' => $username, 'normalized' => $normalized, 'changed' => gmdate('Y-m-d H:i:s'), 'id' => $user->id]);
                }
                $db->execute('UPDATE user_profiles SET display_name = :display, bio = :bio, updated_at = :updated WHERE user_id = :user', ['display' => $display, 'bio' => $bio, 'updated' => gmdate('Y-m-d H:i:s'), 'user' => $user->id]);
            });
            $this->flash('Profile updated.', 'success');
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The profile could not be updated. Please try again or contact support with request ID ' . RequestContext::id() . '.', 'error');
        }
        return Response::redirect('/settings');
    }

    public function updatePrivacy(Request $request): Response
    {
        $userId = (int) $this->userId();
        $this->database->execute('UPDATE user_profiles SET is_public = :public, stats_public = :stats, updated_at = :updated WHERE user_id = :user', [
            'public' => $request->input('public_profile') === '1' ? 1 : 0,
            'stats' => $request->input('leaderboards') === '1' ? 1 : 0,
            'updated' => gmdate('Y-m-d H:i:s'), 'user' => $userId,
        ]);
        $this->mergeSettings($userId, [
            'leaderboards' => $request->input('leaderboards') === '1',
            'show_favorites' => $request->input('show_favorites') === '1',
            'show_achievements' => $request->input('show_achievements') === '1',
        ]);
        $this->flash('Privacy controls saved.', 'success');
        return Response::redirect('/settings');
    }

    public function updatePreferences(Request $request): Response
    {
        $theme = strtolower(str_replace(' ', '-', (string) $request->input('theme', 'purple-parlor')));
        $appearance = strtolower((string) $request->input('appearance', 'dark'));
        if ($appearance === 'follow device') {
            $appearance = 'system';
        }
        $userId = (int) $this->userId();
        try {
            (new ThemeAccessService(new EntitlementService($this->database)))->assertCanSelect($userId, $theme);
            $this->mergeSettings($userId, [
                'theme' => $theme,
                'appearance' => in_array($appearance, ['dark','light','system'], true) ? $appearance : 'dark',
                'effects_volume' => max(0, min(100, (int) $request->input('effects_volume', 70))),
                'music_volume' => max(0, min(100, (int) $request->input('music_volume', 35))),
                'animations' => $request->input('animations') === '1', 'particles' => $request->input('particles') === '1',
                'reduced_motion' => $request->input('reduced_motion') === '1', 'large_text' => $request->input('large_text') === '1',
                'compact' => $request->input('compact') === '1', 'high_contrast' => $request->input('high_contrast') === '1',
                'colorblind_suits' => $request->input('colorblind_suits') === '1', 'confirm_wagers' => $request->input('confirm_wagers') === '1',
            ]);
            $this->flash('Appearance and audio preferences saved.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('Preferences could not be saved. Please try again or contact support with request ID ' . RequestContext::id() . '.', 'error');
        }
        return Response::redirect('/settings');
    }

    public function updateNotifications(Request $request): Response
    {
        $this->mergeSettings((int) $this->userId(), ['notifications' => [
            'security' => $request->input('security_email') === '1', 'billing' => $request->input('billing_email') === '1',
            'news' => $request->input('news_email') === '1',
        ], 'analytics_opt_out' => $request->input('analytics') !== '1']);
        $this->flash('Notification and analytics choices saved.', 'success');
        return Response::redirect('/settings');
    }

    public function settings(Request $request): Response
    {
        $account = $this->database->fetchOne(
            'SELECT pending_email FROM users WHERE id = :user',
            ['user' => (int) $this->userId()],
        );
        return $this->page($request, 'profile/settings', 'Settings', [
            'email_change_pending' => trim((string) ($account['pending_email'] ?? '')) !== '',
        ], true);
    }

    public function emailChangeForm(Request $request): Response
    {
        $account = $this->database->fetchOne(
            'SELECT pending_email FROM users WHERE id = :user',
            ['user' => (int) $this->userId()],
        );
        return $this->page($request, 'profile/email', 'Change email address', [
            'email_change_pending' => trim((string) ($account['pending_email'] ?? '')) !== '',
        ], true);
    }

    public function updateSettings(Request $request): Response
    {
        return $this->updatePreferences($request);
    }

    public function requestEmailChange(Request $request): Response
    {
        $user = $this->auth->currentUser();
        $code = trim((string) $request->input('two_factor_code'));
        if ($user === null || $user->emailVerifiedAt === null
            || !$this->auth->reauthenticate((string) $request->input('password'), $code === '' ? null : $code)) {
            $this->flash('Identity confirmation was not accepted.', 'error');
            return Response::redirect('/settings');
        }

        $newEmail = trim((string) $request->input('email'));
        try {
            $this->database->transaction(function () use ($user, $newEmail, $request): void {
                if (hash_equals(UserRepository::normalizeEmail($user->email), UserRepository::normalizeEmail($newEmail))) {
                    throw new DomainException('The requested address is unchanged.');
                }
                $change = $this->auth->requestEmailChange($user->id, $newEmail);
                $verificationUrl = rtrim((string) $this->config->get('app.url'), '/')
                    . '/verify-email/' . rawurlencode($change['verification_token']);

                $verificationBody = $this->renderEmail('verify-email-change', [
                    'verificationUrl' => $verificationUrl,
                    'recipientName' => $user->username,
                    'expiresIn' => '60 minutes',
                ], "A request was made to use this address for a Purple Parlor account. Verify it within 60 minutes:\n{$verificationUrl}\nIf you did not request this change, ignore this message.");
                $this->mail->enqueue(new MailMessage(
                    $change['email'],
                    $user->username,
                    'Verify your new Purple Parlor email',
                    $verificationBody['html'],
                    $verificationBody['text'],
                ), 'account.email-change.verify');

                $alertBody = $this->renderEmail('security-alert', [
                    'alertTitle' => 'Email-change request',
                    'alertMessage' => 'A request was made to change the email address on your account. Your current address remains active unless the new address is verified.',
                    'eventTime' => gmdate('Y-m-d H:i:s') . ' UTC',
                    'deviceLabel' => substr((string) $request->header('user-agent', 'Unknown browser'), 0, 120),
                    'requestId' => RequestContext::id(),
                ], "A request was made to change the email address on your Purple Parlor account. Your current address remains active unless the new address is verified.\nIf this was not you, change your password, revoke other sessions, and contact support.");
                $this->mail->enqueue(new MailMessage(
                    $user->email,
                    $user->username,
                    'Purple Parlor email-change request',
                    $alertBody['html'],
                    $alertBody['text'],
                ), 'account.email-change.notice');
            });
        } catch (Throwable) {
            // Keep the public result deliberately generic so address validity,
            // uniqueness, and mail-queue state are not disclosed.
        }

        $this->flash('If the new address is eligible, verification instructions have been queued. Your current address remains active until verification.', 'success');
        return Response::redirect('/settings');
    }

    public function statistics(Request $request): Response
    {
        $userId = (int) $this->userId();
        $entitlements = new EntitlementService($this->database);
        $advanced = $entitlements->hasEntitlement($userId, 'statistics.advanced');
        $rows = $this->database->fetchAll("SELECT gd.name, SUM(CASE WHEN ps.stat_key = 'rounds' THEN ps.stat_value ELSE 0 END) AS rounds, SUM(CASE WHEN ps.stat_key = 'fictional_returned' THEN ps.stat_value WHEN ps.stat_key = 'fictional_wagered' THEN -ps.stat_value ELSE 0 END) AS net FROM player_statistics ps INNER JOIN game_definitions gd ON gd.id = ps.game_id WHERE ps.user_id = :user GROUP BY gd.id, gd.name ORDER BY rounds DESC", ['user' => $userId]);
        $rounds = array_sum(array_map(static fn (array $row): int => (int) $row['rounds'], $rows));
        $net = array_sum(array_map(static fn (array $row): int => (int) $row['net'], $rows));
        $gameStats = $advanced
            ? array_map(static fn (array $row): array => ['name' => $row['name'], 'rounds' => (int) $row['rounds'], 'net' => (int) $row['net'], 'time' => 'Not tracked precisely'], $rows)
            : [];
        return $this->page($request, 'profile/stats', 'Personal statistics', [
            'rounds' => $rounds,
            'fictional_net' => $net,
            'game_stats' => $gameStats,
            'advanced_statistics' => $advanced,
            'time_label' => 'Private by default',
        ], true);
    }

    public function statisticsExport(Request $request): Response
    {
        $userId = (int) $this->userId();
        if (!(new EntitlementService($this->database))->hasEntitlement($userId, 'statistics.export')) {
            return $this->page($request, 'errors/403', 'Statistics export is not active', [
                'description' => 'This optional data-format feature requires an active Cozy Club Plus entitlement.',
            ], true, 'layouts/app', 403);
        }
        $rows = $this->database->fetchAll("SELECT gd.slug, gd.name, ps.stat_key, ps.stat_value, ps.updated_at FROM player_statistics ps INNER JOIN game_definitions gd ON gd.id = ps.game_id WHERE ps.user_id = :user ORDER BY gd.name, ps.stat_key", ['user' => $userId]);
        return new Response(json_encode(['exported_at' => gmdate('c'), 'currency_notice' => 'All values are fictional and have no cash value.', 'statistics' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="purple-parlor-statistics-' . gmdate('Ymd') . '.json"', 'Cache-Control' => 'private, no-store']);
    }

    public function sessions(Request $request): Response
    {
        $current = (int) ($_SESSION['auth_session_id'] ?? 0);
        $sessions = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'device' => (string) ($row['user_agent'] ?: 'Unknown browser'),
            'location' => 'Network location not retained', 'last_active' => (string) $row['last_seen_at'],
            'current' => (int) $row['id'] === $current,
        ], $this->sessionsManager->listForUser((int) $this->userId()));
        return $this->page($request, 'profile/sessions', 'Sessions and security', ['sessions' => $sessions], true);
    }

    public function revokeSession(Request $request): Response
    {
        $sessionId = (int) $request->attribute('id');
        if ($sessionId !== (int) ($_SESSION['auth_session_id'] ?? 0)) {
            $this->sessionsManager->revoke((int) $this->userId(), $sessionId);
        }
        $this->flash('The selected session was revoked.', 'success');
        return Response::redirect('/settings/sessions');
    }

    public function revokeAllSessions(Request $request): Response
    {
        $current = (int) ($_SESSION['auth_session_id'] ?? 0);
        $this->database->execute('UPDATE sessions SET revoked_at = :revoked WHERE user_id = :user AND id <> :current AND revoked_at IS NULL', ['revoked' => gmdate('Y-m-d H:i:s'), 'user' => (int) $this->userId(), 'current' => $current]);
        $this->database->execute('DELETE FROM remember_tokens WHERE user_id = :user', ['user' => (int) $this->userId()]);
        $this->flash('Every other session was revoked.', 'success');
        return Response::redirect('/settings/sessions');
    }

    public function updatePassword(Request $request): Response
    {
        $new = (string) $request->input('password');
        if (strlen($new) < 12 || strlen($new) > 1024 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $new) === 1) {
            $this->flash('The new password must be 12–1024 bytes and contain no unsupported control characters.', 'error');
            return Response::redirect('/settings/sessions');
        }
        if (!hash_equals($new, (string) $request->input('password_confirmation'))) {
            $this->flash('The new password confirmation did not match.', 'error');
            return Response::redirect('/settings/sessions');
        }
        if (!$this->auth->reauthenticate((string) $request->input('current_password'), ($code = trim((string) $request->input('two_factor_code'))) === '' ? null : $code)) {
            $this->flash('The current password, second factor, or confirmation was not accepted.', 'error');
            return Response::redirect('/settings/sessions');
        }
        try {
            $this->users->updatePassword((int) $this->userId(), $this->passwords->hash($new));
            $this->sessionsManager->revokeAll((int) $this->userId());
            $this->auth->logout();
            $this->flash('Password changed. Sign in again on this browser.', 'success');
            return Response::redirect('/login');
        } catch (Throwable) {
            $this->flash('The password could not be changed. Please try again or contact support with request ID ' . RequestContext::id() . '.', 'error');
            return Response::redirect('/settings/sessions');
        }
    }

    public function twoFactorSetup(Request $request): Response
    {
        $userId = (int) $this->userId();
        if ($this->twoFactor->enabled($userId)) {
            unset($_SESSION['_pending_two_factor_setup']);
            return $this->page($request, 'auth/two-factor-setup', 'Two-factor authentication', ['setup_key_masked' => 'Two-factor authentication is already enabled. Use Recovery Codes to rotate backup access.'], true);
        }
        $pending = $_SESSION['_pending_two_factor_setup'] ?? null;
        if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== $userId
            || (int) ($pending['created_at'] ?? 0) < time() - 900
            || !is_string($pending['secret'] ?? null)
            || !is_array($pending['recovery_codes'] ?? null)) {
            $setup = $this->twoFactor->beginSetup($userId);
            $pending = [
                'user_id' => $userId,
                'secret' => $setup['secret'],
                'recovery_codes' => $setup['recovery_codes'],
                'created_at' => time(),
            ];
            $_SESSION['_pending_two_factor_setup'] = $pending;
        }
        return $this->page($request, 'auth/two-factor-setup', 'Set up two-factor authentication', ['setup_key_masked' => $pending['secret']], true);
    }

    public function twoFactorConfirm(Request $request): Response
    {
        if (!$this->auth->reauthenticate((string) $request->input('password'))) {
            $this->flash('The password was not accepted.', 'error');
            return Response::redirect('/settings/two-factor');
        }
        if (!$this->twoFactor->confirm((int) $this->userId(), (string) $request->input('code'))) {
            $this->flash('The authenticator code was not accepted.', 'error');
            return Response::redirect('/settings/two-factor');
        }
        $pending = $_SESSION['_pending_two_factor_setup'] ?? null;
        $_SESSION['_new_recovery_codes'] = is_array($pending)
            && (int) ($pending['user_id'] ?? 0) === (int) $this->userId()
            && is_array($pending['recovery_codes'] ?? null)
                ? $pending['recovery_codes']
                : $this->twoFactor->regenerateRecoveryCodes((int) $this->userId());
        unset($_SESSION['_pending_two_factor_setup']);
        $this->sessionsManager->markPrivileged(true);
        $this->flash('Two-factor authentication is enabled. Save the recovery codes now.', 'success');
        return Response::redirect('/settings/recovery-codes');
    }

    public function recoveryCodes(Request $request): Response
    {
        $codes = is_array($_SESSION['_new_recovery_codes'] ?? null) ? $_SESSION['_new_recovery_codes'] : [];
        unset($_SESSION['_new_recovery_codes']);
        return $this->page($request, 'auth/recovery-codes', 'Recovery codes', ['recovery_codes' => $codes], true);
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        if (!$this->auth->reauthenticate((string) $request->input('password'), ($code = trim((string) $request->input('two_factor_code'))) === '' ? null : $code)) {
            $this->flash('Password and administrator second factor are required.', 'error');
            return Response::redirect('/settings/recovery-codes');
        }
        $_SESSION['_new_recovery_codes'] = $this->twoFactor->regenerateRecoveryCodes((int) $this->userId());
        return Response::redirect('/settings/recovery-codes');
    }

    public function export(Request $request): Response
    {
        $rows = $this->database->fetchAll('SELECT status, created_at, expires_at FROM data_export_requests WHERE user_id = :user ORDER BY id DESC LIMIT 10', ['user' => (int) $this->userId()]);
        $exports = array_map(static fn (array $row): array => ['requested' => $row['created_at'], 'status' => $row['status'], 'expires' => $row['expires_at'] ?? 'Not available', 'download_url' => null], $rows);
        return $this->page($request, 'profile/export', 'Data export', ['exports' => $exports], true);
    }

    public function requestExport(Request $request): Response
    {
        if ((string) $request->input('confirm') !== 'on' && (string) $request->input('confirm') !== '1') {
            $this->flash('Confirm that you will store the export safely.', 'error');
            return Response::redirect('/account/export');
        }
        if (!$this->auth->reauthenticate((string) $request->input('password'), ($code = trim((string) $request->input('two_factor_code'))) === '' ? null : $code)) {
            $this->flash('Reauthentication failed.', 'error');
            return Response::redirect('/account/export');
        }
        $userId = (int) $this->userId();
        $payload = $this->accounts->exportCurrent();
        $payload['notice'] = 'Cozy Coins and Parlor Stars have no cash value. Security secrets and other users\' data are excluded.';
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->database->execute("INSERT INTO data_export_requests (user_id, status, file_path, expires_at, created_at, completed_at) VALUES (:user, 'completed', NULL, :expires, :created, :completed)", ['user' => $userId, 'expires' => gmdate('Y-m-d H:i:s', time() + 86400), 'created' => gmdate('Y-m-d H:i:s'), 'completed' => gmdate('Y-m-d H:i:s')]);
        return new Response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="purple-parlor-data-export-' . gmdate('Ymd-His') . '.json"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function deleteForm(Request $request): Response
    {
        return $this->page($request, 'profile/delete', 'Delete account', [], true);
    }

    public function delete(Request $request): Response
    {
        if ((string) $request->input('confirmation') !== 'DELETE') {
            $this->flash('Deletion confirmation or reauthentication failed.', 'error');
            return Response::redirect('/account/delete');
        }
        try {
            $code = trim((string) $request->input('two_factor_code'));
            $this->accounts->deleteCurrent(
                (string) $request->input('password'),
                $code === '' ? null : $code,
            );
        } catch (Throwable) {
            $this->flash('Deletion confirmation or reauthentication failed.', 'error');
            return Response::redirect('/account/delete');
        }
        return Response::redirect('/')->withHeader('Set-Cookie', Application::cookie('purple_parlor_remember', '', time() - 3600, $request));
    }

    public function pause(Request $request): Response
    {
        $hours = match ((string) $request->input('duration', '7d')) {
            '30d' => 30 * 24,
            '90d' => 90 * 24,
            default => 7 * 24,
        };
        $this->responsiblePlay->takeBreak((int) $this->userId(), $hours);
        $this->mergeSettings((int) $this->userId(), ['play_controls' => ['cooldown_until' => gmdate('Y-m-d H:i:s', time() + $hours * 3600)]]);
        $this->flash('New game rounds are paused for the selected period. Billing, support, and privacy controls remain available.', 'success');
        return Response::redirect('/take-a-break');
    }

    public function takeBreak(Request $request): Response
    {
        $_SESSION['break_started_at'] = time();
        return Response::redirect('/take-a-break');
    }

    public function resetBalance(Request $request): Response
    {
        $userId = (int) $this->userId();
        $balance = $this->ledger->balance($userId, VirtualLedgerService::COZY_COINS);
        $settingsRow = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $userId]);
        $settings = json_decode((string) ($settingsRow['settings_json'] ?? '{}'), true);
        $last = strtotime((string) ($settings['last_balance_reset_at'] ?? '')) ?: 0;
        if ($balance > 100 || $last > time() - 86400) {
            $this->flash('The free reset is available when your balance is 100 Cozy Coins or lower and the 24-hour cooldown has ended.', 'info');
            return Response::redirect('/responsible-play');
        }
        $target = 5_000;
        $amount = $target - $balance;
        if ($amount > 0) {
            $this->ledger->apply($userId, VirtualLedgerService::COZY_COINS, $amount, 'account.balance_reset', 'balance-reset:' . $userId . ':' . gmdate('Ymd'));
            $this->mergeSettings($userId, ['last_balance_reset_at' => gmdate('Y-m-d H:i:s')]);
        }
        $this->flash('Your free fictional balance reset is complete. Cozy Coins still have no cash value.', 'success');
        return Response::redirect('/profile');
    }

    public function privacyConsent(Request $request): Response
    {
        $choice = in_array((string) $request->input('choice'), ['essential', 'analytics'], true) ? (string) $request->input('choice') : 'essential';
        if (($userId = $this->userId()) !== null) {
            $this->mergeSettings($userId, ['analytics_opt_out' => $choice !== 'analytics']);
        }
        return Response::redirect(\App\Security\UrlGuard::localRedirect((string) $request->input('return'), '/'))
            ->withHeader('Set-Cookie', Application::cookie('purple_parlor_consent', $choice, time() + 31536000, $request));
    }

    public function playLimits(Request $request): Response
    {
        $roundLimit = max(0, min(1000, (int) $request->input('daily_round_limit', 0)));
        $reminderRaw = (string) $request->input('session_reminder_minutes', $request->input('session_reminder', '0'));
        $dailyRaw = (string) $request->input('daily_minutes', '0');
        $reminder = max(0, min(240, (int) $reminderRaw));
        $dailyMinutes = max(0, min(1440, (int) $dailyRaw));
        $userId = (int) $this->userId();
        $this->responsiblePlay->configure($userId, $reminder > 0 ? $reminder : null, $dailyMinutes > 0 ? $dailyMinutes : null);
        $this->mergeSettings($userId, ['play_controls' => ['daily_round_limit' => $roundLimit, 'session_reminder_minutes' => $reminder, 'daily_limit_minutes' => $dailyMinutes]]);
        $this->flash('Healthy-play limits saved. A zero value means no account-specific limit.', 'success');
        return Response::redirect('/responsible-play');
    }

    public function cooldown(Request $request): Response
    {
        $duration = (string) $request->input('duration', '');
        $hours = match ($duration) {
            '1h' => 1,
            '72h' => 72,
            '7d' => 168,
            default => (int) $request->input('hours', 24),
        };
        $hours = in_array($hours, [1, 6, 12, 24, 72, 168], true) ? $hours : 24;
        $until = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        $userId = (int) $this->userId();
        $this->responsiblePlay->takeBreak($userId, $hours);
        $this->mergeSettings($userId, ['play_controls' => ['cooldown_until' => $until]]);
        $this->flash('Game access is paused until ' . $until . ' UTC. Account and support pages remain available.', 'success');
        return Response::redirect('/take-a-break');
    }

    public function selfExclude(Request $request): Response
    {
        if (!$this->auth->reauthenticate((string) $request->input('password'), ($code = trim((string) $request->input('two_factor_code'))) === '' ? null : $code)) {
            $this->flash('Reauthentication was not accepted.', 'error');
            return Response::redirect('/responsible-play');
        }
        $days = match ((string) $request->input('duration', '')) {
            '6m' => 180,
            '1y' => 365,
            '5y' => 1825,
            'indefinite' => 3650,
            default => (int) $request->input('days', 30),
        };
        $days = in_array($days, [30, 90, 180, 365, 1825, 3650], true) ? $days : 30;
        $until = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $userId = (int) $this->userId();
        $this->responsiblePlay->selfExclude($userId, $days);
        $this->mergeSettings($userId, ['play_controls' => ['self_excluded_until' => $until]]);
        $this->sessionsManager->revokeAll($userId);
        $this->auth->logout();
        return Response::redirect('/take-a-break');
    }

    /** @param array<string,mixed> $changes */
    private function mergeSettings(int $userId, array $changes): void
    {
        $row = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $userId]);
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? array_replace_recursive($settings, $changes) : $changes;
        $this->database->execute('UPDATE user_settings SET settings_json = :settings, updated_at = :updated WHERE user_id = :user', ['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated' => gmdate('Y-m-d H:i:s'), 'user' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    private function recentGames(int $userId): array
    {
        $rows = $this->database->fetchAll('SELECT gd.slug, gd.name, gd.category, gd.configuration_json FROM recent_games rg INNER JOIN game_definitions gd ON gd.id = rg.game_id WHERE rg.user_id = :user ORDER BY rg.last_played_at DESC LIMIT 4', ['user' => $userId]);
        return array_map(static function (array $row): array {
            $config = json_decode((string) ($row['configuration_json'] ?? '{}'), true) ?: [];
            return ['slug' => $row['slug'], 'name' => $row['name'], 'category' => $row['category'], 'description' => $config['description'] ?? 'A cozy play-money game.', 'available' => true];
        }, $rows);
    }

    /** @param array<string,mixed> $variables @return array{html:string,text:string} */
    private function renderEmail(string $template, array $variables, string $fallbackText): array
    {
        return $this->mail->renderTemplate($template, $variables, $this->appData(), $fallbackText);
    }
}
