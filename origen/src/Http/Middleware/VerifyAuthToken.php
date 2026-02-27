<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\AuthTokenService;

class VerifyAuthToken implements MiddlewareInterface
{
    public function __construct(
        private AuthTokenService $authTokenService,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            // No token — let the controller decide (login page vs 401)
            return $next($request);
        }

        try {
            $claims = $this->authTokenService->validate($token);
        } catch (\Exception $e) {
            // Invalid/expired token — clear state, let controller handle
            return $next($request);
        }

        $request->merge([
            'auth_user' => $claims,
            'user_id' => $claims['user_id'],
            'tenant_id' => $claims['tenant_id'],
            'user_role' => $claims['role'],
        ]);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        // 1. Authorization: Bearer <token>
        $authHeader = $request->header('authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // 2. dashboard_token cookie
        $cookies = $this->parseCookies($request->header('cookie', ''));
        if (!empty($cookies['dashboard_token'])) {
            return $cookies['dashboard_token'];
        }

        return null;
    }

    private function parseCookies(string $cookieHeader): array
    {
        $cookies = [];
        foreach (explode(';', $cookieHeader) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $cookies;
    }
}
