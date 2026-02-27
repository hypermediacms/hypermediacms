<?php
/**
 * Content List Resource
 * 
 * Exposes content listings by type.
 * URI: hcms://content/{type}
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

class ContentListResource extends AbstractResource
{
    private string $origenUrl;
    private string $siteKey;

    public function __construct(?string $origenUrl = null, ?string $siteKey = null)
    {
        $this->uriPattern = 'hcms://content/{type}';
        $this->name = 'Content List';
        $this->description = 'List content entries by type';
        $this->mimeType = 'application/json';
        
        $this->origenUrl = $origenUrl ?? 'http://127.0.0.1:8080';
        $this->siteKey = $siteKey ?? ($_ENV['SITE_KEY'] ?? 'htx-starter-key-001');
        
        $this->buildRegex();
    }

    public function read(array $params): array
    {
        $type = $params['type'] ?? '';
        $uri = "hcms://content/{$type}";
        
        if (empty($type)) {
            return $this->formatContent($uri, ['error' => 'Content type required']);
        }

        $content = $this->fetchContent($type);
        
        return $this->formatContent($uri, [
            'type' => $type,
            'count' => count($content),
            'items' => $content
        ]);
    }

    public function listInstances(int $limit = 10): array
    {
        // Return available content types as instances
        $types = $this->getContentTypes();
        $instances = [];

        foreach (array_slice($types, 0, $limit) as $type) {
            $instances[] = $this->formatDescriptor(
                "hcms://content/{$type}",
                ucfirst($type) . ' List',
                "All {$type} entries"
            );
        }

        return $instances;
    }

    private function fetchContent(string $type, int $limit = 20): array
    {
        $result = $this->apiCall('/api/content/get', [
            'type' => $type,
            'limit' => $limit,
            'status' => 'published'
        ]);

        $rows = $result['rows'] ?? [];
        
        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'status' => $row['status'],
                'excerpt' => isset($row['body']) ? substr($row['body'], 0, 200) : '',
                'updated_at' => $row['updated_at']
            ];
        }, $rows);
    }

    private function getContentTypes(): array
    {
        // Get unique content types from database
        $result = $this->apiCall('/api/content/get', ['limit' => 100]);
        $rows = $result['rows'] ?? [];
        
        $types = [];
        foreach ($rows as $row) {
            $types[$row['type']] = true;
        }
        
        return array_keys($types);
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
