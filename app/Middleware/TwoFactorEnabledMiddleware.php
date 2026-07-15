<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Core\Request;
use App\Core\Response;

final class TwoFactorEnabledMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
        private readonly SessionManager $sessions,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        if (!$this->twoFactor->enabled($user->id)) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Administrator two-factor authentication is required.'], 403)
                : Response::redirect('/settings/two-factor');
        }
        if (!$this->sessions->twoFactorVerified()) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Two-factor confirmation is required for this browser session.'], 401)
                : Response::redirect('/confirm-password?return=' . rawurlencode($request->uri));
        }
        return $next($request);
    }
}
