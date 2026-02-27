<?php

namespace Origen\Tests\Unit;

use Origen\Storage\FlatFile\FrontmatterParser;
use PHPUnit\Framework\TestCase;

class FrontmatterParserTest extends TestCase
{
    private FrontmatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FrontmatterParser();
    }

    public function test_parse_extracts_meta_and_body(): void
    {
        $content = "---\ntitle: Hello World\nstatus: draft\n---\nBody content here.";
        $result = $this->parser->parse($content);

        $this->assertEquals('Hello World', $result['meta']['title']);
        $this->assertEquals('draft', $result['meta']['status']);
        $this->assertEquals('Body content here.', $result['body']);
    }

    public function test_parse_handles_no_frontmatter(): void
    {
        $content = "Just plain text.";
        $result = $this->parser->parse($content);

        $this->assertEmpty($result['meta']);
        $this->assertEquals('Just plain text.', $result['body']);
    }

    public function test_parse_handles_empty_body(): void
    {
        $content = "---\ntitle: No Body\n---\n";
        $result = $this->parser->parse($content);

        $this->assertEquals('No Body', $result['meta']['title']);
        $this->assertEquals('', $result['body']);
    }

    public function test_parse_handles_arrays_in_frontmatter(): void
    {
        $content = "---\ntags:\n  - 3\n  - 8\n  - 12\n---\nSome content.";
        $result = $this->parser->parse($content);

        $this->assertEquals([3, 8, 12], $result['meta']['tags']);
    }

    public function test_serialize_produces_valid_frontmatter(): void
    {
        $meta = ['title' => 'Test', 'status' => 'published'];
        $body = "Hello **world**.";

        $output = $this->parser->serialize($meta, $body);
        $reparsed = $this->parser->parse($output);

        $this->assertEquals('Test', $reparsed['meta']['title']);
        $this->assertEquals('published', $reparsed['meta']['status']);
        $this->assertEquals('Hello **world**.', $reparsed['body']);
    }

    public function test_roundtrip_preserves_data(): void
    {
        $meta = ['id' => 42, 'title' => 'My Post', 'slug' => 'my-post', 'status' => 'draft'];
        $body = "Body with **markdown**.";

        $serialized = $this->parser->serialize($meta, $body);
        $parsed = $this->parser->parse($serialized);

        $this->assertEquals($meta, $parsed['meta']);
        $this->assertEquals($body, $parsed['body']);
    }
}
