<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Auth\AuthorizationService;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\AuthorizationRepository;
use App\Repositories\UserRepository;
use App\Security\Csrf;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecretRedactor;
use App\Security\UrlGuard;
use App\Services\AgeConfirmationService;
use Tests\Support\TestCase;

final class SecurityTest extends TestCase
{
    public function testCsrfScopeAndRotation(): void
    {
        session_id('csrf-test-' . bin2hex(random_bytes(4)));
        session_start();
        $csrf = new Csrf(60);
        $token = $csrf->token('billing');
        $this->assertTrue($csrf->validate($token, 'billing', true));
        $this->assertFalse($csrf->validate($token, 'billing'));
        $this->assertFalse($csrf->validate($csrf->token('profile'), 'billing'));
    }

    public function testRateLimitingAndSafeRedirects(): void
    {
        $limiter = new RateLimiter($this->database, self::KEY);
        $this->assertTrue($limiter->allow('contact', 'subject', 2, 60));
        $this->assertTrue($limiter->allow('contact', 'subject', 2, 60));
        $this->assertFalse($limiter->allow('contact', 'subject', 2, 60));
        $this->assertSame('/', UrlGuard::localRedirect('https://evil.test/steal'));
        $this->assertSame('/', UrlGuard::localRedirect('//evil.test/steal'));
        $this->assertSame('/settings?tab=privacy', UrlGuard::localRedirect('/settings?tab=privacy'));
    }

    public function testRbacAndPreparedLookupRejectInjection(): void
    {
        $users = new UserRepository($this->database);
        $user = $users->create('member@example.test', 'PlainMember', (new PasswordHasher())->hash('member secure password!'), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'member');
        $authorization = new AuthorizationService(new AuthorizationRepository($this->database));
        $this->assertTrue($authorization->can($user->id, 'games.play'));
        $this->assertFalse($authorization->can($user->id, 'payments.live.configure'));
        $this->expectException(\InvalidArgumentException::class, fn () => $users->findByEmail("' OR 1=1 --"));
    }

    public function testSecretRedactionAndAgeCookiePrivacy(): void
    {
        $redacted = (new SecretRedactor())->redactArray(['password' => 'secret', 'nested' => ['access_token' => 'abc'], 'message' => 'Bearer abc.def']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['nested']['access_token']);
        $age = new AgeConfirmationService(self::KEY);
        $cookie = $age->issue('policy-2');
        $this->assertTrue($age->validate($cookie, 'policy-2'));
        $this->assertFalse($age->validate($cookie, 'policy-3'));
        $this->assertFalse(str_contains($cookie, '127.0.0.1'));
    }

    public function testContentSecurityPolicyKeepsInjectedHostScriptsBlocked(): void
    {
        $nonce = null;
        $response = (new SecurityHeadersMiddleware($this->config))->process(
            new Request('GET', '/'),
            static function (Request $request) use (&$nonce): Response {
                $nonce = $request->attribute('csp_nonce');
                return new Response('<!doctype html><title>Safe</title>');
            },
        );
        $policy = $response->headers['Content-Security-Policy'] ?? '';

        $this->assertTrue(is_string($nonce) && $nonce !== '');
        $this->assertTrue(str_contains($policy, "script-src 'self' 'nonce-{$nonce}'"));
        $this->assertFalse(str_contains($policy, "'unsafe-inline'"));
        $this->assertFalse(str_contains($policy, 'img1.wsimg.com'));
        $this->assertFalse(str_contains($policy, 'traffic-assets'));
    }
}
