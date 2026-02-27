<?php

namespace Origen\Tests\Unit;

use Origen\Storage\Database\Connection;
use Origen\Storage\Database\Migrator;
use Origen\Storage\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(':memory:');
        (new Migrator($this->connection))->run();

        // Seed a site
        $this->connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES ('test', 'Test', 'test.com', 'key-1')"
        );

        // Seed content
        $this->connection->execute(
            "INSERT INTO content (site_id, type, slug, title, body, status) VALUES (1, 'article', 'hello', 'Hello', 'body1', 'published')"
        );
        $this->connection->execute(
            "INSERT INTO content (site_id, type, slug, title, body, status) VALUES (1, 'article', 'world', 'World', 'body2', 'draft')"
        );
        $this->connection->execute(
            "INSERT INTO content (site_id, type, slug, title, body, status) VALUES (1, 'page', 'about', 'About', 'body3', 'published')"
        );
    }

    public function test_get_all_for_site(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $results = $builder->get();
        $this->assertCount(3, $results);
    }

    public function test_filter_by_type(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $results = $builder->type('article')->get();
        $this->assertCount(2, $results);
    }

    public function test_filter_by_status(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $results = $builder->status('published')->get();
        $this->assertCount(2, $results);
    }

    public function test_filter_by_slug(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $result = $builder->slug('hello')->first();
        $this->assertNotNull($result);
        $this->assertEquals('Hello', $result['title']);
    }

    public function test_limit(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $results = $builder->limit(1)->get();
        $this->assertCount(1, $results);
    }

    public function test_combined_filters(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $results = $builder->type('article')->status('published')->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Hello', $results[0]['title']);
    }

    public function test_first_returns_single_or_null(): void
    {
        $builder = new QueryBuilder($this->connection, 1);
        $result = $builder->type('nonexistent')->first();
        $this->assertNull($result);
    }
}
