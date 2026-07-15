<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\SessionManager;
use App\Core\Request;
use App\Core\Response;

final class ReauthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $sessions, private readonly ?string $requiredRole = null)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        $userId = is_object($user) ? (int) ($user->id ?? 0) : 0;
        $proof = $request->cookies['__Host-purple_parlor_privileged'] ?? null;
        $userAgent = substr((string) $request->header('user-agent', 'unknown'), 0, 500);
        if (!$this->sessions->privileged() && !$this->sessions->validatePrivilegedProof(is_string($proof) ? $proof : null, $userId, $userAgent)) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Recent reauthentication is required.'], 401)
                : Response::redirect('/confirm-password?return=' . rawurlencode($request->uri));
        }
        if ($this->requiredRole !== null) {
            if (!is_object($user) || !in_array($this->requiredRole, (array) ($user->roles ?? []), true)) {
                return Response::json(['error' => 'This action requires the Adult Owner.'], 403);
            }
        }
        return $next($request);
    }
}
