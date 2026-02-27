<?php

namespace Origen\Http;

use Origen\Container;
use Origen\Exceptions\HttpException;
use Origen\Http\Middleware\MiddlewareInterface;

class Kernel
{
    public function __construct(
        private Router $router,
        private Container $container,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method(), $request->path());

            if (!$match) {
                return Response::json(['error' => 'Not found.'], 404);
            }

            // Set route params as request attributes
            foreach ($match['params'] as $key => $value) {
                $request->setAttribute($key, $value);
            }

            // Build middleware pipeline
            $pipeline = new MiddlewarePipeline();
            foreach ($match['middleware'] as $middlewareClass) {
                $middleware = $this->container->make($middlewareClass);
                if ($middleware instanceof MiddlewareInterface) {
                    $pipeline->pipe($middleware);
                }
            }

            // Build the controller/handler destination
            $destination = function (Request $request) use ($match): Response {
                return $this->dispatch($match['handler'], $request);
            };

            return $pipeline->run($request, $destination);
        } catch (HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            $debug = $this->container->has(\Origen\Config::class)
                ? $this->container->make(\Origen\Config::class)->get('debug', false)
                : false;

            $body = ['error' => 'Internal server error.'];
            if ($debug) {
                $body['exception'] = $e->getMessage();
                $body['trace'] = $e->getTraceAsString();
            }

            return Response::json($body, 500);
        }
    }

    private function dispatch(array|callable $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
        } else {
            [$class, $method] = $handler;
            $controller = $this->container->make($class);
            $result = $controller->$method($request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        return Response::json($result);
    }
}
