<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Support/autoload.php';
require $root . '/tests/Support/TestCase.php';
require $root . '/tests/Admin/AdminControllerTest.php';
require $root . '/tests/Admin/AdminMutationServiceTest.php';
require $root . '/tests/Admin/ManagedContentServiceTest.php';

$failed = 0;
foreach ([Tests\Admin\AdminControllerTest::class, Tests\Admin\AdminMutationServiceTest::class, Tests\Admin\ManagedContentServiceTest::class] as $class) {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        try {
            $reflection->newInstance()->runMethod($method->getName());
            echo '[PASS] ' . $reflection->getShortName() . '::' . $method->getName() . PHP_EOL;
        } catch (Throwable $exception) {
            echo '[FAIL] ' . $reflection->getShortName() . '::' . $method->getName() . ': ' . $exception->getMessage() . PHP_EOL;
            $failed++;
        }
    }
}
exit($failed === 0 ? 0 : 1);
