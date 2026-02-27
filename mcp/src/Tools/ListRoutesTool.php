<?php
/**
 * List Routes Tool
 * 
 * Lists all existing routes by scanning HTX files.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class ListRoutesTool implements ToolInterface
{
    private string $siteRoot;

    public function __construct(?string $siteRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
    }

    public function getName(): string
    {
        return 'list_routes';
    }

    public function getDescription(): string
    {
        return 'List all existing routes/pages in the site by scanning HTX files.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_admin' => [
                    'type' => 'boolean',
                    'description' => 'Include admin routes (default: true)'
                ],
                'include_meta' => [
                    'type' => 'boolean',
                    'description' => 'Include HTX metadata (type, action) for each route (default: false)'
                ]
            ]
        ];
    }

    public function execute(array $arguments): mixed
    {
        $includeAdmin = $arguments['include_admin'] ?? true;
        $includeMeta = $arguments['include_meta'] ?? false;

        $routes = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            // Skip layouts and error pages
            $filename = $file->getFilename();
            if (str_starts_with($filename, '_')) {
                continue;
            }

            $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
            
            // Skip admin routes if not requested
            if (!$includeAdmin && str_starts_with($relativePath, 'admin/')) {
                continue;
            }

            $route = $this->filePathToRoute($relativePath);
            
            $routeInfo = [
                'route' => $route,
                'file' => $relativePath,
                'is_admin' => str_starts_with($relativePath, 'admin/'),
                'is_dynamic' => str_contains($route, ':')
            ];

            if ($includeMeta) {
                $routeInfo['meta'] = $this->extractHTXMeta($file->getPathname());
            }

            $routes[] = $routeInfo;
        }

        // Sort routes alphabetically
        usort($routes, fn($a, $b) => strcmp($a['route'], $b['route']));

        return [
            'routes' => $routes,
            'total' => count($routes),
            'public_routes' => count(array_filter($routes, fn($r) => !$r['is_admin'])),
            'admin_routes' => count(array_filter($routes, fn($r) => $r['is_admin']))
        ];
    }

    /**
     * Convert file path to route
     */
    private function filePathToRoute(string $filePath): string
    {
        // Remove .htx extension
        $route = preg_replace('/\.htx$/', '', $filePath);
        
        // Convert index to /
        $route = preg_replace('/\/index$/', '', $route);
        if ($route === 'index') {
            $route = '';
        }
        
        // Convert [param] to :param for display
        $route = preg_replace('/\[([^\]]+)\]/', ':$1', $route);
        
        return '/' . $route;
    }

    /**
     * Extract HTX metadata from a file
     */
    private function extractHTXMeta(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $meta = [];

        // Extract htx:type
        if (preg_match('/<htx:type>\s*([^<]+)\s*<\/htx:type>/i', $content, $matches)) {
            $meta['content_type'] = trim($matches[1]);
        }

        // Extract htx:action
        if (preg_match('/<htx:action>\s*([^<]+)\s*<\/htx:action>/i', $content, $matches)) {
            $meta['action'] = trim($matches[1]);
        }

        // Extract htx:howmany
        if (preg_match('/<htx:howmany>\s*([^<]+)\s*<\/htx:howmany>/i', $content, $matches)) {
            $meta['howmany'] = trim($matches[1]);
        }

        return $meta;
    }
}
