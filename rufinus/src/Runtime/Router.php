<?php

namespace Rufinus\Runtime;

class RouteMatch
{
    public string $filePath;
    public array $params;
    public string $siteRoot;

    public function __construct(string $filePath, array $params, string $siteRoot)
    {
        $this->filePath = $filePath;
        $this->params = $params;
        $this->siteRoot = $siteRoot;
    }
}

class Router
{
    /**
     * Resolve a URL path to an .htx file on disk.
     *
     * @param string $urlPath The request URL path
     * @param string $siteRoot The root directory of the site's pages
     * @return RouteMatch|null Matched route or null for 404
     */
    public function resolve(string $urlPath, string $siteRoot): ?RouteMatch
    {
        $siteRoot = rtrim($siteRoot, '/');
        $path = $this->normalizePath($urlPath);

        // Reject paths that would route to underscore-prefixed files or public/
        if ($this->isExcluded($path)) {
            return null;
        }

        // 1. Check exact file match: {siteRoot}/{path}.htx
        $exactFile = $siteRoot . '/' . $path . '.htx';
        if (file_exists($exactFile)) {
            return new RouteMatch($exactFile, [], $siteRoot);
        }

        // 2. Check directory index: {siteRoot}/{path}/index.htx
        $indexFile = $siteRoot . '/' . $path . '/index.htx';
        if (file_exists($indexFile)) {
            return new RouteMatch($indexFile, [], $siteRoot);
        }

        // 3. Check dynamic segments
        return $this->resolveDynamic($path, $siteRoot);
    }

    /**
     * Normalize URL path: strip query string, trailing slash, default / to index.
     */
    private function normalizePath(string $urlPath): string
    {
        // Strip query string
        $path = parse_url($urlPath, PHP_URL_PATH) ?? '/';

        // Remove leading/trailing slashes
        $path = trim($path, '/');

        // Default empty path to index
        if ($path === '') {
            return 'index';
        }

        return $path;
    }

    /**
     * Check if a path should be excluded from routing.
     */
    private function isExcluded(string $path): bool
    {
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            // Files starting with _ are not routable (layouts, errors)
            if (str_starts_with($segment, '_')) {
                return true;
            }
        }

        // public/ directory is excluded
        if ($segments[0] === 'public') {
            return true;
        }

        return false;
    }

    /**
     * Walk path segments checking for [param] dynamic matches at each level.
     */
    private function resolveDynamic(string $path, string $siteRoot): ?RouteMatch
    {
        $segments = explode('/', $path);
        return $this->walkSegments($segments, 0, $siteRoot, $siteRoot, []);
    }

    /**
     * Recursively walk path segments, trying both exact and dynamic matches.
     */
    private function walkSegments(array $segments, int $index, string $currentDir, string $siteRoot, array $params): ?RouteMatch
    {
        // Base case: we've consumed all segments except the last
        if ($index === count($segments) - 1) {
            $lastSegment = $segments[$index];

            // Try exact file for the last segment
            $exactFile = $currentDir . '/' . $lastSegment . '.htx';
            if (file_exists($exactFile)) {
                return new RouteMatch($exactFile, $params, $siteRoot);
            }

            // Try directory index for the last segment
            $indexFile = $currentDir . '/' . $lastSegment . '/index.htx';
            if (file_exists($indexFile)) {
                return new RouteMatch($indexFile, $params, $siteRoot);
            }

            // Try dynamic file match: [param].htx in current directory
            $dynamicMatch = $this->findDynamicFile($currentDir, $lastSegment, $params);
            if ($dynamicMatch !== null) {
                return new RouteMatch($dynamicMatch['filePath'], $dynamicMatch['params'], $siteRoot);
            }

            return null;
        }

        $segment = $segments[$index];

        // Try exact directory match
        $exactDir = $currentDir . '/' . $segment;
        if (is_dir($exactDir)) {
            $result = $this->walkSegments($segments, $index + 1, $exactDir, $siteRoot, $params);
            if ($result !== null) {
                return $result;
            }
        }

        // Try dynamic directory match: [param]/ directories
        $dynamicDir = $this->findDynamicDir($currentDir, $segment, $params);
        if ($dynamicDir !== null) {
            return $this->walkSegments($segments, $index + 1, $dynamicDir['dirPath'], $siteRoot, $dynamicDir['params']);
        }

        return null;
    }

    /**
     * Find a [param].htx file in a directory that matches a URL segment value.
     */
    private function findDynamicFile(string $dir, string $value, array $params): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if (preg_match('/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]\.htx$/', $file, $matches)) {
                $paramName = $matches[1];
                $params[$paramName] = $value;
                return [
                    'filePath' => $dir . '/' . $file,
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Find a [param]/ directory that matches a URL segment.
     */
    private function findDynamicDir(string $dir, string $value, array $params): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }

        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if (preg_match('/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]$/', $entry, $matches)) {
                $fullPath = $dir . '/' . $entry;
                if (is_dir($fullPath)) {
                    $paramName = $matches[1];
                    $params[$paramName] = $value;
                    return [
                        'dirPath' => $fullPath,
                        'params' => $params,
                    ];
                }
            }
        }

        return null;
    }
}
