<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\RateLimiter;
use App\Http\ErrorPage;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $action,
        private readonly int $maximum,
        private readonly int $windowSeconds,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $subject = (string) ($request->attribute('user_id') ?? $request->clientIp());
        if ($this->action === 'registration') {
            // Keep a generous network-wide ceiling for automated floods, then
            // apply the normal limit to an IP/email pair. This prevents a
            // GoDaddy proxy or shared network from locking out every visitor
            // after a handful of unrelated registration attempts.
            $network = $this->limiter->hit(
                'registration_network',
                $request->clientIp(),
                max(30, $this->maximum * 10),
                $this->windowSeconds,
            );
            if (!$network['allowed']) {
                return $this->limited($request, $network['retry_after']);
            }
            $email = strtolower(trim((string) $request->input('email')));
            $subject = $request->clientIp() . '|' . hash('sha256', $email);
            $result = $this->limiter->hit('registration_identity', $subject, $this->maximum, $this->windowSeconds);
        } else {
            $result = $this->limiter->hit($this->action, $subject, $this->maximum, $this->windowSeconds);
        }
        if (!$result['allowed']) {
            return $this->limited($request, $result['retry_after']);
        }
        return $next($request)->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }

    private function limited(Request $request, int $retryAfter): Response
    {
        return $request->expectsJson()
            ? Response::json(['error' => 'Too many requests. Please try again later.'], 429, ['Retry-After' => (string) $retryAfter])
            : ErrorPage::response(429, null, ['Retry-After' => (string) $retryAfter]);
    }
}
