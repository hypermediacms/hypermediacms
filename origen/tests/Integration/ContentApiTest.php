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
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\Database\UserRepository;
use Origen\Storage\Database\TokenRepository;
use Origen\Storage\FlatFile\ContentFileManager;
use Origen\Storage\FlatFile\SchemaFileManager;
use Origen\Storage\FlatFile\SiteConfigManager;
use Origen\Sync\WriteThrough;
use Origen\Services\AuthTokenService;
use Origen\Services\ActionTokenService;
use Origen\Services\ReplayGuardService;
use Origen\Services\ContentService;
use Origen\Services\SchemaService;
use Origen\Services\RelationshipResolver;
use Origen\Services\MarkdownService;
use Origen\Services\TemplateHydratorService;
use Origen\Services\WorkflowService;
use Origen\Http\Middleware\ResolveTenant;
use Origen\Http\Middleware\EnforceHtxVersion;
use Origen\Http\Middleware\VerifyActionToken;
use Origen\Http\Controllers\ContentController;
use Origen\Http\Controllers\ContentTypeController;
use Origen\Http\Controllers\AuthController;
use PHPUnit\Framework\TestCase;

class ContentApiTest extends TestCase
{
    private Kernel $kernel;
    private string $tempDir;
    private string $siteKey;
    private Container $container;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/origen_integration_' . uniqid();
        mkdir($this->tempDir . '/content', 0755, true);
        mkdir($this->tempDir . '/schemas', 0755, true);

        $connection = new Connection(':memory:');
        (new Migrator($connection))->run();

        $this->container = new Container();
        Container::setInstance($this->container);

        // Seed site
        $this->siteKey = 'test-key-001';
        $connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES ('test-site', 'Test Site', 'test.example.com', ?)",
            [$this->siteKey]
        );

        $appKey = 'test-secret-key';
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn($k, $d = null) => match($k) {
            'app_key' => $appKey,
            'content_path' => $this->tempDir . '/content',
            'schema_path' => $this->tempDir . '/schemas',
            'debug' => true,
            default => $d,
        });

        $this->container->instance(Config::class, $config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Connection::class, $connection);

        // Repositories
        $siteRepo = new SiteRepository($connection);
        $userRepo = new UserRepository($connection);
        $tokenRepo = new TokenRepository($connection);
        $contentRepo = new ContentRepository($connection);
        $schemaRepo = new SchemaRepository($connection);

        $this->container->instance(SiteRepository::class, $siteRepo);
        $this->container->instance(UserRepository::class, $userRepo);
        $this->container->instance(TokenRepository::class, $tokenRepo);
        $this->container->instance(ContentRepository::class, $contentRepo);
        $this->container->instance(SchemaRepository::class, $schemaRepo);

        // Flat-file managers
        $contentFiles = new ContentFileManager($this->tempDir . '/content');
        $schemaFiles = new SchemaFileManager($this->tempDir . '/schemas');

        $this->container->instance(ContentFileManager::class, $contentFiles);
        $this->container->instance(SchemaFileManager::class, $schemaFiles);

        // Services
        $authToken = new AuthTokenService($appKey);
        $actionToken = new ActionTokenService($appKey);
        $replayGuard = new ReplayGuardService($tokenRepo);
        $markdown = new MarkdownService();
        $hydrator = new TemplateHydratorService();
        $workflow = new WorkflowService();

        $this->container->instance(AuthTokenService::class, $authToken);
        $this->container->instance(ActionTokenService::class, $actionToken);
        $this->container->instance(ReplayGuardService::class, $replayGuard);
        $this->container->instance(MarkdownService::class, $markdown);
        $this->container->instance(TemplateHydratorService::class, $hydrator);
        $this->container->instance(WorkflowService::class, $workflow);

        $schemaService = new SchemaService($schemaRepo, $contentRepo);
        $this->container->instance(SchemaService::class, $schemaService);

        $relationshipResolver = new RelationshipResolver($schemaService, $contentRepo, $markdown);
        $this->container->instance(RelationshipResolver::class, $relationshipResolver);

        $writeThrough = new WriteThrough($connection, $contentRepo, $contentFiles, $schemaRepo, $schemaFiles);
        $this->container->instance(WriteThrough::class, $writeThrough);

        $contentService = new ContentService($writeThrough, $schemaService, $contentRepo);
        $this->container->instance(ContentService::class, $contentService);

        // Router
        $router = new Router();
        $tenantMiddleware = [ResolveTenant::class, EnforceHtxVersion::class];
        $actionMiddleware = array_merge($tenantMiddleware, [VerifyActionToken::class]);

        $router->post('/api/content/prepare', [ContentController::class, 'prepare'], $tenantMiddleware);
        $router->post('/api/content/get', [ContentController::class, 'get'], $tenantMiddleware);
        $router->post('/api/content/save', [ContentController::class, 'save'], $actionMiddleware);
        $router->post('/api/content/update', [ContentController::class, 'update'], $actionMiddleware);
        $router->post('/api/content/delete', [ContentController::class, 'delete'], $actionMiddleware);
        $router->get('/api/content-types', [ContentTypeController::class, 'index'], $tenantMiddleware);
        $router->post('/api/content-types', [ContentTypeController::class, 'store'], $tenantMiddleware);
        $router->get('/api/health', fn() => Response::json(['status' => 'ok']), []);

        $this->kernel = new Kernel($router, $this->container);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_health_endpoint(): void
    {
        $request = new Request('GET', '/api/health');
        $response = $this->kernel->handle($request);

        $this->assertEquals(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertEquals('ok', $data['status']);
    }

    public function test_requires_site_key(): void
    {
        $request = new Request('POST', '/api/content/get', [
            'content-type' => 'application/json',
            'x-htx-version' => '1',
        ], [], ['meta' => []]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(401, $response->status());
    }

    public function test_requires_htx_version(): void
    {
        $request = new Request('POST', '/api/content/get', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
        ], [], ['meta' => []]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(400, $response->status());
    }

    public function test_prepare_returns_form_contract(): void
    {
        $request = new Request('POST', '/api/content/prepare', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'meta' => ['action' => 'prepare-save', 'type' => 'article'],
            'responseTemplates' => [],
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(200, $response->status());

        $data = json_decode($response->body(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('endpoint', $data['data']);
        $this->assertArrayHasKey('payload', $data['data']);
        $this->assertArrayHasKey('values', $data['data']);
        $this->assertEquals('/api/content/save', $data['data']['endpoint']);
    }

    public function test_full_content_crud_flow(): void
    {
        // 1. Prepare for save
        $prepareRequest = new Request('POST', '/api/content/prepare', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'meta' => ['action' => 'prepare-save', 'type' => 'article'],
            'responseTemplates' => [],
        ]);

        $prepareResponse = $this->kernel->handle($prepareRequest);
        $this->assertEquals(200, $prepareResponse->status());

        $prepareData = json_decode($prepareResponse->body(), true);
        $payload = json_decode($prepareData['data']['payload'], true);
        $token = $payload['htx-token'];

        // 2. Save content
        $saveRequest = new Request('POST', '/api/content/save', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'htx-token' => $token,
            'htx-context' => 'save',
            'htx-recordId' => null,
            'title' => 'My Test Post',
            'body' => 'Some **markdown** content.',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $saveResponse = $this->kernel->handle($saveRequest);
        $this->assertEquals(200, $saveResponse->status());
        $this->assertStringContainsString('Content created!', $saveResponse->body());

        // 3. Get content
        $getRequest = new Request('POST', '/api/content/get', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'meta' => ['type' => 'article'],
        ]);

        $getResponse = $this->kernel->handle($getRequest);
        $this->assertEquals(200, $getResponse->status());

        $getData = json_decode($getResponse->body(), true);
        $this->assertCount(1, $getData['rows']);
        $this->assertEquals('My Test Post', $getData['rows'][0]['title']);
        $this->assertArrayHasKey('body_html', $getData['rows'][0]);

        // 4. Verify flat file exists
        $filePath = $this->tempDir . '/content/test-site/article/my-test-post.md';
        $this->assertFileExists($filePath);
    }

    public function test_get_returns_empty_for_nonexistent_type(): void
    {
        $request = new Request('POST', '/api/content/get', [
            'content-type' => 'application/json',
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ], [], [
            'meta' => ['type' => 'nonexistent'],
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(200, $response->status());

        $data = json_decode($response->body(), true);
        $this->assertEmpty($data['rows']);
    }

    public function test_content_types_json(): void
    {
        $request = new Request('GET', '/api/content-types', [
            'x-site-key' => $this->siteKey,
            'x-htx-version' => '1',
        ]);

        $response = $this->kernel->handle($request);
        $this->assertEquals(200, $response->status());

        $data = json_decode($response->body(), true);
        $this->assertArrayHasKey('types', $data);
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
