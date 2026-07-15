<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\TwoFactorEnabledMiddleware;
use App\Repositories\UserRepository;
use App\Security\Encryptor;
use App\Security\IpHasher;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\Totp;
use Tests\Support\TestCase;

final class AuthServiceTest extends TestCase
{
    public function testRegistrationVerificationLoginLogoutAndSessionRegeneration(): void
    {
        $auth = $this->auth();
        $registration = $auth->register('player@example.test', 'CozyPlayer', 'a-secure test password 42!', '127.0.0.1');
        $this->assertSame('pending_verification', $registration['user']->status);
        $this->assertTrue($auth->verifyEmail($registration['verification_token']));
        session_id('fixed-session-id-for-test');
        $before = session_id();
        $result = $auth->login('player@example.test', 'a-secure test password 42!', '127.0.0.1', 'Test Agent');
        $this->assertTrue($result->successful);
        $this->assertNotSame($before, session_id(), 'Login must regenerate the PHP session ID.');
        $this->assertSame($result->user->id, $auth->currentUser()?->id);
        $auth->logout();
        $this->assertSame(null, $auth->currentUser());
    }

    public function testResentRegistrationVerificationLinksRemainUsableUntilOneSucceeds(): void
    {
        $auth = $this->auth();
        $registration = $auth->register('resend@example.test', 'ResendPlayer', 'a secure resend password 42!', '127.0.0.8');
        $resentToken = $auth->issueEmailVerification($registration['user']->id);

        $this->assertTrue($auth->verifyEmail($registration['verification_token']));
        $this->assertFalse($auth->verifyEmail($resentToken), 'Completing one verification must consume every outstanding registration link.');
        $remaining = $this->database->fetchOne(
            'SELECT COUNT(*) AS aggregate FROM email_verifications WHERE user_id = :user AND used_at IS NULL',
            ['user' => $registration['user']->id],
        );
        $this->assertSame(0, (int) $remaining['aggregate']);
    }

    public function testRegistrationsForDifferentAddressesDoNotShareOneIpLockout(): void
    {
        $auth = $this->auth();
        for ($i = 1; $i <= 6; $i++) {
            $registration = $auth->register(
                "shared-network-{$i}@example.test",
                "SharedNetwork{$i}",
                'a secure shared-network password!',
                '203.0.113.25',
            );
            $this->assertSame('pending_verification', $registration['user']->status);
        }
    }

    public function testPasswordResetIsHashedSingleUseAndRevokesSessions(): void
    {
        $auth = $this->auth();
        $registration = $auth->register('reset@example.test', 'ResetPlayer', 'original secure password!', '127.0.0.2');
        $auth->verifyEmail($registration['verification_token']);
        $request = $auth->requestPasswordReset('reset@example.test', '127.0.0.2');
        $this->assertTrue(is_array($request));
        $stored = $this->database->fetchOne('SELECT token_hash FROM password_resets WHERE user_id = :user', ['user' => $registration['user']->id]);
        $this->assertNotSame($request['token'], $stored['token_hash']);
        $this->assertTrue($auth->resetPassword($request['token'], 'replacement secure password!'));
        $this->assertFalse($auth->resetPassword($request['token'], 'another replacement password!'));
        $this->assertTrue($auth->login('reset@example.test', 'replacement secure password!', '127.0.0.2', 'Test Agent')->successful);
    }

    public function testLockoutAndGenericUnknownReset(): void
    {
        $auth = $this->auth();
        $registration = $auth->register('locked@example.test', 'LockedPlayer', 'correct secure password!', '127.0.0.3');
        $auth->verifyEmail($registration['verification_token']);
        for ($i = 0; $i < 8; $i++) {
            $auth->login('locked@example.test', 'wrong secure password!', '127.0.0.' . (10 + $i), 'Test Agent');
        }
        $this->assertSame('locked', $auth->login('locked@example.test', 'correct secure password!', '127.0.1.1', 'Test Agent')->status);
        $this->assertSame(null, $auth->requestPasswordReset('does-not-exist@example.test', '127.0.1.2'));
    }

    public function testAdministratorTotpAndRecoveryCodes(): void
    {
        $users = new UserRepository($this->database);
        $user = $users->create('admin@example.test', 'TestAdmin', (new PasswordHasher())->hash('administrator secure password!'), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'developer_admin');
        $totp = new Totp();
        $service = new TwoFactorService($this->database, new Encryptor(self::KEY), $totp);
        $setup = $service->beginSetup($user->id);
        $code = $totp->at($setup['secret'], intdiv(time(), 30));
        $this->assertTrue($service->confirm($user->id, $code));
        $this->assertFalse($service->verify($user->id, $code), 'TOTP counters must not be replayable.');
        $this->assertTrue($service->verify($user->id, $setup['recovery_codes'][0]));
        $this->assertFalse($service->verify($user->id, $setup['recovery_codes'][0]), 'Recovery codes must be one-time.');
        $replacement = $service->regenerateRecoveryCodes($user->id);
        $this->assertSame(10, count($replacement));
        $this->assertFalse($service->verify($user->id, $setup['recovery_codes'][1]), 'Regeneration must invalidate every old recovery code.');
        $this->assertTrue($service->verify($user->id, $replacement[0]));
    }

    public function testAdministratorWithoutTwoFactorGetsRestrictedSetupSession(): void
    {
        $users = new UserRepository($this->database);
        $user = $users->create('setup-admin@example.test', 'SetupAdmin', (new PasswordHasher())->hash('administrator secure password!'), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'developer_admin');

        $auth = $this->auth();
        $result = $auth->login('setup-admin@example.test', 'administrator secure password!', '127.0.0.4', 'Test Agent', true);

        $this->assertTrue($result->successful, 'The administrator must be authenticated so the enrollment route is reachable.');
        $this->assertSame('two_factor_setup_required', $result->status);
        $this->assertSame($user->id, $auth->currentUser()?->id);
        $this->assertSame(null, $result->rememberToken, 'A pre-enrollment administrator must never receive a remember token.');
        $remember = $this->database->fetchOne('SELECT id FROM remember_tokens WHERE user_id = :user', ['user' => $user->id]);
        $this->assertSame(null, $remember);
    }

    public function testRememberedAdministratorMustReenterPasswordAndTwoFactorForAdminRoutes(): void
    {
        $users = new UserRepository($this->database);
        $user = $users->create('remembered-admin@example.test', 'RememberedAdmin', (new PasswordHasher())->hash('administrator secure password!'), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'adult_owner');

        $totp = new Totp();
        $twoFactor = new TwoFactorService($this->database, new Encryptor(self::KEY), $totp);
        $setup = $twoFactor->beginSetup($user->id);
        $counter = intdiv(time(), 30);
        $this->assertTrue($twoFactor->confirm($user->id, $totp->at($setup['secret'], $counter - 1)));

        $sessions = new SessionManager($this->database, $this->config, new IpHasher(self::KEY));
        $auth = new AuthService(
            $this->database,
            $users,
            new PasswordHasher(),
            $sessions,
            $twoFactor,
            new RateLimiter($this->database, self::KEY),
            new IpHasher(self::KEY),
            self::KEY,
        );
        $this->assertSame('two_factor_required', $auth->login(
            'remembered-admin@example.test',
            'administrator secure password!',
            '127.0.0.5',
            'Test Agent',
            true,
        )->status);
        $login = $auth->completeTwoFactor(
            $totp->at($setup['secret'], $counter),
            '127.0.0.5',
            'Test Agent',
            true,
        );
        $this->assertTrue($login->successful);
        $this->assertTrue($sessions->privileged());
        $this->assertTrue($sessions->twoFactorVerified());
        $this->assertTrue(is_string($login->rememberToken));

        // Simulate closing the browser session without logging out. The
        // persistent token may restore identity, but never elevation.
        $_SESSION = [];
        session_regenerate_id(true);
        $rotated = $sessions->consumeRememberToken((string) $login->rememberToken, '127.0.0.5', 'Test Agent');
        $this->assertTrue(is_string($rotated));
        $this->assertSame($user->id, $auth->currentUser()?->id);
        $this->assertFalse($sessions->privileged());
        $this->assertFalse($sessions->twoFactorVerified());

        $middleware = new TwoFactorEnabledMiddleware($auth, $twoFactor, $sessions);
        $response = $middleware->process(
            new Request('GET', '/admin'),
            static fn (Request $request): Response => new Response('should-not-run'),
        );
        $this->assertSame(303, $response->status);
        $this->assertSame('/confirm-password?return=%2Fadmin', $response->headers['Location'] ?? null);
    }

    public function testPrivilegedProofIsSignedShortLivedAndBrowserBound(): void
    {
        $sessions = new SessionManager($this->database, $this->config, new IpHasher(self::KEY));
        $proof = $sessions->issuePrivilegedProof(42, 'Expected Browser');

        $this->assertTrue($sessions->validatePrivilegedProof($proof, 42, 'Expected Browser'));
        $this->assertFalse($sessions->validatePrivilegedProof($proof, 43, 'Expected Browser'));
        $this->assertFalse($sessions->validatePrivilegedProof($proof, 42, 'Different Browser'));
        $this->assertFalse($sessions->validatePrivilegedProof($proof . 'tampered', 42, 'Expected Browser'));
    }

    public function testResponseCanPreserveMultipleCookies(): void
    {
        $response = Response::redirect('/profile')
            ->withAddedHeader('Set-Cookie', 'first=one; Path=/')
            ->withAddedHeader('Set-Cookie', 'second=two; Path=/');

        $this->assertSame(
            ['first=one; Path=/', 'second=two; Path=/'],
            $response->headers['Set-Cookie'] ?? null,
        );
    }

    private function auth(): AuthService
    {
        $ip = new IpHasher(self::KEY);
        $sessions = new SessionManager($this->database, $this->config, $ip);
        return new AuthService($this->database, new UserRepository($this->database), new PasswordHasher(), $sessions,
            new TwoFactorService($this->database, new Encryptor(self::KEY), new Totp()), new RateLimiter($this->database, self::KEY), $ip, self::KEY);
    }
}
