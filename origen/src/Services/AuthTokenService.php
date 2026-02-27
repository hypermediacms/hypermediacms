<?php

namespace Origen\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthTokenService
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /**
     * Issue an auth JWT for a logged-in user on a specific site.
     *
     * @param array $user User record
     * @param array $site Site record
     * @param string $role Membership role
     * @return string Encoded JWT
     */
    public function issue(array $user, array $site, string $role): string
    {
        $claims = [
            'sub' => 'user:' . $user['id'],
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'tenant_id' => $site['id'],
            'role' => $role,
            'type' => 'auth',
            'iat' => time(),
            'exp' => time() + 86400, // 24 hours
        ];

        return JWT::encode($claims, $this->key, 'HS256');
    }

    /**
     * Validate an auth JWT and return decoded claims.
     *
     * @throws \Exception On invalid signature, expiry, or wrong token type
     */
    public function validate(string $token): array
    {
        $decoded = (array) JWT::decode($token, new Key($this->key, 'HS256'));

        if (($decoded['type'] ?? null) !== 'auth') {
            throw new \Exception('Invalid token type.');
        }

        return $decoded;
    }
}
