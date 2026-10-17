<?php

declare(strict_types=1);

namespace TeamCodeHub\\Core;

use Closure;
use LogicException;

final class ModuleRegistry
{
    /** @var array<string, Closure(): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function register(string $id, callable $factory): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new LogicException('Module identifier cannot be empty.');
        }
        if (isset($this->factories[$id]) || isset($this->instances[$id])) {
            throw new LogicException('Module is already registered: ' . $id);
        }

        $this->factories[$id] = Closure::fromCallable($factory);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id] ?? null;
        if ($factory === null) {
            throw new LogicException('Unknown module: ' . $id);
        }

        $instance = $factory();
        if (!is_object($instance)) {
            throw new LogicException('Module factory must return an object: ' . $id);
        }

        $this->instances[$id] = $instance;
        return $instance;
    }

    /** @return list<string> */
    public function registeredIds(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->factories),
            array_keys($this->instances),
        )));
    }
}
