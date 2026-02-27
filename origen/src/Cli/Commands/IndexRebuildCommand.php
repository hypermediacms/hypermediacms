<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Container;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\FlatFile\ContentFileManager;
use Origen\Storage\FlatFile\SchemaFileManager;
use Origen\Storage\FlatFile\SiteConfigManager;

class IndexRebuildCommand implements CommandInterface
{
    public function name(): string
    {
        return 'index:rebuild';
    }

    public function description(): string
    {
        return 'Rebuild SQLite index from flat files';
    }

    public function run(Container $container, array $args): int
    {
        $connection = $container->make(Connection::class);
        $siteRepo = $container->make(SiteRepository::class);
        $contentRepo = $container->make(ContentRepository::class);
        $schemaRepo = $container->make(SchemaRepository::class);
        $siteConfigManager = $container->make(SiteConfigManager::class);
        $contentFiles = $container->make(ContentFileManager::class);
        $schemaFiles = $container->make(SchemaFileManager::class);

        echo "Rebuilding index...\n";

        // 1. Scan and upsert sites
        $sites = [];
        foreach ($siteConfigManager->scanAll() as $siteConfig) {
            $site = $siteRepo->upsert($siteConfig);
            $sites[$siteConfig['slug']] = $site;
            echo "  Site: {$siteConfig['slug']} (id={$site['id']})\n";
        }

        if (empty($sites)) {
            echo "No sites found. Create a site first with: php hcms site:create\n";
            return 0;
        }

        // 2. Rebuild schemas
        $schemaCount = 0;
        foreach ($schemaFiles->listAll() as $schemaEntry) {
            $siteSlug = $schemaEntry['siteSlug'];
            if (!isset($sites[$siteSlug])) {
                continue;
            }
            $siteId = (int) $sites[$siteSlug]['id'];
            $contentType = $schemaEntry['contentType'];
            $schema = $schemaEntry['schema'];

            $fields = [];
            foreach ($schema['fields'] ?? [] as $field) {
                $fields[] = [
                    'field_name' => $field['name'],
                    'field_type' => $field['type'],
                    'constraints' => $field['constraints'] ?? [],
                    'ui_hints' => [],
                ];
            }

            $schemaRepo->replaceForType($siteId, $contentType, $fields);
            $schemaCount++;
        }
        echo "  Schemas: {$schemaCount} rebuilt\n";

        // 3. Rebuild content (preserve IDs from frontmatter)
        $contentCount = 0;
        foreach ($contentFiles->listAll() as $entry) {
            $siteSlug = $entry['siteSlug'];
            if (!isset($sites[$siteSlug])) {
                continue;
            }
            $siteId = (int) $sites[$siteSlug]['id'];
            $meta = $entry['meta'];

            $data = [
                'type' => $entry['type'],
                'slug' => $meta['slug'] ?? $entry['slug'],
                'title' => $meta['title'] ?? $entry['slug'],
                'body' => $entry['body'],
                'status' => $meta['status'] ?? 'draft',
                'file_path' => $entry['filePath'],
                'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $meta['updated_at'] ?? date('Y-m-d H:i:s'),
            ];

            if (!empty($meta['id'])) {
                $record = $contentRepo->insertWithId((int) $meta['id'], $siteId, $data);
            } else {
                $record = $contentRepo->insert($siteId, $data);
            }

            // Rebuild field values from frontmatter
            $coreFields = ['id', 'title', 'slug', 'status', 'created_at', 'updated_at'];
            foreach ($meta as $key => $value) {
                if (in_array($key, $coreFields)) {
                    continue;
                }
                $fieldValue = is_array($value) ? json_encode($value) : (string) $value;
                $contentRepo->upsertFieldValue($record['id'], $siteId, $key, $fieldValue);
            }

            $contentCount++;
        }
        echo "  Content: {$contentCount} entries rebuilt\n";

        echo "Index rebuild complete.\n";
        return 0;
    }
}
