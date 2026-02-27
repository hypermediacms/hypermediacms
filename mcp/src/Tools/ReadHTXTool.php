<?php
/**
 * Read HTX Tool
 * 
 * Reads the contents of an existing HTX template file.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class ReadHTXTool implements ToolInterface
{
    private string $siteRoot;

    public function __construct(?string $siteRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
    }

    public function getName(): string
    {
        return 'read_htx';
    }

    public function getDescription(): string
    {
        return 'Read the contents of an existing HTX template file. Useful for understanding current templates before modifying.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route' => [
                    'type' => 'string',
                    'description' => 'The route to read (e.g., "/articles", "/blog/:slug")'
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Direct file path relative to site root (e.g., "articles/[slug].htx")'
                ]
            ]
        ];
    }

    public function execute(array $arguments): mixed
    {
        $route = $arguments['route'] ?? null;
        $file = $arguments['file'] ?? null;

        if (!$route && !$file) {
            return ['error' => 'Either route or file is required'];
        }

        // Determine file path
        if ($file) {
            $filePath = $this->siteRoot . '/' . ltrim($file, '/');
        } else {
            $filePath = $this->resolveRouteToFile($route);
        }

        if (!$filePath) {
            return [
                'error' => 'Could not resolve route to file',
                'route' => $route
            ];
        }

        if (!file_exists($filePath)) {
            return [
                'error' => 'File not found',
                'path' => str_replace($this->siteRoot . '/', '', $filePath)
            ];
        }

        $content = file_get_contents($filePath);
        $relativePath = str_replace($this->siteRoot . '/', '', $filePath);

        // Extract metadata from HTX
        $meta = $this->extractMeta($content);

        return [
            'success' => true,
            'file' => $relativePath,
            'route' => $this->fileToRoute($relativePath),
            'meta' => $meta,
            'content' => $content,
            'size' => strlen($content),
            'lines' => substr_count($content, "\n") + 1
        ];
    }

    /**
     * Resolve a route to its HTX file path
     */
    private function resolveRouteToFile(string $route): ?string
    {
        $route = '/' . trim($route, '/');
        
        // Convert :param to [param]
        $route = preg_replace('/:(\w+)/', '[$1]', $route);
        
        $basePath = ltrim($route, '/') ?: 'index';
        
        // Try different file patterns
        $candidates = [
            $this->siteRoot . '/' . $basePath . '.htx',
            $this->siteRoot . '/' . $basePath . '/index.htx',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert file path to route
     */
    private function fileToRoute(string $file): string
    {
        $route = preg_replace('/\.htx$/', '', $file);
        $route = preg_replace('/\/index$/', '', $route);
        $route = preg_replace('/\[(\w+)\]/', ':$1', $route);
        
        if ($route === 'index' || $route === '') {
            return '/';
        }
        
        return '/' . $route;
    }

    /**
     * Extract HTX metadata from content
     */
    private function extractMeta(string $content): array
    {
        $meta = [];

        $tags = ['type', 'action', 'howmany', 'order', 'responseRedirect', 'recordId'];
        
        foreach ($tags as $tag) {
            if (preg_match('/<htx:' . $tag . '>\s*([^<]+)\s*<\/htx:' . $tag . '>/i', $content, $matches)) {
                $meta[$tag] = trim($matches[1]);
            }
        }

        return $meta;
    }
}
