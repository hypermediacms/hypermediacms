<?php
/**
 * Create Portfolio Prompt
 * 
 * Guides the AI through creating a portfolio/projects section
 * for showcasing work.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class CreatePortfolioPrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'create_portfolio';
    }

    public function getDescription(): string
    {
        return 'Create a portfolio or projects section to showcase work, ' .
               'with optional fields like tech stack, links, and images.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'section_name',
                'description' => 'Name for the section (default: "project")',
                'required' => false
            ],
            [
                'name' => 'fields',
                'description' => 'Comma-separated extra fields: tech_stack, demo_url, github_url, client, year, featured_image',
                'required' => false
            ],
            [
                'name' => 'layout',
                'description' => 'Grid layout style: cards, masonry, list (default: cards)',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $name = $arguments['section_name'] ?? 'project';
        $fieldsStr = $arguments['fields'] ?? 'tech_stack,demo_url,github_url,featured_image';
        $layout = $arguments['layout'] ?? 'cards';
        
        $features = array_map('trim', explode(',', $fieldsStr));
        $plural = $name . 's';

        // Build field definitions
        $fields = [];
        
        if (in_array('tech_stack', $features)) {
            $fields[] = '{"name": "tech_stack", "type": "text", "placeholder": "React, Node.js, PostgreSQL"}';
        }
        if (in_array('demo_url', $features)) {
            $fields[] = '{"name": "demo_url", "type": "url", "placeholder": "https://demo.example.com"}';
        }
        if (in_array('github_url', $features)) {
            $fields[] = '{"name": "github_url", "type": "url", "placeholder": "https://github.com/..."}';
        }
        if (in_array('client', $features)) {
            $fields[] = '{"name": "client", "type": "text", "placeholder": "Client or company name"}';
        }
        if (in_array('year', $features)) {
            $fields[] = '{"name": "year", "type": "number", "placeholder": "2024"}';
        }
        if (in_array('featured_image', $features)) {
            $fields[] = '{"name": "featured_image", "type": "url", "placeholder": "Screenshot or preview image URL"}';
        }
        if (in_array('category', $features)) {
            $fields[] = '{"name": "category", "type": "select", "options": ["web", "mobile", "design", "other"]}';
        }

        $fieldsJson = '[' . implode(', ', $fields) . ']';

        $layoutStyles = match ($layout) {
            'masonry' => 'CSS grid with varying heights, Pinterest-style',
            'list' => 'Full-width cards, one per row, more detail visible',
            default => 'Equal-height cards in responsive grid (2-3 columns)'
        };

        $prompt = <<<PROMPT
Create a portfolio section called "{$name}" to showcase work.

## Configuration

- **Content Type**: {$name}
- **Plural Route**: /{$plural}
- **Fields**: {$fieldsStr}
- **Layout**: {$layout} ({$layoutStyles})

## Steps

### 1. Scaffold the Section

Use `scaffold_section`:

```json
{
  "name": "{$name}",
  "plural": "{$plural}",
  "fields": {$fieldsJson},
  "add_to_nav": true,
  "description": "My {$plural} and work"
}
```

### 2. Customize the List Template

Update `/{$plural}/index.htx` with portfolio-appropriate styling:

- Large preview images (if featured_image is enabled)
- Tech stack tags/badges
- Hover effects showing project links
- Filter by category (if enabled)

Example card structure:
```html
<article class="portfolio-card">
  <img src="__featured_image__" alt="__title__">
  <div class="overlay">
    <h3>__title__</h3>
    <p class="tech">__tech_stack__</p>
    <div class="links">
      <a href="__demo_url__">Demo</a>
      <a href="__github_url__">Code</a>
    </div>
  </div>
</article>
```

### 3. Customize the Single Page

Update `/{$plural}/[slug].htx` with:

- Hero image/screenshot
- Project description
- Tech stack breakdown
- Links to demo and source
- Client info (if applicable)
- Related projects

### 4. Create Sample Projects

Add 2-3 sample projects demonstrating different types of work:

```json
{
  "title": "Sample Web App",
  "slug": "sample-web-app",
  "body": "A full-stack web application built with modern technologies...",
  "tech_stack": "React, Node.js, PostgreSQL",
  "demo_url": "https://example.com",
  "github_url": "https://github.com/example/repo",
  "status": "published"
}
```

### 5. Verify and Polish

- Check responsive layout on mobile
- Verify all links work
- Test filtering if enabled
- Add subtle animations/transitions

Begin creating the portfolio section now.
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
