<?php

namespace Origen\Storage\FlatFile;

use Symfony\Component\Yaml\Yaml;

class SchemaFileManager
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Read a schema file.
     */
    public function read(string $siteSlug, string $contentType): ?array
    {
        $path = $this->filePath($siteSlug, $contentType);
        if (!file_exists($path)) {
            return null;
        }
        return Yaml::parseFile($path);
    }

    /**
     * Write a schema file.
     */
    public function write(string $siteSlug, string $contentType, array $schema): string
    {
        $path = $this->filePath($siteSlug, $contentType);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, Yaml::dump($schema, 4, 2));
        return $path;
    }

    /**
     * Delete a schema file.
     */
    public function delete(string $siteSlug, string $contentType): void
    {
        $path = $this->filePath($siteSlug, $contentType);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * List all schema types for a site.
     */
    public function listTypes(string $siteSlug): array
    {
        $dir = $this->basePath . '/' . $siteSlug;
        if (!is_dir($dir)) {
            return [];
        }

        $types = [];
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isFile() && $file->getExtension() === 'yaml') {
                $types[] = $file->getBasename('.yaml');
            }
        }
        sort($types);
        return $types;
    }

    /**
     * List all schemas across all sites. Generator yields {siteSlug, contentType, schema}.
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

            foreach (new \DirectoryIterator($siteDir->getPathname()) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'yaml') {
                    continue;
                }
                $contentType = $file->getBasename('.yaml');
                $schema = Yaml::parseFile($file->getPathname());

                yield [
                    'siteSlug' => $siteSlug,
                    'contentType' => $contentType,
                    'schema' => $schema,
                ];
            }
        }
    }

    private function filePath(string $siteSlug, string $contentType): string
    {
        return "{$this->basePath}/{$siteSlug}/{$contentType}.yaml";
    }
}
