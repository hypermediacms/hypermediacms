<?php

namespace Rufinus\Runtime;

class AuthGuard
{
    private const COOKIE_NAME = 'htx_session';

    /**
     * Check if a path requires authentication.
     */
    public function requiresAuth(string $path): bool
    {
        $path = '/' . trim($path, '/');

        if (! str_starts_with($path, '/admin')) {
            return false;
        }

        // Login page is public
        if ($path === '/admin/login' || str_starts_with($path, '/admin/login/')) {
            return false;
        }

        return true;
    }

    /**
     * Read the auth token from the session cookie.
     */
    public function getToken(): ?string
    {
        return $_COOKIE[self::COOKIE_NAME] ?? null;
    }

    /**
     * Build a redirect response to the login page.
     */
    public function redirectToLogin(): Response
    {
        return new Response(302, '', [
            'Location' => '/admin/login',
        ]);
    }

    /**
     * Set the auth cookie on a response.
     */
    public function setAuthCookie(Response $response, string $token, int $maxAge = 86400): Response
    {
        return $response->withCookie(
            name: self::COOKIE_NAME,
            value: $token,
            maxAge: $maxAge,
            path: '/',
            secure: ($_SERVER['HTTPS'] ?? '') === 'on',
            httpOnly: true,
            sameSite: 'Lax'
        );
    }

    /**
     * Clear the auth cookie on a response (set expired).
     */
    public function clearAuthCookie(Response $response): Response
    {
        return $response->withCookie(
            name: self::COOKIE_NAME,
            value: '',
            maxAge: -1,
            path: '/',
            secure: ($_SERVER['HTTPS'] ?? '') === 'on',
            httpOnly: true,
            sameSite: 'Lax'
        );
    }
}
