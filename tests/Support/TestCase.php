<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Database\Database;
use App\Database\Schema;
use Database\Seeds\DatabaseSeeder;
use PDO;
use RuntimeException;

abstract class TestCase
{
    protected Database $database;
    protected Config $config;
    protected const KEY = 'base64:dGVzdC1rZXktMzItYnl0ZXMtbG9uZy0xMjM0NTY3ODkwMTI=';

    final public function runMethod(string $method): void
    {
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }
    }

    protected function setUp(): void
    {
        $this->database = new Database(new PDO('sqlite::memory:'));
        Schema::install($this->database);
        $permissions = require dirname(__DIR__, 2) . '/config/permissions.php';
        $games = require dirname(__DIR__, 2) . '/config/games.php';
        $this->config = new Config([
            'app' => [
                'name' => 'The Purple Parlor', 'env' => 'testing', 'debug' => false, 'url' => 'http://localhost', 'key' => self::KEY,
                'session' => ['cookie' => 'purple_test_' . bin2hex(random_bytes(4)), 'lifetime_minutes' => 120, 'secure' => false, 'same_site' => 'Lax', 'domain' => '', 'path' => '/', 'remember_days' => 30, 'privileged_minutes' => 15],
                'mail' => ['driver' => 'log', 'max_attempts' => 3], 'logging' => ['level' => 'debug', 'max_files' => 2], 'backup' => ['path' => '', 'retention_days' => 1],
            ],
            'permissions' => $permissions,
            'games' => $games,
            'payments' => [
                'enabled' => false, 'mode' => 'sandbox', 'provider' => 'demo', 'adult_owner_confirmed' => false, 'live_activation_lock' => true,
                'currency' => 'USD', 'webhook_retention_days' => 30, 'subscription_grace_days' => 7,
                'paypal' => ['enabled' => false, 'environment' => 'sandbox', 'client_id' => '', 'client_secret' => '', 'webhook_id' => '', 'plans' => []],
                'square' => ['enabled' => false, 'environment' => 'sandbox', 'application_id' => '', 'access_token' => '', 'location_id' => '', 'signature_key' => '', 'webhook_url' => '', 'api_version' => '2026-05-20', 'plans' => []],
            ],
            'membership' => [
                'daily_coin_bonuses' => ['cozy_club' => 1000, 'cozy_club_plus' => 2500],
            ],
        ]);
        require_once dirname(__DIR__, 2) . '/database/seeds/DatabaseSeeder.php';
        (new DatabaseSeeder($this->database, $this->config))->run();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false.'): void
    {
        if ($condition) throw new RuntimeException($message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) throw new RuntimeException($message !== '' ? $message : 'Values unexpectedly match.');
    }

    protected function expectException(string $class, callable $callback): void
    {
        try { $callback(); }
        catch (\Throwable $e) {
            if ($e instanceof $class) return;
            throw new RuntimeException('Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
        }
        throw new RuntimeException('Expected exception ' . $class . ' was not thrown.');
    }
}
