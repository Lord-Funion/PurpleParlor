<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthService;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Authentication required.'], 401)
                : Response::redirect('/login?return=' . rawurlencode($request->uri));
        }
        $response = $next($request->withAttribute('user', $user)->withAttribute('user_id', $user->id));
        if ($request->method === 'GET' && $response->status < 400 && session_status() === PHP_SESSION_ACTIVE) {
            $stored = $_SESSION['_csrf']['default']['token'] ?? null;
            if (is_string($stored) && preg_match('/^[a-f0-9]{64}$/', $stored) === 1) {
                $cookie = rawurlencode('__Host-purple_parlor_csrf') . '=' . rawurlencode($stored)
                    . '; Path=/; Max-Age=7200; Expires=' . gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 7200)
                    . '; Secure; HttpOnly; SameSite=Strict';
                return $response->withAddedHeader('Set-Cookie', $cookie);
            }
        }
        return $response;
    }
}
