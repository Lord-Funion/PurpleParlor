<?php

declare(strict_types=1);

use App\Http\UrlGenerator;

if (!function_exists('route')) {
    /** @param array<string, scalar> $parameters */
    function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        return UrlGenerator::route($name, $parameters, $absolute);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        return '/assets/' . $path;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $token = (new \App\Security\Csrf())->token();
        // A __Host- cookie cannot be scoped to a parent domain or non-root
        // path. It provides a hardened double-submit fallback for shared hosts
        // that unexpectedly replace PHP session identifiers between requests.
        if ($token !== '' && !headers_sent()) {
            setcookie('__Host-purple_parlor_csrf', $token, [
                'expires' => time() + 7200,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        return $token;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }
}
