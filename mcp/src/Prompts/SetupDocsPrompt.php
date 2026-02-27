<?php
/**
 * Setup Docs Prompt
 * 
 * Guides the AI through setting up a documentation section
 * with sidebar navigation and organized categories.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class SetupDocsPrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'setup_docs';
    }

    public function getDescription(): string
    {
        return 'Create a documentation section with sidebar navigation, ' .
               'categories, and proper ordering for technical docs or guides.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'sections',
                'description' => 'Comma-separated doc sections/categories (e.g., "Getting Started,API Reference,Examples")',
                'required' => false
            ],
            [
                'name' => 'route_prefix',
                'description' => 'Base route for docs (default: /docs)',
                'required' => false
            ],
            [
                'name' => 'include_search',
                'description' => 'Include search functionality (yes/no, default: yes)',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $sectionsStr = $arguments['sections'] ?? 'Getting Started,Guides,API Reference';
        $routePrefix = $arguments['route_prefix'] ?? '/docs';
        $includeSearch = ($arguments['include_search'] ?? 'yes') === 'yes';
        
        $sections = array_map('trim', explode(',', $sectionsStr));
        $sectionsJson = json_encode($sections);

        $prompt = <<<PROMPT
Set up a comprehensive documentation section for this site.

## Configuration

- **Route Prefix**: {$routePrefix}
- **Sections**: {$sectionsStr}
- **Search**: {($includeSearch ? 'Yes' : 'No')}

## Implementation Steps

### 1. Create Documentation Schema

Use `create_schema` with these fields:

```json
{
  "content_type": "doc",
  "fields": [
    {"name": "section", "type": "select", "options": {$sectionsJson}},
    {"name": "sort_order", "type": "number", "placeholder": "Order within section (lower = first)"},
    {"name": "summary", "type": "textarea", "placeholder": "Brief description for listings"},
    {"name": "prev_doc", "type": "text", "placeholder": "Slug of previous doc (for navigation)"},
    {"name": "next_doc", "type": "text", "placeholder": "Slug of next doc (for navigation)"}
  ]
}
```

### 2. Create Documentation Layout

Create a custom layout for docs at `{$routePrefix}/_layout.htx` with:
- Sidebar navigation grouped by section
- Main content area
- Previous/Next navigation at bottom
- Table of contents for current page (optional)

### 3. Create Documentation Index

Create `{$routePrefix}/index.htx` that shows:
- Welcome/overview content
- List of sections with descriptions
- Quick links to popular docs

### 4. Create Single Doc Template

Create `{$routePrefix}/[slug].htx` with:
- Full document content
- Sidebar (inherited from layout)
- Previous/Next navigation
- Last updated timestamp

### 5. Create Admin Pages

Use `scaffold_section` or manually create:
- `admin/docs/index.htx` - List all docs, sortable by section
- `admin/docs/new.htx` - Create new doc
- `admin/docs/[id].htx` - Edit doc

### 6. Add Sample Content

Create introductory docs for each section:

PROMPT;

        // Add sample docs for each section
        foreach ($sections as $i => $section) {
            $slug = strtolower(str_replace(' ', '-', $section));
            $prompt .= <<<DOC

**{$section}:**
```json
{
  "title": "Introduction to {$section}",
  "slug": "{$slug}-intro",
  "section": "{$section}",
  "sort_order": {$i}0,
  "body": "Welcome to {$section}. This guide will help you...",
  "status": "published"
}
```
DOC;
        }

        $prompt .= <<<PROMPT


### 7. Verify Structure

After setup, verify:
- All routes work correctly
- Sidebar shows all sections
- Navigation between docs works
- Admin pages function properly

Begin setting up the documentation section now.
PROMPT;

        return [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];
    }
}
