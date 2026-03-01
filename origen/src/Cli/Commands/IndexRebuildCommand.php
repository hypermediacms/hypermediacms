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
                    'field_name' => $field['field_name'] ?? $field['name'] ?? '',
                    'field_type' => $field['field_type'] ?? $field['type'] ?? 'text',
                    'constraints' => $field['constraints'] ?? [],
                    'ui_hints' => $field['ui_hints'] ?? [],
                ];
            }

            $schemaRepo->replaceForType($siteId, $contentType, $fields);
            $schemaCount++;
        }
        echo "  Schemas: {$schemaCount} rebuilt\n";

        // Load storage mode settings for all types
        $dataTypes = []; // key: "siteId:type" => true for data/ephemeral types
        $settingsStmt = $connection->pdo()->query('SELECT site_id, content_type, storage_mode FROM content_type_settings');
        foreach ($settingsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['storage_mode'] !== 'content') {
                $dataTypes[$row['site_id'] . ':' . $row['content_type']] = true;
            }
        }

        // 3. Delete only content-mode rows (data/ephemeral rows are preserved)
        if (!empty($dataTypes)) {
            // Delete content rows that are in 'content' storage mode (will be rebuilt from files)
            $connection->pdo()->exec(
                "DELETE FROM content WHERE id NOT IN (
                    SELECT c.id FROM content c
                    JOIN content_type_settings cts
                      ON c.site_id = cts.site_id AND c.type = cts.content_type
                    WHERE cts.storage_mode IN ('data', 'ephemeral')
                )"
            );
        } else {
            $connection->pdo()->exec('DELETE FROM content');
        }

        // Rebuild content from files (only content-mode types have files)
        $contentCount = 0;
        $skippedCount = 0;
        foreach ($contentFiles->listAll() as $entry) {
            $siteSlug = $entry['siteSlug'];
            if (!isset($sites[$siteSlug])) {
                continue;
            }
            $siteId = (int) $sites[$siteSlug]['id'];

            // Skip file scan for data/ephemeral types
            $typeKey = $siteId . ':' . $entry['type'];
            if (isset($dataTypes[$typeKey])) {
                $skippedCount++;
                continue;
            }

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
        if ($skippedCount > 0) {
            echo "  Skipped: {$skippedCount} data/ephemeral files\n";
        }

        echo "Index rebuild complete.\n";
        return 0;
    }
}
