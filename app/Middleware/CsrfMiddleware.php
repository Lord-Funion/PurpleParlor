<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\Csrf;
use App\Http\ErrorPage;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf, private readonly string $scope = 'default')
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if ($request->method === 'GET') {
            $response = $next($request);
            if ($response->status < 400 && session_status() === PHP_SESSION_ACTIVE) {
                $stored = $_SESSION['_csrf'][$this->scope]['token'] ?? null;
                $token = is_string($stored) && preg_match('/^[a-f0-9]{64}$/', $stored) === 1
                    ? $stored
                    : $this->csrf->token($this->scope);
                $cookie = rawurlencode('__Host-purple_parlor_csrf') . '=' . rawurlencode($token)
                    . '; Path=/; Max-Age=7200; Expires=' . gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 7200)
                    . '; Secure; HttpOnly; SameSite=Strict';
                return $response->withAddedHeader('Set-Cookie', $cookie);
            }
            return $response;
        }
        $token = $request->header('x-csrf-token') ?? (is_string($request->body['_csrf'] ?? null) ? $request->body['_csrf'] : null);
        $sessionValid = $this->csrf->validate($token, $this->scope);
        $cookieToken = $request->cookies['__Host-purple_parlor_csrf'] ?? null;
        $doubleSubmitValid = is_string($token)
            && preg_match('/^[a-f0-9]{64}$/', $token) === 1
            && is_string($cookieToken)
            && hash_equals($cookieToken, $token);
        $sameOriginPasswordConfirmation = $request->uri === '/confirm-password'
            && $this->isSameOrigin($request);
        if (!$sessionValid && !$doubleSubmitValid && !$sameOriginPasswordConfirmation) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Your session token expired. Refresh and try again.'], 419)
                : ErrorPage::response(419);
        }
        return $next($request);
    }

    private function isSameOrigin(Request $request): bool
    {
        $expected = parse_url(trim((string) (function_exists('env') ? env('APP_URL', '') : '')));
        if (!is_array($expected) || !isset($expected['scheme'], $expected['host'])) {
            return false;
        }
        $source = trim((string) ($request->header('origin') ?: $request->header('referer', '')));
        $actual = parse_url($source);
        if (!is_array($actual) || !isset($actual['scheme'], $actual['host'])) {
            return false;
        }
        $expectedPort = (int) ($expected['port'] ?? (strtolower((string) $expected['scheme']) === 'https' ? 443 : 80));
        $actualPort = (int) ($actual['port'] ?? (strtolower((string) $actual['scheme']) === 'https' ? 443 : 80));
        return hash_equals(strtolower((string) $expected['scheme']), strtolower((string) $actual['scheme']))
            && hash_equals(strtolower((string) $expected['host']), strtolower((string) $actual['host']))
            && $expectedPort === $actualPort;
    }
}
