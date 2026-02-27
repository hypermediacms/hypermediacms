<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;

class WriteFileTool implements ToolInterface
{
    public function __construct(
        private readonly string $siteRoot,
        private readonly ?string $auditLog = null
    ) {}

    public function getName(): string
    {
        return 'write_file';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Create or update an HTX template file. Use this to build new pages or modify existing templates.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path relative to site root (e.g., "contact.htx", "blog/new-post.htx")',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The HTX template content to write',
                    ],
                    'createDirectories' => [
                        'type' => 'boolean',
                        'description' => 'Create parent directories if they don\'t exist',
                        'default' => true,
                    ],
                ],
                'required' => ['path', 'content'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $path = $input['path'] ?? '';
        $content = $input['content'] ?? '';
        $createDirs = $input['createDirectories'] ?? true;

        $fullPath = $this->siteRoot . '/' . ltrim($path, '/');

        // Security check
        if (!$this->isWithinSiteRoot($fullPath)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: Access denied - path outside site root']],
                'isError' => true,
            ];
        }

        // Check for dangerous paths
        if ($this->isDangerousPath($path)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: Cannot write to protected path']],
                'isError' => true,
            ];
        }

        // Create directories if needed
        $dir = dirname($fullPath);
        if ($createDirs && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return [
                    'content' => [['type' => 'text', 'text' => "Error: Failed to create directory: {$dir}"]],
                    'isError' => true,
                ];
            }
        }

        // Check if file exists (for audit log)
        $isNew = !file_exists($fullPath);
        $oldContent = $isNew ? null : file_get_contents($fullPath);

        // Write the file
        if (file_put_contents($fullPath, $content) === false) {
            return [
                'content' => [['type' => 'text', 'text' => "Error: Failed to write file: {$path}"]],
                'isError' => true,
            ];
        }

        // Audit log
        $this->logWrite($path, $isNew, $oldContent, $content);

        $action = $isNew ? 'Created' : 'Updated';
        $url = '/' . preg_replace('/\.htx$/', '', $path);
        $url = preg_replace('/\/index$/', '/', $url);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "{$action} {$path}\nPreview URL: {$url}",
                ]
            ],
        ];
    }

    private function isWithinSiteRoot(string $path): bool
    {
        // Normalize the path
        $normalized = $this->normalizePath($path);
        $normalizedRoot = realpath($this->siteRoot);

        if ($normalizedRoot === false) {
            return false;
        }

        return str_starts_with($normalized, $normalizedRoot);
    }

    private function normalizePath(string $path): string
    {
        // Prevent directory traversal
        $path = str_replace(['../', '..\\'], '', $path);
        
        // Resolve what we can
        $dir = dirname($path);
        if (is_dir($dir)) {
            return realpath($dir) . '/' . basename($path);
        }

        return $this->siteRoot . '/' . ltrim($path, '/');
    }

    private function isDangerousPath(string $path): bool
    {
        $dangerous = [
            '/vendor/',
            '/node_modules/',
            '/.git/',
            '/.env',
            '/composer.',
            '/package.',
        ];

        foreach ($dangerous as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function logWrite(string $path, bool $isNew, ?string $oldContent, string $newContent): void
    {
        if (!$this->auditLog) {
            return;
        }

        $entry = [
            'timestamp' => date('c'),
            'action' => $isNew ? 'create' : 'update',
            'path' => $path,
            'bytes' => strlen($newContent),
        ];

        $line = json_encode($entry) . "\n";
        file_put_contents($this->auditLog, $line, FILE_APPEND | LOCK_EX);
    }
}
