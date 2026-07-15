<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Services\CronDispatcher;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

try {
    $result = Bootstrap::create($root)->get(CronDispatcher::class)->run();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['status'] === 'completed' || $result['status'] === 'skipped' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cron failed; inspect storage/logs. Reference: ' . \App\Core\RequestContext::id() . PHP_EOL);
    exit(1);
}
