<?php
/**
 * List Content Types Tool
 * 
 * Lists all available content types by scanning schema files and existing HTX files.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class ListContentTypesTool implements ToolInterface
{
    private string $schemasRoot;
    private string $siteRoot;

    public function __construct(?string $schemasRoot = null, ?string $siteRoot = null)
    {
        $this->schemasRoot = $schemasRoot ?? dirname(__DIR__, 2) . '/../schemas';
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
    }

    public function getName(): string
    {
        return 'list_content_types';
    }

    public function getDescription(): string
    {
        return 'List all available content types, including their schemas and which routes display them.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_fields' => [
                    'type' => 'boolean',
                    'description' => 'Include field definitions for each content type (default: false)'
                ]
            ]
        ];
    }

    public function execute(array $arguments): mixed
    {
        $includeFields = $arguments['include_fields'] ?? false;

        $contentTypes = [];

        // Scan schema directories
        foreach (glob($this->schemasRoot . '/*', GLOB_ONLYDIR) as $siteDir) {
            $siteName = basename($siteDir);
            
            foreach (glob($siteDir . '/*.yaml') as $schemaFile) {
                $typeName = basename($schemaFile, '.yaml');
                
                $typeInfo = [
                    'name' => $typeName,
                    'site' => $siteName,
                    'schema_file' => str_replace($this->schemasRoot . '/', '', $schemaFile),
                    'routes' => $this->findRoutesForType($typeName)
                ];

                if ($includeFields) {
                    $typeInfo['fields'] = $this->parseSchemaFields($schemaFile);
                }

                $contentTypes[] = $typeInfo;
            }
        }

        // Also detect content types from HTX files that might not have schemas
        $htxTypes = $this->detectTypesFromHTX();
        foreach ($htxTypes as $typeName) {
            $exists = false;
            foreach ($contentTypes as $ct) {
                if ($ct['name'] === $typeName) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $contentTypes[] = [
                    'name' => $typeName,
                    'site' => null,
                    'schema_file' => null,
                    'routes' => $this->findRoutesForType($typeName),
                    'note' => 'Detected from HTX files, no schema defined'
                ];
            }
        }

        return [
            'content_types' => $contentTypes,
            'total' => count($contentTypes)
        ];
    }

    /**
     * Find routes that display a given content type
     */
    private function findRoutesForType(string $typeName): array
    {
        $routes = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            
            // Check if this HTX file references the content type
            if (preg_match('/<htx:type>\s*' . preg_quote($typeName, '/') . '\s*<\/htx:type>/i', $content)) {
                $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
                $route = $this->filePathToRoute($relativePath);
                $routes[] = $route;
            }
        }

        return $routes;
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
     * Parse schema YAML to extract fields
     */
    private function parseSchemaFields(string $schemaFile): array
    {
        $content = file_get_contents($schemaFile);
        $data = yaml_parse($content);
        
        if (!$data || !isset($data['fields'])) {
            return [];
        }

        return array_map(function ($field) {
            return [
                'name' => $field['field_name'] ?? 'unknown',
                'type' => $field['field_type'] ?? 'text',
                'constraints' => $field['constraints'] ?? []
            ];
        }, $data['fields']);
    }

    /**
     * Detect content types referenced in HTX files
     */
    private function detectTypesFromHTX(): array
    {
        $types = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            
            if (preg_match('/<htx:type>\s*([^<]+)\s*<\/htx:type>/i', $content, $matches)) {
                $types[] = trim($matches[1]);
            }
        }

        return array_unique($types);
    }
}
