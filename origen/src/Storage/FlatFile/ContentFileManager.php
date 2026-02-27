<?php

namespace Origen\Storage\FlatFile;

class ContentFileManager
{
    private string $basePath;
    private FrontmatterParser $parser;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->parser = new FrontmatterParser();
    }

    /**
     * Read a content file.
     *
     * @return array{meta: array, body: string}|null
     */
    public function read(string $siteSlug, string $type, string $slug): ?array
    {
        $path = $this->filePath($siteSlug, $type, $slug);
        if (!file_exists($path)) {
            return null;
        }
        return $this->parser->parse(file_get_contents($path));
    }

    /**
     * Write a content file.
     */
    public function write(string $siteSlug, string $type, string $slug, array $meta, string $body): string
    {
        $path = $this->filePath($siteSlug, $type, $slug);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->parser->serialize($meta, $body));
        return $path;
    }

    /**
     * Delete a content file.
     */
    public function delete(string $siteSlug, string $type, string $slug): void
    {
        $path = $this->filePath($siteSlug, $type, $slug);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Rename (move) a content file when slug changes.
     */
    public function rename(string $siteSlug, string $type, string $oldSlug, string $newSlug): void
    {
        $oldPath = $this->filePath($siteSlug, $type, $oldSlug);
        $newPath = $this->filePath($siteSlug, $type, $newSlug);

        if (file_exists($oldPath)) {
            $dir = dirname($newPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            rename($oldPath, $newPath);
        }
    }

    /**
     * List all content files for all sites. Generator yields {siteSlug, type, slug, meta, body}.
     */
    public function listAll(): \Generator
    {
        if (!is_dir($this->basePath)) {
            return;
        }

        foreach (new \DirectoryIterator($this->basePath) as $siteDir) {
            if (!$siteDir->isDir() || $siteDir->isDot()) {
                continue;
            }
            $siteSlug = $siteDir->getFilename();

            foreach (new \DirectoryIterator($siteDir->getPathname()) as $typeDir) {
                if (!$typeDir->isDir() || $typeDir->isDot()) {
                    continue;
                }
                $type = $typeDir->getFilename();

                foreach (new \DirectoryIterator($typeDir->getPathname()) as $file) {
                    if (!$file->isFile() || $file->getExtension() !== 'md') {
                        continue;
                    }
                    $slug = $file->getBasename('.md');
                    $parsed = $this->parser->parse(file_get_contents($file->getPathname()));

                    yield [
                        'siteSlug' => $siteSlug,
                        'type' => $type,
                        'slug' => $slug,
                        'meta' => $parsed['meta'],
                        'body' => $parsed['body'],
                        'filePath' => $file->getPathname(),
                    ];
                }
            }
        }
    }

    /**
     * List all content files for a specific site.
     */
    public function listForSite(string $siteSlug): \Generator
    {
        $sitePath = $this->basePath . '/' . $siteSlug;
        if (!is_dir($sitePath)) {
            return;
        }

        foreach (new \DirectoryIterator($sitePath) as $typeDir) {
            if (!$typeDir->isDir() || $typeDir->isDot()) {
                continue;
            }
            $type = $typeDir->getFilename();

            foreach (new \DirectoryIterator($typeDir->getPathname()) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'md') {
                    continue;
                }
                $slug = $file->getBasename('.md');
                $parsed = $this->parser->parse(file_get_contents($file->getPathname()));

                yield [
                    'siteSlug' => $siteSlug,
                    'type' => $type,
                    'slug' => $slug,
                    'meta' => $parsed['meta'],
                    'body' => $parsed['body'],
                    'filePath' => $file->getPathname(),
                ];
            }
        }
    }

    public function filePath(string $siteSlug, string $type, string $slug): string
    {
        return "{$this->basePath}/{$siteSlug}/{$type}/{$slug}.md";
    }
}
