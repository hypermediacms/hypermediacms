<?php
/**
 * Template Resource
 * 
 * Exposes HTX template files.
 * URIs:
 * - hcms://templates (list all)
 * - hcms://template/{path} (specific template)
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

class TemplateResource extends AbstractResource
{
    private string $siteRoot;
    private bool $isList;

    public function __construct(?string $siteRoot = null, bool $isList = false)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
        $this->isList = $isList;
        
        if ($isList) {
            $this->uriPattern = 'hcms://templates';
            $this->name = 'Template List';
            $this->description = 'List all HTX templates';
        } else {
            $this->uriPattern = 'hcms://template/{path}';
            $this->name = 'HTX Template';
            $this->description = 'HTX template file content';
        }
        
        $this->mimeType = 'text/plain';
        $this->buildRegex();
    }

    public function matches(string $uri): bool
    {
        if ($this->isList) {
            return $uri === 'hcms://templates';
        }
        
        // For template paths, match anything starting with hcms://template/
        return str_starts_with($uri, 'hcms://template/');
    }

    public function extractParams(string $uri): array
    {
        if ($this->isList) {
            return [];
        }
        
        // Extract everything after hcms://template/
        $path = substr($uri, strlen('hcms://template/'));
        return ['path' => $path];
    }

    public function read(array $params): array
    {
        if ($this->isList) {
            return $this->readTemplateList();
        }
        
        $path = $params['path'] ?? '';
        $uri = "hcms://template/{$path}";
        
        if (empty($path)) {
            return $this->formatContent($uri, 'Error: Template path required');
        }

        // Ensure path ends with .htx
        if (!str_ends_with($path, '.htx')) {
            $path .= '.htx';
        }

        $fullPath = $this->siteRoot . '/' . $path;
        
        if (!file_exists($fullPath)) {
            return $this->formatContent($uri, "Error: Template not found: {$path}");
        }

        $content = file_get_contents($fullPath);
        $meta = $this->extractMeta($content);

        // For templates, we return the raw HTX content with metadata header
        $output = "# Template: {$path}\n";
        $output .= "# Route: " . $this->pathToRoute($path) . "\n";
        if (!empty($meta)) {
            $output .= "# Meta: " . json_encode($meta) . "\n";
        }
        $output .= "\n" . $content;

        return $this->formatContent($uri, $output);
    }

    private function readTemplateList(): array
    {
        $templates = $this->getAllTemplates();
        
        $this->mimeType = 'application/json';
        return $this->formatContent('hcms://templates', [
            'count' => count($templates),
            'templates' => $templates
        ]);
    }

    public function listInstances(int $limit = 10): array
    {
        if ($this->isList) {
            return [$this->formatDescriptor(
                'hcms://templates',
                'All Templates',
                'List of all HTX template files'
            )];
        }

        $templates = $this->getAllTemplates();
        $instances = [];

        foreach (array_slice($templates, 0, $limit) as $tpl) {
            $instances[] = [
                'uri' => "hcms://template/{$tpl['path']}",
                'name' => $tpl['path'],
                'description' => "Route: {$tpl['route']}" . 
                    ($tpl['type'] ? " | Type: {$tpl['type']}" : ''),
                'mimeType' => 'text/plain'
            ];
        }

        return $instances;
    }

    private function getAllTemplates(): array
    {
        $templates = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
            
            // Skip layouts and partials for main list
            if (str_starts_with(basename($relativePath), '_')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $meta = $this->extractMeta($content);

            $templates[] = [
                'path' => $relativePath,
                'route' => $this->pathToRoute($relativePath),
                'type' => $meta['type'] ?? null,
                'is_admin' => str_starts_with($relativePath, 'admin/')
            ];
        }

        usort($templates, fn($a, $b) => strcmp($a['path'], $b['path']));

        return $templates;
    }

    private function pathToRoute(string $path): string
    {
        $route = preg_replace('/\.htx$/', '', $path);
        $route = preg_replace('/\/index$/', '', $route);
        $route = preg_replace('/\[(\w+)\]/', ':$1', $route);
        
        if ($route === 'index' || $route === '') {
            return '/';
        }
        
        return '/' . $route;
    }

    private function extractMeta(string $content): array
    {
        $meta = [];
        $tags = ['type', 'action', 'howmany', 'order'];
        
        foreach ($tags as $tag) {
            if (preg_match('/<htx:' . $tag . '>\s*([^<]+)\s*<\/htx:' . $tag . '>/i', $content, $matches)) {
                $meta[$tag] = trim($matches[1]);
            }
        }

        return $meta;
    }
}
