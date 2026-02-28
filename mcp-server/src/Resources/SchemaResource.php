<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Resources;

use HyperMedia\McpServer\Contracts\ResourceInterface;
use HyperMedia\McpServer\Services\SchemaRegistry;

class SchemaResource implements ResourceInterface
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry
    ) {}

    public function getScheme(): string
    {
        return 'schema';
    }

    public function list(): array
    {
        $resources = [];

        foreach ($this->schemaRegistry->listTypes() as $type) {
            $mimeType = $this->schemaRegistry->hasFileSchema($type) ? 'text/yaml' : 'text/plain';

            $resources[] = [
                'uri' => "schema://{$type}",
                'name' => ucwords(str_replace('_', ' ', $type)) . ' Schema',
                'mimeType' => $mimeType,
                'description' => "Content type schema for {$type}",
            ];
        }

        return $resources;
    }

    public function read(string $uri): string
    {
        $parsed = parse_url($uri);
        $type = $parsed['host'] ?? '';

        if (!$type) {
            throw new \InvalidArgumentException("Invalid schema URI: {$uri}");
        }

        $schema = $this->schemaRegistry->getSchema($type);

        return $schema['content'];
    }

    public function supportsSubscription(): bool
    {
        return false;
    }
}
