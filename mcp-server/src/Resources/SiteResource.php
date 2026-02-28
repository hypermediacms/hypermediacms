<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Resources;

use HyperMedia\McpServer\Contracts\ResourceInterface;

class SiteResource implements ResourceInterface
{
    public function __construct(
        private readonly string $siteRoot
    ) {}

    public function getScheme(): string
    {
        return 'site';
    }

    public function list(): array
    {
        return [
            [
                'uri' => 'site://routes',
                'name' => 'Site Routes',
                'mimeType' => 'application/json',
                'description' => 'Route manifest derived from .htx template files',
            ],
            [
                'uri' => 'site://config',
                'name' => 'Site Configuration',
                'mimeType' => 'text/yaml',
                'description' => 'Site-level configuration from _site.yaml',
            ],
        ];
    }

    public function read(string $uri): string
    {
        $parsed = parse_url($uri);
        $host = $parsed['host'] ?? '';

        return match ($host) {
            'routes' => $this->readRoutes(),
            'config' => $this->readConfig(),
            default => throw new \InvalidArgumentException("Unknown site resource: {$uri}"),
        };
    }

    public function supportsSubscription(): bool
    {
        return false;
    }

    private function readRoutes(): string
    {
        $routes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'htx') {
                continue;
            }

            $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
            $name = basename($relativePath, '.htx');

            // Skip internal templates (files starting with _)
            if (str_starts_with($name, '_')) {
                continue;
            }

            $route = $this->buildRoute($relativePath);
            if ($route !== null) {
                $routes[] = $route;
            }
        }

        usort($routes, fn(array $a, array $b) => strcmp($a['pattern'], $b['pattern']));

        return json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function buildRoute(string $relativePath): ?array
    {
        $withoutExt = preg_replace('/\.htx$/', '', $relativePath);

        // Convert [slug] to :slug
        $pattern = preg_replace('/\[([^\]]+)\]/', ':$1', $withoutExt);

        // Remove trailing /index
        $pattern = preg_replace('#/index$#', '', $pattern);

        // Handle root index
        if ($pattern === 'index') {
            $pattern = '';
        }

        $urlPattern = '/' . $pattern;

        // Classify route
        $isDynamic = str_contains($urlPattern, ':');
        if ($urlPattern === '/') {
            $kind = 'index';
        } elseif ($isDynamic) {
            $kind = 'dynamic';
        } else {
            $kind = 'static';
        }

        $route = [
            'file' => $relativePath,
            'pattern' => $urlPattern,
            'kind' => $kind,
        ];

        if ($isDynamic) {
            preg_match_all('/:([a-zA-Z_]+)/', $urlPattern, $matches);
            $route['segments'] = $matches[1];
        }

        return $route;
    }

    private function readConfig(): string
    {
        $configFile = $this->siteRoot . '/_site.yaml';

        if (file_exists($configFile)) {
            return file_get_contents($configFile);
        }

        return "# No _site.yaml found\nsite_root: {$this->siteRoot}\n";
    }
}
