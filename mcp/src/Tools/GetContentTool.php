<?php
/**
 * Get Content Tool
 * 
 * Fetches content entries from Origen API.
 * Supports listing, filtering, and searching.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class GetContentTool implements ToolInterface
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
        return 'get_content';
    }

    public function getDescription(): string
    {
        return 'Fetch content entries. List all, filter by type, or get a specific item by ID/slug.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_type' => [
                    'type' => 'string',
                    'description' => 'Filter by content type (e.g., "article", "recipe")'
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Get specific content by ID'
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Get specific content by slug'
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'review', 'published', 'archived'],
                    'description' => 'Filter by status'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results (default: 20)'
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Number of results to skip (for pagination)'
                ],
                'order' => [
                    'type' => 'string',
                    'enum' => ['recent', 'oldest', 'title', 'updated'],
                    'description' => 'Sort order (default: recent)'
                ],
                'include_body' => [
                    'type' => 'boolean',
                    'description' => 'Include full body content (default: false for lists)'
                ]
            ]
        ];
    }

    public function execute(array $arguments): mixed
    {
        $contentType = $arguments['content_type'] ?? null;
        $id = $arguments['id'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $status = $arguments['status'] ?? null;
        $limit = $arguments['limit'] ?? 20;
        $offset = $arguments['offset'] ?? 0;
        $order = $arguments['order'] ?? 'recent';
        $includeBody = $arguments['include_body'] ?? ($id || $slug); // Include body for single items

        $requestData = [
            'limit' => $limit,
            'offset' => $offset,
            'order' => $order
        ];

        if ($contentType) {
            $requestData['type'] = $contentType;
        }
        if ($id) {
            $requestData['id'] = $id;
            $requestData['limit'] = 1;
        }
        if ($slug) {
            $requestData['slug'] = $slug;
            $requestData['limit'] = 1;
        }
        if ($status) {
            $requestData['status'] = $status;
        }

        $result = $this->apiCall('POST', '/api/content/get', $requestData);

        if (isset($result['error'])) {
            return ['error' => 'Failed to fetch content', 'details' => $result['error']];
        }

        $rows = $result['rows'] ?? [];
        
        // Format the response
        $items = array_map(function ($row) use ($includeBody) {
            $item = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];

            if ($includeBody) {
                $item['body'] = $row['body'] ?? '';
                $item['body_html'] = $row['body_html'] ?? '';
            }

            // Include any custom fields
            $coreFields = ['id', 'type', 'title', 'slug', 'body', 'body_html', 'status', 'created_at', 'updated_at', 'site_id'];
            foreach ($row as $key => $value) {
                if (!in_array($key, $coreFields) && !isset($item[$key])) {
                    $item[$key] = $value;
                }
            }

            return $item;
        }, $rows);

        // Single item request
        if ($id || $slug) {
            if (empty($items)) {
                return [
                    'error' => 'Content not found',
                    'id' => $id,
                    'slug' => $slug
                ];
            }
            return [
                'success' => true,
                'content' => $items[0]
            ];
        }

        // List request
        return [
            'success' => true,
            'count' => count($items),
            'limit' => $limit,
            'offset' => $offset,
            'items' => $items
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
