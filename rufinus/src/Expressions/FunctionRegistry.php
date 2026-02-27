<?php

namespace Rufinus\Expressions;

use Rufinus\Expressions\Exceptions\ExpressionParseException;
use Rufinus\Expressions\Functions\StringFunctions;
use Rufinus\Expressions\Functions\DateFunctions;
use Rufinus\Expressions\Functions\NumberFunctions;
use Rufinus\Expressions\Functions\ArrayFunctions;

class FunctionRegistry
{
    /** @var array<string, callable> */
    private array $functions = [];

    public function register(string $name, callable $handler): void
    {
        $this->functions[$name] = $handler;
    }

    public function call(string $name, array $args): mixed
    {
        if (!$this->has($name)) {
            throw new ExpressionParseException("Unknown function: {$name}");
        }

        return ($this->functions[$name])(...$args);
    }

    public function has(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    public function registerDefaults(): void
    {
        StringFunctions::register($this);
        DateFunctions::register($this);
        NumberFunctions::register($this);
        ArrayFunctions::register($this);
    }
}
