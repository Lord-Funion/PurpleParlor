<?php

declare(strict_types=1);

use App\Auth\AuthorizationService;
use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Container;
use App\Core\ExceptionHandler;
use App\Core\Router;
use App\Http\Application;
use App\Http\GuestWallet;
use App\Http\UrlGenerator;
use App\Http\View;
use App\Middleware\AgeConfirmedMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\LazyMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ReauthenticationMiddleware;
use App\Middleware\RequirePermissionMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\TwoFactorEnabledMiddleware;
use App\Security\RateLimiter;
use PurpleParlor\Games\GameRegistry;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once $root . '/app/Support/autoload.php';
    require_once $root . '/app/Support/helpers.php';
}
require_once $root . '/app/Http/web_helpers.php';

/** @var Container $container */
$container = Bootstrap::create($root);
$config = $container->get(Config::class);
$manifest = require $root . '/config/routes.php';
UrlGenerator::configure($manifest, (string) $config->get('app.url', ''));

$container->singleton(View::class, new View($root . '/resources/views'));
$container->singleton(GuestWallet::class, new GuestWallet());
$container->singleton(GameRegistry::class, static fn () => GameRegistry::fromConfigFile($root . '/config/games.php'));
$container->singleton(SecurityHeadersMiddleware::class, static fn (Container $c) => new SecurityHeadersMiddleware($c->get(Config::class)));

$ratePolicies = [
    'age' => [20, 3600], 'api' => [180, 60], 'game' => [120, 60], 'reward' => [10, 3600],
    'login' => [20, 900], 'registration' => [8, 3600], 'verification' => [10, 3600],
    'password-reset' => [8, 3600], 'payment' => [15, 300], 'webhook' => [300, 60],
    'contact' => [8, 3600], 'install' => [10, 3600],
];

$middleware = static function (string $alias) use ($container, $ratePolicies): object {
    if ($alias === 'auth') {
        return new LazyMiddleware(static fn (): object => $container->get(AuthMiddleware::class));
    }
    if ($alias === 'guest') {
        return new LazyMiddleware(static fn (): object => $container->get(GuestMiddleware::class));
    }
    if ($alias === 'csrf') {
        return new LazyMiddleware(static fn (): object => $container->get(CsrfMiddleware::class));
    }
    if ($alias === 'age') {
        return new LazyMiddleware(static fn (): object => $container->get(AgeConfirmedMiddleware::class));
    }
    if ($alias === '2fa') {
        return new LazyMiddleware(static fn (): object => $container->get(TwoFactorEnabledMiddleware::class));
    }
    if (str_starts_with($alias, 'can:')) {
        $permission = substr($alias, 4);
        return new LazyMiddleware(static fn (): object => new RequirePermissionMiddleware($container->get(AuthorizationService::class), $permission));
    }
    if (str_starts_with($alias, 'rate:')) {
        $action = substr($alias, 5);
        [$maximum, $window] = $ratePolicies[$action] ?? [60, 60];
        return new LazyMiddleware(static fn (): object => new RateLimitMiddleware($container->get(RateLimiter::class), $action, $maximum, $window));
    }
    if ($alias === 'reauth' || str_starts_with($alias, 'reauth:')) {
        $role = str_contains($alias, ':') ? substr($alias, strpos($alias, ':') + 1) : null;
        return new LazyMiddleware(static fn (): object => new ReauthenticationMiddleware($container->get(SessionManager::class), $role ?: null));
    }
    throw new RuntimeException('Unknown route middleware alias: ' . $alias);
};

$router = $container->get(Router::class);
foreach ($manifest as $definition) {
    [$method, $path, $name, $handler] = $definition;
    $aliases = isset($definition[4]) && is_array($definition[4]) ? $definition[4] : [];
    if (is_array($handler) && is_string($handler[0] ?? null) && !str_contains($handler[0], '\\')) {
        $handler[0] = 'App\\Controllers\\' . $handler[0];
    }
    $instances = array_map($middleware, $aliases);
    $router->add($method, $path, $handler, $instances);
}

return new Application(
    $container,
    $router,
    $container->get(ExceptionHandler::class),
    $container->get(SecurityHeadersMiddleware::class),
);
