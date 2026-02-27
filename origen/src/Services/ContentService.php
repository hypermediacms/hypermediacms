<?php

namespace Origen\Services;

use Origen\Exceptions\SlugConflictException;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\QueryBuilder;
use Origen\Storage\Database\Connection;
use Origen\Sync\WriteThrough;

class ContentService
{
    public function __construct(
        private WriteThrough $writeThrough,
        private SchemaService $schemaService,
        private ContentRepository $contentRepo,
    ) {}

    /**
     * Query content for a site based on meta directives.
     */
    public function query(array $site, array $meta): array
    {
        $connection = \Origen\Container::getInstance()->make(Connection::class);
        $builder = new QueryBuilder($connection, (int) $site['id']);

        if (isset($meta['type'])) {
            $builder->type($meta['type']);
        }
        if (isset($meta['slug'])) {
            $builder->slug($meta['slug']);
        }
        if (isset($meta['recordId'])) {
            $builder->recordId($meta['recordId']);
        }
        if (isset($meta['status'])) {
            $builder->status($meta['status']);
        }
        if (isset($meta['where'])) {
            $this->applyWhereConditions($builder, $meta['where']);
        }
        if (isset($meta['order'])) {
            $direction = $meta['order'] === 'oldest' ? 'asc' : 'desc';
            $builder->orderBy('created_at', $direction);
        }
        if (isset($meta['howmany'])) {
            $builder->limit((int) $meta['howmany']);
        }

        $rows = $builder->get();

        // Attach field values to each row
        $contentIds = array_column($rows, 'id');
        $allFieldValues = $this->contentRepo->getFieldValuesForIds($contentIds);
        $fieldValuesByContent = [];
        foreach ($allFieldValues as $fv) {
            $fieldValuesByContent[$fv['content_id']][] = $fv;
        }
        foreach ($rows as &$row) {
            $row['fieldValues'] = $fieldValuesByContent[$row['id']] ?? [];
        }
        unset($row);

        // Optionally limit fields returned
        if (isset($meta['fields'])) {
            $fields = is_array($meta['fields']) ? $meta['fields'] : explode(',', $meta['fields']);
            $allowed = array_merge(['id'], $fields);
            $rows = array_map(function ($row) use ($allowed) {
                return array_intersect_key($row, array_flip($allowed));
            }, $rows);
        }

        return $rows;
    }

    /**
     * Create content within a tenant.
     */
    public function create(array $site, array $data): array
    {
        $siteId = (int) $site['id'];
        $siteSlug = $site['slug'];

        $slug = !empty($data['slug'])
            ? $this->normalizeSlug($data['slug'], $siteId)
            : $this->generateSlug($data['title'], $siteId);

        $contentData = [
            'type' => $data['type'] ?? 'article',
            'slug' => $slug,
            'title' => $data['title'],
            'body' => $data['body'] ?? '',
            'status' => $data['status'] ?? 'draft',
        ];

        // Separate custom fields from core fields (exclude htx-* and system fields)
        $reserved = ['type', 'slug', 'title', 'body', 'status', 'responseTemplates', 'htx-token', 'htx-context', 'htx-recordId'];
        $customFields = array_diff_key($data, array_flip($reserved));

        // Pass custom field values to WriteThrough for frontmatter
        if (!empty($customFields)) {
            $contentData['field_values'] = $customFields;
        }

        $record = $this->writeThrough->createContent($siteSlug, $siteId, $contentData);

        // Sync custom field values to SQLite
        if (!empty($customFields)) {
            $this->schemaService->syncFieldValues($record['id'], $siteId, $customFields);
        }

        return $record;
    }

    /**
     * Update existing content.
     */
    public function update(array $existing, array $site, array $data): array
    {
        $siteId = (int) $site['id'];
        $siteSlug = $site['slug'];

        $fillable = array_intersect_key($data, array_flip(['title', 'slug', 'body', 'status']));

        if (!empty($fillable['slug'])) {
            $fillable['slug'] = $this->normalizeSlug($fillable['slug'], $siteId, $existing['id']);
        } elseif (isset($fillable['title'])) {
            $fillable['slug'] = $this->generateSlug($fillable['title'], $siteId, $existing['id']);
        }

        $record = $this->writeThrough->updateContent($siteSlug, $siteId, $existing, $fillable);

        // Sync custom field values (exclude htx-* and system fields)
        $reserved = ['type', 'slug', 'title', 'body', 'status', 'responseTemplates', 'htx-token', 'htx-context', 'htx-recordId'];
        $customFields = array_diff_key($data, array_flip($reserved));
        if (!empty($customFields)) {
            $this->schemaService->syncFieldValues($record['id'], $siteId, $customFields);
            // Re-write the flat file with updated field values
            $this->writeThrough->updateContent($siteSlug, $siteId, $record, []);
        }

        return $record;
    }

    /**
     * Delete content.
     */
    public function delete(array $existing, array $site): void
    {
        $this->writeThrough->deleteContent($site['slug'], $existing);
    }

    /**
     * Find content by ID scoped to a site.
     */
    public function findForTenant(int|string $id, array $site): ?array
    {
        $record = $this->contentRepo->findById((int) $id);
        if (!$record || (int) $record['site_id'] !== (int) $site['id']) {
            return null;
        }
        $record['fieldValues'] = $this->contentRepo->getFieldValues($record['id']);
        return $record;
    }

    private function normalizeSlug(string $slug, int $siteId, ?int $excludeId = null): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug));
        $base = trim($base, '-');

        if ($this->contentRepo->slugExists($siteId, $base, $excludeId)) {
            throw new SlugConflictException($base);
        }

        return $base;
    }

    private function generateSlug(string $title, int $siteId, ?int $excludeId = null): string
    {
        $base = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
        $slug = $base;
        $counter = 1;

        while ($this->contentRepo->slugExists($siteId, $slug, $excludeId)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function applyWhereConditions(QueryBuilder $builder, string $where): void
    {
        $conditions = explode(',', $where);
        foreach ($conditions as $condition) {
            $parts = explode('=', trim($condition), 2);
            if (count($parts) === 2) {
                $builder->where(trim($parts[0]), trim($parts[1]));
            }
        }
    }
}
