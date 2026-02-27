<?php
/**
 * Create Blog Section Prompt
 * 
 * Guides the AI through creating a complete blog section with:
 * - Content type schema with customizable fields
 * - List and single page templates
 * - Admin pages for management
 * - Optional features like categories, tags, etc.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class CreateBlogSectionPrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'create_blog_section';
    }

    public function getDescription(): string
    {
        return 'Create a complete blog section with posts, customizable fields, and admin pages. ' .
               'Supports optional features like categories, tags, author, and featured images.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'section_name',
                'description' => 'Name for the blog section (default: "post")',
                'required' => false
            ],
            [
                'name' => 'features',
                'description' => 'Comma-separated features to include: categories, tags, author, featured_image, excerpt, reading_time',
                'required' => false
            ],
            [
                'name' => 'add_to_nav',
                'description' => 'Add link to main navigation (yes/no, default: yes)',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $name = $arguments['section_name'] ?? 'post';
        $featuresStr = $arguments['features'] ?? 'categories,excerpt';
        $addToNav = ($arguments['add_to_nav'] ?? 'yes') !== 'no';
        
        $features = array_map('trim', explode(',', $featuresStr));
        $plural = $name . 's';

        // Build the field definitions based on features
        $fields = [];
        
        if (in_array('categories', $features)) {
            $fields[] = '{"name": "category", "type": "select", "options": ["general", "tutorial", "news", "review"]}';
        }
        if (in_array('tags', $features)) {
            $fields[] = '{"name": "tags", "type": "text", "placeholder": "Comma-separated tags"}';
        }
        if (in_array('author', $features)) {
            $fields[] = '{"name": "author", "type": "text"}';
        }
        if (in_array('featured_image', $features)) {
            $fields[] = '{"name": "featured_image", "type": "url", "placeholder": "Image URL"}';
        }
        if (in_array('excerpt', $features)) {
            $fields[] = '{"name": "excerpt", "type": "textarea", "placeholder": "Brief summary for listings"}';
        }
        if (in_array('reading_time', $features)) {
            $fields[] = '{"name": "reading_time", "type": "number", "placeholder": "Minutes to read"}';
        }

        $fieldsJson = '[' . implode(', ', $fields) . ']';

        $prompt = <<<PROMPT
Create a complete blog section called "{$name}" for this Hypermedia CMS site.

## Requirements

1. **Content Type**: {$name}
   - Plural: {$plural}
   - Custom fields: {$featuresStr}

2. **Pages to Create**:
   - `/{$plural}` - List page showing all published {$plural}
   - `/{$plural}/:slug` - Single {$name} page with full content
   - Admin pages for creating and editing {$plural}

3. **Navigation**: {($addToNav ? 'Add link to main nav' : 'Do not add to nav')}

## Steps

Use the `scaffold_section` tool with these parameters:

```json
{
  "name": "{$name}",
  "plural": "{$plural}",
  "fields": {$fieldsJson},
  "add_to_nav": {($addToNav ? 'true' : 'false')},
  "description": "All {$plural}"
}
```

After scaffolding, customize the templates if needed:
- Add featured image display to single page if that feature was requested
- Add category/tag filtering to list page if those features were requested
- Style the reading time display appropriately

Finally, create 2-3 sample {$plural} using `create_content` to demonstrate the section.
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
