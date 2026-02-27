<?php

namespace Origen\Tests\Unit;

use Origen\Container;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    public function test_bind_and_make(): void
    {
        $container = new Container();
        $container->bind('greeting', fn() => 'hello');
        $this->assertEquals('hello', $container->make('greeting'));
    }

    public function test_singleton_returns_same_instance(): void
    {
        $container = new Container();
        $container->singleton('counter', fn() => new \stdClass());

        $a = $container->make('counter');
        $b = $container->make('counter');
        $this->assertSame($a, $b);
    }

    public function test_instance_returns_exact_object(): void
    {
        $container = new Container();
        $obj = new \stdClass();
        $obj->value = 42;
        $container->instance('obj', $obj);

        $this->assertSame($obj, $container->make('obj'));
    }

    public function test_has(): void
    {
        $container = new Container();
        $this->assertFalse($container->has('foo'));
        $container->bind('foo', fn() => 'bar');
        $this->assertTrue($container->has('foo'));
    }

    public function test_auto_wires_constructor(): void
    {
        $container = new Container();
        $obj = $container->build(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }
}
