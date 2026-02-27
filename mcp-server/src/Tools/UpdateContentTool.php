<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class UpdateContentTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen
    ) {}

    public function getName(): string
    {
        return 'update_content';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Update existing content in the CMS. Use this to modify articles, update forms, or change any content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Content type (e.g., "article", "form_definition")',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'ID of the content to update (use either id or slug)',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Slug of the content to update (use either id or slug)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'New title',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'New status',
                        'enum' => ['draft', 'published', 'review', 'archived'],
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'New body content',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'New excerpt',
                    ],
                    'fields' => [
                        'type' => 'object',
                        'description' => 'Additional fields to update',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['type'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $type = $input['type'];

            // Get the content first to find the ID
            $recordId = $input['id'] ?? null;

            if (!$recordId && isset($input['slug'])) {
                $existing = $this->origen->get($type, $input['slug']);
                if (!$existing) {
                    throw new \RuntimeException("Content not found: {$input['slug']}");
                }
                $recordId = (int) $existing['id'];
            }

            if (!$recordId) {
                throw new \RuntimeException('Must provide either id or slug');
            }

            // Prepare the mutation
            $prepareResult = $this->origen->prepare('update', $type, $recordId);
            $tokenData = OrigenClient::extractToken($prepareResult);

            if (!$tokenData['token']) {
                throw new \RuntimeException('Failed to get mutation token');
            }

            // Build update data (only include fields that were provided)
            $data = [];

            if (isset($input['title'])) {
                $data['title'] = $input['title'];
            }

            if (isset($input['status'])) {
                $data['status'] = $input['status'];
            }

            if (isset($input['body'])) {
                $data['body'] = $input['body'];
            }

            if (isset($input['excerpt'])) {
                $data['excerpt'] = $input['excerpt'];
            }

            // Merge additional fields
            if (isset($input['fields']) && is_array($input['fields'])) {
                foreach ($input['fields'] as $key => $value) {
                    if (is_array($value)) {
                        $data[$key] = json_encode($value);
                    } else {
                        $data[$key] = $value;
                    }
                }
            }

            if (empty($data)) {
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => 'No fields provided to update.',
                    ]],
                    'isError' => true,
                ];
            }

            // Execute update
            $result = $this->origen->update(
                $tokenData['token'],
                $tokenData['context'],
                $recordId,
                $data
            );

            $updatedFields = implode(', ', array_keys($data));

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "✅ Updated {$type} (ID: {$recordId})\n" .
                              "Fields updated: {$updatedFields}",
                ]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "Error updating content: {$e->getMessage()}",
                ]],
                'isError' => true,
            ];
        }
    }
}
