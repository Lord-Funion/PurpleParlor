<?php

declare(strict_types=1);

$composer = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => dirname(__DIR__) . '/',
        'PurpleParlor\\Games\\' => dirname(__DIR__) . '/Games/',
        'Database\\Seeds\\' => dirname(__DIR__, 2) . '/database/seeds/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});

require_once __DIR__ . '/helpers.php';
