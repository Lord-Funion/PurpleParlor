<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Services\DatabaseBackupService;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

try {
    $result = Bootstrap::create($root)->get(DatabaseBackupService::class)->create();
    echo 'Backup: ' . $result['path'] . PHP_EOL;
    echo 'SHA-256: ' . $result['checksum'] . PHP_EOL;
    echo 'Bytes: ' . $result['size'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed safely: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
