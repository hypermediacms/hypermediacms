<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Services;

/**
 * HTTP client for communicating with Origen API.
 */
class OrigenClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $siteKey,
        private readonly string $htxVersion = '1'
    ) {}

    /**
     * Query content from Origen.
     */
    public function query(array $params): array
    {
        return $this->post('/api/content/get', $params);
    }

    /**
     * Get a single content item by ID or slug.
     */
    public function get(string $type, string|int $idOrSlug): ?array
    {
        $params = ['type' => $type];
        
        if (is_numeric($idOrSlug)) {
            $params['id'] = (int) $idOrSlug;
        } else {
            $params['slug'] = $idOrSlug;
        }

        $result = $this->post('/api/content/get', $params);
        
        return $result['rows'][0] ?? null;
    }

    /**
     * Prepare a mutation (get token).
     */
    public function prepare(string $action, string $type, ?int $recordId = null): array
    {
        $meta = [
            'type' => $type,
            'action' => $action,
        ];

        if ($recordId !== null) {
            $meta['recordId'] = (string) $recordId;
        }

        return $this->post('/api/content/prepare', ['meta' => $meta]);
    }

    /**
     * Execute a save operation.
     */
    public function save(string $token, string $context, array $data): array
    {
        $payload = array_merge($data, [
            'htx-token' => $token,
            'htx-context' => $context,
            'htx-recordId' => null,
        ]);

        return $this->post('/api/content/save', $payload);
    }

    /**
     * Execute an update operation.
     */
    public function update(string $token, string $context, int $recordId, array $data): array
    {
        $payload = array_merge($data, [
            'htx-token' => $token,
            'htx-context' => $context,
            'htx-recordId' => $recordId,
        ]);

        return $this->post('/api/content/update', $payload);
    }

    /**
     * Execute a delete operation.
     */
    public function delete(string $token, string $context, int $recordId): array
    {
        $payload = [
            'htx-token' => $token,
            'htx-context' => $context,
            'htx-recordId' => $recordId,
        ];

        return $this->post('/api/content/delete', $payload);
    }

    /**
     * Get available content types.
     */
    public function getContentTypes(): array
    {
        return $this->post('/api/types', []);
    }

    /**
     * Make a POST request to Origen.
     */
    private function post(string $endpoint, array $data): array
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'X-Site-Key: ' . $this->siteKey,
            'X-HTX-Version: ' . $this->htxVersion,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Origen API error: {$error}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response from Origen: {$response}");
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException("Origen error: {$decoded['error']}");
        }

        return $decoded;
    }

    /**
     * Extract token from prepare response.
     */
    public static function extractToken(array $prepareResponse): array
    {
        $payloadStr = $prepareResponse['data']['payload'] ?? '{}';
        $payload = json_decode($payloadStr, true);

        return [
            'token' => $payload['htx-token'] ?? null,
            'context' => $payload['htx-context'] ?? null,
        ];
    }
}
