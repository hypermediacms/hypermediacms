<?php

namespace Origen;

use Origen\Http\Kernel;
use Origen\Http\Router;
use Origen\Http\Middleware\ResolveTenant;
use Origen\Http\Middleware\EnforceHtxVersion;
use Origen\Http\Middleware\VerifyActionToken;
use Origen\Http\Middleware\VerifyAuthToken;
use Origen\Http\Controllers\ContentController;
use Origen\Http\Controllers\ContentTypeController;
use Origen\Http\Controllers\AuthController;
use Origen\Http\Controllers\StatusController;
use Origen\Http\Controllers\PreviewController;
use Origen\Services\AuthTokenService;
use Origen\Services\ActionTokenService;
use Origen\Services\ReplayGuardService;
use Origen\Services\ContentService;
use Origen\Services\SchemaService;
use Origen\Services\RelationshipResolver;
use Origen\Services\MarkdownService;
use Origen\Services\TemplateHydratorService;
use Origen\Services\WorkflowService;
use Origen\Storage\Database\Connection;
use Origen\Storage\Database\Migrator;
use Origen\Storage\Database\ContentRepository;
use Origen\Storage\Database\SchemaRepository;
use Origen\Storage\Database\SiteRepository;
use Origen\Storage\Database\UserRepository;
use Origen\Storage\Database\TokenRepository;
use Origen\Storage\Database\QueryBuilder;
use Origen\Storage\FlatFile\ContentFileManager;
use Origen\Storage\FlatFile\SchemaFileManager;
use Origen\Storage\FlatFile\SiteConfigManager;
use Origen\Sync\WriteThrough;

class Bootstrap
{
    public static function boot(string $basePath): Kernel
    {
        $config = new Config($basePath);
        $container = new Container();
        Container::setInstance($container);

        // Core
        $container->instance(Config::class, $config);
        $container->instance(Container::class, $container);

        // Database
        $connection = new Connection($config->get('db_path'));
        $container->instance(Connection::class, $connection);

        $migrator = new Migrator($connection);
        $migrator->run();

        // Repositories
        $container->singleton(SiteRepository::class, fn($c) => new SiteRepository($c->make(Connection::class)));
        $container->singleton(UserRepository::class, fn($c) => new UserRepository($c->make(Connection::class)));
        $container->singleton(TokenRepository::class, fn($c) => new TokenRepository($c->make(Connection::class)));
        $container->singleton(ContentRepository::class, fn($c) => new ContentRepository($c->make(Connection::class)));
        $container->singleton(SchemaRepository::class, fn($c) => new SchemaRepository($c->make(Connection::class)));

        // Flat-file managers
        $container->singleton(ContentFileManager::class, fn($c) => new ContentFileManager($c->make(Config::class)->get('content_path')));
        $container->singleton(SchemaFileManager::class, fn($c) => new SchemaFileManager($c->make(Config::class)->get('schema_path')));
        $container->singleton(SiteConfigManager::class, fn($c) => new SiteConfigManager($c->make(Config::class)->get('content_path')));

        // Sync
        $container->singleton(WriteThrough::class, fn($c) => new WriteThrough(
            $c->make(Connection::class),
            $c->make(ContentRepository::class),
            $c->make(ContentFileManager::class),
            $c->make(SchemaRepository::class),
            $c->make(SchemaFileManager::class),
        ));

        // Services
        $container->singleton(AuthTokenService::class, fn($c) => new AuthTokenService($c->make(Config::class)->get('app_key')));
        $container->singleton(ActionTokenService::class, fn($c) => new ActionTokenService($c->make(Config::class)->get('app_key')));
        $container->singleton(ReplayGuardService::class, fn($c) => new ReplayGuardService($c->make(TokenRepository::class)));
        $container->singleton(MarkdownService::class, fn() => new MarkdownService());
        $container->singleton(TemplateHydratorService::class, fn() => new TemplateHydratorService());
        $container->singleton(WorkflowService::class, fn() => new WorkflowService());

        $container->singleton(SchemaService::class, fn($c) => new SchemaService(
            $c->make(SchemaRepository::class),
            $c->make(ContentRepository::class),
            $c->make(Connection::class),
        ));
        $container->singleton(RelationshipResolver::class, fn($c) => new RelationshipResolver(
            $c->make(SchemaService::class),
            $c->make(ContentRepository::class),
            $c->make(MarkdownService::class),
        ));
        $container->singleton(ContentService::class, fn($c) => new ContentService(
            $c->make(WriteThrough::class),
            $c->make(SchemaService::class),
            $c->make(ContentRepository::class),
        ));

        // Site scan: upsert _site.yaml into SQLite
        $siteConfigManager = $container->make(SiteConfigManager::class);
        $siteRepo = $container->make(SiteRepository::class);
        foreach ($siteConfigManager->scanAll() as $siteConfig) {
            $siteRepo->upsert($siteConfig);
        }

        // Schema scan: sync schema YAMLs into SQLite
        $schemaFileManager = $container->make(SchemaFileManager::class);
        $schemaService = $container->make(SchemaService::class);
        foreach ($schemaFileManager->listAll() as $entry) {
            $site = $siteRepo->findBySlug($entry['siteSlug']);
            if ($site && !empty($entry['schema']['fields'])) {
                $schemaService->saveTypeSchema($site, $entry['contentType'], $entry['schema']['fields']);
            }
        }

        // Ensure super_admins have membership on every site
        $userRepo = $container->make(UserRepository::class);
        $userRepo->ensureSuperAdminMemberships($connection);

        // Purge expired ephemeral content
        $connection->pdo()->exec(
            "DELETE FROM content WHERE id IN (
                SELECT c.id FROM content c
                JOIN content_type_settings cts
                  ON c.site_id = cts.site_id AND c.type = cts.content_type
                WHERE cts.storage_mode = 'ephemeral'
                  AND cts.retention_days IS NOT NULL
                  AND datetime(c.created_at, '+' || cts.retention_days || ' days') < datetime('now')
            )"
        );

        // Router + routes
        $router = new Router();
        $container->instance(Router::class, $router);

        $container->singleton(VerifyAuthToken::class, fn($c) => new VerifyAuthToken($c->make(AuthTokenService::class)));

        $dashboardMiddleware = [VerifyAuthToken::class];
        $tenantMiddleware = [ResolveTenant::class, EnforceHtxVersion::class];
        $actionMiddleware = array_merge($tenantMiddleware, [VerifyActionToken::class]);

        // Auth routes
        $router->post('/api/auth/login', [AuthController::class, 'login'], $tenantMiddleware);
        $router->get('/api/auth/me', [AuthController::class, 'me'], $tenantMiddleware);
        $router->post('/api/auth/logout', [AuthController::class, 'logout'], $tenantMiddleware);
        $router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword'], $tenantMiddleware);

        // Content routes
        $router->post('/api/content/prepare', [ContentController::class, 'prepare'], $tenantMiddleware);
        $router->post('/api/content/get', [ContentController::class, 'get'], $tenantMiddleware);
        $router->post('/api/content/save', [ContentController::class, 'save'], $actionMiddleware);
        $router->post('/api/content/update', [ContentController::class, 'update'], $actionMiddleware);
        $router->post('/api/content/delete', [ContentController::class, 'delete'], $actionMiddleware);

        // Content type routes
        $router->get('/api/content-types', [ContentTypeController::class, 'index'], $tenantMiddleware);
        $router->get('/api/content-types/{type}', [ContentTypeController::class, 'show'], $tenantMiddleware);
        $router->post('/api/content-types', [ContentTypeController::class, 'store'], $tenantMiddleware);
        $router->get('/api/content-types/{type}/fields', [ContentTypeController::class, 'fieldsHtml'], $tenantMiddleware);
        $router->delete('/api/content-types/{type}', [ContentTypeController::class, 'destroy'], $tenantMiddleware);

        // Dashboard
        $router->get('/', [StatusController::class, 'dashboard'], $dashboardMiddleware);
        $router->post('/', [StatusController::class, 'login'], $dashboardMiddleware);
        $router->get('/logout', [StatusController::class, 'logout'], $dashboardMiddleware);

        // Preview routes
        $router->post('/api/preview', [PreviewController::class, 'preview'], $tenantMiddleware);
        $router->get('/api/preview/status/{content_type}', [PreviewController::class, 'status'], $tenantMiddleware);

        // Health check
        $router->get('/api/health', fn() => \Origen\Http\Response::json(['status' => 'ok']), []);

        $kernel = new Kernel($router, $container);
        $container->instance(Kernel::class, $kernel);

        return $kernel;
    }

    public static function bootCli(string $basePath): Container
    {
        $config = new Config($basePath);
        $container = new Container();
        Container::setInstance($container);

        $container->instance(Config::class, $config);
        $container->instance(Container::class, $container);

        $connection = new Connection($config->get('db_path'));
        $container->instance(Connection::class, $connection);

        $migrator = new Migrator($connection);
        $migrator->run();

        // Repositories
        $container->singleton(SiteRepository::class, fn($c) => new SiteRepository($c->make(Connection::class)));
        $container->singleton(UserRepository::class, fn($c) => new UserRepository($c->make(Connection::class)));
        $container->singleton(TokenRepository::class, fn($c) => new TokenRepository($c->make(Connection::class)));
        $container->singleton(ContentRepository::class, fn($c) => new ContentRepository($c->make(Connection::class)));
        $container->singleton(SchemaRepository::class, fn($c) => new SchemaRepository($c->make(Connection::class)));

        // Flat-file managers
        $container->singleton(ContentFileManager::class, fn($c) => new ContentFileManager($c->make(Config::class)->get('content_path')));
        $container->singleton(SchemaFileManager::class, fn($c) => new SchemaFileManager($c->make(Config::class)->get('schema_path')));
        $container->singleton(SiteConfigManager::class, fn($c) => new SiteConfigManager($c->make(Config::class)->get('content_path')));

        // Sync
        $container->singleton(WriteThrough::class, fn($c) => new WriteThrough(
            $c->make(Connection::class),
            $c->make(ContentRepository::class),
            $c->make(ContentFileManager::class),
            $c->make(SchemaRepository::class),
            $c->make(SchemaFileManager::class),
        ));

        // Services
        $container->singleton(AuthTokenService::class, fn($c) => new AuthTokenService($c->make(Config::class)->get('app_key')));
        $container->singleton(SchemaService::class, fn($c) => new SchemaService(
            $c->make(SchemaRepository::class),
            $c->make(ContentRepository::class),
            $c->make(Connection::class),
        ));
        $container->singleton(ContentService::class, fn($c) => new ContentService(
            $c->make(WriteThrough::class),
            $c->make(SchemaService::class),
            $c->make(ContentRepository::class),
        ));

        // Purge expired ephemeral content
        $connection->pdo()->exec(
            "DELETE FROM content WHERE id IN (
                SELECT c.id FROM content c
                JOIN content_type_settings cts
                  ON c.site_id = cts.site_id AND c.type = cts.content_type
                WHERE cts.storage_mode = 'ephemeral'
                  AND cts.retention_days IS NOT NULL
                  AND datetime(c.created_at, '+' || cts.retention_days || ' days') < datetime('now')
            )"
        );

        return $container;
    }
}
