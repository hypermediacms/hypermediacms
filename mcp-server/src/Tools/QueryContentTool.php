<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class QueryContentTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen
    ) {}

    public function getName(): string
    {
        return 'query_content';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Query content from the CMS. Use this to list articles, forms, or any content type.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Content type to query (e.g., "article", "form_definition", "todo")',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status',
                        'enum' => ['draft', 'published', 'archived', 'review'],
                    ],
                    'order' => [
                        'type' => 'string',
                        'description' => 'Sort order',
                        'enum' => ['newest', 'oldest', 'recent', 'alpha'],
                        'default' => 'newest',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results',
                        'default' => 20,
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Get a specific item by slug',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Get a specific item by ID',
                    ],
                ],
                'required' => ['type'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $params = [
                'type' => $input['type'],
            ];

            if (isset($input['status'])) {
                $params['status'] = $input['status'];
            }

            if (isset($input['order'])) {
                $params['order'] = $input['order'];
            }

            if (isset($input['limit'])) {
                $params['howmany'] = $input['limit'];
            }

            if (isset($input['slug'])) {
                $params['slug'] = $input['slug'];
            }

            if (isset($input['id'])) {
                $params['id'] = $input['id'];
            }

            $result = $this->origen->query($params);
            $rows = $result['rows'] ?? [];

            if (empty($rows)) {
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => "No {$input['type']} content found.",
                    ]],
                ];
            }

            // Format output
            $output = $this->formatResults($rows, $input['type']);

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $output,
                ]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "Error querying content: {$e->getMessage()}",
                ]],
                'isError' => true,
            ];
        }
    }

    private function formatResults(array $rows, string $type): string
    {
        $lines = [
            "Found " . count($rows) . " {$type} item(s):",
            "",
        ];

        foreach ($rows as $row) {
            $id = $row['id'] ?? '?';
            $title = $row['title'] ?? 'Untitled';
            $slug = $row['slug'] ?? '';
            $status = $row['status'] ?? 'unknown';

            $lines[] = "• [{$id}] {$title}";
            $lines[] = "  Slug: {$slug} | Status: {$status}";

            // Add type-specific info
            if ($type === 'article' && isset($row['excerpt'])) {
                $excerpt = substr($row['excerpt'], 0, 100);
                if (strlen($row['excerpt']) > 100) $excerpt .= '...';
                $lines[] = "  {$excerpt}";
            }

            if ($type === 'form_definition' && isset($row['field_count'])) {
                $lines[] = "  Fields: {$row['field_count']}";
            }

            $lines[] = "";
        }

        return implode("\n", $lines);
    }
}
