<?php
/**
 * Content Item Resource
 * 
 * Exposes individual content entries.
 * URI: hcms://content/{type}/{slug}
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

class ContentItemResource extends AbstractResource
{
    private string $origenUrl;
    private string $siteKey;

    public function __construct(?string $origenUrl = null, ?string $siteKey = null)
    {
        $this->uriPattern = 'hcms://content/{type}/{slug}';
        $this->name = 'Content Item';
        $this->description = 'Individual content entry';
        $this->mimeType = 'application/json';
        
        $this->origenUrl = $origenUrl ?? 'http://127.0.0.1:8080';
        $this->siteKey = $siteKey ?? ($_ENV['SITE_KEY'] ?? 'htx-starter-key-001');
        
        $this->buildRegex();
    }

    public function read(array $params): array
    {
        $type = $params['type'] ?? '';
        $slug = $params['slug'] ?? '';
        $uri = "hcms://content/{$type}/{$slug}";
        
        if (empty($type) || empty($slug)) {
            return $this->formatContent($uri, ['error' => 'Type and slug required']);
        }

        $content = $this->fetchContentBySlug($type, $slug);
        
        if (!$content) {
            return $this->formatContent($uri, ['error' => 'Content not found']);
        }

        return $this->formatContent($uri, $content);
    }

    public function listInstances(int $limit = 10): array
    {
        // Return recent content items across all types
        $result = $this->apiCall('/api/content/get', [
            'limit' => $limit,
            'status' => 'published',
            'order' => 'recent'
        ]);

        $instances = [];
        foreach ($result['rows'] ?? [] as $row) {
            $instances[] = $this->formatDescriptor(
                "hcms://content/{$row['type']}/{$row['slug']}",
                $row['title'],
                "Type: {$row['type']}"
            );
        }

        return $instances;
    }

    private function fetchContentBySlug(string $type, string $slug): ?array
    {
        $result = $this->apiCall('/api/content/get', [
            'type' => $type,
            'slug' => $slug,
            'limit' => 1
        ]);

        $rows = $result['rows'] ?? [];
        
        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'body' => $row['body'] ?? '',
            'body_html' => $row['body_html'] ?? '',
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            // Include any custom fields
            'custom_fields' => array_diff_key($row, array_flip([
                'id', 'type', 'title', 'slug', 'body', 'body_html', 
                'status', 'created_at', 'updated_at', 'site_id'
            ]))
        ];
    }

    private function apiCall(string $endpoint, array $data): array
    {
        $ch = curl_init($this->origenUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Site-Key: ' . $this->siteKey,
                'X-HTX-Version: 1'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }
}
