<?php

namespace Origen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Origen\Services\SchemaService;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;

class SchemaServiceObjectTest extends TestCase
{
    private SchemaService $schemaService;

    protected function setUp(): void
    {
        // Create mocks
        $schemaRepo = $this->createMock(SchemaRepository::class);
        $contentRepo = $this->createMock(ContentRepository::class);
        $connection = $this->createMock(Connection::class);

        $this->schemaService = new SchemaService($schemaRepo, $contentRepo, $connection);
    }

    public function test_validates_object_field_requires_schema(): void
    {
        $errors = $this->schemaService->validateObjectConstraints([
            'constraints' => ['cardinality' => 'many']
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('schema', $errors[0]);
    }

    public function test_validates_object_field_requires_cardinality(): void
    {
        $errors = $this->schemaService->validateObjectConstraints([
            'constraints' => [
                'schema' => [
                    ['field_name' => 'src', 'field_type' => 'text']
                ]
            ]
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cardinality', $errors[0]);
    }

    public function test_validates_valid_object_field(): void
    {
        $errors = $this->schemaService->validateObjectConstraints([
            'constraints' => [
                'cardinality' => 'many',
                'schema' => [
                    ['field_name' => 'src', 'field_type' => 'text'],
                    ['field_name' => 'caption', 'field_type' => 'textarea'],
                ]
            ]
        ]);

        $this->assertEmpty($errors);
    }

    public function test_validates_nested_object_fields(): void
    {
        $errors = $this->schemaService->validateObjectConstraints([
            'constraints' => [
                'cardinality' => 'many',
                'schema' => [
                    ['field_name' => 'title', 'field_type' => 'text'],
                    [
                        'field_name' => 'items',
                        'field_type' => 'object',
                        'constraints' => [
                            'cardinality' => 'many',
                            'schema' => [
                                ['field_name' => 'name', 'field_type' => 'text']
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->assertEmpty($errors);
    }

    public function test_enforces_max_nesting_depth(): void
    {
        // Build deeply nested structure (7 levels - exceeds max of 5)
        $field = $this->buildDeeplyNested(7);
        $errors = $this->schemaService->validateObjectConstraints($field);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('maximum depth', $errors[0]);
    }

    public function test_validates_nested_field_requires_name(): void
    {
        $errors = $this->schemaService->validateObjectConstraints([
            'constraints' => [
                'cardinality' => 'many',
                'schema' => [
                    ['field_type' => 'text'] // Missing field_name
                ]
            ]
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('field_name', $errors[0]);
    }

    public function test_validate_object_value_cardinality_many(): void
    {
        $field = [
            'constraints' => [
                'cardinality' => 'many',
                'schema' => [
                    ['field_name' => 'src', 'field_type' => 'text', 'constraints' => ['required' => true]],
                    ['field_name' => 'alt', 'field_type' => 'text'],
                ]
            ]
        ];

        $value = [
            ['src' => '/img/1.jpg', 'alt' => 'Photo 1'],
            ['src' => '/img/2.jpg', 'alt' => 'Photo 2'],
        ];

        $errors = $this->schemaService->validateObjectValue($field, $value);
        $this->assertEmpty($errors);
    }

    public function test_validate_object_value_cardinality_one(): void
    {
        $field = [
            'constraints' => [
                'cardinality' => 'one',
                'schema' => [
                    ['field_name' => 'image', 'field_type' => 'text'],
                    ['field_name' => 'headline', 'field_type' => 'text'],
                ]
            ]
        ];

        $value = ['image' => '/hero.jpg', 'headline' => 'Welcome'];

        $errors = $this->schemaService->validateObjectValue($field, $value);
        $this->assertEmpty($errors);
    }

    public function test_validate_object_value_required_field_missing(): void
    {
        $field = [
            'constraints' => [
                'cardinality' => 'many',
                'schema' => [
                    ['field_name' => 'src', 'field_type' => 'text', 'constraints' => ['required' => true]],
                ]
            ]
        ];

        $value = [
            ['alt' => 'No src here'], // Missing required 'src'
        ];

        $errors = $this->schemaService->validateObjectValue($field, $value);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('src', $errors[0]);
        $this->assertStringContainsString('required', $errors[0]);
    }

    public function test_validate_object_value_wrong_cardinality(): void
    {
        $field = [
            'constraints' => [
                'cardinality' => 'one',
                'schema' => [
                    ['field_name' => 'title', 'field_type' => 'text'],
                ]
            ]
        ];

        // Array instead of single object
        $value = [
            ['title' => 'First'],
            ['title' => 'Second'],
        ];

        $errors = $this->schemaService->validateObjectValue($field, $value);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('single object', $errors[0]);
    }

    /**
     * Build a deeply nested object structure.
     * 
     * @param int $depth Number of object nesting levels
     * @return array Field definition with nested objects
     */
    private function buildDeeplyNested(int $depth): array
    {
        // Start with innermost object containing a text field
        $innermost = [
            'cardinality' => 'many',
            'schema' => [
                ['field_name' => 'leaf', 'field_type' => 'text']
            ]
        ];

        // Wrap in object layers
        $current = $innermost;
        for ($i = 1; $i < $depth; $i++) {
            $current = [
                'cardinality' => 'many',
                'schema' => [
                    [
                        'field_name' => 'level_' . $i,
                        'field_type' => 'object',
                        'constraints' => $current
                    ]
                ]
            ];
        }

        return ['constraints' => $current];
    }
}
