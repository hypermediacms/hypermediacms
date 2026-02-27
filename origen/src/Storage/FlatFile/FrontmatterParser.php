<?php

namespace Origen\Storage\FlatFile;

use Symfony\Component\Yaml\Yaml;

class FrontmatterParser
{
    /**
     * Parse a file's content into frontmatter meta and body.
     *
     * @return array{meta: array, body: string}
     */
    public function parse(string $content): array
    {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            return ['meta' => [], 'body' => $content];
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);

        if (count($parts) < 3) {
            return ['meta' => [], 'body' => $content];
        }

        $yamlString = $parts[1];
        $body = ltrim($parts[2]);

        $meta = Yaml::parse($yamlString) ?? [];

        return ['meta' => $meta, 'body' => $body];
    }

    /**
     * Serialize meta + body back to a frontmatter Markdown string.
     */
    public function serialize(array $meta, string $body): string
    {
        $yaml = Yaml::dump($meta, 4, 2);
        return "---\n{$yaml}---\n{$body}";
    }
}
