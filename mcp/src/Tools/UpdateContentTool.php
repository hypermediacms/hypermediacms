<?php
/**
 * Update Content Tool
 * 
 * Updates existing content entries via the Origen API.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class UpdateContentTool implements ToolInterface
{
    private string $origenUrl;
    private string $siteKey;

    public function __construct(?string $origenUrl = null, ?string $siteKey = null)
    {
        $this->origenUrl = $origenUrl ?? 'http://127.0.0.1:8080';
        $this->siteKey = $siteKey ?? ($_ENV['SITE_KEY'] ?? 'htx-starter-key-001');
    }

    public function getName(): string
    {
        return 'update_content';
    }

    public function getDescription(): string
    {
        return 'Update an existing content entry by ID or slug.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_type' => [
                    'type' => 'string',
                    'description' => 'The content type'
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Content ID (use this or slug)'
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Content slug (use this or id)'
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New title (optional)'
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'New body content (optional)'
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'review', 'published', 'archived'],
                    'description' => 'New status (optional)'
                ],
                'custom_fields' => [
                    'type' => 'object',
                    'description' => 'Custom fields to update',
                    'additionalProperties' => true
                ]
            ],
            'required' => ['content_type']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $contentType = $arguments['content_type'] ?? '';
        $id = $arguments['id'] ?? null;
        $slug = $arguments['slug'] ?? null;

        if (empty($contentType)) {
            return ['error' => 'content_type is required'];
        }
        if (!$id && !$slug) {
            return ['error' => 'Either id or slug is required'];
        }

        // First, fetch the existing content
        $getResult = $this->apiCall('POST', '/api/content/get', [
            'type' => $contentType,
            'id' => $id,
            'slug' => $slug,
            'limit' => 1
        ]);

        if (isset($getResult['error'])) {
            return ['error' => 'Failed to fetch content', 'details' => $getResult['error']];
        }

        $rows = $getResult['rows'] ?? [];
        if (empty($rows)) {
            return ['error' => 'Content not found', 'id' => $id, 'slug' => $slug];
        }

        $existing = $rows[0];
        $recordId = $existing['id'];

        // Prepare for update
        $prepareResult = $this->apiCall('POST', '/api/content/prepare', [
            'action' => 'update',
            'type' => $contentType,
            'recordId' => $recordId
        ]);

        if (isset($prepareResult['error'])) {
            return ['error' => 'Prepare failed', 'details' => $prepareResult['error']];
        }

        $payload = json_decode($prepareResult['data']['payload'] ?? '{}', true);
        $token = $payload['htx-token'] ?? null;

        if (!$token) {
            return ['error' => 'Failed to get action token'];
        }

        // Build update data, merging with existing
        $updateData = [
            'htx-recordId' => $recordId,
            'htx-context' => 'update',
            'htx-token' => $token,
            'type' => $contentType,
            'title' => $arguments['title'] ?? $existing['title'],
            'slug' => $existing['slug'], // Don't change slug on update
            'body' => $arguments['body'] ?? $existing['body'],
            'status' => $arguments['status'] ?? $existing['status']
        ];

        // Merge custom fields
        $customFields = $arguments['custom_fields'] ?? [];
        foreach ($customFields as $key => $value) {
            $updateData[$key] = $value;
        }

        $updateResult = $this->apiCall('POST', '/api/content/update', $updateData);

        if (isset($updateResult['error'])) {
            return ['error' => 'Update failed', 'details' => $updateResult['error']];
        }

        return [
            'success' => true,
            'id' => $recordId,
            'content_type' => $contentType,
            'title' => $updateData['title'],
            'message' => "Content updated successfully"
        ];
    }

    private function apiCall(string $method, string $endpoint, array $data): array
    {
        $url = $this->origenUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Site-Key: ' . $this->siteKey,
                'X-HTX-Version: 1'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => "cURL error: {$error}"];
        }

        if ($httpCode >= 400) {
            return ['error' => "HTTP {$httpCode}: {$response}"];
        }

        $decoded = json_decode($response, true);
        return $decoded ?? ['raw' => $response];
    }
}
