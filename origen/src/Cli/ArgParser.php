<?php

namespace Origen\Cli;

class ArgParser
{
    private array $flags = [];
    private array $positional = [];

    public function __construct(array $args)
    {
        $i = 0;
        $count = count($args);

        while ($i < $count) {
            $arg = $args[$i];

            if (str_starts_with($arg, '--')) {
                if (str_contains($arg, '=')) {
                    [$key, $value] = explode('=', substr($arg, 2), 2);
                    $this->flags[$key] = $value;
                } elseif ($i + 1 < $count && !str_starts_with($args[$i + 1], '--')) {
                    $this->flags[substr($arg, 2)] = $args[$i + 1];
                    $i++;
                } else {
                    $this->flags[substr($arg, 2)] = '';
                }
            } else {
                $this->positional[] = $arg;
            }

            $i++;
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->flags[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->flags);
    }

    public function positional(): array
    {
        return $this->positional;
    }
}
