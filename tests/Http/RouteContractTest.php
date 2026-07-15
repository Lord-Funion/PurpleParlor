<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\TestCase;

final class RouteContractTest extends TestCase
{
    public function testEveryRouteHasARealControllerMethodAndAgeFormMatchesItsHandler(): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        foreach ($routes as $index => $route) {
            $this->assertTrue(count($route) >= 4, 'Route ' . $index . ' is malformed.');
            $handler = $route[3];
            $class = 'App\\Controllers\\' . $handler[0];
            $this->assertTrue(class_exists($class), 'Missing controller ' . $class . '.');
            $this->assertTrue(method_exists($class, $handler[1]), 'Missing route handler ' . $class . '::' . $handler[1] . '.');
        }
        $age = file_get_contents(dirname(__DIR__, 2) . '/resources/views/age-gate.php');
        $this->assertTrue(is_string($age) && str_contains($age, 'name="confirmation"') && str_contains($age, 'value="yes"'));
    }

    public function testEmailChangeRouteAndReauthenticationFormStayAligned(): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        $emailRoute = null;
        $emailFormRoute = null;
        foreach ($routes as $route) {
            if (($route[2] ?? null) === 'profile.email.update') {
                $emailRoute = $route;
            }
            if (($route[2] ?? null) === 'profile.email.edit') {
                $emailFormRoute = $route;
            }
        }

        $this->assertTrue(is_array($emailRoute), 'The email-change route is missing.');
        $this->assertSame('POST', $emailRoute[0]);
        $this->assertSame('/settings/email', $emailRoute[1]);
        $this->assertSame(['AccountController', 'requestEmailChange'], $emailRoute[3]);
        foreach (['auth', 'csrf', 'reauth', 'rate:verification'] as $middleware) {
            $this->assertTrue(in_array($middleware, $emailRoute[4], true), 'Email change is missing ' . $middleware . '.');
        }
        $this->assertTrue(is_array($emailFormRoute), 'The reauthenticated email-change form route is missing.');
        $this->assertSame('GET', $emailFormRoute[0]);
        $this->assertSame(['AccountController', 'emailChangeForm'], $emailFormRoute[3]);
        $this->assertTrue(in_array('reauth', $emailFormRoute[4], true));

        $settings = file_get_contents(dirname(__DIR__, 2) . '/resources/views/profile/settings.php');
        $this->assertTrue(is_string($settings) && str_contains($settings, "profile.email.edit"));
        $form = file_get_contents(dirname(__DIR__, 2) . '/resources/views/profile/email.php');
        $this->assertTrue(is_string($form) && str_contains($form, "profile.email.update"));
        foreach (['name="email"', 'name="password"'] as $field) {
            $this->assertTrue(str_contains($form, $field), 'Email-change form is missing ' . $field . '.');
        }
        $secondFactor = file_get_contents(dirname(__DIR__, 2) . '/resources/views/partials/reauth-two-factor.php');
        $this->assertTrue(is_string($secondFactor) && str_contains($secondFactor, 'name="two_factor_code"'));
        $this->assertTrue(str_contains($form, "partials/reauth-two-factor.php"));
    }

    public function testStaticProfileRoutesPrecedeThePublicUsernameCatchAll(): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        $positions = [];
        foreach ($routes as $index => $route) {
            $positions[$route[2]] = $index;
        }

        $this->assertTrue(isset($positions['profile.public'], $positions['profile.stats'], $positions['profile.stats-export']));
        $this->assertTrue($positions['profile.stats'] < $positions['profile.public']);
        $this->assertTrue($positions['profile.stats-export'] < $positions['profile.public']);
    }

    public function testEveryStateChangingBrowserRouteUsesCsrfExceptVerifiedWebhooks(): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        $webhooks = ['webhooks.paypal', 'webhooks.square'];
        foreach ($routes as $route) {
            if (($route[0] ?? 'GET') === 'GET' || in_array($route[2] ?? '', $webhooks, true)) {
                continue;
            }
            $this->assertTrue(
                in_array('csrf', $route[4] ?? [], true),
                'State-changing route ' . ($route[2] ?? '(unnamed)') . ' is missing CSRF protection.',
            );
        }
    }

    public function testDynamicRoutesDoNotShadowLaterStaticRoutes(): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        foreach ($routes as $index => $route) {
            $path = (string) ($route[1] ?? '');
            if (!str_contains($path, '{')) {
                continue;
            }
            $quoted = preg_quote($path, '#');
            $pattern = preg_replace('#\\\\\{[A-Za-z_][A-Za-z0-9_]*\\\\\}#', '[^/]+', $quoted);
            $this->assertTrue(is_string($pattern));
            foreach (array_slice($routes, $index + 1) as $later) {
                if (($later[0] ?? null) !== ($route[0] ?? null) || str_contains((string) ($later[1] ?? ''), '{')) {
                    continue;
                }
                $this->assertTrue(
                    preg_match('#^' . $pattern . '/?$#', (string) $later[1]) !== 1,
                    'Dynamic route ' . $path . ' shadows later static route ' . $later[1] . '.',
                );
            }
        }
    }

    public function testCheckoutAndRegistrationPostVersionedPolicyAcceptance(): void
    {
        $root = dirname(__DIR__, 2);
        $checkout = file_get_contents($root . '/resources/views/billing/checkout.php');
        $billing = file_get_contents($root . '/app/Controllers/BillingController.php');
        $registration = file_get_contents($root . '/app/Controllers/AuthController.php');
        $this->assertTrue(is_string($checkout) && str_contains($checkout, 'name="terms_accepted"'));
        $this->assertTrue(is_string($billing) && str_contains($billing, "['terms', 'subscription-terms']"));
        $this->assertFalse(str_contains((string) $billing, "\$provider === 'demo' && \$request->input('scenario')"));
        $this->assertTrue(is_string($registration) && str_contains($registration, "['terms', 'privacy', 'virtual-currency']"));
    }
}
