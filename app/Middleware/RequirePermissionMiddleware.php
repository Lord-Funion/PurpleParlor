<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthorizationService;
use App\Core\Request;
use App\Core\Response;

final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly string $permission)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $userId = $request->attribute('user_id');
        if (!$this->authorization->can(is_numeric($userId) ? (int) $userId : null, $this->permission)) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Forbidden.'], 403)
                : new Response('<h1>Access denied</h1>', 403, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        return $next($request);
    }
}
