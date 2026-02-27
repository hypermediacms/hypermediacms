<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
