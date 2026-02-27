<?php
/**
 * Schema Resource
 * 
 * Exposes content type schemas.
 * URIs: 
 * - hcms://schemas (list all)
 * - hcms://schema/{type} (specific schema)
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

use Symfony\Component\Yaml\Yaml;

class SchemaResource extends AbstractResource
{
    private string $schemasRoot;
    private bool $isList;

    public function __construct(?string $schemasRoot = null, bool $isList = false)
    {
        $this->schemasRoot = $schemasRoot ?? dirname(__DIR__, 2) . '/../schemas';
        $this->isList = $isList;
        
        if ($isList) {
            $this->uriPattern = 'hcms://schemas';
            $this->name = 'Schema List';
            $this->description = 'List all content type schemas';
        } else {
            $this->uriPattern = 'hcms://schema/{type}';
            $this->name = 'Schema Definition';
            $this->description = 'Content type schema with field definitions';
        }
        
        $this->mimeType = 'application/json';
        $this->buildRegex();
    }

    public function read(array $params): array
    {
        if ($this->isList) {
            return $this->readSchemaList();
        }
        
        $type = $params['type'] ?? '';
        $uri = "hcms://schema/{$type}";
        
        if (empty($type)) {
            return $this->formatContent($uri, ['error' => 'Schema type required']);
        }

        $schema = $this->findSchema($type);
        
        if (!$schema) {
            return $this->formatContent($uri, ['error' => 'Schema not found']);
        }

        return $this->formatContent($uri, $schema);
    }

    private function readSchemaList(): array
    {
        $schemas = $this->getAllSchemas();
        
        return $this->formatContent('hcms://schemas', [
            'count' => count($schemas),
            'schemas' => $schemas
        ]);
    }

    public function listInstances(int $limit = 10): array
    {
        if ($this->isList) {
            return [$this->formatDescriptor(
                'hcms://schemas',
                'All Schemas',
                'List of all content type schemas'
            )];
        }

        $schemas = $this->getAllSchemas();
        $instances = [];

        foreach (array_slice($schemas, 0, $limit) as $schema) {
            $instances[] = $this->formatDescriptor(
                "hcms://schema/{$schema['type']}",
                ucfirst($schema['type']) . ' Schema',
                "Fields: " . implode(', ', array_column($schema['fields'] ?? [], 'name'))
            );
        }

        return $instances;
    }

    private function findSchema(string $type): ?array
    {
        // Search all site directories for the schema
        foreach (glob($this->schemasRoot . '/*', GLOB_ONLYDIR) as $siteDir) {
            $schemaFile = $siteDir . '/' . $type . '.yaml';
            if (file_exists($schemaFile)) {
                return $this->parseSchema($type, $schemaFile);
            }
        }

        return null;
    }

    private function getAllSchemas(): array
    {
        $schemas = [];

        foreach (glob($this->schemasRoot . '/*', GLOB_ONLYDIR) as $siteDir) {
            $site = basename($siteDir);
            
            foreach (glob($siteDir . '/*.yaml') as $schemaFile) {
                $type = basename($schemaFile, '.yaml');
                $schemas[] = $this->parseSchema($type, $schemaFile, $site);
            }
        }

        return $schemas;
    }

    private function parseSchema(string $type, string $file, ?string $site = null): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (\Exception $e) {
            return [
                'type' => $type,
                'error' => 'Failed to parse schema'
            ];
        }

        $fields = array_map(function ($field) {
            return [
                'name' => $field['field_name'] ?? 'unknown',
                'type' => $field['field_type'] ?? 'text',
                'constraints' => $field['constraints'] ?? [],
                'ui_hints' => $field['ui_hints'] ?? []
            ];
        }, $data['fields'] ?? []);

        return [
            'type' => $type,
            'site' => $site ?? basename(dirname($file)),
            'fields' => $fields,
            'base_fields' => ['title', 'slug', 'body', 'status', 'created_at', 'updated_at']
        ];
    }
}
