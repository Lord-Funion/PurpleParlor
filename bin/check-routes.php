<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

$routes = require $root . '/config/routes.php';
$errors = [];
$names = [];
$signatures = [];
$webhookNames = ['webhooks.paypal', 'webhooks.square'];

foreach ($routes as $index => $route) {
    if (!is_array($route) || count($route) < 4) {
        $errors[] = "Route {$index} is malformed.";
        continue;
    }
    [$method, $path, $name, $handler] = $route;
    $middleware = $route[4] ?? [];
    $method = strtoupper((string) $method);
    $signature = $method . ' ' . $path;
    if (isset($signatures[$signature])) {
        $errors[] = "Duplicate route signature {$signature}.";
    }
    $signatures[$signature] = true;
    if (isset($names[$name])) {
        $errors[] = "Duplicate route name {$name}.";
    }
    $names[$name] = true;

    $class = 'App\\Controllers\\' . ($handler[0] ?? '');
    $action = (string) ($handler[1] ?? '');
    if (!class_exists($class) || !method_exists($class, $action)) {
        $errors[] = "Missing handler {$class}::{$action} for {$signature}.";
    }
    if ($method !== 'GET' && !in_array($name, $webhookNames, true) && !in_array('csrf', $middleware, true)) {
        $errors[] = "State-changing route {$name} is missing CSRF middleware.";
    }

    if (!str_contains((string) $path, '{')) {
        continue;
    }
    $quoted = preg_quote((string) $path, '#');
    $pattern = preg_replace('#\\\\\{[A-Za-z_][A-Za-z0-9_]*\\\\\}#', '[^/]+', $quoted);
    if (!is_string($pattern)) {
        $errors[] = "Could not compile route pattern {$path}.";
        continue;
    }
    foreach (array_slice($routes, $index + 1) as $later) {
        if (strtoupper((string) ($later[0] ?? '')) !== $method || str_contains((string) ($later[1] ?? ''), '{')) {
            continue;
        }
        if (preg_match('#^' . $pattern . '/?$#', (string) $later[1]) === 1) {
            $errors[] = "Dynamic route {$signature} shadows later static route {$later[0]} {$later[1]}.";
        }
    }
}

$references = 0;
$viewIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/resources', FilesystemIterator::SKIP_DOTS),
);
foreach ($viewIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if (!is_string($contents)) {
        $errors[] = 'Could not read ' . $file->getPathname() . '.';
        continue;
    }
    preg_match_all('/\\broute\\(\\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches);
    foreach ($matches[1] ?? [] as $routeName) {
        ++$references;
        if (!isset($names[$routeName])) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $errors[] = "Unknown route name {$routeName} referenced by {$relative}.";
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '[FAIL] ' . $error . PHP_EOL);
    }
    fwrite(STDERR, count($errors) . " route contract error(s)." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '[OK] ' . count($routes) . " routes, " . count($names) . " unique names, and {$references} view references checked." . PHP_EOL);
