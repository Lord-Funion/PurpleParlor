<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';
require $root . '/tests/Support/TestCase.php';
ob_start();

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);
$before = get_declared_classes();
foreach ($files as $file) {
    require_once $file;
}
$classes = array_values(array_diff(get_declared_classes(), $before));
$passed = 0;
$failed = 0;
foreach ($classes as $class) {
    if (!is_subclass_of($class, Tests\Support\TestCase::class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $test = $reflection->newInstance();
        try {
            $test->runMethod($method->getName());
            echo '[PASS] ' . $class . '::' . $method->getName() . PHP_EOL;
            $passed++;
        } catch (Throwable $e) {
            echo '[FAIL] ' . $class . '::' . $method->getName() . ' — ' . $e->getMessage() . PHP_EOL;
            $failed++;
        }
    }
}
echo PHP_EOL . "{$passed} passed, {$failed} failed." . PHP_EOL;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
