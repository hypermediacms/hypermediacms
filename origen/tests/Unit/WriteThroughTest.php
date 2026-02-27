<?php

namespace Origen\Tests\Unit;

use Origen\Storage\Database\Connection;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\Migrator;
use Origen\Storage\Database\SchemaRepository;
use Origen\Storage\FlatFile\ContentFileManager;
use Origen\Storage\FlatFile\SchemaFileManager;
use Origen\Sync\WriteThrough;
use PHPUnit\Framework\TestCase;

class WriteThroughTest extends TestCase
{
    private WriteThrough $writeThrough;
    private ContentRepository $contentRepo;
    private string $tempDir;

    protected function setUp(): void
    {
        $connection = new Connection(':memory:');
        (new Migrator($connection))->run();

        $connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES ('test', 'Test', 'test.com', 'key-1')"
        );

        $this->tempDir = sys_get_temp_dir() . '/origen_test_' . uniqid();
        mkdir($this->tempDir . '/content', 0755, true);
        mkdir($this->tempDir . '/schemas', 0755, true);

        $this->contentRepo = new ContentRepository($connection);
        $schemaRepo = new SchemaRepository($connection);
        $contentFiles = new ContentFileManager($this->tempDir . '/content');
        $schemaFiles = new SchemaFileManager($this->tempDir . '/schemas');

        $this->writeThrough = new WriteThrough(
            $connection,
            $this->contentRepo,
            $contentFiles,
            $schemaRepo,
            $schemaFiles,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_create_content_writes_db_and_file(): void
    {
        $record = $this->writeThrough->createContent('test', 1, [
            'type' => 'article',
            'slug' => 'hello-world',
            'title' => 'Hello World',
            'body' => 'Some content.',
            'status' => 'draft',
        ]);

        $this->assertNotNull($record['id']);
        $this->assertEquals('hello-world', $record['slug']);

        // Check DB
        $dbRecord = $this->contentRepo->findById($record['id']);
        $this->assertNotNull($dbRecord);
        $this->assertEquals('Hello World', $dbRecord['title']);

        // Check file
        $filePath = $this->tempDir . '/content/test/article/hello-world.md';
        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);
        $this->assertStringContainsString('Hello World', $content);
    }

    public function test_delete_content_removes_db_and_file(): void
    {
        $record = $this->writeThrough->createContent('test', 1, [
            'type' => 'article',
            'slug' => 'to-delete',
            'title' => 'To Delete',
            'body' => '',
            'status' => 'draft',
        ]);

        $filePath = $this->tempDir . '/content/test/article/to-delete.md';
        $this->assertFileExists($filePath);

        $this->writeThrough->deleteContent('test', $record);

        $this->assertNull($this->contentRepo->findById($record['id']));
        $this->assertFileDoesNotExist($filePath);
    }

    public function test_save_schema_writes_db_and_file(): void
    {
        $fields = [
            ['field_name' => 'subtitle', 'field_type' => 'text', 'constraints' => []],
        ];

        $this->writeThrough->saveSchema('test', 1, 'article', $fields);

        $schemaPath = $this->tempDir . '/schemas/test/article.yaml';
        $this->assertFileExists($schemaPath);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
