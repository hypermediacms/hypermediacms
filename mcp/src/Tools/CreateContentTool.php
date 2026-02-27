<?php
/**
 * Create Content Tool
 * 
 * Creates content entries via the Origen API.
 * Handles the full prepare → save flow.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class CreateContentTool implements ToolInterface
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
        return 'create_content';
    }

    public function getDescription(): string
    {
        return 'Create a new content entry. Handles authentication and saves to both database and flat files.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_type' => [
                    'type' => 'string',
                    'description' => 'The content type (e.g., "article", "thought", "product")'
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Content title'
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL slug (auto-generated from title if not provided)'
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Main content body (supports Markdown)'
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'review', 'published', 'archived'],
                    'description' => 'Publication status (default: draft)'
                ],
                'custom_fields' => [
                    'type' => 'object',
                    'description' => 'Additional fields defined in the schema (e.g., {"mood": "grateful", "category": "tech"})',
                    'additionalProperties' => true
                ]
            ],
            'required' => ['content_type', 'title']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $contentType = $arguments['content_type'] ?? '';
        $title = $arguments['title'] ?? '';
        $slug = $arguments['slug'] ?? $this->slugify($title);
        $body = $arguments['body'] ?? '';
        $status = $arguments['status'] ?? 'draft';
        $customFields = $arguments['custom_fields'] ?? [];

        if (empty($contentType)) {
            return ['error' => 'content_type is required'];
        }
        if (empty($title)) {
            return ['error' => 'title is required'];
        }

        // Step 1: Prepare (get action token)
        $prepareResult = $this->apiCall('POST', '/api/content/prepare', [
            'action' => 'save',
            'type' => $contentType
        ]);

        if (isset($prepareResult['error'])) {
            return [
                'error' => 'Prepare failed',
                'details' => $prepareResult['error']
            ];
        }

        // Extract the token from prepare response
        $payload = json_decode($prepareResult['data']['payload'] ?? '{}', true);
        $token = $payload['htx-token'] ?? null;

        if (!$token) {
            return ['error' => 'Failed to get action token'];
        }

        // Step 2: Save content
        $saveData = [
            'htx-recordId' => null,
            'htx-context' => 'save',
            'htx-token' => $token,
            'type' => $contentType,
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'status' => $status
        ];

        // Merge custom fields
        foreach ($customFields as $key => $value) {
            $saveData[$key] = $value;
        }

        $saveResult = $this->apiCall('POST', '/api/content/save', $saveData);

        if (isset($saveResult['error'])) {
            return [
                'error' => 'Save failed',
                'details' => $saveResult['error']
            ];
        }

        return [
            'success' => true,
            'content_type' => $contentType,
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'message' => "Content '{$title}' created successfully"
        ];
    }

    /**
     * Make an API call to Origen
     */
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

        // Try to parse as JSON, fall back to raw response
        $decoded = json_decode($response, true);
        return $decoded ?? ['raw' => $response];
    }

    /**
     * Generate a URL-safe slug from a title
     */
    private function slugify(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}
