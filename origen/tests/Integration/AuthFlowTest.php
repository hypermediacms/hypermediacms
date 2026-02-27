<?php

namespace Origen\Tests\Integration;

use Origen\Http\Kernel;
use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Http\Router;
use Origen\Container;
use Origen\Config;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\Migrator;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\Database\UserRepository;
use Origen\Services\AuthTokenService;
use Origen\Http\Middleware\ResolveTenant;
use Origen\Http\Middleware\EnforceHtxVersion;
use Origen\Http\Controllers\AuthController;
use PHPUnit\Framework\TestCase;

class AuthFlowTest extends TestCase
{
    private Kernel $kernel;
    private string $siteKey;

    protected function setUp(): void
    {
        $connection = new Connection(':memory:');
        (new Migrator($connection))->run();

        $container = new Container();
        Container::setInstance($container);

        $this->siteKey = 'auth-test-key';
        $appKey = 'test-secret-key';

        $connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES ('auth-site', 'Auth Site', 'auth.example.com', ?)",
            [$this->siteKey]
        );

        // Create user
        $hash = password_hash('secret123', PASSWORD_BCRYPT);
        $connection->execute(
            "INSERT INTO users (name, email, password_hash) VALUES ('Test User', 'test@example.com', ?)",
            [$hash]
        );

        // Create membership
        $connection->execute(
            "INSERT INTO memberships (site_id, user_id, role) VALUES (1, 1, 'editor')"
        );

        $siteRepo = new SiteRepository($connection);
        $userRepo = new UserRepository($connection);
        $authToken = new AuthTokenService($appKey);

        $container->instance(SiteRepository::class, $siteRepo);
        $container->instance(UserRepository::class, $userRepo);
        $container->instance(AuthTokenService::class, $authToken);

        $router = new Router();
        $tenantMiddleware = [ResolveTenant::class, EnforceHtxVersion::class];

        $router->post('/api/auth/login', [AuthController::class, 'login'], $tenantMiddleware);
        $router->get('/api/auth/me', [AuthController::class, 'me'], $tenantMiddleware);
        $router->post('/api/auth/logout', [AuthController::class, 'logout'], $tenantMiddleware);

        $this->kernel = new Kernel($router, $container);
    }

    public function test_login_returns_token(): void
    {
        $request = new Request('POST', '/api/auth/login', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(200, $response->status());

        $data = json_decode($response->body(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertEquals('test@example.com', $data['user']['email']);
        $this->assertEquals('editor', $data['user']['role']);
    }

    public function test_login_rejects_bad_password(): void
    {
        $request = new Request('POST', '/api/auth/login', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(401, $response->status());
    }

    public function test_me_returns_user_info(): void
    {
        // First login
        $loginRequest = new Request('POST', '/api/auth/login', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $loginResponse = $this->kernel->handle($loginRequest);
        $loginData = json_decode($loginResponse->body(), true);
        $token = $loginData['token'];

        // Then call /me
        $meRequest = new Request('GET', '/api/auth/me', [
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
            'authorization' => 'Bearer ' . $token,
        ]);

        $meResponse = $this->kernel->handle($meRequest);
        $this->assertEquals(200, $meResponse->status());

        $data = json_decode($meResponse->body(), true);
        $this->assertEquals('test@example.com', $data['user']['email']);
        $this->assertEquals('editor', $data['user']['role']);
    }

    public function test_me_rejects_no_token(): void
    {
        $request = new Request('GET', '/api/auth/me', [
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(401, $response->status());
    }
}
