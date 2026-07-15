<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';

if (!is_file($envPath)) {
    fwrite(STDERR, "Create .env from .env.example before generating a key.\n");
    exit(1);
}

$contents = file_get_contents($envPath);
if ($contents === false) {
    fwrite(STDERR, "The private .env file could not be read.\n");
    exit(1);
}

$key = 'base64:' . base64_encode(random_bytes(32));
if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
    $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1);
} else {
    $updated = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
}

if (!is_string($updated) || file_put_contents($envPath, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "The private .env file could not be updated.\n");
    exit(1);
}

@chmod($envPath, 0600);
fwrite(STDOUT, "A new application key was written to .env. Keep that file private.\n");

