<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Payments\SubscriptionReconciler;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

try {
    $result = Bootstrap::create($root)->get(SubscriptionReconciler::class)->reconcile(500);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['errors'] === [] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Subscription reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
