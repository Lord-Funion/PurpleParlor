<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\SessionManager;
use App\Auth\AuthService;
use App\Core\Container;
use App\Core\ExceptionHandler;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Core\Router;
use App\Database\Database;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\AnalyticsService;
use Throwable;

/**
 * Small shared-hosting friendly HTTP kernel. It owns session startup, remember
 * token rotation, exception rendering, and the security-header envelope.
 */
final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private readonly SecurityHeadersMiddleware $securityHeaders,
    ) {
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        RequestContext::set($request->header('x-request-id', '') ?? '');

        try {
            $sessions = null;
            try {
                $sessions = $this->container->get(SessionManager::class);
                $sessions->start();
            } catch (Throwable $sessionFailure) {
                if (rtrim($request->uri, '/') !== '/install') {
                    throw $sessionFailure;
                }
                $this->startInstallerSession($request);
            }
            $request = $this->methodOverride($request);
            $rememberCookie = $sessions instanceof SessionManager ? $this->restoreRememberedSession($request, $sessions) : null;
            if ($sessions instanceof SessionManager && ($authenticatedId = $sessions->authenticatedUserId()) !== null) {
                // Let the outer security-header envelope mark every private
                // response no-store, including routes without explicit auth middleware.
                $request = $request->withAttribute('user_id', $authenticatedId);
            }
            $response = $this->securityHeaders->process($request, fn (Request $secured): Response => $this->maintenanceRedirect($secured) ?? $this->router->dispatch($secured));
            $response = $rememberCookie === null ? $response : $response->withAddedHeader('Set-Cookie', $rememberCookie);
            $this->recordAnalytics($request, $response, $started);
            return $response;
        } catch (Throwable $exception) {
            $response = $this->exceptions->render($exception, $request)
                ->withHeader('X-Request-ID', RequestContext::id())
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'no-store');
            $this->recordAnalytics($request, $response, $started);
            return $response;
        }
    }

    private function recordAnalytics(Request $request, Response $response, int $started): void
    {
        if (($request->cookies['purple_parlor_consent'] ?? '') !== 'analytics'
            || rtrim($request->uri, '/') === '/install'
            || (defined('BASE_PATH') && !is_file(BASE_PATH . '/storage/installed.lock'))) {
            return;
        }
        try {
            $analytics = $this->container->get(AnalyticsService::class);
            $userId = is_int($request->attribute('user_id')) ? $request->attribute('user_id') : null;
            $agent = strtolower((string) $request->header('user-agent', ''));
            $device = str_contains($agent, 'ipad') || str_contains($agent, 'tablet') ? 'tablet'
                : (str_contains($agent, 'mobile') || str_contains($agent, 'android') ? 'mobile' : ($agent === '' ? 'other' : 'desktop'));
            $segments = array_values(array_filter(explode('/', trim($request->uri, '/'))));
            $pageKey = $segments[0] ?? 'home';
            $duration = (int) min(600_000, max(0, round((hrtime(true) - $started) / 1_000_000)));
            if ($request->method === 'GET' && $response->status < 400) {
                $analytics->record('page_view', $userId, $request->clientIp(), $device, null, ['page_key' => $pageKey]);
            }
            $analytics->record('performance_timing', $userId, $request->clientIp(), $device, $duration, ['page_key' => $pageKey]);
            if ($request->method === 'GET' && ($segments[0] ?? '') === 'games' && isset($segments[1]) && !in_array($segments[1], ['all','search','favorites','recent','category'], true)) {
                $analytics->record('game_launch', $userId, $request->clientIp(), $device, null, ['game_slug' => $segments[1]]);
            }
            if ($response->status >= 400) {
                $analytics->record('application_error', $userId, $request->clientIp(), $device, null, ['error_code' => 'http_' . $response->status]);
            }
            if ($request->method === 'POST' && str_starts_with($request->uri, '/api/games/') && str_contains((string) ($response->headers['Content-Type'] ?? ''), 'application/json')) {
                $payload = json_decode($response->body, true);
                if (is_array($payload) && ($payload['complete'] ?? false) === true) {
                    $analytics->record('round_completed', $userId, $request->clientIp(), $device, null, ['game_slug' => (string) ($payload['slug'] ?? 'unknown')]);
                }
            }
            if ($request->method === 'POST' && $request->uri === '/billing/checkout' && $response->status < 400) {
                $analytics->record('subscription_conversion', $userId, $request->clientIp(), $device, null, ['feature_key' => 'billing.checkout']);
            } elseif ($request->method === 'POST' && $response->status < 400) {
                $analytics->record('feature_used', $userId, $request->clientIp(), $device, null, ['feature_key' => $pageKey]);
            }
        } catch (Throwable) {
            // Analytics is optional and must never break the requested action.
        }
    }

    private function maintenanceRedirect(Request $request): ?Response
    {
        $allowed = ['/maintenance', '/install', '/login', '/logout', '/two-factor', '/confirm-password', '/verify-email', '/forgot-password', '/reset-password'];
        if (in_array(rtrim($request->uri, '/') ?: '/', $allowed, true)
            || str_starts_with($request->uri, '/verify-email/')
            || str_starts_with($request->uri, '/reset-password/')
            || str_starts_with($request->uri, '/api/webhooks/')) {
            return null;
        }
        try {
            $row = $this->container->get(Database::class)->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'site.maintenance_mode'");
            if ($row === null || json_decode((string) $row['setting_value'], true) !== true) {
                return null;
            }
            $user = $this->container->get(AuthService::class)->currentUser();
            if ($user?->isAdministrator()) {
                return null;
            }
            return Response::redirect('/maintenance');
        } catch (Throwable) {
            return null;
        }
    }

    private function startInstallerSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('purple_parlor_session');
        $secure = strtolower((string) ($request->server['HTTPS'] ?? '')) === 'on'
            || (string) ($request->server['SERVER_PORT'] ?? '') === '443';
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if (!session_start()) {
            throw new \RuntimeException('Unable to start the protected installer session.');
        }
    }

    private function methodOverride(Request $request): Request
    {
        if ($request->method !== 'POST') {
            return $request;
        }
        $override = strtoupper((string) ($request->body['_method'] ?? ''));
        if (!in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $request;
        }
        return new Request(
            $override,
            $request->uri,
            $request->query,
            $request->body,
            $request->cookies,
            $request->files,
            $request->server,
            $request->headers,
            $request->rawBody,
        );
    }

    private function restoreRememberedSession(Request $request, SessionManager $sessions): ?string
    {
        if ($sessions->authenticatedUserId() !== null) {
            return null;
        }
        $remember = $request->cookies['purple_parlor_remember'] ?? null;
        if (!is_string($remember) || $remember === '') {
            return null;
        }
        $rotated = $sessions->consumeRememberToken(
            $remember,
            $request->clientIp(),
            substr((string) $request->header('user-agent', 'unknown'), 0, 500),
        );
        if ($rotated === null) {
            return self::cookie('purple_parlor_remember', '', time() - 3600, $request);
        }
        return self::cookie('purple_parlor_remember', $rotated, time() + 2592000, $request);
    }

    public static function cookie(string $name, string $value, int $expires, Request $request, string $sameSite = 'Lax'): string
    {
        $https = strtolower((string) ($request->server['HTTPS'] ?? '')) === 'on'
            || (string) ($request->server['SERVER_PORT'] ?? '') === '443';
        $parts = [rawurlencode($name) . '=' . rawurlencode($value), 'Path=/', 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires), 'HttpOnly', 'SameSite=' . $sameSite];
        if ($https) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }
}
