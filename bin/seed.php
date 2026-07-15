<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Database\Database;
use Database\Seeds\DatabaseSeeder;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';
require_once $root . '/database/seeds/DatabaseSeeder.php';

try {
    Env::load($root . '/.env');
    $config = Config::loadDirectory($root . '/config');
    $counts = (new DatabaseSeeder(Database::connect($config), $config))->run();
    echo "Seed complete (new rows):\n";
    foreach ($counts as $group => $count) {
        echo ' - ' . $group . ': ' . $count . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
