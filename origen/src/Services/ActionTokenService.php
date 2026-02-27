<?php

namespace Origen\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ActionTokenService
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /**
     * Issue an action token with htx-* claims.
     *
     * @return array{token: string, jti: string}
     */
    public function issue(string $sub, int $tenantId, string $htxContext, ?string $htxRecordId = null): array
    {
        $jti = $this->generateUuid();

        $claims = [
            'sub' => $sub,
            'tenant_id' => $tenantId,
            'htx-context' => $htxContext,
            'htx-recordId' => $htxRecordId,
            'jti' => $jti,
            'iat' => time(),
            'exp' => time() + 300, // 5 minutes
        ];

        $token = JWT::encode($claims, $this->key, 'HS256');

        return ['token' => $token, 'jti' => $jti];
    }

    /**
     * Validate an action token and return decoded claims.
     *
     * @throws \Exception On invalid signature, expiry, or claim mismatch
     */
    public function validate(
        string $token,
        int $tenantId,
        string $expectedContext,
        ?string $expectedRecordId = null
    ): array {
        $decoded = (array) JWT::decode($token, new Key($this->key, 'HS256'));

        if (($decoded['tenant_id'] ?? null) !== $tenantId) {
            throw new \Exception('Tenant mismatch.');
        }

        if (($decoded['htx-context'] ?? null) !== $expectedContext) {
            throw new \Exception('Context mismatch.');
        }

        if ($expectedRecordId !== null && (string)($decoded['htx-recordId'] ?? '') !== (string)$expectedRecordId) {
            throw new \Exception('Record ID mismatch.');
        }

        return $decoded;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
