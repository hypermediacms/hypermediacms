<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class CreateContentTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen
    ) {}

    public function getName(): string
    {
        return 'create_content';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Create new content in the CMS. Use this to add articles, create form definitions, or add any content type.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Content type (e.g., "article", "form_definition", "todo")',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Title of the content',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'URL slug (auto-generated from title if not provided)',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Publication status',
                        'enum' => ['draft', 'published', 'review'],
                        'default' => 'draft',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Main body content (markdown supported for articles)',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'Short excerpt or summary',
                    ],
                    'fields' => [
                        'type' => 'object',
                        'description' => 'Additional fields specific to the content type (e.g., form fields JSON)',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['type', 'title'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $type = $input['type'];
            $title = $input['title'];
            $slug = $input['slug'] ?? $this->generateSlug($title);
            $status = $input['status'] ?? 'draft';

            // Prepare the mutation
            $prepareResult = $this->origen->prepare('save', $type);
            $tokenData = OrigenClient::extractToken($prepareResult);

            if (!$tokenData['token']) {
                throw new \RuntimeException('Failed to get mutation token');
            }

            // Build content data
            $data = [
                'type' => $type,
                'title' => $title,
                'slug' => $slug,
                'status' => $status,
            ];

            if (isset($input['body'])) {
                $data['body'] = $input['body'];
            }

            if (isset($input['excerpt'])) {
                $data['excerpt'] = $input['excerpt'];
            }

            // Merge additional fields
            if (isset($input['fields']) && is_array($input['fields'])) {
                foreach ($input['fields'] as $key => $value) {
                    // Handle JSON fields
                    if (is_array($value)) {
                        $data[$key] = json_encode($value);
                    } else {
                        $data[$key] = $value;
                    }
                }
            }

            // Execute save
            $result = $this->origen->save(
                $tokenData['token'],
                $tokenData['context'],
                $data
            );

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "✅ Created {$type}: {$title}\n" .
                              "Slug: {$slug}\n" .
                              "Status: {$status}\n" .
                              "URL: /{$this->getUrlPath($type, $slug)}",
                ]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "Error creating content: {$e->getMessage()}",
                ]],
                'isError' => true,
            ];
        }
    }

    private function generateSlug(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    private function getUrlPath(string $type, string $slug): string
    {
        return match ($type) {
            'article' => "blog/{$slug}",
            'form_definition' => "forms/{$slug}",
            default => "{$type}/{$slug}",
        };
    }
}
