<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path, bool $override = false): void
    {
        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read the environment file.');
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException('Malformed environment entry on line ' . ($lineNumber + 1));
            }
            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException('Invalid environment key on line ' . ($lineNumber + 1));
            }
            if (!$override && (array_key_exists($key, $_ENV) || getenv($key) !== false)) {
                continue;
            }
            $value = self::parseValue(trim(substr($line, $separator + 1)));
            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function require(string $key): string
    {
        $value = trim((string) self::get($key, ''));
        if ($value === '') {
            throw new RuntimeException("Required environment value {$key} is missing.");
        }
        return $value;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    private static function parseValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                $inner = substr($value, 1, -1);
                return $quote === '"' ? stripcslashes($inner) : $inner;
            }
        }
        $comment = preg_split('/\s+#/', $value, 2);
        return trim($comment[0] ?? '');
    }
}
