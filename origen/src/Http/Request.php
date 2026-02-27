<?php

namespace Origen\Http;

class Request
{
    private string $method;
    private string $path;
    private array $headers;
    private array $query;
    private array $body;
    private array $attributes = [];

    public function __construct(string $method, string $path, array $headers = [], array $query = [], array $body = [])
    {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?? '/', '/');
        $this->headers = $headers;
        $this->query = $query;
        $this->body = $body;
    }

    public static function capture(): static
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $query = $_GET;

        // Parse headers from $_SERVER
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        // Parse body
        $body = $_POST;
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new static($method, $path, $headers, $query, $body);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $all = array_merge($this->query, $this->body, $this->attributes);
        if ($key === null) {
            return $all;
        }
        return $all[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->attributes);
    }

    public function body(): array
    {
        return $this->body;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function merge(array $data): void
    {
        $this->attributes = array_merge($this->attributes, $data);
    }
}
