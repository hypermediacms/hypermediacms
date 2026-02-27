<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Resources;

use HyperMedia\McpServer\Contracts\ResourceInterface;
use HyperMedia\McpServer\Services\OrigenClient;

class ContentResource implements ResourceInterface
{
    public function __construct(
        private readonly OrigenClient $origen
    ) {}

    public function getScheme(): string
    {
        return 'content';
    }

    public function list(): array
    {
        $resources = [];

        // Query recent content of common types
        $types = ['article', 'form_definition', 'todo'];

        foreach ($types as $type) {
            try {
                $result = $this->origen->query([
                    'type' => $type,
                    'status' => 'published',
                    'order' => 'newest',
                    'howmany' => 20,
                ]);

                foreach ($result['rows'] ?? [] as $row) {
                    $resources[] = [
                        'uri' => "content://{$type}/{$row['slug']}",
                        'name' => $row['title'] ?? $row['slug'],
                        'mimeType' => 'application/json',
                        'description' => $this->getDescription($type, $row),
                    ];
                }
            } catch (\Throwable $e) {
                // Skip types that fail (might not exist)
                continue;
            }
        }

        return $resources;
    }

    public function read(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = trim(($parsed['host'] ?? '') . ($parsed['path'] ?? ''), '/');
        $parts = explode('/', $path, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid content URI: {$uri}");
        }

        [$type, $slug] = $parts;

        $content = $this->origen->get($type, $slug);

        if (!$content) {
            throw new \InvalidArgumentException("Content not found: {$uri}");
        }

        return json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function supportsSubscription(): bool
    {
        return false;
    }

    private function getDescription(string $type, array $row): string
    {
        $status = $row['status'] ?? 'unknown';
        $date = $row['created_at'] ?? '';

        $desc = ucfirst($type) . " ({$status})";

        if (isset($row['excerpt']) && $row['excerpt']) {
            $excerpt = substr($row['excerpt'], 0, 80);
            if (strlen($row['excerpt']) > 80) {
                $excerpt .= '...';
            }
            $desc .= ": {$excerpt}";
        }

        return $desc;
    }
}
