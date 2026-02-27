<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class GetSchemaTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen,
        private readonly string $schemaPath
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
            // List all types
            if ($input['list_types'] ?? false) {
                return $this->listTypes();
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

            // Try to read schema file
            $schemaFile = $this->schemaPath . '/' . $type . '.yaml';
            
            if (file_exists($schemaFile)) {
                $schema = file_get_contents($schemaFile);
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => "Schema for {$type}:\n\n{$schema}",
                    ]],
                ];
            }

            // Return built-in schema descriptions
            $schema = $this->getBuiltInSchema($type);

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $schema,
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

    private function listTypes(): array
    {
        $types = [];

        // Scan schema directory
        if (is_dir($this->schemaPath)) {
            foreach (glob($this->schemaPath . '/*.yaml') as $file) {
                $types[] = basename($file, '.yaml');
            }
        }

        // Add built-in types
        $builtIn = ['article', 'form_definition', 'form_submission', 'todo', 'documentation'];
        $types = array_unique(array_merge($types, $builtIn));
        sort($types);

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

    private function getBuiltInSchema(string $type): string
    {
        $schemas = [
            'article' => <<<SCHEMA
Schema for article:

Core Fields (all content types):
  - id: integer (auto-generated)
  - title: string (required)
  - slug: string (URL-safe identifier)
  - body: text (markdown content)
  - body_html: text (auto-generated from body)
  - status: enum [draft, published, review, archived]
  - created_at: datetime
  - updated_at: datetime

Article-Specific Fields:
  - excerpt: text (short summary)
  - category: string
  - summary: text

Usage Example:
{
  "type": "article",
  "title": "My Post",
  "slug": "my-post",
  "status": "published",
  "body": "# Hello\\n\\nThis is my article.",
  "excerpt": "A brief summary"
}
SCHEMA,

            'form_definition' => <<<SCHEMA
Schema for form_definition:

Core Fields:
  - id, title, slug, status (see article)

Form-Specific Fields:
  - fields: JSON array of field definitions
  - field_count: integer (auto-calculated)
  - active: boolean
  - submit_text: string (button label)

Field Definition Structure:
{
  "id": "field_123",
  "type": "text|email|textarea|select|checkbox|radio|date|number|heading|paragraph|divider",
  "label": "Field Label",
  "placeholder": "Placeholder text",
  "required": true|false,
  "options": [{"value": "opt1", "label": "Option 1"}]  // for select/radio
}

Usage Example:
{
  "type": "form_definition",
  "title": "Contact Form",
  "slug": "contact",
  "status": "published",
  "fields": {
    "fields": [
      {"id": "name", "type": "text", "label": "Name", "required": true},
      {"id": "email", "type": "email", "label": "Email", "required": true}
    ]
  }
}
SCHEMA,

            'todo' => <<<SCHEMA
Schema for todo:

Core Fields:
  - id, title, slug, status

Todo-Specific Fields:
  - completed: boolean

Usage Example:
{
  "type": "todo",
  "title": "Buy groceries",
  "status": "published",
  "fields": {
    "completed": "false"
  }
}
SCHEMA,

            'form_submission' => <<<SCHEMA
Schema for form_submission:

Core Fields:
  - id, title, slug, status

Submission-Specific Fields:
  - form_id: integer (reference to form_definition)
  - form_slug: string
  - data: JSON (submitted field values)
  - submitted_at: datetime

Note: Form submissions are typically created by form handlers, not manually.
SCHEMA,
        ];

        return $schemas[$type] ?? "No schema information available for: {$type}\n\nThis may be a custom content type. Check the schemas directory.";
    }
}
