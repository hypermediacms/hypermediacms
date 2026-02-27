<?php

namespace Origen\Http;

class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function addRoute(string $method, string $path, array|callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => '/' . ltrim($path, '/'),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Match a request method+path to a registered route.
     *
     * @return array{handler: array|callable, params: array, middleware: array}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);
            if ($params !== null) {
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        return null;
    }

    /**
     * Match a route pattern against a request path.
     * Supports {param} segments.
     *
     * @return array|null Extracted params or null if no match
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($patternSegments as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $paramName = substr($segment, 1, -1);
                $params[$paramName] = urldecode($pathSegments[$i]);
            } elseif ($segment !== $pathSegments[$i]) {
                return null;
            }
        }

        return $params;
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
