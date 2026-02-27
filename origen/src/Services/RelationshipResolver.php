<?php

namespace Origen\Services;

use Origen\Storage\Database\ContentRepository;

class RelationshipResolver
{
    private const MAX_RELATED_ITEMS = 100;

    public function __construct(
        private SchemaService $schemaService,
        private ContentRepository $contentRepo,
        private MarkdownService $markdownService,
    ) {}

    /**
     * Resolve relationships for a collection of content rows in batch.
     *
     * @param array $site Site record
     * @param array $rows Array of content row arrays
     * @return array<int, array<string, mixed>> Map of content_id → resolved relationship data
     */
    public function resolveForCollection(array $site, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        // Group rows by type to load schemas once per type
        $rowsByType = [];
        foreach ($rows as $row) {
            $rowsByType[$row['type']][] = $row;
        }

        // Collect relationship schemas per type
        $relationshipFields = [];
        foreach (array_keys($rowsByType) as $type) {
            $schemas = $this->schemaService->getSchemaForType($site, $type);
            foreach ($schemas as $schema) {
                if ($schema['field_type'] === 'relationship') {
                    $relationshipFields[$type][] = $schema;
                }
            }
        }

        if (empty($relationshipFields)) {
            return [];
        }

        // Collect all referenced IDs per target type
        $idsByTargetType = [];
        $rowFieldData = [];

        foreach ($rowsByType as $type => $typeRows) {
            if (!isset($relationshipFields[$type])) {
                continue;
            }

            foreach ($typeRows as $row) {
                foreach ($relationshipFields[$type] as $schema) {
                    $constraints = $schema['constraints'] ?? [];
                    $targetType = $constraints['target_type'] ?? null;
                    $cardinality = $constraints['cardinality'] ?? 'one';

                    if (!$targetType) {
                        continue;
                    }

                    // Get stored value from field values
                    $storedValue = null;
                    if (!empty($row['fieldValues'])) {
                        foreach ($row['fieldValues'] as $fv) {
                            if ($fv['field_name'] === $schema['field_name']) {
                                $storedValue = $fv['field_value'];
                                break;
                            }
                        }
                    }

                    $ids = $this->parseStoredIds($storedValue, $cardinality);
                    $ids = array_slice($ids, 0, self::MAX_RELATED_ITEMS);

                    $rowFieldData[$row['id']][$schema['field_name']] = [
                        'ids' => $ids,
                        'cardinality' => $cardinality,
                        'target_type' => $targetType,
                    ];

                    foreach ($ids as $id) {
                        $idsByTargetType[$targetType][] = $id;
                    }
                }
            }
        }

        // Execute one query per target type
        $resolvedContent = [];
        foreach ($idsByTargetType as $targetType => $ids) {
            $uniqueIds = array_unique($ids);
            $related = $this->contentRepo->findByIdsAndType((int) $site['id'], $targetType, $uniqueIds);

            // Batch-load field values for related items
            $relatedIds = array_column($related, 'id');
            $relatedFieldValues = $this->contentRepo->getFieldValuesForIds($relatedIds);
            $fvByContent = [];
            foreach ($relatedFieldValues as $fv) {
                $fvByContent[$fv['content_id']][] = $fv;
            }

            foreach ($related as $item) {
                $item['fieldValues'] = $fvByContent[$item['id']] ?? [];
                $resolvedContent[$targetType][$item['id']] = $this->contentToArray($item);
            }
        }

        // Distribute resolved objects back to each row
        $result = [];
        foreach ($rowFieldData as $contentId => $fields) {
            foreach ($fields as $fieldName => $meta) {
                $cardinality = $meta['cardinality'];
                $targetType = $meta['target_type'];
                $ids = $meta['ids'];

                if ($cardinality === 'one') {
                    $id = $ids[0] ?? null;
                    $result[$contentId][$fieldName] = $id !== null
                        ? ($resolvedContent[$targetType][$id] ?? null)
                        : null;
                } else {
                    $items = [];
                    foreach ($ids as $id) {
                        if (isset($resolvedContent[$targetType][$id])) {
                            $items[] = $resolvedContent[$targetType][$id];
                        }
                    }
                    $result[$contentId][$fieldName] = $items;
                }
            }
        }

        return $result;
    }

    private function parseStoredIds(?string $storedValue, string $cardinality): array
    {
        if ($storedValue === null || $storedValue === '') {
            return [];
        }

        if ($cardinality === 'one') {
            return [(int) $storedValue];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }

        return [];
    }

    private function contentToArray(array $content): array
    {
        $data = [
            'id' => $content['id'],
            'type' => $content['type'],
            'slug' => $content['slug'],
            'title' => $content['title'],
            'body' => $content['body'],
            'body_html' => $this->markdownService->toHtml($content['body'] ?? ''),
            'status' => $content['status'],
            'created_at' => $content['created_at'],
            'updated_at' => $content['updated_at'],
        ];

        if (!empty($content['fieldValues'])) {
            foreach ($content['fieldValues'] as $fv) {
                $data[$fv['field_name']] = $fv['field_value'];
            }
        }

        return $data;
    }
}
