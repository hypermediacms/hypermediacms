<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Tools;

use HyperMedia\McpServer\Contracts\ToolInterface;

class ListFilesTool implements ToolInterface
{
    public function __construct(
        private readonly string $siteRoot
    ) {}

    public function getName(): string
    {
        return 'list_files';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'List files and directories in the site. Use this to explore the site structure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'directory' => [
                        'type' => 'string',
                        'description' => 'Directory path relative to site root (default: root)',
                        'default' => '',
                    ],
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Optional glob pattern to filter files (e.g., "*.htx")',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'List files recursively',
                        'default' => false,
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $directory = $input['directory'] ?? '';
        $pattern = $input['pattern'] ?? null;
        $recursive = $input['recursive'] ?? false;

        $fullPath = $this->siteRoot . '/' . ltrim($directory, '/');
        $fullPath = rtrim($fullPath, '/');

        // Security check
        if (!$this->isWithinSiteRoot($fullPath)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: Access denied - path outside site root']],
                'isError' => true,
            ];
        }

        if (!is_dir($fullPath)) {
            return [
                'content' => [['type' => 'text', 'text' => "Error: Directory not found: {$directory}"]],
                'isError' => true,
            ];
        }

        $items = $this->listDirectory($fullPath, $pattern, $recursive);

        // Format output
        $output = $this->formatOutput($items, $directory);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $output,
                ]
            ],
        ];
    }

    private function listDirectory(string $path, ?string $pattern, bool $recursive): array
    {
        $items = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new \DirectoryIterator($path);
        }

        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }

            $relativePath = $this->getRelativePath($file->getPathname());

            // Apply pattern filter
            if ($pattern && !fnmatch($pattern, $file->getFilename())) {
                if (!$file->isDir()) {
                    continue;
                }
            }

            $items[] = [
                'name' => $file->getFilename(),
                'path' => $relativePath,
                'type' => $file->isDir() ? 'directory' : 'file',
                'size' => $file->isFile() ? $file->getSize() : null,
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }

        // Sort: directories first, then by name
        usort($items, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    private function formatOutput(array $items, string $baseDir): string
    {
        if (empty($items)) {
            return "Directory is empty: /{$baseDir}";
        }

        $lines = ["Contents of /{$baseDir}:", ""];

        foreach ($items as $item) {
            $prefix = $item['type'] === 'directory' ? '📁' : '📄';
            $size = $item['size'] !== null ? " ({$this->formatSize($item['size'])})" : '';
            $lines[] = "{$prefix} {$item['name']}{$size}";
        }

        $lines[] = "";
        $lines[] = sprintf("Total: %d items", count($items));

        return implode("\n", $lines);
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . " KB";
        }
        return round($bytes / (1024 * 1024), 1) . " MB";
    }

    private function getRelativePath(string $fullPath): string
    {
        return str_replace($this->siteRoot . '/', '', $fullPath);
    }

    private function isWithinSiteRoot(string $path): bool
    {
        $realPath = realpath($path);
        $realSiteRoot = realpath($this->siteRoot);

        if ($realPath === false || $realSiteRoot === false) {
            return false;
        }

        return str_starts_with($realPath, $realSiteRoot);
    }
}
