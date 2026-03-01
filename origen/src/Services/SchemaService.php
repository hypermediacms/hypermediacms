<?php

namespace Origen\Services;

use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;

class SchemaService
{
    public function __construct(
        private SchemaRepository $schemaRepo,
        private ContentRepository $contentRepo,
        private Connection $connection,
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
     * Validate object field constraints at schema-definition time.
     *
     * @param array $field The field definition
     * @param int $depth Current nesting depth (for recursion limit)
     * @return array Validation errors
     */
    public function validateObjectConstraints(array $field, int $depth = 0): array
    {
        $errors = [];
        $constraints = $field['constraints'] ?? [];
        $maxDepth = 5;

        if ($depth > $maxDepth) {
            $errors[] = "Object nesting exceeds maximum depth of {$maxDepth}.";
            return $errors;
        }

        if (!isset($constraints['schema']) || !is_array($constraints['schema'])) {
            $errors[] = 'Object fields require a "schema" array defining nested fields.';
        }

        if (!isset($constraints['cardinality']) || !in_array($constraints['cardinality'], ['one', 'many'], true)) {
            $errors[] = 'Object fields require cardinality of "one" or "many".';
        }

        // Validate each nested field
        foreach ($constraints['schema'] ?? [] as $i => $nestedField) {
            if (empty($nestedField['field_name'])) {
                $errors[] = "Nested field at index {$i} requires field_name.";
                continue;
            }
            if (empty($nestedField['field_type'])) {
                $errors[] = "Nested field '{$nestedField['field_name']}' requires field_type.";
                continue;
            }

            // Recurse for nested objects
            if ($nestedField['field_type'] === 'object') {
                $nestedErrors = $this->validateObjectConstraints($nestedField, $depth + 1);
                foreach ($nestedErrors as $err) {
                    $errors[] = "{$nestedField['field_name']}: {$err}";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a value against an object field's nested schema.
     *
     * @param array $field The field definition with constraints.schema
     * @param mixed $value The value to validate
     * @return array Validation errors
     */
    public function validateObjectValue(array $field, $value): array
    {
        $errors = [];
        $constraints = $field['constraints'] ?? [];
        $cardinality = $constraints['cardinality'] ?? 'one';
        $schema = $constraints['schema'] ?? [];

        if ($value === null || $value === '' || $value === []) {
            // Empty is allowed unless required
            if (!empty($constraints['required'])) {
                $errors[] = 'This field is required.';
            }
            return $errors;
        }

        if ($cardinality === 'one') {
            if (!is_array($value) || $this->isSequentialArray($value)) {
                $errors[] = 'Expected a single object, not an array.';
                return $errors;
            }
            $errors = array_merge($errors, $this->validateObjectItem($schema, $value, ''));
        } else {
            if (!is_array($value) || !$this->isSequentialArray($value)) {
                $errors[] = 'Expected an array of objects.';
                return $errors;
            }
            foreach ($value as $i => $item) {
                $itemErrors = $this->validateObjectItem($schema, $item, "[{$i}]");
                $errors = array_merge($errors, $itemErrors);
            }
        }

        return $errors;
    }

    /**
     * Check if an array is sequential (0-indexed numeric keys).
     */
    private function isSequentialArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Validate a single object item against a nested schema.
     */
    private function validateObjectItem(array $schema, $item, string $prefix): array
    {
        $errors = [];

        if (!is_array($item)) {
            $errors[] = "{$prefix} must be an object.";
            return $errors;
        }

        foreach ($schema as $fieldDef) {
            $name = $fieldDef['field_name'];
            $type = $fieldDef['field_type'];
            $fieldConstraints = $fieldDef['constraints'] ?? [];
            $value = $item[$name] ?? null;
            $path = $prefix ? "{$prefix}.{$name}" : $name;

            // Required check
            if (!empty($fieldConstraints['required']) && ($value === null || $value === '')) {
                $errors[] = "{$path} is required.";
            }

            // Type-specific validation
            if ($value !== null && $value !== '') {
                if ($type === 'number' && !is_numeric($value)) {
                    $errors[] = "{$path} must be a number.";
                }
                if ($type === 'object') {
                    $nestedErrors = $this->validateObjectValue($fieldDef, $value);
                    foreach ($nestedErrors as $err) {
                        $errors[] = "{$path}: {$err}";
                    }
                }
            }
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
        // Normalize keys: YAML may use name/type or field_name/field_type
        $normalized = array_map(function ($field) {
            return [
                'field_name' => $field['field_name'] ?? $field['name'] ?? '',
                'field_type' => $field['field_type'] ?? $field['type'] ?? 'text',
                'constraints' => $field['constraints'] ?? [],
                'ui_hints' => $field['ui_hints'] ?? [],
            ];
        }, $fields);

        $this->schemaRepo->replaceForType((int) $site['id'], $contentType, $normalized);
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
     *
     * Handles relationship fields (array of IDs) and object fields (nested JSON).
     */
    public function syncFieldValues(int $contentId, int $siteId, array $values, array $schemas = []): void
    {
        // Build lookup of field types
        $fieldTypes = [];
        foreach ($schemas as $schema) {
            $fieldTypes[$schema['field_name']] = $schema['field_type'];
        }

        foreach ($values as $fieldName => $fieldValue) {
            if (is_array($fieldValue)) {
                $fieldType = $fieldTypes[$fieldName] ?? null;

                if ($fieldType === 'object') {
                    // Object fields: encode as-is (preserve nested structure)
                    $fieldValue = json_encode($fieldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    // Relationship fields: array of integer IDs
                    $fieldValue = json_encode(array_values(array_filter(array_map('intval', $fieldValue))));
                }
            }

            $this->contentRepo->upsertFieldValue($contentId, $siteId, $fieldName, $fieldValue);
        }
    }

    /**
     * Get the storage mode for a content type. Returns 'content' if no setting exists.
     */
    public function getStorageMode(int $siteId, string $contentType): string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT storage_mode FROM content_type_settings WHERE site_id = ? AND content_type = ?'
        );
        $stmt->execute([$siteId, $contentType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['storage_mode'] : 'content';
    }

    /**
     * Save content type settings (storage mode, retention).
     */
    public function saveTypeSettings(int $siteId, string $contentType, string $mode, ?int $retentionDays = null): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO content_type_settings (site_id, content_type, storage_mode, retention_days)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(site_id, content_type)
             DO UPDATE SET storage_mode = excluded.storage_mode, retention_days = excluded.retention_days'
        );
        $stmt->execute([$siteId, $contentType, $mode, $retentionDays]);
    }

    /**
     * Get full content type settings row, or null if none.
     */
    public function getTypeSettings(int $siteId, string $contentType): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM content_type_settings WHERE site_id = ? AND content_type = ?'
        );
        $stmt->execute([$siteId, $contentType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
