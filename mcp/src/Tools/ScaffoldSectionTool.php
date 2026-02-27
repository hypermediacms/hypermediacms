<?php
/**
 * Scaffold Section Tool
 * 
 * Creates a complete content section in one call:
 * - Schema (custom fields)
 * - Public list page HTX
 * - Public single page HTX  
 * - Admin pages (list, new, edit)
 * - Optional: layout file
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

use HyperMediaCMS\MCP\Services\HTXGenerator;
use HyperMediaCMS\MCP\Services\RouteResolver;
use Symfony\Component\Yaml\Yaml;

class ScaffoldSectionTool implements ToolInterface
{
    private string $siteRoot;
    private string $schemasRoot;
    private HTXGenerator $generator;
    private RouteResolver $routeResolver;

    public function __construct(?string $siteRoot = null, ?string $schemasRoot = null)
    {
        $this->siteRoot = $siteRoot ?? dirname(__DIR__, 2) . '/../rufinus/site';
        $this->schemasRoot = $schemasRoot ?? dirname(__DIR__, 2) . '/../schemas';
        $this->generator = new HTXGenerator();
        $this->routeResolver = new RouteResolver($this->siteRoot);
    }

    public function getName(): string
    {
        return 'scaffold_section';
    }

    public function getDescription(): string
    {
        return 'Scaffold a complete content section: schema, public pages (list + single), and admin pages. ' .
               'One command to create everything needed for a new content type.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Section name (singular, e.g., "event", "product", "recipe")'
                ],
                'plural' => [
                    'type' => 'string',
                    'description' => 'Plural form for routes (default: name + "s")'
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Custom fields for the schema',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => [
                                'type' => 'string',
                                'enum' => ['text', 'textarea', 'number', 'select', 'checkbox', 'date', 'datetime', 'email', 'url', 'image']
                            ],
                            'options' => [
                                'type' => 'array',
                                'items' => ['type' => 'string']
                            ],
                            'required' => ['type' => 'boolean'],
                            'placeholder' => ['type' => 'string']
                        ],
                        'required' => ['name', 'type']
                    ]
                ],
                'template_style' => [
                    'type' => 'string',
                    'enum' => ['card', 'table', 'minimal'],
                    'description' => 'Visual style for list pages (default: card)'
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'Site namespace for schema (default: starter)'
                ],
                'add_to_nav' => [
                    'type' => 'boolean',
                    'description' => 'Add link to main navigation (default: false)'
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Description shown on the list page'
                ]
            ],
            'required' => ['name']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $name = $arguments['name'] ?? '';
        $plural = $arguments['plural'] ?? $name . 's';
        $fields = $arguments['fields'] ?? [];
        $templateStyle = $arguments['template_style'] ?? 'card';
        $site = $arguments['site'] ?? 'starter';
        $addToNav = $arguments['add_to_nav'] ?? false;
        $description = $arguments['description'] ?? "All {$plural}";

        if (empty($name)) {
            return ['error' => 'Section name is required'];
        }

        // Validate name format
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return [
                'error' => 'Invalid section name',
                'message' => 'Must be lowercase, start with a letter, contain only letters, numbers, underscores'
            ];
        }

        $created = [];
        $errors = [];

        // 1. Create schema
        if (!empty($fields)) {
            $schemaResult = $this->createSchema($name, $fields, $site);
            if ($schemaResult['success']) {
                $created['schema'] = $schemaResult['file'];
            } else {
                $errors['schema'] = $schemaResult['error'];
            }
        }

        // 2. Create public list page
        $listResult = $this->createListPage($name, $plural, $templateStyle, $description);
        if ($listResult['success']) {
            $created['list_page'] = $listResult['file'];
        } else {
            $errors['list_page'] = $listResult['error'];
        }

        // 3. Create public single page
        $singleResult = $this->createSinglePage($name, $plural, $templateStyle);
        if ($singleResult['success']) {
            $created['single_page'] = $singleResult['file'];
        } else {
            $errors['single_page'] = $singleResult['error'];
        }

        // 4. Create section layout (optional styling)
        $layoutResult = $this->createLayout($plural);
        if ($layoutResult['success']) {
            $created['layout'] = $layoutResult['file'];
        }

        // 5. Create admin pages
        $adminResult = $this->createAdminPages($name, $plural);
        if ($adminResult['success']) {
            $created['admin_pages'] = $adminResult['files'];
        } else {
            $errors['admin_pages'] = $adminResult['error'];
        }

        // 6. Add to navigation if requested
        if ($addToNav) {
            $navResult = $this->addToNavigation($plural, ucfirst($plural));
            if ($navResult['success']) {
                $created['navigation'] = 'Updated _layout.htx';
            } else {
                $errors['navigation'] = $navResult['error'];
            }
        }

        return [
            'success' => empty($errors),
            'section' => $name,
            'plural' => $plural,
            'routes' => [
                'list' => "/{$plural}",
                'single' => "/{$plural}/:slug",
                'admin_list' => "/admin/{$plural}",
                'admin_new' => "/admin/{$plural}/new",
                'admin_edit' => "/admin/{$plural}/:id"
            ],
            'created' => $created,
            'errors' => $errors
        ];
    }

    private function createSchema(string $name, array $fields, string $site): array
    {
        $schemaFields = [];
        foreach ($fields as $field) {
            $fieldDef = [
                'field_name' => $field['name'],
                'field_type' => $field['type']
            ];

            $constraints = [];
            if (!empty($field['required'])) {
                $constraints['required'] = true;
            }
            if (!empty($field['options'])) {
                $constraints['options'] = $field['options'];
            }
            if (!empty($constraints)) {
                $fieldDef['constraints'] = $constraints;
            }

            if (!empty($field['placeholder'])) {
                $fieldDef['ui_hints'] = ['placeholder' => $field['placeholder']];
            }

            $schemaFields[] = $fieldDef;
        }

        $schemaDir = $this->schemasRoot . '/' . $site;
        if (!is_dir($schemaDir)) {
            mkdir($schemaDir, 0755, true);
        }

        $schemaFile = $schemaDir . '/' . $name . '.yaml';
        $yaml = Yaml::dump(['fields' => $schemaFields], 4, 2);
        file_put_contents($schemaFile, $yaml);

        return ['success' => true, 'file' => "{$site}/{$name}.yaml"];
    }

    private function createListPage(string $name, string $plural, string $style, string $description): array
    {
        $title = ucfirst($plural);
        
        $content = <<<HTX
<htx:type>{$name}</htx:type>
<htx:howmany>20</htx:howmany>
<htx:order>recent</htx:order>

<htx>
  <div style="margin-bottom: 2rem;">
    <h1>{$title}</h1>
    <p style="color: #666;">{$description}</p>
  </div>

  <htx:each>
    <article class="card" style="margin-bottom: 1rem; padding: 1.5rem;">
      <h3 style="margin-bottom: 0.5rem;">
        <a href="/{$plural}/__slug__" hx-get="/{$plural}/__slug__" hx-target="main" hx-push-url="true">
          __title__
        </a>
      </h3>
      <p style="color: #666; margin-bottom: 0.5rem;">{{ truncate(body, 150) }}</p>
      <div style="font-size: 0.85rem; color: #888;">
        {{ time_ago(updated_at) }} &middot; <span class="status-__status__">__status__</span>
      </div>
    </article>
  </htx:each>

  <htx:none>
    <div style="text-align: center; padding: 3rem; color: #666;">
      <p>No {$plural} found.</p>
    </div>
  </htx:none>
</htx>
HTX;

        $dir = $this->siteRoot . '/' . $plural;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/index.htx';
        file_put_contents($file, $content);

        return ['success' => true, 'file' => "{$plural}/index.htx"];
    }

    private function createSinglePage(string $name, string $plural, string $style): array
    {
        $content = <<<HTX
<htx:type>{$name}</htx:type>
<htx:howmany>1</htx:howmany>

<htx>
  <htx:each>
    <article style="max-width: 700px; margin: 0 auto;">
      <a href="/{$plural}" hx-get="/{$plural}" hx-target="main" hx-push-url="true" 
         style="display: inline-block; margin-bottom: 1.5rem; color: #1a1a2e; text-decoration: none; font-size: 0.9rem;">
        ← Back to all {$plural}
      </a>
      
      <div class="card" style="padding: 2rem;">
        <header style="margin-bottom: 1.5rem;">
          <h1 style="margin: 0; font-size: 1.75rem;">__title__</h1>
          <div style="margin-top: 0.5rem; font-size: 0.85rem; color: #888;">
            {{ time_ago(updated_at) }}
          </div>
        </header>

        <div style="line-height: 1.8; color: #333;">
          {{! body_html }}
        </div>
      </div>
    </article>
  </htx:each>

  <htx:none>
    <div class="card" style="text-align: center; padding: 3rem;">
      <p style="color: #666;">Not found.</p>
      <a href="/{$plural}" hx-get="/{$plural}" hx-target="main" hx-push-url="true">
        ← Back to all {$plural}
      </a>
    </div>
  </htx:none>
</htx>
HTX;

        $dir = $this->siteRoot . '/' . $plural;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/[slug].htx';
        file_put_contents($file, $content);

        return ['success' => true, 'file' => "{$plural}/[slug].htx"];
    }

    private function createLayout(string $plural): array
    {
        // Optional: create a section-specific layout
        // For now, just return success without creating
        return ['success' => true, 'file' => null];
    }

    private function createAdminPages(string $name, string $plural): array
    {
        $title = ucfirst($plural);
        $adminDir = $this->siteRoot . '/admin/' . $plural;
        
        if (!is_dir($adminDir)) {
            mkdir($adminDir, 0755, true);
        }

        $files = [];

        // Admin list
        $listContent = $this->generator->generate([
            'content_type' => $name,
            'display_mode' => 'list',
            'template_style' => 'table',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/index.htx', $listContent);
        $files[] = "admin/{$plural}/index.htx";

        // Admin new
        $newContent = $this->generator->generate([
            'content_type' => $name,
            'display_mode' => 'form',
            'action' => 'create',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/new.htx', $newContent);
        $files[] = "admin/{$plural}/new.htx";

        // Admin edit
        $editContent = $this->generator->generate([
            'content_type' => $name,
            'display_mode' => 'form',
            'action' => 'update',
            'is_admin' => true
        ]);
        file_put_contents($adminDir . '/[id].htx', $editContent);
        $files[] = "admin/{$plural}/[id].htx";

        return ['success' => true, 'files' => $files];
    }

    private function addToNavigation(string $plural, string $label): array
    {
        $layoutFile = $this->siteRoot . '/_layout.htx';
        
        if (!file_exists($layoutFile)) {
            return ['success' => false, 'error' => 'Layout file not found'];
        }

        $content = file_get_contents($layoutFile);
        
        // Find the nav section and add a link
        $navLink = "<a href=\"/{$plural}\" hx-get=\"/{$plural}\" hx-target=\"main\" hx-push-url=\"true\">{$label}</a>";
        
        // Look for the About link and insert before it
        if (strpos($content, 'href="/about"') !== false) {
            $content = str_replace(
                '<a href="/about"',
                $navLink . "\n    " . '<a href="/about"',
                $content
            );
            file_put_contents($layoutFile, $content);
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Could not find insertion point in nav'];
    }
}
