<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\AgeConfirmationService;

final class AgeConfirmedMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AgeConfirmationService $age, private readonly Config $config)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $version = (string) $this->config->get('app.legal_policy_version', 1);
        if ($this->age->validate(is_string($request->cookies['purple_parlor_age'] ?? null) ? $request->cookies['purple_parlor_age'] : null, $version)) {
            return $next($request->withAttribute('age_confirmed', true));
        }
        if ($request->expectsJson()) {
            return Response::json(['error' => 'Age confirmation is required.', 'confirmation_url' => '/age'], 403);
        }
        return Response::redirect('/age?return=' . rawurlencode($request->uri));
    }
}
