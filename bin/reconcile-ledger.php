<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Services\VirtualLedgerService;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

try {
    $userId = null;
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--user=')) {
            $userId = filter_var(substr($argument, 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        }
    }
    $report = Bootstrap::create($root)->get(VirtualLedgerService::class)->reconcile($userId);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report['mismatches'] === [] && $report['integrity_errors'] === [] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ledger reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
