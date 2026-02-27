<?php
/**
 * Audit Site Prompt
 * 
 * Guides the AI through reviewing the site structure and
 * suggesting improvements.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

class AuditSitePrompt implements PromptInterface
{
    public function getName(): string
    {
        return 'audit_site';
    }

    public function getDescription(): string
    {
        return 'Review site structure, identify issues, and suggest improvements. ' .
               'Checks routes, content types, orphaned content, and missing templates.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'focus',
                'description' => 'Area to focus on: all, routes, content, templates, performance (default: all)',
                'required' => false
            ],
            [
                'name' => 'verbose',
                'description' => 'Include detailed findings (yes/no, default: no)',
                'required' => false
            ]
        ];
    }

    public function getMessages(array $arguments): array
    {
        $focus = $arguments['focus'] ?? 'all';
        $verbose = ($arguments['verbose'] ?? 'no') === 'yes';

        $prompt = <<<PROMPT
Perform a comprehensive audit of this Hypermedia CMS site.

## Audit Focus: {$focus}

## Steps

### 1. Gather Site Information

Use these resources to understand the current state:
- `hcms://site/stats` — Get content counts and metrics
- `hcms://site/routes` — Get all routes and their configurations
- `hcms://schemas` — Get all content type schemas
- `hcms://templates` — Get all HTX template files

### 2. Check for Issues

**Route Issues:**
- Routes without corresponding content types
- Dynamic routes (`:slug`) without matching content
- Admin routes that may be missing CRUD operations
- Duplicate or conflicting routes

**Content Issues:**
- Content types without any published content
- Orphaned content (exists but no route displays it)
- Content with missing required custom fields
- Draft content that's been sitting too long

**Template Issues:**
- Templates referencing non-existent content types
- Missing `<htx:none>` fallback for empty states
- Templates without proper HTMX attributes
- Inconsistent styling across similar templates

**Schema Issues:**
- Content types used in templates but no schema defined
- Schemas with fields that aren't being used
- Missing validation constraints on required fields

### 3. Generate Report

Create a structured report with:

```markdown
# Site Audit Report

## Summary
- Total routes: X
- Total content types: X  
- Total content items: X
- Issues found: X

## Issues

### Critical
- [List critical issues]

### Warnings  
- [List warnings]

### Suggestions
- [List improvement suggestions]

## Recommendations

[Prioritized list of recommended actions]
```

### 4. Optional Fixes

If issues are found, offer to fix them using the available tools:
- `scaffold_section` for missing admin pages
- `update_htx` to fix template issues
- `create_content` to add missing sample content

{$verbose ? '### Verbose Mode\n\nInclude detailed code snippets and specific file contents in the report.' : ''}

Begin the audit now.
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
