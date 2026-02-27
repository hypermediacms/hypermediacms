<?php

namespace Origen\Tests\Unit;

use Origen\Http\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function test_match_static_route(): void
    {
        $router = new Router();
        $router->get('/api/health', ['Controller', 'health']);

        $match = $router->match('GET', '/api/health');
        $this->assertNotNull($match);
        $this->assertEquals(['Controller', 'health'], $match['handler']);
        $this->assertEmpty($match['params']);
    }

    public function test_match_with_param(): void
    {
        $router = new Router();
        $router->get('/api/content-types/{type}', ['Controller', 'show']);

        $match = $router->match('GET', '/api/content-types/article');
        $this->assertNotNull($match);
        $this->assertEquals('article', $match['params']['type']);
    }

    public function test_match_returns_null_for_unknown_route(): void
    {
        $router = new Router();
        $router->get('/api/health', ['Controller', 'health']);

        $this->assertNull($router->match('GET', '/api/unknown'));
    }

    public function test_match_respects_http_method(): void
    {
        $router = new Router();
        $router->post('/api/content', ['Controller', 'store']);

        $this->assertNull($router->match('GET', '/api/content'));
        $this->assertNotNull($router->match('POST', '/api/content'));
    }

    public function test_match_multiple_params(): void
    {
        $router = new Router();
        $router->get('/api/{site}/{type}', ['Controller', 'index']);

        $match = $router->match('GET', '/api/marketing/blog');
        $this->assertNotNull($match);
        $this->assertEquals('marketing', $match['params']['site']);
        $this->assertEquals('blog', $match['params']['type']);
    }

    public function test_middleware_is_returned(): void
    {
        $router = new Router();
        $router->post('/api/test', ['Controller', 'test'], ['Middleware1', 'Middleware2']);

        $match = $router->match('POST', '/api/test');
        $this->assertEquals(['Middleware1', 'Middleware2'], $match['middleware']);
    }
}
