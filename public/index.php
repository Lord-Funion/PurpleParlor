<?php

declare(strict_types=1);

// Shared hosts can enable display_errors globally. Never expose warnings,
// absolute paths, or stack details in an HTTP response; the application logs
// diagnostics privately and renders branded error pages instead.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

define('BASE_PATH', dirname(__DIR__));

/** @var App\Http\Application $application */
$application = require BASE_PATH . '/app/bootstrap.php';
$application->handle(App\Core\Request::capture())->send();
