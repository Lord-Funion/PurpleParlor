<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

final class UrlGenerator
{
    /** @var array<string, string> */
    private const ALIASES = [
        'profile.settings' => 'settings.index',
        'profile.export' => 'account.export',
        'profile.delete' => 'account.delete',
        'billing.dashboard' => 'billing.index',
        'contact' => 'contact.index',
        'games.round' => 'api.games.round',
        'games.action' => 'api.games.action',
    ];
    /** @var array<string, string> */
    private static array $routes = [];
    private static string $baseUrl = '';

    /** @param list<array<mixed>> $manifest */
    public static function configure(array $manifest, string $baseUrl = ''): void
    {
        self::$baseUrl = rtrim($baseUrl, '/');
        self::$routes = [];
        foreach ($manifest as $route) {
            if (isset($route[1], $route[2]) && is_string($route[1]) && is_string($route[2])) {
                self::$routes[$route[2]] = $route[1];
            }
        }
    }

    /** @param array<string, scalar> $parameters */
    public static function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $name = self::ALIASES[$name] ?? $name;
        if (!isset(self::$routes[$name])) {
            throw new InvalidArgumentException("Unknown route name: {$name}");
        }
        $path = self::$routes[$name];
        foreach ($parameters as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (str_contains($path, $placeholder)) {
                $path = str_replace($placeholder, rawurlencode((string) $value), $path);
                unset($parameters[$key]);
            }
        }
        if (preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $path)) {
            throw new InvalidArgumentException("Missing parameter for route: {$name}");
        }
        if ($parameters !== []) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        return $absolute && self::$baseUrl !== '' ? self::$baseUrl . $path : $path;
    }
}
