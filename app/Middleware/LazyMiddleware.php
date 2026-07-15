<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;
use RuntimeException;

/** Defers database-backed middleware construction until its route is matched. */
final class LazyMiddleware implements MiddlewareInterface
{
    private ?MiddlewareInterface $resolved = null;

    /** @param Closure():object $resolver */
    public function __construct(private readonly Closure $resolver)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $middleware = $this->resolved ??= ($this->resolver)();
        if (!$middleware instanceof MiddlewareInterface) {
            throw new RuntimeException('Lazy route middleware did not resolve to a middleware instance.');
        }
        return $middleware->process($request, $next);
    }
}
