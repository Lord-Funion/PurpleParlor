<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Controllers\AccountController;
use App\Core\Request;
use App\Http\GuestWallet;
use App\Http\View;
use App\Mail\MailMessage;
use App\Mail\MailQueue;
use App\Mail\MailTransportInterface;
use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\Encryptor;
use App\Security\IpHasher;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Services\AccountService;
use App\Services\AgeConfirmationService;
use App\Services\AuditService;
use App\Services\EntitlementService;
use App\Services\ResponsiblePlayService;
use App\Services\VirtualLedgerService;
use Tests\Support\TestCase;

final class AccountPrivacyControllerTest extends TestCase
{
    private const PASSWORD = 'correct horse battery purple!';

    public function testLockedThemeCannotBePersistedUntilEntitlementIsActive(): void
    {
        $context = $this->context();
        $request = new Request('POST', '/settings/preferences', [], ['theme' => 'royal-plum', 'appearance' => 'dark']);
        $context['controller']->updatePreferences($request);
        $settings = json_decode((string) $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $context['user_id']])['settings_json'], true);
        $this->assertFalse(($settings['theme'] ?? null) === 'royal-plum', 'A client-submitted locked theme was persisted.');

        (new EntitlementService($this->database))->grant($context['user_id'], 'theme.purple_premium', 'test', 'theme-controller-suite');
        $context['controller']->updatePreferences($request);
        $settings = json_decode((string) $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $context['user_id']])['settings_json'], true);
        $this->assertSame('royal-plum', $settings['theme'] ?? null);
    }

    public function testConsentBannerAndCookieUseOneCanonicalContract(): void
    {
        $context = $this->context();
        $withoutChoice = $context['controller']->settings(new Request('GET', '/settings'));
        $this->assertTrue(str_contains($withoutChoice->body, 'cookie-consent-title'));
        $withChoice = $context['controller']->settings(new Request('GET', '/settings', [], [], ['purple_parlor_consent' => 'essential']));
        $this->assertFalse(str_contains($withChoice->body, 'cookie-consent-title'));

        $response = $context['controller']->privacyConsent(new Request('POST', '/privacy/consent', [], ['choice' => 'analytics']));
        $this->assertTrue(str_contains((string) ($response->headers['Set-Cookie'] ?? ''), 'purple_parlor_consent=analytics'));
    }

    public function testStatisticsExportRequiresItsSpecificEntitlement(): void
    {
        $context = $this->context();
        $denied = $context['controller']->statisticsExport(new Request('GET', '/profile/statistics/export'));
        $this->assertSame(403, $denied->status);
        (new EntitlementService($this->database))->grant($context['user_id'], 'statistics.export', 'test', 'statistics-export-suite');
        $allowed = $context['controller']->statisticsExport(new Request('GET', '/profile/statistics/export'));
        $this->assertSame(200, $allowed->status);
        $this->assertTrue(str_contains((string) ($allowed->headers['Content-Disposition'] ?? ''), 'attachment'));
    }

    public function testControllerExportUsesCompleteAccountServicePayload(): void
    {
        $context = $this->context();
        $userId = $context['user_id'];
        $now = gmdate('Y-m-d H:i:s');
        $context['users']->assignRole($userId, 'member');
        $context['ledger']->apply($userId, VirtualLedgerService::COZY_COINS, 250, 'test.export', 'test-export-wallet-' . $userId);
        $achievement = $this->database->fetchOne("SELECT id FROM achievements WHERE achievement_key = 'welcome'");
        $this->database->execute('INSERT INTO user_achievements (user_id, achievement_id, unlocked_at) VALUES (:user, :achievement, :now)', ['user' => $userId, 'achievement' => $achievement['id'], 'now' => $now]);
        $this->database->execute('INSERT INTO user_entitlements (user_id, entitlement_key, source_type, source_id, starts_at, created_at, updated_at) VALUES (:user, :key, :type, :source, :now, :now, :now)', ['user' => $userId, 'key' => 'theme.cozy', 'type' => 'test', 'source' => 'privacy-suite', 'now' => $now]);
        $this->database->execute('INSERT INTO legal_acceptances (user_id, document_key, document_version, accepted_at) VALUES (:user, :document, :version, :now)', ['user' => $userId, 'document' => 'privacy', 'version' => 'test-v1', 'now' => $now]);
        $game = $this->database->fetchOne('SELECT id FROM game_definitions ORDER BY id LIMIT 1');
        $mission = $this->database->fetchOne('SELECT id FROM missions ORDER BY id LIMIT 1');
        $leaderboard = $this->database->fetchOne('SELECT id FROM leaderboards ORDER BY id LIMIT 1');
        $this->database->execute('INSERT INTO game_favorites (user_id, game_id, created_at) VALUES (:user, :game, :now)', ['user' => $userId, 'game' => $game['id'], 'now' => $now]);
        $this->database->execute('INSERT INTO recent_games (user_id, game_id, last_played_at) VALUES (:user, :game, :now)', ['user' => $userId, 'game' => $game['id'], 'now' => $now]);
        $this->database->execute('INSERT INTO player_statistics (user_id, game_id, stat_key, stat_value, updated_at) VALUES (:user, :game, :key, 3, :now)', ['user' => $userId, 'game' => $game['id'], 'key' => 'rounds', 'now' => $now]);
        $this->database->execute('INSERT INTO mission_progress (user_id, mission_id, progress_value, updated_at) VALUES (:user, :mission, 1, :now)', ['user' => $userId, 'mission' => $mission['id'], 'now' => $now]);
        $this->database->execute('INSERT INTO leaderboard_entries (leaderboard_id, user_id, score, updated_at) VALUES (:leaderboard, :user, 7, :now)', ['leaderboard' => $leaderboard['id'], 'user' => $userId, 'now' => $now]);
        $this->database->execute('INSERT INTO daily_reward_claims (user_id, reward_date, amount, idempotency_key, claimed_at) VALUES (:user, :date, 10, :key, :now)', ['user' => $userId, 'date' => gmdate('Y-m-d'), 'key' => 'privacy-export-reward-' . $userId, 'now' => $now]);
        $this->database->execute('INSERT INTO notifications (user_id, type, data_json, created_at) VALUES (:user, :type, :data, :now)', ['user' => $userId, 'type' => 'privacy-test', 'data' => '{}', 'now' => $now]);
        $this->database->execute('INSERT INTO responsible_play_controls (user_id, session_reminder_minutes, daily_limit_minutes, autoplay_allowed, updated_at) VALUES (:user, 30, 120, 0, :now)', ['user' => $userId, 'now' => $now]);
        $this->database->execute('INSERT INTO contact_messages (user_id, name, email, subject, message, status, spam_score, submitted_at) VALUES (:user, :name, :email, :subject, :message, :status, 0, :now)', ['user' => $userId, 'name' => 'Privacy Member', 'email' => 'old-address@example.test', 'subject' => 'My request', 'message' => 'My message', 'status' => 'new', 'now' => $now]);
        $this->database->execute('INSERT INTO support_tickets (user_id, subject, status, priority, created_at, updated_at) VALUES (:user, :subject, :status, :priority, :now, :now)', ['user' => $userId, 'subject' => 'My ticket', 'status' => 'open', 'priority' => 'normal', 'now' => $now]);
        $this->database->execute('INSERT INTO licensing_inquiries (name, business_name, email, service_requested, message, consent, spam_score, status, submitted_at) VALUES (:name, :business, :email, :service, :message, 1, 0, :status, :now)', ['name' => 'Privacy Member', 'business' => 'Cozy Business', 'email' => 'old-address@example.test', 'service' => 'Licensing', 'message' => 'My business inquiry', 'status' => 'new', 'now' => $now]);
        $this->database->execute('UPDATE user_settings SET settings_json = :settings, updated_at = :now WHERE user_id = :user', ['settings' => '{"notifications":{"security":true}}', 'now' => $now, 'user' => $userId]);

        $response = $context['controller']->requestExport(new Request('POST', '/account/export', [], [
            'confirm' => 'on',
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        foreach (['roles', 'virtual_wallets', 'achievements', 'entitlements', 'legal_acceptances'] as $section) {
            $this->assertTrue(array_key_exists($section, $payload), 'Export is missing ' . $section . '.');
            $this->assertSame(1, count($payload[$section]), 'Export did not include the seeded ' . $section . ' record.');
        }
        $this->assertSame('member', $payload['roles'][0]);
        $this->assertSame('cozy_coins', $payload['virtual_wallets'][0]['currency']);
        $this->assertSame('welcome', $payload['achievements'][0]['achievement_key']);
        $this->assertSame('theme.cozy', $payload['entitlements'][0]['entitlement_key']);
        $this->assertSame('privacy', $payload['legal_acceptances'][0]['document_key']);
        foreach (['mission_progress', 'favorite_games', 'recent_games', 'player_statistics', 'leaderboard_entries', 'daily_rewards', 'notifications', 'contact_messages', 'support_tickets', 'licensing_inquiries'] as $section) {
            $this->assertSame(1, count($payload[$section] ?? []), 'Export did not include the seeded ' . $section . ' record.');
        }
        $this->assertSame(true, $payload['notification_preferences']['security'] ?? null);
        $this->assertSame(30, (int) ($payload['responsible_play_settings']['session_reminder_minutes'] ?? 0));
        $this->assertSame('private, no-store', $response->headers['Cache-Control'] ?? null);
    }

    public function testDeletionKeepsTypedPasswordAndTwoFactorGuardsThenUsesFullPurge(): void
    {
        $context = $this->context(true);
        $userId = $context['user_id'];
        $old = $this->database->fetchOne('SELECT email, username, password_hash FROM users WHERE id = :id', ['id' => $userId]);
        $now = gmdate('Y-m-d H:i:s');
        $context['users']->assignRole($userId, 'member');
        $context['sessions']->authenticate($userId, '127.0.0.2', 'Second Privacy Test Browser', true, true);
        $context['sessions']->issueRememberToken($userId);
        $context['auth']->issueEmailVerification($userId);
        $this->database->execute('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:user, :hash, :expires, :now)', ['user' => $userId, 'hash' => hash('sha256', 'privacy-delete-test'), 'expires' => gmdate('Y-m-d H:i:s', time() + 3600), 'now' => $now]);
        $this->database->execute('INSERT INTO notifications (user_id, type, data_json, created_at) VALUES (:user, :type, :data, :now)', ['user' => $userId, 'type' => 'test', 'data' => '{}', 'now' => $now]);
        $this->database->execute('INSERT INTO payment_provider_customers (user_id, provider, external_customer_id, created_at, updated_at) VALUES (:user, :provider, :external, :now, :now)', ['user' => $userId, 'provider' => 'demo', 'external' => 'delete-test-' . $userId, 'now' => $now]);

        $context['controller']->delete(new Request('POST', '/account/delete', [], [
            'confirmation' => 'delete',
            'password' => self::PASSWORD,
            'two_factor_code' => $context['recovery_code'],
        ]));
        $this->assertSame('active', $this->database->fetchOne('SELECT status FROM users WHERE id = :id', ['id' => $userId])['status']);

        $context['controller']->delete(new Request('POST', '/account/delete', [], [
            'confirmation' => 'DELETE',
            'password' => self::PASSWORD,
        ]));
        $this->assertSame('active', $this->database->fetchOne('SELECT status FROM users WHERE id = :id', ['id' => $userId])['status']);

        $response = $context['controller']->delete(new Request('POST', '/account/delete', [], [
            'confirmation' => 'DELETE',
            'password' => self::PASSWORD,
            'two_factor_code' => $context['recovery_code'],
        ]));
        $this->assertSame('/', $response->headers['Location'] ?? null);

        $deleted = $this->database->fetchOne('SELECT email, username, password_hash, pending_email, status, deleted_at FROM users WHERE id = :id', ['id' => $userId]);
        $this->assertSame('deleted', $deleted['status']);
        $this->assertTrue($deleted['deleted_at'] !== null);
        $this->assertNotSame($old['email'], $deleted['email']);
        $this->assertNotSame($old['username'], $deleted['username']);
        $this->assertFalse(password_verify(self::PASSWORD, (string) $deleted['password_hash']), 'Deletion must rotate the password credential.');
        $this->assertSame(null, $deleted['pending_email']);

        foreach (['user_roles', 'user_profiles', 'user_settings', 'remember_tokens', 'email_verifications', 'password_resets', 'recovery_codes', 'two_factor_secrets', 'notifications', 'payment_provider_customers'] as $table) {
            $count = (int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM {$table} WHERE user_id = :user", ['user' => $userId])['aggregate'];
            $this->assertSame(0, $count, $table . ' retained user-linked records after deletion.');
        }
        $activeSessions = (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM sessions WHERE user_id = :user AND revoked_at IS NULL', ['user' => $userId])['aggregate'];
        $this->assertSame(0, $activeSessions);
    }

    public function testEmailChangeQueuesBothMessagesAndVerificationRevokesSessions(): void
    {
        $context = $this->context(true);
        $response = $context['controller']->requestEmailChange(new Request('POST', '/settings/email', [], [
            'email' => 'new-address@example.test',
            'password' => self::PASSWORD,
            'two_factor_code' => $context['recovery_code'],
        ], [], [], ['REMOTE_ADDR' => '127.0.0.1'], ['user-agent' => 'Privacy Test Browser']));

        $this->assertSame('/settings', $response->headers['Location'] ?? null);
        $account = $this->database->fetchOne('SELECT email, pending_email FROM users WHERE id = :id', ['id' => $context['user_id']]);
        $this->assertSame('old-address@example.test', $account['email']);
        $this->assertSame('new-address@example.test', $account['pending_email']);
        $queued = $this->database->fetchAll('SELECT recipient_email, template_key, text_body FROM email_queue ORDER BY id');
        $this->assertSame(2, count($queued));
        $this->assertSame('new-address@example.test', $queued[0]['recipient_email']);
        $this->assertSame('account.email-change.verify', $queued[0]['template_key']);
        $this->assertSame('old-address@example.test', $queued[1]['recipient_email']);
        $this->assertSame('account.email-change.notice', $queued[1]['template_key']);
        $this->assertTrue(!str_contains((string) $queued[1]['text_body'], 'new-address@example.test'), 'The old-address notice should not disclose the requested address.');
        $this->assertTrue(preg_match('#/verify-email/([a-f0-9]{64})#', (string) $queued[0]['text_body'], $match) === 1);

        $this->assertTrue($context['auth']->verifyEmail($match[1]));
        $changed = $this->database->fetchOne('SELECT email, pending_email FROM users WHERE id = :id', ['id' => $context['user_id']]);
        $this->assertSame('new-address@example.test', $changed['email']);
        $this->assertSame(null, $changed['pending_email']);
        $activeSessions = (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM sessions WHERE user_id = :user AND revoked_at IS NULL', ['user' => $context['user_id']])['aggregate'];
        $this->assertSame(0, $activeSessions, 'Verifying the new address must revoke every session.');
    }

    public function testIneligibleEmailChangeUsesGenericMessageAndRollsBack(): void
    {
        $context = $this->context();
        $context['users']->create('already-used@example.test', 'OtherMember', (new PasswordHasher())->hash('another long secure password!'), 'active');

        $context['controller']->requestEmailChange(new Request('POST', '/settings/email', [], [
            'email' => 'already-used@example.test',
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(
            'If the new address is eligible, verification instructions have been queued. Your current address remains active until verification.',
            $_SESSION['_flash']['message'] ?? null,
        );
        $this->assertSame(null, $this->database->fetchOne('SELECT pending_email FROM users WHERE id = :id', ['id' => $context['user_id']])['pending_email']);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM email_queue')['aggregate']);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM email_verifications WHERE user_id = :user', ['user' => $context['user_id']])['aggregate']);
    }

    public function testTwoFactorSetupRefreshIsIdempotentAndConfirmationElevatesSession(): void
    {
        $context = $this->context();
        $request = new Request('GET', '/settings/two-factor');
        $this->assertSame(200, $context['controller']->twoFactorSetup($request)->status);
        $first = $_SESSION['_pending_two_factor_setup'] ?? null;
        $storedFirst = $this->database->fetchOne('SELECT encrypted_secret FROM two_factor_secrets WHERE user_id = :user', ['user' => $context['user_id']]);

        $this->assertSame(200, $context['controller']->twoFactorSetup($request)->status);
        $second = $_SESSION['_pending_two_factor_setup'] ?? null;
        $storedSecond = $this->database->fetchOne('SELECT encrypted_secret FROM two_factor_secrets WHERE user_id = :user', ['user' => $context['user_id']]);
        $this->assertSame($first['secret'] ?? null, $second['secret'] ?? null);
        $this->assertSame($first['recovery_codes'] ?? null, $second['recovery_codes'] ?? null);
        $this->assertSame($storedFirst['encrypted_secret'] ?? null, $storedSecond['encrypted_secret'] ?? null);

        $code = (new Totp())->at((string) $first['secret'], intdiv(time(), 30));
        $response = $context['controller']->twoFactorConfirm(new Request('POST', '/settings/two-factor', [], [
            'password' => self::PASSWORD,
            'code' => $code,
        ]));
        $this->assertSame('/settings/recovery-codes', $response->headers['Location'] ?? null);
        $this->assertTrue($context['sessions']->privileged());
        $this->assertTrue($context['sessions']->twoFactorVerified());
        $this->assertFalse(isset($_SESSION['_pending_two_factor_setup']));
        $this->assertSame(10, count($_SESSION['_new_recovery_codes'] ?? []));
    }

    public function testProfileRejectsControlCharactersAndRedactsUnexpectedDatabaseErrors(): void
    {
        $context = $this->context();
        $context['controller']->updateProfile(new Request('POST', '/settings/profile', [], [
            'display_name' => "Unsafe\x01Name",
            'username' => 'PrivacyMember',
            'bio' => 'A normal biography.',
        ]));
        $this->assertSame('Profile text cannot contain control characters.', $_SESSION['_flash']['message'] ?? null);

        $this->database->execute("CREATE TRIGGER privacy_profile_failure BEFORE UPDATE ON user_profiles BEGIN SELECT RAISE(ABORT, 'sensitive sql profile detail'); END");
        $context['controller']->updateProfile(new Request('POST', '/settings/profile', [], [
            'display_name' => 'Safe Name',
            'username' => 'PrivacyMember',
            'bio' => 'A normal biography.',
        ]));
        $message = (string) ($_SESSION['_flash']['message'] ?? '');
        $this->assertTrue(str_contains($message, 'request ID'));
        $this->assertFalse(str_contains($message, 'sensitive sql profile detail'));
    }

    public function testPasswordPolicyAndUnexpectedFailuresUseFixedSafeMessages(): void
    {
        $context = $this->context();
        $context['controller']->updatePassword(new Request('POST', '/settings/password', [], [
            'current_password' => self::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));
        $this->assertSame('The new password must be 12–1024 bytes and contain no unsupported control characters.', $_SESSION['_flash']['message'] ?? null);

        $this->database->execute("CREATE TRIGGER privacy_password_failure BEFORE UPDATE OF password_hash ON users BEGIN SELECT RAISE(ABORT, 'sensitive sql password detail'); END");
        $replacement = 'replacement secure password 123!';
        $context['controller']->updatePassword(new Request('POST', '/settings/password', [], [
            'current_password' => self::PASSWORD,
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ]));
        $message = (string) ($_SESSION['_flash']['message'] ?? '');
        $this->assertTrue(str_contains($message, 'request ID'));
        $this->assertFalse(str_contains($message, 'sensitive sql password detail'));
    }

    /** @return array<string,mixed> */
    private function context(bool $withTwoFactor = false): array
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $users = new UserRepository($this->database);
        $passwords = new PasswordHasher();
        $user = $users->create('old-address@example.test', 'PrivacyMember', $passwords->hash(self::PASSWORD), 'active');
        $users->markVerified($user->id);
        $ip = new IpHasher(self::KEY);
        $sessions = new SessionManager($this->database, $this->config, $ip);
        $totp = new Totp();
        $twoFactor = new TwoFactorService($this->database, new Encryptor(self::KEY), $totp);
        $recoveryCode = null;
        if ($withTwoFactor) {
            $setup = $twoFactor->beginSetup($user->id);
            $this->assertTrue($twoFactor->confirm($user->id, $totp->at($setup['secret'], intdiv(time(), 30))));
            $recoveryCode = $setup['recovery_codes'][0];
        }
        $ledger = new VirtualLedgerService($this->database, new LedgerRepository($this->database), self::KEY);
        $auth = new AuthService($this->database, $users, $passwords, $sessions, $twoFactor, new RateLimiter($this->database, self::KEY), $ip, self::KEY, $ledger);
        $sessions->authenticate($user->id, '127.0.0.1', 'Privacy Test Browser', true, $withTwoFactor);
        $accounts = new AccountService($this->database, $auth, $users, new AuditService($this->database, self::KEY));
        $mail = new MailQueue($this->database, new class implements MailTransportInterface {
            public function send(MailMessage $message): void
            {
            }
        }, $this->config);
        $controller = new AccountController(
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->config,
            $this->database,
            $auth,
            new AgeConfirmationService(self::KEY),
            new GuestWallet(),
            $sessions,
            $users,
            $passwords,
            $twoFactor,
            new ResponsiblePlayService($this->database),
            $ledger,
            $accounts,
            $mail,
        );

        return compact('controller', 'auth', 'users', 'sessions', 'twoFactor', 'ledger', 'accounts', 'mail', 'recoveryCode')
            + ['user_id' => $user->id, 'recovery_code' => $recoveryCode];
    }
}
