<?php
/**
 * Preview Content Tool
 * 
 * Renders content through an HTX template without persisting.
 * Used for live preview during content editing.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

use Rufinus\EdgeHTX;

class PreviewContentTool implements ToolInterface
{
    private string $siteRoot;
    private ?EdgeHTX $edgeHTX = null;

    public function __construct(?string $siteRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
    }

    public function getName(): string
    {
        return 'preview_content';
    }

    public function getDescription(): string
    {
        return 'Preview content by rendering it through an HTX template without saving. ' .
               'Useful for seeing how content will look before publishing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route' => [
                    'type' => 'string',
                    'description' => 'The route to preview (must have an existing HTX file)'
                ],
                'content' => [
                    'type' => 'object',
                    'description' => 'The content data to preview (field name => value pairs)',
                    'additionalProperties' => true
                ],
                'route_params' => [
                    'type' => 'object',
                    'description' => 'Dynamic route parameters (e.g., {"slug": "my-post"} for /blog/:slug)',
                    'additionalProperties' => true
                ]
            ],
            'required' => ['route', 'content']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $route = $arguments['route'] ?? '';
        $content = $arguments['content'] ?? [];
        $routeParams = $arguments['route_params'] ?? [];

        if (empty($route)) {
            return ['error' => 'Route is required'];
        }

        // Find the HTX file for this route
        $htxFile = $this->resolveHTXFile($route, $routeParams);
        
        if ($htxFile === null) {
            return [
                'error' => 'No HTX template found for route',
                'route' => $route,
                'suggestion' => 'Use create_htx to create a template for this route first'
            ];
        }

        // Read the HTX template
        $htxContent = file_get_contents($htxFile);

        // Render the preview
        try {
            $rendered = $this->renderPreview($htxContent, $content);
            
            return [
                'success' => true,
                'route' => $route,
                'template_file' => str_replace($this->siteRoot . '/', '', $htxFile),
                'rendered_html' => $rendered,
                'content_previewed' => $content
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Preview rendering failed',
                'message' => $e->getMessage(),
                'route' => $route
            ];
        }
    }

    /**
     * Resolve route to HTX file path
     */
    private function resolveHTXFile(string $route, array $routeParams): ?string
    {
        // Normalize route
        $route = '/' . trim($route, '/');
        
        // Convert route to potential file paths
        $paths = [];
        
        // Direct match (e.g., /about -> about.htx)
        $directPath = ltrim($route, '/') ?: 'index';
        $paths[] = $this->siteRoot . '/' . $directPath . '.htx';
        $paths[] = $this->siteRoot . '/' . $directPath . '/index.htx';
        
        // Dynamic route match (e.g., /blog/my-post -> blog/[slug].htx)
        $segments = explode('/', ltrim($route, '/'));
        if (count($segments) > 1) {
            $lastSegment = array_pop($segments);
            $basePath = implode('/', $segments);
            
            // Try common dynamic patterns
            $paths[] = $this->siteRoot . '/' . $basePath . '/[slug].htx';
            $paths[] = $this->siteRoot . '/' . $basePath . '/[id].htx';
        }

        // Find first existing file
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Render content through HTX template for preview
     */
    private function renderPreview(string $htxContent, array $content): string
    {
        // Extract the template part (inside <htx>...</htx>)
        if (!preg_match('/<htx>(.*)<\/htx>/s', $htxContent, $matches)) {
            // If no <htx> wrapper, treat entire content as template
            $template = $htxContent;
        } else {
            $template = $matches[1];
        }

        // Find <htx:each> block for list content
        if (preg_match('/<htx:each>(.*)<\/htx:each>/s', $template, $eachMatches)) {
            $template = $eachMatches[1];
        }

        // Hydrate the template with content
        $rendered = $template;

        // Replace __field__ placeholders
        foreach ($content as $field => $value) {
            $rendered = str_replace("__{$field}__", htmlspecialchars((string)$value), $rendered);
        }

        // Replace {{ field }} Twig-style placeholders
        foreach ($content as $field => $value) {
            $rendered = preg_replace(
                '/\{\{\s*' . preg_quote($field, '/') . '\s*\}\}/',
                htmlspecialchars((string)$value),
                $rendered
            );
        }

        // Replace {{! field }} (unescaped) placeholders
        foreach ($content as $field => $value) {
            $rendered = preg_replace(
                '/\{\{!\s*' . preg_quote($field, '/') . '\s*\}\}/',
                (string)$value,
                $rendered
            );
        }

        // Clean up any remaining placeholders
        $rendered = preg_replace('/__[a-z_]+__/i', '', $rendered);
        $rendered = preg_replace('/\{\{[^}]+\}\}/', '', $rendered);

        return trim($rendered);
    }
}
