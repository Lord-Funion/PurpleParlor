<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Database\Database;
use App\Database\Migrator;

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';

try {
    Env::load($root . '/.env');
    $config = Config::loadDirectory($root . '/config');
    $database = Database::connect($config);
    $applied = (new Migrator($database, $root . '/database/migrations'))->migrate();
    echo $applied === [] ? "Database is already current.\n" : "Applied migrations:\n - " . implode("\n - ", $applied) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
