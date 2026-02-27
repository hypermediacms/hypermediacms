<?php
/**
 * Quick Content Prompt
 * 
 * Quickly create content with AI-assisted writing.
 * Good for when you know what you want but need help writing it.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class QuickContentPrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'quick_content';
    }

    public function getDescription(): string
    {
        return 'Quickly create content with AI assistance. Describe what you want ' .
               'and the AI will help write and publish it.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'content_type',
                'description' => 'Type of content to create (e.g., article, recipe, project)',
                'required' => true
            ],
            [
                'name' => 'topic',
                'description' => 'What the content should be about',
                'required' => true
            ],
            [
                'name' => 'tone',
                'description' => 'Writing tone: professional, casual, technical, friendly (default: professional)',
                'required' => false
            ],
            [
                'name' => 'length',
                'description' => 'Approximate length: short, medium, long (default: medium)',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $contentType = $arguments['content_type'] ?? 'article';
        $topic = $arguments['topic'] ?? 'general topic';
        $tone = $arguments['tone'] ?? 'professional';
        $length = $arguments['length'] ?? 'medium';

        $lengthGuide = match ($length) {
            'short' => '200-400 words, focused and concise',
            'long' => '800-1200 words, comprehensive with examples',
            default => '400-600 words, balanced coverage'
        };

        $prompt = <<<PROMPT
Create a new {$contentType} about: "{$topic}"

## Writing Guidelines

- **Tone**: {$tone}
- **Length**: {$length} ({$lengthGuide})
- **Format**: Use Markdown with headers, lists where appropriate

## Steps

### 1. Check the Content Type

First, read the schema to understand what fields are available:
- Use resource `hcms://schema/{$contentType}` 

### 2. Generate the Content

Write the content following these principles:
- Start with an engaging introduction
- Use clear section headers for longer pieces
- Include practical examples or actionable advice
- End with a conclusion or call-to-action

### 3. Generate Metadata

Based on the content type's schema, fill in:
- A compelling title (not just "{$topic}")
- A URL-friendly slug
- Any custom fields (category, tags, etc.)
- Set status to "published"

### 4. Create the Content

Use `create_content` with the generated content:

```json
{
  "content_type": "{$contentType}",
  "title": "[Generated title]",
  "slug": "[generated-slug]",
  "body": "[Generated markdown content]",
  "status": "published",
  "custom_fields": {
    // Any custom fields from the schema
  }
}
```

### 5. Verify

After creating, check that:
- The content appears on the public page
- All custom fields display correctly
- The formatting looks good

Now, write the content about "{$topic}" and create it.
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
