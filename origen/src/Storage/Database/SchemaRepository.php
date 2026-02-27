<?php

namespace Origen\Storage\Database;

class SchemaRepository
{
    public function __construct(private Connection $connection) {}

    public function getForType(int $siteId, string $contentType): array
    {
        return $this->connection->query(
            'SELECT * FROM field_schemas WHERE site_id = ? AND content_type = ? ORDER BY sort_order',
            [$siteId, $contentType]
        )->fetchAll();
    }

    /**
     * Replace all field schemas for a content type.
     */
    public function replaceForType(int $siteId, string $contentType, array $fields): void
    {
        $this->deleteForType($siteId, $contentType);

        foreach ($fields as $i => $field) {
            $this->connection->execute(
                'INSERT INTO field_schemas (site_id, content_type, field_name, field_type, constraints, ui_hints, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId,
                    $contentType,
                    $field['field_name'],
                    $field['field_type'],
                    json_encode($field['constraints'] ?? []),
                    json_encode($field['ui_hints'] ?? []),
                    $i,
                ]
            );
        }
    }

    public function deleteForType(int $siteId, string $contentType): void
    {
        $this->connection->execute(
            'DELETE FROM field_schemas WHERE site_id = ? AND content_type = ?',
            [$siteId, $contentType]
        );
    }

    /**
     * List distinct content types that have schemas.
     */
    public function listTypes(int $siteId): array
    {
        return array_column(
            $this->connection->query(
                'SELECT DISTINCT content_type FROM field_schemas WHERE site_id = ?',
                [$siteId]
            )->fetchAll(),
            'content_type'
        );
    }
}
