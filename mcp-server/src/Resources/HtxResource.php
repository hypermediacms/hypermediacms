<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Resources;

use HyperMedia\McpServer\Contracts\ResourceInterface;

class HtxResource implements ResourceInterface
{
    public function __construct(
        private readonly string $siteRoot
    ) {}

    public function getScheme(): string
    {
        return 'htx';
    }

    public function list(): array
    {
        $resources = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'htx') {
                $relativePath = $this->getRelativePath($file->getPathname());
                $resources[] = [
                    'uri' => "htx://{$relativePath}",
                    'name' => $this->getDisplayName($relativePath),
                    'mimeType' => 'text/x-htx',
                    'description' => $this->getDescription($relativePath),
                ];
            }
        }

        return $resources;
    }

    public function read(string $uri): string
    {
        $path = $this->uriToPath($uri);
        
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Resource not found: {$uri}");
        }

        if (!$this->isWithinSiteRoot($path)) {
            throw new \SecurityException("Access denied: {$uri}");
        }

        return file_get_contents($path);
    }

    public function supportsSubscription(): bool
    {
        return true;
    }

    private function uriToPath(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
        return $this->siteRoot . '/' . ltrim($path, '/');
    }

    private function getRelativePath(string $fullPath): string
    {
        return str_replace($this->siteRoot . '/', '', $fullPath);
    }

    private function getDisplayName(string $relativePath): string
    {
        $name = basename($relativePath, '.htx');
        
        // Handle special cases
        if ($name === 'index') {
            $dir = dirname($relativePath);
            $name = $dir === '.' ? 'Home Page' : ucfirst(basename($dir)) . ' Index';
        } elseif ($name === '_layout') {
            $dir = dirname($relativePath);
            $name = $dir === '.' ? 'Root Layout' : ucfirst(basename($dir)) . ' Layout';
        } elseif (str_starts_with($name, '[') && str_ends_with($name, ']')) {
            $param = trim($name, '[]');
            $dir = dirname($relativePath);
            $name = ucfirst(basename($dir)) . " (:{$param})";
        } else {
            $name = ucwords(str_replace(['-', '_'], ' ', $name));
        }

        return $name;
    }

    private function getDescription(string $relativePath): string
    {
        $name = basename($relativePath, '.htx');
        $dir = dirname($relativePath);

        if ($name === 'index') {
            return $dir === '.' ? 'Main site index page' : "Index page for /{$dir}";
        }
        if ($name === '_layout') {
            return $dir === '.' ? 'Root layout template' : "Layout template for /{$dir}";
        }
        if (str_starts_with($name, '[')) {
            return "Dynamic route template at /{$relativePath}";
        }

        return "Template at /{$relativePath}";
    }

    private function isWithinSiteRoot(string $path): bool
    {
        $realPath = realpath($path);
        $realSiteRoot = realpath($this->siteRoot);

        if ($realPath === false || $realSiteRoot === false) {
            return false;
        }

        return str_starts_with($realPath, $realSiteRoot);
    }
}
