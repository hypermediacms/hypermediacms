<?php
/**
 * Create Schema Tool
 * 
 * Creates YAML schema files that define custom fields for content types.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

class CreateSchemaTool implements ToolInterface
{
    private string $schemasRoot;

    public function __construct(?string $schemasRoot = null)
    {
        $this->schemasRoot = $schemasRoot ?? dirname(__DIR__, 2) . '/../schemas';
    }

    public function getName(): string
    {
        return 'create_schema';
    }

    public function getDescription(): string
    {
        return 'Create a YAML schema file that defines custom fields for a content type. ' .
               'Schemas extend the base fields (title, slug, body, status) with custom fields.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_type' => [
                    'type' => 'string',
                    'description' => 'The name of the content type (e.g., "product", "event")'
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'The site/namespace for the schema (default: "starter")'
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Array of field definitions',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Field name (snake_case)'
                            ],
                            'type' => [
                                'type' => 'string',
                                'enum' => ['text', 'textarea', 'number', 'select', 'checkbox', 'date', 'datetime', 'email', 'url', 'image', 'file'],
                                'description' => 'Field type'
                            ],
                            'required' => [
                                'type' => 'boolean',
                                'description' => 'Whether the field is required'
                            ],
                            'options' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Options for select fields'
                            ],
                            'placeholder' => [
                                'type' => 'string',
                                'description' => 'Placeholder text for the field'
                            ],
                            'max_length' => [
                                'type' => 'integer',
                                'description' => 'Maximum length for text fields'
                            ],
                            'min' => [
                                'type' => 'number',
                                'description' => 'Minimum value for number fields'
                            ],
                            'max' => [
                                'type' => 'number',
                                'description' => 'Maximum value for number fields'
                            ]
                        ],
                        'required' => ['name', 'type']
                    ]
                ]
            ],
            'required' => ['content_type', 'fields']
        ];
    }

    public function execute(array $arguments): mixed
    {
        $contentType = $arguments['content_type'] ?? '';
        $site = $arguments['site'] ?? 'starter';
        $fields = $arguments['fields'] ?? [];

        if (empty($contentType)) {
            return ['error' => 'Content type name is required'];
        }

        if (empty($fields)) {
            return ['error' => 'At least one field is required'];
        }

        // Validate content type name
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $contentType)) {
            return [
                'error' => 'Invalid content type name',
                'message' => 'Must be lowercase, start with a letter, and contain only letters, numbers, and underscores'
            ];
        }

        // Build the schema YAML
        $schemaFields = [];
        foreach ($fields as $field) {
            $fieldDef = [
                'field_name' => $field['name'],
                'field_type' => $field['type']
            ];

            // Build constraints
            $constraints = [];
            if (!empty($field['required'])) {
                $constraints['required'] = true;
            }
            if (!empty($field['max_length'])) {
                $constraints['max_length'] = $field['max_length'];
            }
            if (isset($field['min'])) {
                $constraints['min'] = $field['min'];
            }
            if (isset($field['max'])) {
                $constraints['max'] = $field['max'];
            }
            if (!empty($field['options'])) {
                $constraints['options'] = $field['options'];
            }
            if (!empty($constraints)) {
                $fieldDef['constraints'] = $constraints;
            }

            // Build UI hints
            $uiHints = [];
            if (!empty($field['placeholder'])) {
                $uiHints['placeholder'] = $field['placeholder'];
            }
            if (!empty($uiHints)) {
                $fieldDef['ui_hints'] = $uiHints;
            }

            $schemaFields[] = $fieldDef;
        }

        $schema = ['fields' => $schemaFields];

        // Create the YAML content
        $yamlContent = $this->arrayToYaml($schema);

        // Ensure directory exists
        $siteDir = $this->schemasRoot . '/' . $site;
        if (!is_dir($siteDir)) {
            mkdir($siteDir, 0755, true);
        }

        // Write the schema file
        $schemaFile = $siteDir . '/' . $contentType . '.yaml';
        $isUpdate = file_exists($schemaFile);
        file_put_contents($schemaFile, $yamlContent);

        return [
            'success' => true,
            'action' => $isUpdate ? 'updated' : 'created',
            'content_type' => $contentType,
            'site' => $site,
            'schema_file' => $site . '/' . $contentType . '.yaml',
            'fields_count' => count($fields),
            'fields' => array_map(fn($f) => $f['name'] . ' (' . $f['type'] . ')', $fields)
        ];
    }

    /**
     * Convert array to YAML string (simple implementation)
     */
    private function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_int($key)) {
                // Array item
                if (is_array($value)) {
                    $yaml .= $prefix . "- ";
                    $first = true;
                    foreach ($value as $subKey => $subValue) {
                        if ($first) {
                            $yaml .= $subKey . ': ';
                            if (is_array($subValue)) {
                                $yaml .= "\n" . $this->arrayToYaml([$subKey => $subValue], $indent + 2);
                                $yaml = preg_replace('/\n\s*' . $subKey . ':/', '', $yaml);
                            } else {
                                $yaml .= $this->formatYamlValue($subValue) . "\n";
                            }
                            $first = false;
                        } else {
                            $yaml .= $prefix . '  ' . $subKey . ': ';
                            if (is_array($subValue)) {
                                $yaml .= "\n" . $this->arrayToYaml($subValue, $indent + 3);
                            } else {
                                $yaml .= $this->formatYamlValue($subValue) . "\n";
                            }
                        }
                    }
                } else {
                    $yaml .= $prefix . '- ' . $this->formatYamlValue($value) . "\n";
                }
            } else {
                // Keyed item
                $yaml .= $prefix . $key . ':';
                if (is_array($value)) {
                    $yaml .= "\n" . $this->arrayToYaml($value, $indent + 1);
                } else {
                    $yaml .= ' ' . $this->formatYamlValue($value) . "\n";
                }
            }
        }

        return $yaml;
    }

    /**
     * Format a value for YAML output
     */
    private function formatYamlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value) && (str_contains($value, ':') || str_contains($value, '#') || str_contains($value, '"'))) {
            return '"' . addslashes($value) . '"';
        }
        return (string)$value;
    }
}
