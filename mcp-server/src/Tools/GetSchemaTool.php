<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;
use HyperMedia\McpServer\Services\SchemaRegistry;

class GetSchemaTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen,
        private readonly SchemaRegistry $schemaRegistry
    ) {}

    public function getName(): string
    {
        return 'get_schema';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Get the schema for a content type. Use this to understand what fields are available.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Content type to get schema for (e.g., "article", "form_definition")',
                    ],
                    'list_types' => [
                        'type' => 'boolean',
                        'description' => 'If true, list all available content types instead',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            if ($input['list_types'] ?? false) {
                $types = $this->schemaRegistry->listTypes();

                $output = "Available content types:\n\n";
                foreach ($types as $type) {
                    $output .= "• {$type}\n";
                }
                $output .= "\nUse get_schema with a specific type to see its fields.";

                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => $output,
                    ]],
                ];
            }

            $type = $input['type'] ?? null;
            if (!$type) {
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => "Please specify a content type or set list_types: true",
                    ]],
                    'isError' => true,
                ];
            }

            $schema = $this->schemaRegistry->getSchema($type);

            $text = $schema['source'] === 'file'
                ? "Schema for {$type}:\n\n{$schema['content']}"
                : $schema['content'];

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "Error getting schema: {$e->getMessage()}",
                ]],
                'isError' => true,
            ];
        }
    }
}
