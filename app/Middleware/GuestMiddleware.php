<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthService;
use App\Core\Request;
use App\Core\Response;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $this->auth->currentUser() === null ? $next($request) : Response::redirect('/profile');
    }
}
