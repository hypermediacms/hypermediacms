<?php

namespace Origen\Storage\Database;

class ContentRepository
{
    public function __construct(private Connection $connection) {}

    public function insert(int $siteId, array $data): array
    {
        $this->connection->execute(
            'INSERT INTO content (site_id, type, slug, title, body, status, file_path) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $data['type'] ?? 'article',
                $data['slug'],
                $data['title'],
                $data['body'] ?? '',
                $data['status'] ?? 'draft',
                $data['file_path'] ?? null,
            ]
        );
        return $this->findById($this->connection->lastInsertId());
    }

    /**
     * Insert with a specific ID (for index rebuild from frontmatter).
     */
    public function insertWithId(int $id, int $siteId, array $data): array
    {
        $this->connection->execute(
            'INSERT OR REPLACE INTO content (id, site_id, type, slug, title, body, status, file_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $siteId,
                $data['type'] ?? 'article',
                $data['slug'],
                $data['title'],
                $data['body'] ?? '',
                $data['status'] ?? 'draft',
                $data['file_path'] ?? null,
                $data['created_at'] ?? date('Y-m-d H:i:s'),
                $data['updated_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
        return $this->findById($id);
    }

    public function update(int $id, array $data): array
    {
        $sets = [];
        $params = [];
        foreach (['type', 'slug', 'title', 'body', 'status', 'file_path'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = ?";
                $params[] = $data[$col];
            }
        }
        $sets[] = "updated_at = datetime('now')";
        $params[] = $id;

        $this->connection->execute(
            'UPDATE content SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->connection->execute('DELETE FROM content WHERE id = ?', [$id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->query('SELECT * FROM content WHERE id = ?', [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySlug(int $siteId, string $slug): ?array
    {
        $stmt = $this->connection->query(
            'SELECT * FROM content WHERE site_id = ? AND slug = ?',
            [$siteId, $slug]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function slugExists(int $siteId, string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM content WHERE site_id = ? AND slug = ?';
        $params = [$siteId, $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        return (bool) $this->connection->query($sql, $params)->fetch();
    }

    public function getFieldValues(int $contentId): array
    {
        return $this->connection->query(
            'SELECT * FROM content_field_values WHERE content_id = ?',
            [$contentId]
        )->fetchAll();
    }

    public function upsertFieldValue(int $contentId, int $siteId, string $fieldName, ?string $fieldValue): void
    {
        $this->connection->execute(
            'INSERT INTO content_field_values (content_id, site_id, field_name, field_value)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(content_id, field_name)
             DO UPDATE SET field_value = ?, site_id = ?',
            [$contentId, $siteId, $fieldName, $fieldValue, $fieldValue, $siteId]
        );
    }

    public function deleteFieldValues(int $contentId): void
    {
        $this->connection->execute(
            'DELETE FROM content_field_values WHERE content_id = ?',
            [$contentId]
        );
    }

    /**
     * Query content by type for relationship selectors.
     */
    public function findByType(int $siteId, string $type, array $columns = ['id', 'title']): array
    {
        $cols = implode(', ', $columns);
        return $this->connection->query(
            "SELECT {$cols} FROM content WHERE site_id = ? AND type = ? ORDER BY title",
            [$siteId, $type]
        )->fetchAll();
    }

    /**
     * Find multiple records by IDs.
     */
    public function findByIds(int $siteId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->connection->query(
            "SELECT * FROM content WHERE site_id = ? AND id IN ({$placeholders})",
            array_merge([$siteId], $ids)
        )->fetchAll();
    }

    /**
     * Find multiple records by IDs and type.
     */
    public function findByIdsAndType(int $siteId, string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->connection->query(
            "SELECT * FROM content WHERE site_id = ? AND type = ? AND id IN ({$placeholders})",
            array_merge([$siteId, $type], $ids)
        )->fetchAll();
    }

    /**
     * Get field values for multiple content IDs in batch.
     */
    public function getFieldValuesForIds(array $contentIds): array
    {
        if (empty($contentIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
        return $this->connection->query(
            "SELECT * FROM content_field_values WHERE content_id IN ({$placeholders})",
            $contentIds
        )->fetchAll();
    }

    /**
     * Get distinct types for a site.
     */
    public function distinctTypes(int $siteId): array
    {
        return array_column(
            $this->connection->query(
                'SELECT DISTINCT type FROM content WHERE site_id = ?',
                [$siteId]
            )->fetchAll(),
            'type'
        );
    }
}
