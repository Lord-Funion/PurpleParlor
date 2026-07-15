<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public static function loadDirectory(string $directory): self
    {
        $items = [];
        if (!is_dir($directory)) {
            return new self();
        }
        $files = glob(rtrim($directory, '/\\') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($name === 'app.example' && is_file(dirname($file) . '/app.php')) {
                continue;
            }
            if ($name === 'app.example') {
                $name = 'app';
            }
            $value = require $file;
            if (!is_array($value)) {
                throw new InvalidArgumentException("Configuration file {$file} must return an array.");
            }
            $items[$name] = $value;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Required configuration value {$key} is missing.");
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor =& $this->items;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        $cursor = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
