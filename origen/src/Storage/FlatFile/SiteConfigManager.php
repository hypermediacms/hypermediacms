<?php

namespace Origen\Storage\FlatFile;

use Symfony\Component\Yaml\Yaml;

class SiteConfigManager
{
    private string $contentBasePath;

    public function __construct(string $contentBasePath)
    {
        $this->contentBasePath = rtrim($contentBasePath, '/');
    }

    /**
     * Scan all _site.yaml files under content/{site-slug}/.
     *
     * @return \Generator yields site config arrays with 'slug' key added
     */
    public function scanAll(): \Generator
    {
        if (!is_dir($this->contentBasePath)) {
            return;
        }

        foreach (new \DirectoryIterator($this->contentBasePath) as $dir) {
            if (!$dir->isDir() || $dir->isDot()) {
                continue;
            }

            $configPath = $dir->getPathname() . '/_site.yaml';
            if (!file_exists($configPath)) {
                continue;
            }

            $config = Yaml::parseFile($configPath);
            $config['slug'] = $dir->getFilename();

            yield $config;
        }
    }

    /**
     * Read a specific site's config.
     */
    public function read(string $siteSlug): ?array
    {
        $path = $this->configPath($siteSlug);
        if (!file_exists($path)) {
            return null;
        }
        $config = Yaml::parseFile($path);
        $config['slug'] = $siteSlug;
        return $config;
    }

    /**
     * Write a site config.
     */
    public function write(string $siteSlug, array $config): string
    {
        $path = $this->configPath($siteSlug);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Don't write the slug into the YAML — it's derived from the directory name
        $data = $config;
        unset($data['slug']);

        file_put_contents($path, Yaml::dump($data, 4, 2));
        return $path;
    }

    private function configPath(string $siteSlug): string
    {
        return "{$this->contentBasePath}/{$siteSlug}/_site.yaml";
    }
}
