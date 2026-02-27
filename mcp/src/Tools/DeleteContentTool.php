<?php
/**
 * Delete Content Tool
 * 
 * Deletes content entries via the Origen API.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class DeleteContentTool implements ToolInterface
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
        return 'delete_content';
    }

    public function getDescription(): string
    {
        return 'Delete a content entry by ID or slug. Use with caution — this is permanent.';
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
                    'description' => 'Content ID to delete (use this or slug)'
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Content slug to delete (use this or id)'
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to confirm deletion'
                ]
            ],
            'required' => ['content_type', 'confirm']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $contentType = $arguments['content_type'] ?? '';
        $id = $arguments['id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $confirm = $arguments['confirm'] ?? false;

        if (empty($contentType)) {
            return ['error' => 'content_type is required'];
        }
        if (!$id && !$slug) {
            return ['error' => 'Either id or slug is required'];
        }
        if (!$confirm) {
            return [
                'error' => 'Deletion not confirmed',
                'message' => 'Set confirm: true to delete content. This action is permanent.'
            ];
        }

        // First, fetch the content to get the ID and verify it exists
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
            return [
                'error' => 'Content not found',
                'content_type' => $contentType,
                'id' => $id,
                'slug' => $slug
            ];
        }

        $content = $rows[0];
        $recordId = $content['id'];
        $title = $content['title'] ?? 'Untitled';

        // Prepare for delete
        $prepareResult = $this->apiCall('POST', '/api/content/prepare', [
            'action' => 'delete',
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

        // Execute delete
        $deleteResult = $this->apiCall('POST', '/api/content/delete', [
            'htx-recordId' => $recordId,
            'htx-context' => 'delete',
            'htx-token' => $token
        ]);

        if (isset($deleteResult['error'])) {
            return ['error' => 'Delete failed', 'details' => $deleteResult['error']];
        }

        return [
            'success' => true,
            'deleted' => [
                'id' => $recordId,
                'title' => $title,
                'content_type' => $contentType
            ],
            'message' => "Content '{$title}' has been deleted"
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
