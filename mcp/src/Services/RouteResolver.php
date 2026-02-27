<?php
/**
 * Route Resolver Service
 * 
 * Resolves routes to HTX file paths and vice versa.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Services;

class RouteResolver
{
    private string $siteRoot;

    public function __construct(string $siteRoot)
    {
        $this->siteRoot = rtrim($siteRoot, '/');
    }

    /**
     * Check if a route already has an HTX file
     * 
     * @return string|null The existing file path, or null if not found
     */
    public function resolveRoute(string $route): ?string
    {
        $route = '/' . trim($route, '/');
        
        // Generate potential file paths for this route
        $paths = $this->getPotentialPaths($route);

        foreach ($paths as $path) {
            $fullPath = $this->siteRoot . '/' . $path;
            if (file_exists($fullPath)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert a route to its HTX file path
     */
    public function routeToFilePath(string $route): string
    {
        $route = trim($route, '/');
        
        if (empty($route)) {
            return 'index.htx';
        }

        // Check if route ends with a dynamic segment
        $segments = explode('/', $route);
        $lastSegment = end($segments);

        // If the last segment looks like a dynamic param placeholder
        if (preg_match('/^:(\w+)$/', $lastSegment, $matches)) {
            // Convert :param to [param]
            $segments[count($segments) - 1] = '[' . $matches[1] . ']';
            return implode('/', $segments) . '.htx';
        }

        // If it's a concrete path, it becomes directory/index.htx or file.htx
        // Prefer file.htx for simplicity
        return $route . '.htx';
    }

    /**
     * Convert an HTX file path to its route
     */
    public function filePathToRoute(string $filePath): string
    {
        // Remove .htx extension
        $route = preg_replace('/\.htx$/', '', $filePath);
        
        // Convert index to /
        $route = preg_replace('/\/index$/', '', $route);
        if ($route === 'index') {
            return '/';
        }
        
        // Convert [param] to :param for display
        $route = preg_replace('/\[([^\]]+)\]/', ':$1', $route);
        
        return '/' . $route;
    }

    /**
     * Get all potential file paths that could match a route
     */
    private function getPotentialPaths(string $route): array
    {
        $route = ltrim($route, '/');
        $paths = [];

        if (empty($route)) {
            $paths[] = 'index.htx';
            return $paths;
        }

        // Direct match
        $paths[] = $route . '.htx';
        $paths[] = $route . '/index.htx';

        // Dynamic segment matches
        $segments = explode('/', $route);
        
        if (count($segments) > 1) {
            // Try replacing last segment with common dynamic patterns
            $basePath = implode('/', array_slice($segments, 0, -1));
            $paths[] = $basePath . '/[slug].htx';
            $paths[] = $basePath . '/[id].htx';
            $paths[] = $basePath . '/[name].htx';
        }

        // Try replacing last segment with wildcard for any single-segment route
        if (count($segments) === 1) {
            $paths[] = '[slug].htx';
            $paths[] = '[id].htx';
        }

        return $paths;
    }

    /**
     * List all HTX files in a directory
     */
    public function listHTXFiles(string $subPath = ''): array
    {
        $searchPath = $this->siteRoot;
        if ($subPath) {
            $searchPath .= '/' . trim($subPath, '/');
        }

        if (!is_dir($searchPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchPath)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'htx') {
                $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
                $files[] = [
                    'path' => $relativePath,
                    'route' => $this->filePathToRoute($relativePath)
                ];
            }
        }

        return $files;
    }
}
