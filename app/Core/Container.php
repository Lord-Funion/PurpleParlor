<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $bindings = [];
    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function singleton(string $id, object $value): void
    {
        if ($value instanceof Closure) {
            $this->bindings[$id] = function (self $container) use ($id, $value): mixed {
                return $this->instances[$id] ??= $value($container);
            };
            return;
        }
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }
        return $this->autowire($id);
    }

    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException("No container binding exists for {$id}.");
        }
        $reflection = new ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("{$id} is not instantiable.");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new RuntimeException("Cannot resolve {$id}::\${$parameter->getName()}.");
            }
        }
        return $reflection->newInstanceArgs($arguments);
    }
}
