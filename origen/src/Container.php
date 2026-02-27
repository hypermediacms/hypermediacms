<?php

namespace Origen;

class Container
{
    private array $bindings = [];
    private array $singletons = [];
    private array $instances = [];

    private static ?Container $instance = null;

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public static function setInstance(Container $container): void
    {
        static::$instance = $container;
    }

    public function bind(string $abstract, callable|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        $this->singletons[$abstract] = $concrete;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function make(string $abstract): mixed
    {
        // Check for pre-set instances
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Check for singletons (already resolved)
        if (isset($this->singletons[$abstract]) && isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve singleton
        if (isset($this->singletons[$abstract])) {
            $concrete = $this->singletons[$abstract];
            $resolved = is_callable($concrete) ? $concrete($this) : $this->build($concrete);
            $this->instances[$abstract] = $resolved;
            return $resolved;
        }

        // Resolve binding
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            return is_callable($concrete) ? $concrete($this) : $this->build($concrete);
        }

        // Auto-wire
        return $this->build($abstract);
    }

    public function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Class {$class} does not exist.");
        }

        $reflector = new \ReflectionClass($class);
        $constructor = $reflector->getConstructor();

        if (!$constructor) {
            return new $class();
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $params[] = $this->make($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException(
                    "Cannot resolve parameter \${$param->getName()} for {$class}."
                );
            }
        }

        return $reflector->newInstanceArgs($params);
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract]);
    }
}
