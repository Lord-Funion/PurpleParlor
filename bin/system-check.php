<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Database\Database;
use App\Services\SystemHealthService;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

if (in_array('--generate-key', $argv, true)) {
    echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
    exit(0);
}

Env::load($root . '/.env');
$config = Config::loadDirectory($root . '/config');
try {
    $database = Database::connect($config);
} catch (Throwable) {
    $database = null;
}
$checks = (new SystemHealthService($config, $database, $root))->check();
$failed = false;
foreach ($checks as $name => $check) {
    $recommended = str_starts_with($name, 'recommended_');
    echo ($check['ok'] ? '[OK]   ' : ($recommended ? '[WARN] ' : '[FAIL] ')) . str_pad($name, 34) . $check['detail'] . PHP_EOL;
    if (!$check['ok'] && !$recommended) {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
