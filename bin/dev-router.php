<?php

declare(strict_types=1);

// Router for PHP's local development server. Apache/cPanel uses public/.htaccess
// instead; this file simply lets local clean URLs coexist with real assets.
$public = str_replace('\\', '/', dirname(__DIR__) . '/public');
$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$candidate = realpath($public . '/' . ltrim($requestPath, '/'));

if ($requestPath !== '/'
    && $candidate !== false
    && is_file($candidate)
    && str_starts_with(str_replace('\\', '/', $candidate), $public . '/')) {
    return false;
}

require $public . '/index.php';
