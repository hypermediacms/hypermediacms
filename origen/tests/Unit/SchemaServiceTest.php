<?php

namespace Origen\Tests\Unit;

use Origen\Services\SchemaService;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\Migrator;
use Origen\Storage\Database\SchemaRepository;
use PHPUnit\Framework\TestCase;

class SchemaServiceTest extends TestCase
{
    private SchemaService $service;
    private SchemaRepository $schemaRepo;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(':memory:');
        (new Migrator($this->connection))->run();

        $this->connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES ('test', 'Test', 'test.com', 'key-1')"
        );

        $this->schemaRepo = new SchemaRepository($this->connection);
        $contentRepo = new ContentRepository($this->connection);
        $this->service = new SchemaService($this->schemaRepo, $contentRepo, $this->connection);
    }

    public function test_save_and_get_schema(): void
    {
        $site = ['id' => 1, 'slug' => 'test'];
        $fields = [
            ['field_name' => 'subtitle', 'field_type' => 'text', 'constraints' => [], 'ui_hints' => []],
            ['field_name' => 'featured', 'field_type' => 'boolean', 'constraints' => [], 'ui_hints' => []],
        ];

        $this->service->saveTypeSchema($site, 'article', $fields);
        $result = $this->service->getSchemaForType($site, 'article');

        $this->assertCount(2, $result);
        $this->assertEquals('subtitle', $result[0]['field_name']);
        $this->assertEquals('text', $result[0]['field_type']);
        $this->assertEquals('featured', $result[1]['field_name']);
    }

    public function test_list_types(): void
    {
        $site = ['id' => 1, 'slug' => 'test'];
        $this->service->saveTypeSchema($site, 'article', [
            ['field_name' => 'x', 'field_type' => 'text', 'constraints' => [], 'ui_hints' => []],
        ]);
        $this->service->saveTypeSchema($site, 'page', [
            ['field_name' => 'y', 'field_type' => 'text', 'constraints' => [], 'ui_hints' => []],
        ]);

        $types = $this->service->listTypes($site);
        $this->assertContains('article', $types);
        $this->assertContains('page', $types);
    }

    public function test_delete_type(): void
    {
        $site = ['id' => 1, 'slug' => 'test'];
        $this->service->saveTypeSchema($site, 'article', [
            ['field_name' => 'x', 'field_type' => 'text', 'constraints' => [], 'ui_hints' => []],
        ]);

        $this->service->deleteType($site, 'article');
        $result = $this->service->getSchemaForType($site, 'article');
        $this->assertEmpty($result);
    }

    public function test_validate_relationship_constraints(): void
    {
        $errors = $this->service->validateRelationshipConstraints([
            'constraints' => ['target_type' => 'author', 'cardinality' => 'one'],
        ]);
        $this->assertEmpty($errors);

        $errors = $this->service->validateRelationshipConstraints([
            'constraints' => [],
        ]);
        $this->assertNotEmpty($errors);
    }
}
