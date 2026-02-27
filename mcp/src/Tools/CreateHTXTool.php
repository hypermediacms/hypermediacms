<?php
/**
 * Create HTX Tool
 * 
 * Creates HTX template files for displaying content at specified routes.
 * This is the primary tool for scaffolding new pages/views.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

use HyperMediaCMS\MCP\Services\HTXGenerator;
use HyperMediaCMS\MCP\Services\RouteResolver;

class CreateHTXTool implements ToolInterface
{
    private HTXGenerator $generator;
    private RouteResolver $routeResolver;
    private string $siteRoot;

    public function __construct(?string $siteRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
        $this->generator = new HTXGenerator();
        $this->routeResolver = new RouteResolver($this->siteRoot);
    }

    public function getName(): string
    {
        return 'create_htx';
    }

    public function getDescription(): string
    {
        return 'Create an HTX template file for displaying content at a specified route. ' .
               'The HTX file defines both the display template and connects to a content type.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route' => [
                    'type' => 'string',
                    'description' => 'The URL route where content will be displayed (e.g., "/blog", "/products/[slug]")'
                ],
                'content_type' => [
                    'type' => 'string',
                    'description' => 'The content type to display (e.g., "article", "product")'
                ],
                'display_mode' => [
                    'type' => 'string',
                    'enum' => ['list', 'single', 'form'],
                    'description' => 'How to display content: "list" for multiple items, "single" for one item, "form" for create/edit'
                ],
                'template_style' => [
                    'type' => 'string',
                    'enum' => ['card', 'table', 'minimal', 'custom'],
                    'description' => 'Visual style for the template (default: card)'
                ],
                'fields_to_display' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of fields to display (uses defaults if not specified)'
                ],
                'include_admin' => [
                    'type' => 'boolean',
                    'description' => 'Also create admin HTX files for managing this content (default: false)'
                ]
            ],
            'required' => ['route', 'content_type', 'display_mode']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $route = $arguments['route'] ?? '';
        $contentType = $arguments['content_type'] ?? '';
        $displayMode = $arguments['display_mode'] ?? 'list';
        $templateStyle = $arguments['template_style'] ?? 'card';
        $fieldsToDisplay = $arguments['fields_to_display'] ?? null;
        $includeAdmin = $arguments['include_admin'] ?? false;

        // Validate inputs
        if (empty($route)) {
            return ['error' => 'Route is required'];
        }
        if (empty($contentType)) {
            return ['error' => 'Content type is required'];
        }

        // Check if route already exists
        $existingFile = $this->routeResolver->resolveRoute($route);
        if ($existingFile !== null) {
            return [
                'error' => 'Route already exists',
                'existing_file' => $existingFile
            ];
        }

        // Generate the HTX content
        $htxContent = $this->generator->generate([
            'content_type' => $contentType,
            'display_mode' => $displayMode,
            'template_style' => $templateStyle,
            'fields' => $fieldsToDisplay
        ]);

        // Determine the file path
        $filePath = $this->routeResolver->routeToFilePath($route);
        $fullPath = $this->siteRoot . '/' . $filePath;

        // Create directory if needed
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write the HTX file
        file_put_contents($fullPath, $htxContent);

        $result = [
            'success' => true,
            'route' => $route,
            'file_created' => $filePath,
            'content_type' => $contentType,
            'display_mode' => $displayMode
        ];

        // Create admin files if requested
        if ($includeAdmin) {
            $adminFiles = $this->createAdminFiles($contentType, $templateStyle);
            $result['admin_files_created'] = $adminFiles;
        }

        return $result;
    }

    /**
     * Create admin HTX files for managing the content type
     */
    private function createAdminFiles(string $contentType, string $templateStyle): array
    {
        $adminDir = $this->siteRoot . '/admin/' . $contentType . 's';
        
        if (!is_dir($adminDir)) {
            mkdir($adminDir, 0755, true);
        }

        $files = [];

        // Index (list all)
        $indexContent = $this->generator->generate([
            'content_type' => $contentType,
            'display_mode' => 'list',
            'template_style' => 'table',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/index.htx', $indexContent);
        $files[] = "admin/{$contentType}s/index.htx";

        // New (create form)
        $newContent = $this->generator->generate([
            'content_type' => $contentType,
            'display_mode' => 'form',
            'action' => 'create',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/new.htx', $newContent);
        $files[] = "admin/{$contentType}s/new.htx";

        // Edit (update form)
        $editContent = $this->generator->generate([
            'content_type' => $contentType,
            'display_mode' => 'form',
            'action' => 'update',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/[id].htx', $editContent);
        $files[] = "admin/{$contentType}s/[id].htx";

        return $files;
    }
}
