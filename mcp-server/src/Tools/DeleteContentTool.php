<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class DeleteContentTool implements ToolInterface
{
    public function __construct(
        private readonly OrigenClient $origen
    ) {}

    public function getName(): string
    {
        return 'delete_content';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Delete content from the CMS. Use with caution - this permanently removes content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Content type (e.g., "article", "form_definition")',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'ID of the content to delete (use either id or slug)',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Slug of the content to delete (use either id or slug)',
                    ],
                    'confirm' => [
                        'type' => 'boolean',
                        'description' => 'Must be true to confirm deletion',
                    ],
                ],
                'required' => ['type', 'confirm'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            // Require explicit confirmation
            if (!($input['confirm'] ?? false)) {
                return [
                    'content' => [[
                        'type' => 'text',
                        'text' => "⚠️ Deletion requires confirmation.\n" .
                                  "Set confirm: true to proceed with deletion.",
                    ]],
                    'isError' => true,
                ];
            }

            $type = $input['type'];

            // Get the record ID
            $recordId = $input['id'] ?? null;
            $title = "ID {$recordId}";

            if (!$recordId && isset($input['slug'])) {
                $existing = $this->origen->get($type, $input['slug']);
                if (!$existing) {
                    throw new \RuntimeException("Content not found: {$input['slug']}");
                }
                $recordId = (int) $existing['id'];
                $title = $existing['title'] ?? $input['slug'];
            }

            if (!$recordId) {
                throw new \RuntimeException('Must provide either id or slug');
            }

            // Get content info for confirmation message
            $existing = $this->origen->get($type, $recordId);
            if ($existing) {
                $title = $existing['title'] ?? $title;
            }

            // Prepare the mutation
            $prepareResult = $this->origen->prepare('delete', $type, $recordId);
            $tokenData = OrigenClient::extractToken($prepareResult);

            if (!$tokenData['token']) {
                throw new \RuntimeException('Failed to get mutation token');
            }

            // Execute delete
            $result = $this->origen->delete(
                $tokenData['token'],
                $tokenData['context'],
                $recordId
            );

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "🗑️ Deleted {$type}: {$title} (ID: {$recordId})",
                ]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => "Error deleting content: {$e->getMessage()}",
                ]],
                'isError' => true,
            ];
        }
    }
}
