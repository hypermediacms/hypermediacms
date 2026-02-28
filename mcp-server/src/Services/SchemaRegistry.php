<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Services;

class SchemaRegistry
{
    public function __construct(
        private readonly string $schemaPath
    ) {}

    /**
     * List all available content types.
     *
     * @return string[]
     */
    public function listTypes(): array
    {
        $types = [];

        if (is_dir($this->schemaPath)) {
            foreach (glob($this->schemaPath . '/*.yaml') as $file) {
                $types[] = basename($file, '.yaml');
            }
        }

        $builtIn = ['article', 'form_definition', 'form_submission', 'todo', 'documentation'];
        $types = array_unique(array_merge($types, $builtIn));
        sort($types);

        return $types;
    }

    /**
     * Check if a file-based schema exists for the given type.
     */
    public function hasFileSchema(string $type): bool
    {
        return file_exists($this->schemaPath . '/' . $type . '.yaml');
    }

    /**
     * Get schema content for a type.
     *
     * @return array{content: string, source: 'file'|'builtin'}
     */
    public function getSchema(string $type): array
    {
        $schemaFile = $this->schemaPath . '/' . $type . '.yaml';

        if (file_exists($schemaFile)) {
            return [
                'content' => file_get_contents($schemaFile),
                'source' => 'file',
            ];
        }

        return [
            'content' => $this->getBuiltInSchema($type),
            'source' => 'builtin',
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
