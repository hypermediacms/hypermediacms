<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;

class ReadFileTool implements ToolInterface
{
    public function __construct(
        private readonly string $siteRoot
    ) {}

    public function getName(): string
    {
        return 'read_file';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Read the contents of an HTX template or other file from the site',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path relative to site root (e.g., "index.htx", "blog/[slug].htx")',
                    ],
                ],
                'required' => ['path'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $path = $input['path'] ?? '';
        $fullPath = $this->siteRoot . '/' . ltrim($path, '/');

        // Security check
        if (!$this->isWithinSiteRoot($fullPath)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: Access denied - path outside site root']],
                'isError' => true,
            ];
        }

        if (!file_exists($fullPath)) {
            return [
                'content' => [['type' => 'text', 'text' => "Error: File not found: {$path}"]],
                'isError' => true,
            ];
        }

        $content = file_get_contents($fullPath);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $content,
                ]
            ],
        ];
    }

    private function isWithinSiteRoot(string $path): bool
    {
        // Resolve the directory first (file might not exist yet)
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $dir = $this->siteRoot;
        }

        $realDir = realpath($dir);
        $realSiteRoot = realpath($this->siteRoot);

        if ($realDir === false || $realSiteRoot === false) {
            return false;
        }

        return str_starts_with($realDir, $realSiteRoot);
    }
}
