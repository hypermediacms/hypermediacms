<?php

namespace Origen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rufinus\Runtime\RequestHandler;

class InjectParamsTest extends TestCase
{
    private \ReflectionMethod $method;
    private RequestHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new RequestHandler();
        $this->method = new \ReflectionMethod(RequestHandler::class, 'injectParams');
        $this->method->setAccessible(true);
    }

    private function invoke(string $dsl, array $params): string
    {
        return $this->method->invoke($this->handler, $dsl, $params);
    }

    public function test_basic_slug_injection_without_where(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx:howmany>1</htx:howmany>\n<htx>\n<htx:each>__title__</htx:each>\n</htx>";

        $result = $this->invoke($dsl, ['slug' => 'hello-world']);

        // Should inject slug as top-level meta directive
        $this->assertStringContainsString('<htx:slug>hello-world</htx:slug>', $result);
    }

    public function test_where_clause_placeholder_replacement(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx:where>category=__slug__</htx:where>\n<htx>\n<htx:each>__title__</htx:each>\n</htx>";

        $result = $this->invoke($dsl, ['slug' => 'tutorials']);

        // The where clause should have __slug__ resolved
        $this->assertStringContainsString('<htx:where>category=tutorials</htx:where>', $result);
        // Should NOT inject slug as a top-level directive (it was consumed by where)
        $this->assertStringNotContainsString("<htx:slug>tutorials</htx:slug>", $result);
    }

    public function test_template_body_placeholders_preserved(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx:where>category=__slug__</htx:where>\n<htx>\n<htx:each><a href=\"/articles/__slug__\">__title__</a></htx:each>\n</htx>";

        $result = $this->invoke($dsl, ['slug' => 'tutorials']);

        // Template body __slug__ should be preserved for per-row hydration
        $this->assertStringContainsString('__slug__', $result);
        // But it should only be inside the template body, not the <htx:where> tag
        $this->assertStringContainsString('<htx:where>category=tutorials</htx:where>', $result);
    }

    public function test_numeric_id_injects_record_id(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx:action>prepare-update</htx:action>\n<htx>\n<htx:each>__title__</htx:each>\n</htx>";

        $result = $this->invoke($dsl, ['id' => '42']);

        // Should inject both id and recordId
        $this->assertStringContainsString('<htx:id>42</htx:id>', $result);
        $this->assertStringContainsString('<htx:recordId>42</htx:recordId>', $result);
    }

    public function test_empty_params_returns_unchanged(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx>\n</htx>";

        $result = $this->invoke($dsl, []);

        $this->assertEquals($dsl, $result);
    }

    public function test_html_special_chars_escaped(): void
    {
        $dsl = "<htx:type>article</htx:type>\n<htx:howmany>1</htx:howmany>";

        $result = $this->invoke($dsl, ['slug' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
}
