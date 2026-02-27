<?php

namespace Origen\Sync;

use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;
use Origen\Storage\FlatFile\ContentFileManager;
use Origen\Storage\FlatFile\SchemaFileManager;

class WriteThrough
{
    public function __construct(
        private Connection $connection,
        private ContentRepository $contentRepo,
        private ContentFileManager $contentFiles,
        private SchemaRepository $schemaRepo,
        private SchemaFileManager $schemaFiles,
    ) {}

    /**
     * Create content: SQLite insert → get ID → write .md (if content mode) → commit.
     */
    public function createContent(string $siteSlug, int $siteId, array $data, string $storageMode = 'content'): array
    {
        $this->connection->beginTransaction();
        try {
            $record = $this->contentRepo->insert($siteId, $data);

            if ($storageMode === 'content') {
                // Build frontmatter meta
                $meta = [
                    'id' => $record['id'],
                    'title' => $record['title'],
                    'slug' => $record['slug'],
                    'status' => $record['status'],
                    'created_at' => $record['created_at'],
                    'updated_at' => $record['updated_at'],
                ];

                // Include custom field values in frontmatter
                if (!empty($data['field_values'])) {
                    foreach ($data['field_values'] as $name => $value) {
                        $meta[$name] = $this->prepareFieldForFrontmatter($value);
                    }
                }

                $filePath = $this->contentFiles->write(
                    $siteSlug,
                    $record['type'],
                    $record['slug'],
                    $meta,
                    $record['body'] ?? ''
                );

                // Update file_path in DB
                $this->contentRepo->update($record['id'], ['file_path' => $filePath]);
                $record['file_path'] = $filePath;
            }

            $this->connection->commit();
            return $record;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            // Clean up orphaned file if it was written
            if (isset($filePath) && file_exists($filePath)) {
                @unlink($filePath);
            }
            throw $e;
        }
    }

    /**
     * Update content: update SQLite → update .md (if content mode) → commit.
     */
    public function updateContent(string $siteSlug, int $siteId, array $existing, array $data, string $storageMode = 'content'): array
    {
        $this->connection->beginTransaction();
        try {
            $oldSlug = $existing['slug'];
            $record = $this->contentRepo->update($existing['id'], $data);

            if ($storageMode === 'content') {
                // If slug changed, rename the file
                if (isset($data['slug']) && $data['slug'] !== $oldSlug) {
                    $this->contentFiles->rename($siteSlug, $record['type'], $oldSlug, $data['slug']);
                }

                // Build frontmatter
                $meta = [
                    'id' => $record['id'],
                    'title' => $record['title'],
                    'slug' => $record['slug'],
                    'status' => $record['status'],
                    'created_at' => $record['created_at'],
                    'updated_at' => $record['updated_at'],
                ];

                // Include field values
                $fieldValues = $this->contentRepo->getFieldValues($record['id']);
                foreach ($fieldValues as $fv) {
                    $meta[$fv['field_name']] = $this->prepareFieldForFrontmatter($fv['field_value']);
                }

                $filePath = $this->contentFiles->write(
                    $siteSlug,
                    $record['type'],
                    $record['slug'],
                    $meta,
                    $record['body'] ?? ''
                );

                $this->contentRepo->update($record['id'], ['file_path' => $filePath]);
                $record['file_path'] = $filePath;
            }

            $this->connection->commit();
            return $record;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Delete content: delete from SQLite → delete .md (if content mode).
     */
    public function deleteContent(string $siteSlug, array $record, string $storageMode = 'content'): void
    {
        $this->connection->beginTransaction();
        try {
            $this->contentRepo->delete($record['id']);
            if ($storageMode === 'content') {
                $this->contentFiles->delete($siteSlug, $record['type'], $record['slug']);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Save schema: write to SQLite → write .yaml.
     */
    public function saveSchema(string $siteSlug, int $siteId, string $contentType, array $fields): void
    {
        $this->connection->beginTransaction();
        try {
            $this->schemaRepo->replaceForType($siteId, $contentType, $fields);

            $schema = ['fields' => array_map(fn($f) => [
                'name' => $f['field_name'],
                'type' => $f['field_type'],
                'constraints' => $f['constraints'] ?? [],
            ], $fields)];

            $this->schemaFiles->write($siteSlug, $contentType, $schema);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Delete schema: delete from SQLite → delete .yaml.
     */
    public function deleteSchema(string $siteSlug, int $siteId, string $contentType): void
    {
        $this->connection->beginTransaction();
        try {
            $this->schemaRepo->deleteForType($siteId, $contentType);
            $this->schemaFiles->delete($siteSlug, $contentType);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Prepare a field value for YAML frontmatter storage.
     * JSON arrays → PHP arrays so YAML serializes them properly.
     */
    private function prepareFieldForFrontmatter(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_numeric($value) && !str_contains((string) $value, '.')) {
            return (int) $value;
        }
        return $value;
    }
}
