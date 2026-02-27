<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;

class EnforceHtxVersion implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $version = $request->header('x-htx-version');

        if (!$version) {
            return Response::json(['error' => 'X-HTX-Version header is required.'], 400);
        }

        if ($version !== '1') {
            return Response::json([
                'error' => "Unsupported HTX version: {$version}. This server supports version 1.",
            ], 400);
        }

        return $next($request);
    }
}
