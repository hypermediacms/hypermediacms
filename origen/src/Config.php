<?php

namespace Origen;

class Config
{
    private array $config = [];

    public function __construct(string $basePath)
    {
        $this->loadEnv($basePath . '/.env');
        $defaults = require $basePath . '/origen/config/origen.php';
        $this->config = $defaults;

        // Resolve relative paths against basePath (not the process cwd)
        foreach (['db_path', 'content_path', 'schema_path'] as $key) {
            if (isset($this->config[$key]) && str_starts_with($this->config[$key], './')) {
                $this->config[$key] = $basePath . substr($this->config[$key], 1);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }

    private function loadEnv(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Strip surrounding quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Convert boolean-like strings
            $lower = strtolower((string) $value);
            if ($lower === 'true') {
                $value = true;
            } elseif ($lower === 'false') {
                $value = false;
            }

            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}
