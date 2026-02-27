<?php
/**
 * Update HTX Tool
 * 
 * Modifies existing HTX template files.
 * Supports full replacement or targeted updates to specific sections.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class UpdateHTXTool implements ToolInterface
{
    private string $siteRoot;

    public function __construct(?string $siteRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
    }

    public function getName(): string
    {
        return 'update_htx';
    }

    public function getDescription(): string
    {
        return 'Update an existing HTX template file. Can replace entire content or update specific meta tags.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route' => [
                    'type' => 'string',
                    'description' => 'The route to update (e.g., "/articles", "/blog/:slug")'
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Direct file path relative to site root'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'New full content to replace the file (use this OR updates)'
                ],
                'updates' => [
                    'type' => 'object',
                    'description' => 'Partial updates to specific HTX meta tags',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'Update htx:type'],
                        'howmany' => ['type' => 'string', 'description' => 'Update htx:howmany'],
                        'order' => ['type' => 'string', 'description' => 'Update htx:order'],
                        'action' => ['type' => 'string', 'description' => 'Update htx:action'],
                        'responseRedirect' => ['type' => 'string', 'description' => 'Update htx:responseRedirect']
                    }
                ],
                'backup' => [
                    'type' => 'boolean',
                    'description' => 'Create a backup before updating (default: true)'
                ]
            ]
        ];
    }

    public function execute(array $arguments): mixed
    {
        $route = $arguments['route'] ?? null;
        $file = $arguments['file'] ?? null;
        $newContent = $arguments['content'] ?? null;
        $updates = $arguments['updates'] ?? null;
        $backup = $arguments['backup'] ?? true;

        if (!$route && !$file) {
            return ['error' => 'Either route or file is required'];
        }

        if (!$newContent && !$updates) {
            return ['error' => 'Either content or updates is required'];
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

        $relativePath = str_replace($this->siteRoot . '/', '', $filePath);
        $originalContent = file_get_contents($filePath);

        // Create backup if requested
        if ($backup) {
            $backupPath = $filePath . '.bak.' . date('Y-m-d-His');
            file_put_contents($backupPath, $originalContent);
        }

        // Determine new content
        if ($newContent) {
            // Full replacement
            $finalContent = $newContent;
        } else {
            // Partial updates to meta tags
            $finalContent = $this->applyUpdates($originalContent, $updates);
        }

        // Write the updated file
        file_put_contents($filePath, $finalContent);

        return [
            'success' => true,
            'file' => $relativePath,
            'route' => $this->fileToRoute($relativePath),
            'backup' => $backup ? str_replace($this->siteRoot . '/', '', $backupPath ?? '') : null,
            'changes' => $newContent ? 'full_replacement' : array_keys($updates)
        ];
    }

    /**
     * Apply partial updates to HTX meta tags
     */
    private function applyUpdates(string $content, array $updates): string
    {
        foreach ($updates as $tag => $value) {
            $pattern = '/<htx:' . preg_quote($tag, '/') . '>[^<]*<\/htx:' . preg_quote($tag, '/') . '>/i';
            $replacement = "<htx:{$tag}>{$value}</htx:{$tag}>";
            
            if (preg_match($pattern, $content)) {
                // Update existing tag
                $content = preg_replace($pattern, $replacement, $content);
            } else {
                // Add new tag at the beginning
                $content = $replacement . "\n" . $content;
            }
        }

        return $content;
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
}
