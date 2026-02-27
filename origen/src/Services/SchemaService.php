<?php

namespace Origen\Services;

use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;

class SchemaService
{
    public function __construct(
        private SchemaRepository $schemaRepo,
        private ContentRepository $contentRepo,
    ) {}

    /**
     * Get the field schema for a content type within a site.
     */
    public function getSchemaForType(array $site, string $contentType): array
    {
        $rows = $this->schemaRepo->getForType((int) $site['id'], $contentType);
        // Decode JSON constraints/ui_hints
        return array_map(function ($row) {
            $row['constraints'] = json_decode($row['constraints'] ?? '{}', true) ?: [];
            $row['ui_hints'] = json_decode($row['ui_hints'] ?? '{}', true) ?: [];
            return $row;
        }, $rows);
    }

    /**
     * Validate relationship field constraints at schema-definition time.
     */
    public function validateRelationshipConstraints(array $field): array
    {
        $errors = [];
        $constraints = $field['constraints'] ?? [];

        if (empty($constraints['target_type']) || !is_string($constraints['target_type'])) {
            $errors[] = 'Relationship fields require a non-empty target_type.';
        }

        if (!isset($constraints['cardinality']) || !in_array($constraints['cardinality'], ['one', 'many'], true)) {
            $errors[] = 'Relationship fields require cardinality of "one" or "many".';
        }

        return $errors;
    }

    /**
     * Validate field values against the schema constraints.
     */
    public function validateFieldValues(array $site, string $contentType, array $values): array
    {
        $schemas = $this->getSchemaForType($site, $contentType);
        $errors = [];

        foreach ($schemas as $schema) {
            $name = $schema['field_name'];
            $constraints = $schema['constraints'] ?? [];
            $value = $values[$name] ?? null;

            if (!empty($constraints['required']) && ($value === null || $value === '')) {
                $errors[$name][] = "The {$name} field is required.";
            }

            if ($schema['field_type'] === 'relationship' && $value !== null && $value !== '') {
                $targetType = $constraints['target_type'] ?? null;
                $cardinality = $constraints['cardinality'] ?? 'one';

                if ($targetType) {
                    $ids = $this->parseRelationshipIds($value, $cardinality);
                    $ids = array_unique($ids);

                    if (!empty($ids)) {
                        $valid = $this->contentRepo->findByIdsAndType((int) $site['id'], $targetType, $ids);
                        if (count($valid) !== count($ids)) {
                            $errors[$name][] = "The {$name} field contains invalid or non-existent references.";
                        }
                    }
                }
            } elseif ($value !== null && $value !== '') {
                if (isset($constraints['max_length']) && strlen($value) > $constraints['max_length']) {
                    $errors[$name][] = "The {$name} field must not exceed {$constraints['max_length']} characters.";
                }
                if (isset($constraints['min_length']) && strlen($value) < $constraints['min_length']) {
                    $errors[$name][] = "The {$name} field must be at least {$constraints['min_length']} characters.";
                }
                if (isset($constraints['pattern']) && !preg_match($constraints['pattern'], $value)) {
                    $errors[$name][] = "The {$name} field format is invalid.";
                }
            }
        }

        return $errors;
    }

    private function parseRelationshipIds($value, string $cardinality): array
    {
        if ($cardinality === 'one') {
            return [(int) $value];
        }

        if (is_array($value)) {
            return array_map('intval', $value);
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }

        return [];
    }

    /**
     * Get all distinct content types across schemas + content for a site.
     */
    public function listTypes(array $site): array
    {
        $siteId = (int) $site['id'];
        $schemaTypes = $this->schemaRepo->listTypes($siteId);
        $contentTypes = $this->contentRepo->distinctTypes($siteId);
        return array_values(array_unique(array_merge($schemaTypes, $contentTypes)));
    }

    /**
     * Save/replace the full field schema for a content type.
     */
    public function saveTypeSchema(array $site, string $contentType, array $fields): void
    {
        $this->schemaRepo->replaceForType((int) $site['id'], $contentType, $fields);
    }

    /**
     * Delete a content type's schema.
     */
    public function deleteType(array $site, string $contentType): void
    {
        $this->schemaRepo->deleteForType((int) $site['id'], $contentType);
    }

    /**
     * Sync (upsert) content field values.
     */
    public function syncFieldValues(int $contentId, int $siteId, array $values): void
    {
        foreach ($values as $fieldName => $fieldValue) {
            if (is_array($fieldValue)) {
                $fieldValue = json_encode(array_values(array_filter(array_map('intval', $fieldValue))));
            }

            $this->contentRepo->upsertFieldValue($contentId, $siteId, $fieldName, $fieldValue);
        }
    }
}
