<?php
/**
 * Site Resource
 * 
 * Exposes site-level information.
 * URIs:
 * - hcms://site/routes
 * - hcms://site/config
 * - hcms://site/stats
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

class SiteResource extends AbstractResource
{
    private string $siteRoot;
    private string $schemasRoot;
    private string $origenUrl;
    private string $siteKey;
    private string $resourceType;

    public function __construct(
        string $resourceType = 'routes',
        ?string $siteRoot = null,
        ?string $schemasRoot = null,
        ?string $origenUrl = null,
        ?string $siteKey = null
    ) {
        $this->resourceType = $resourceType;
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
        $this->schemasRoot = $schemasRoot ?? dirname(__DIR__, 2) . '/../schemas';
        $this->origenUrl = $origenUrl ?? 'http://127.0.0.1:8080';
        $this->siteKey = $siteKey ?? ($_ENV['SITE_KEY'] ?? 'htx-starter-key-001');
        
        $this->uriPattern = "hcms://site/{$resourceType}";
        $this->mimeType = 'application/json';
        
        switch ($resourceType) {
            case 'routes':
                $this->name = 'Site Routes';
                $this->description = 'All routes and their configurations';
                break;
            case 'config':
                $this->name = 'Site Configuration';
                $this->description = 'Site settings and environment';
                break;
            case 'stats':
                $this->name = 'Site Statistics';
                $this->description = 'Content counts and site metrics';
                break;
        }
        
        $this->buildRegex();
    }

    public function read(array $params): array
    {
        $uri = "hcms://site/{$this->resourceType}";
        
        $data = match ($this->resourceType) {
            'routes' => $this->getRoutes(),
            'config' => $this->getConfig(),
            'stats' => $this->getStats(),
            default => ['error' => 'Unknown site resource']
        };

        return $this->formatContent($uri, $data);
    }

    public function listInstances(int $limit = 10): array
    {
        return [$this->formatDescriptor(
            "hcms://site/{$this->resourceType}",
            $this->name,
            $this->description
        )];
    }

    private function getRoutes(): array
    {
        $routes = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
            $filename = basename($relativePath);
            
            // Skip layouts and partials
            if (str_starts_with($filename, '_')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            
            $routes[] = [
                'route' => $this->pathToRoute($relativePath),
                'file' => $relativePath,
                'type' => $this->extractTag($content, 'type'),
                'action' => $this->extractTag($content, 'action'),
                'is_admin' => str_starts_with($relativePath, 'admin/'),
                'is_dynamic' => str_contains($relativePath, '[')
            ];
        }

        usort($routes, fn($a, $b) => strcmp($a['route'], $b['route']));

        return [
            'total' => count($routes),
            'public' => count(array_filter($routes, fn($r) => !$r['is_admin'])),
            'admin' => count(array_filter($routes, fn($r) => $r['is_admin'])),
            'routes' => $routes
        ];
    }

    private function getConfig(): array
    {
        // Return safe configuration info (no secrets)
        return [
            'site_root' => basename($this->siteRoot),
            'schemas_root' => basename($this->schemasRoot),
            'origen_url' => $this->origenUrl,
            'mcp_version' => '0.4.0',
            'capabilities' => [
                'tools' => true,
                'resources' => true,
                'prompts' => false
            ]
        ];
    }

    private function getStats(): array
    {
        // Get content counts
        $result = $this->apiCall('/api/content/get', ['limit' => 1000]);
        $rows = $result['rows'] ?? [];

        $byType = [];
        $byStatus = [];
        
        foreach ($rows as $row) {
            $type = $row['type'] ?? 'unknown';
            $status = $row['status'] ?? 'unknown';
            
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        // Count templates
        $templates = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'htx' && !str_starts_with($file->getFilename(), '_')) {
                $templates++;
            }
        }

        // Count schemas
        $schemas = 0;
        foreach (glob($this->schemasRoot . '/*/*.yaml') as $f) {
            $schemas++;
        }

        return [
            'content' => [
                'total' => count($rows),
                'by_type' => $byType,
                'by_status' => $byStatus
            ],
            'templates' => $templates,
            'schemas' => $schemas
        ];
    }

    private function pathToRoute(string $path): string
    {
        $route = preg_replace('/\.htx$/', '', $path);
        $route = preg_replace('/\/index$/', '', $route);
        $route = preg_replace('/\[(\w+)\]/', ':$1', $route);
        
        return '/' . ($route === 'index' ? '' : $route);
    }

    private function extractTag(string $content, string $tag): ?string
    {
        if (preg_match('/<htx:' . $tag . '>\s*([^<]+)\s*<\/htx:' . $tag . '>/i', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
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
