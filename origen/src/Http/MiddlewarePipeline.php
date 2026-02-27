<?php

namespace Origen\Http;

use Origen\Http\Middleware\MiddlewareInterface;

class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    public function pipe(MiddlewareInterface $middleware): static
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Run the pipeline: each middleware calls $next to proceed.
     */
    public function run(Request $request, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, MiddlewareInterface $middleware) {
                return function (Request $request) use ($middleware, $next): Response {
                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}
