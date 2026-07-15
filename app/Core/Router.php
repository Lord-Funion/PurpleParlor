<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareInterface;
use App\Http\ErrorPage;
use RuntimeException;

final class Router
{
    /** @var list<array{methods:list<string>,pattern:string,handler:callable|array|string,middleware:list<mixed>}> */
    private array $routes = [];

    public function __construct(private readonly ?Container $container = null)
    {
    }

    public function add(string|array $methods, string $path, callable|array|string $handler, array $middleware = []): self
    {
        $methods = array_map('strtoupper', (array) $methods);
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $quoted = preg_quote($path, '#');
        $pattern = preg_replace('#\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);
        $this->routes[] = ['methods' => $methods, 'pattern' => '#^' . $pattern . '/?$#', 'handler' => $handler, 'middleware' => $middleware];
        return $this;
    }

    public function get(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->uri, $matches)) {
                continue;
            }
            $allowed = array_merge($allowed, $route['methods']);
            if (!in_array($request->method, $route['methods'], true)) {
                continue;
            }
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $decoded = rawurldecode($value);
                    if (str_contains($decoded, '/') || str_contains($decoded, '\\') || str_contains($decoded, "\0")) {
                        continue 2;
                    }
                    $request = $request->withAttribute($key, $decoded);
                }
            }
            $handler = $this->resolveCallable($route['handler']);
            $next = static fn (Request $req): Response => self::normalize($handler($req));
            foreach (array_reverse($route['middleware']) as $middleware) {
                $instance = is_string($middleware) ? $this->container?->get($middleware) : $middleware;
                if (!$instance instanceof MiddlewareInterface) {
                    throw new RuntimeException('Route middleware must implement MiddlewareInterface.');
                }
                $previous = $next;
                $next = static fn (Request $req): Response => $instance->process($req, $previous);
            }
            return $next($request);
        }
        if ($allowed !== []) {
            return $request->expectsJson()
                ? Response::json(['error' => 'Method not allowed.'], 405, ['Allow' => implode(', ', array_unique($allowed))])
                : ErrorPage::response(405, null, ['Allow' => implode(', ', array_unique($allowed))]);
        }
        return $request->expectsJson()
            ? Response::json(['error' => 'Not found.'], 404)
            : ErrorPage::response(404);
    }

    private function resolveCallable(callable|array|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            return [$this->container?->get($class) ?? new $class(), $method];
        }
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            return [$this->container?->get($handler[0]) ?? new $handler[0](), $handler[1]];
        }
        throw new RuntimeException('Route handler is not callable.');
    }

    private static function normalize(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_array($result) => Response::json($result),
            is_string($result) => new Response($result, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            default => new Response('', 204),
        };
    }
}
