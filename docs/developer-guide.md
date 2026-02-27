# Hypermedia CMS -- Developer Guide

This guide covers the internal architecture and common development tasks for Hypermedia CMS. It assumes you have cloned the repository and run `composer install`.

---

## 1. Project Structure

```
cms/
├── .env                          # Environment configuration
├── composer.json                 # Dependencies + autoload (PSR-4)
├── hcms                          # CLI entry point
├── phpunit.xml                   # Test configuration
├── content/                      # Flat-file content (source of truth)
│   └── {site-slug}/
│       ├── _site.yaml            # Site metadata (name, domain, api_key)
│       └── {type}/{slug}.md      # Content items (YAML frontmatter + Markdown body)
├── schemas/                      # Content type schemas
│   └── {site-slug}/{type}.yaml   # Field definitions per content type
├── storage/index/                # SQLite database (rebuildable from flat files)
├── origen/                       # Backend (API server)
│   ├── config/origen.php         # Config defaults (reads from .env via env())
│   ├── public/index.php          # HTTP entry point
│   ├── src/                      # Application source code
│   └── tests/                    # Unit and integration tests
├── rufinus/                      # Edge runtime (HTX DSL engine)
│   ├── src/                      # Library code (DSL parser, executors, expression engine)
│   └── site/                     # Demo site (.htx pages)
└── docs/                         # Documentation
```

**Key principle:** Flat files under `content/` and `schemas/` are the source of truth. The SQLite database in `storage/index/` is a rebuildable index that can be regenerated at any time with `php hcms index:rebuild`.

---

## 2. Adding a New API Endpoint

### Step 1: Create a controller method

Add a method to an existing controller in `origen/src/Http/Controllers/`, or create a new controller class. Controllers receive an `Origen\Http\Request` and return an `Origen\Http\Response`.

```php
<?php

namespace Origen\Http\Controllers;

use Origen\Http\Request;
use Origen\Http\Response;

class MyController
{
    public function __construct(
        // Dependencies are auto-wired from the Container
        private SomeService $service,
    ) {}

    public function index(Request $request): Response
    {
        $site = $request->getAttribute('current_site');
        $data = $this->service->getData((int) $site['id']);

        return Response::json(['items' => $data]);
    }
}
```

The container will resolve constructor dependencies automatically via reflection.

### Step 2: Register the route

Add the route in `origen/src/Bootstrap.php` inside the `boot()` method (and in `bootCli()` if services are needed there).

```php
$router->get('/api/my-endpoint', [MyController::class, 'index'], $tenantMiddleware);
$router->post('/api/my-endpoint', [MyController::class, 'store'], $actionMiddleware);
```

The router supports `get()`, `post()`, and `delete()` methods. Route parameters use `{param}` syntax:

```php
$router->get('/api/widgets/{id}', [WidgetController::class, 'show'], $tenantMiddleware);
// Access in controller: $request->getAttribute('id')
```

### Middleware stacks

There are three middleware configurations used for routes:

| Stack | Middleware | Use case |
|---|---|---|
| None (`[]`) | No middleware | Public endpoints (e.g., `/api/health`) |
| `$tenantMiddleware` | `ResolveTenant` + `EnforceHtxVersion` | Read endpoints requiring `X-Site-Key` and `X-HTX-Version` headers |
| `$actionMiddleware` | Tenant middleware + `VerifyActionToken` | Write endpoints requiring a one-time action token (anti-replay) |

### Response helpers

```php
Response::json(['key' => 'value']);         // 200 JSON response
Response::json(['error' => 'Oops'], 400);  // JSON with status code
Response::html('<div>Hello</div>');         // 200 HTML response
Response::html('<p>Not found</p>', 404);   // HTML with status code
```

### Accessing tenant context

The `ResolveTenant` middleware reads the `X-Site-Key` header, looks up the site, and attaches it to the request:

```php
$site = $request->getAttribute('current_site');
// $site is an associative array: ['id' => 1, 'slug' => 'my-site', 'name' => '...', ...]
```

---

## 3. Adding a New Content Field Type

Field types are used in content type schemas (e.g., `text`, `textarea`, `number`, `date`, `boolean`, `relationship`).

### Step 1: Add validation (if needed)

In `Origen\Services\SchemaService::validateFieldValues()`, add type-specific validation logic. The method iterates over schema fields and validates submitted values against constraints:

```php
// Inside validateFieldValues(), after the existing type checks:
} elseif ($schema['field_type'] === 'my_new_type') {
    // Custom validation for the new type
    if ($value !== null && !isValidMyType($value)) {
        $errors[$name][] = "The {$name} field has an invalid format.";
    }
}
```

### Step 2: Add form rendering

In `Origen\Http\Controllers\ContentController::renderFieldInput()`, add an `elseif` branch for the new type to generate the appropriate HTML form input:

```php
} elseif ($field['field_type'] === 'color') {
    $html .= '<input type="color" id="' . $this->e($field['field_name'])
        . '" name="' . $this->e($field['field_name'])
        . '" value="' . $this->e($value) . '">';
}
```

Also add the same rendering in `ContentTypeController::fieldsHtml()` so the dynamic field loader works.

### Step 3: Register in the type dropdown

Add your type to the `$typeOptions` array in `ContentTypeController::renderFieldRow()`:

```php
foreach (['text', 'textarea', 'number', 'date', 'select', 'boolean', 'relationship', 'color'] as $t) {
```

### Storage

Field values are stored in two places (write-through):
- **SQLite:** `content_field_values` table (field_name, field_value as text)
- **Flat file:** YAML frontmatter of the content's `.md` file

---

## 4. Creating a New CLI Command

### Step 1: Implement `CommandInterface`

```php
<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Container;

class MyCommand implements CommandInterface
{
    public function name(): string
    {
        return 'my:command';
    }

    public function description(): string
    {
        return 'Does something useful';
    }

    public function run(Container $container, array $args): int
    {
        // Resolve services from the container
        $connection = $container->make(\Origen\Storage\Database\Connection::class);
        $config = $container->make(\Origen\Config::class);

        echo "Running with DB: " . $config->get('db_path') . "\n";

        // $args contains CLI arguments after the command name
        // e.g., `php hcms my:command foo bar` -> $args = ['foo', 'bar']

        // Return 0 for success, non-zero for failure
        return 0;
    }
}
```

### Step 2: Register in `hcms`

Open the `hcms` file at the project root and add your command:

```php
use Origen\Cli\Commands\MyCommand;

$app->register(new MyCommand());
```

The command is now available via `php hcms my:command`.

---

## 5. Building a Rufinus Site

Rufinus is the edge runtime that renders `.htx` pages by fetching content from an Origen API server.

### Step 1: Create a site directory with `serve.php`

Copy the entry point from the demo site:

```php
<?php
// my-site/serve.php

require_once __DIR__ . '/../../vendor/autoload.php';
// Adjust the path above based on where your site lives relative to vendor/

use Rufinus\Runtime\RequestHandler;

$handler = new RequestHandler();
$response = $handler->handle(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    getallheaders(),
    __DIR__,                           // site root -- .htx files live here
    'http://localhost:8080',           // Origen API server URL
    'your-site-api-key'               // matches api_key in _site.yaml
);

if ($response === null) {
    return false; // static file, let PHP built-in server handle it
}

http_response_code($response->status);
foreach ($response->headers as $k => $v) {
    header("{$k}: {$v}");
}
echo $response->body;
```

Run it with the PHP built-in server:

```bash
cd my-site
php -S localhost:8081 serve.php
```

### Step 2: Add a root layout

Create `_layout.htx` at the site root. It must contain a `__content__` placeholder:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Site</title>
  <script src="https://unpkg.com/htmx.org@2"></script>
</head>
<body>
  <nav><!-- navigation --></nav>
  <main>__content__</main>
  <footer><!-- footer --></footer>
</body>
</html>
```

Subdirectories can have their own `_layout.htx` files for nested layouts.

### Step 3: Add pages

Create `.htx` files for your pages. A page that fetches and displays content:

```html
<!-- index.htx -->
<htx:type>article</htx:type>
<htx:order>recent</htx:order>
<htx:howmany>10</htx:howmany>

<htx>
  <htx:each>
    <article>
      <h2><a href="/articles/__slug__">__title__</a></h2>
      <p>__body__</p>
    </article>
  </htx:each>

  <htx:none>
    <p>No articles found.</p>
  </htx:none>
</htx>
```

A static page (no data directives) renders without calling the API:

```html
<!-- about.htx -->
<htx>
  <h1>About Us</h1>
  <p>This is a static page.</p>
</htx>
```

### Dynamic routes

Use `[param]` in the filename for dynamic URL segments:

```
articles/[slug].htx    ->  /articles/my-first-post
docs/[slug].htx        ->  /docs/getting-started
```

The parameter value is injected as an `<htx:slug>` meta directive before DSL execution. The Origen API uses it to filter content.

### Key concept: site root

The `$siteRoot` path passed to `RequestHandler::handle()` is the directory that contains your `.htx` files. All page routing is resolved relative to this directory. This means you can place your site directory anywhere on disk as long as `serve.php` passes the correct root path.

---

## 6. Testing

### Test locations

| Suite | Directory | Purpose |
|---|---|---|
| Unit | `origen/tests/Unit/` | Test individual components in isolation |
| Integration | `origen/tests/Integration/` | Test full API round-trips with in-memory SQLite |

### Running tests

```bash
# Run all tests
vendor/bin/phpunit origen/tests/

# Run only unit tests
vendor/bin/phpunit origen/tests/Unit/

# Run only integration tests
vendor/bin/phpunit origen/tests/Integration/

# Run a specific test file
vendor/bin/phpunit origen/tests/Integration/ContentApiTest.php
```

### Integration test pattern

Integration tests create a complete in-memory environment: a `:memory:` SQLite database, a temp directory for flat files, all services wired into a real Container, a real Router with routes, and a real Kernel. Then they construct `Request` objects and assert on the `Response`.

```php
class MyFeatureTest extends TestCase
{
    private Kernel $kernel;
    private string $tempDir;
    private Container $container;

    protected function setUp(): void
    {
        // 1. Create temp directory for flat files
        $this->tempDir = sys_get_temp_dir() . '/origen_test_' . uniqid();
        mkdir($this->tempDir . '/content', 0755, true);
        mkdir($this->tempDir . '/schemas', 0755, true);

        // 2. In-memory database
        $connection = new Connection(':memory:');
        (new Migrator($connection))->run();

        // 3. Build container and wire services
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance(Connection::class, $connection);
        // ... register all repositories, services, etc.

        // 4. Seed test data
        $connection->execute(
            "INSERT INTO sites (slug, name, domain, api_key) VALUES (?, ?, ?, ?)",
            ['test-site', 'Test Site', 'test.example.com', 'test-key-001']
        );

        // 5. Set up router and kernel
        $router = new Router();
        $router->get('/api/health', fn() => Response::json(['status' => 'ok']), []);
        // ... register routes with middleware

        $this->kernel = new Kernel($router, $this->container);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tempDir);
    }

    public function test_something(): void
    {
        $request = new Request('GET', '/api/health');
        $response = $this->kernel->handle($request);

        $this->assertEquals(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertEquals('ok', $data['status']);
    }
}
```

The key pattern is:
1. `new Connection(':memory:')` -- no disk I/O for the database
2. `(new Migrator($connection))->run()` -- create tables
3. Seed data with raw SQL or through repositories
4. Build `Kernel` with a `Router` and `Container`
5. Create `Request` objects, call `$this->kernel->handle($request)`, assert on `Response`
6. Clean up temp directories in `tearDown()`

---

## 7. Container and Dependency Injection

The `Origen\Container` class is a lightweight IoC container with four core operations:

### `bind(string $class, callable $factory): void`

Register a factory that is called every time the class is resolved:

```php
$container->bind(Logger::class, fn($c) => new Logger($c->make(Config::class)));
// Each call to $container->make(Logger::class) creates a new instance
```

### `singleton(string $class, callable $factory): void`

Register a factory that is called once. Subsequent calls return the same instance:

```php
$container->singleton(Connection::class, fn($c) => new Connection($c->make(Config::class)->get('db_path')));
// First call creates the Connection; all subsequent calls return the same one
```

### `instance(string $class, object $instance): void`

Register a pre-built object directly:

```php
$config = new Config($basePath);
$container->instance(Config::class, $config);
```

### `make(string $class): mixed`

Resolve a class. Checks instances first, then singletons, then bindings. If none match, it auto-wires by reflecting the constructor and recursively resolving typed parameters:

```php
$controller = $container->make(ContentController::class);
// Inspects ContentController's constructor, resolves each typed parameter via make()
```

Auto-wiring works for any class whose constructor parameters are either:
- Type-hinted classes (resolved recursively)
- Parameters with default values (used as-is)

### Service registration

All services are registered in `Bootstrap::boot()` for the HTTP server and `Bootstrap::bootCli()` for CLI commands. The pattern is:

```php
// Pre-built instances
$container->instance(Config::class, $config);
$container->instance(Connection::class, $connection);

// Singletons with factory closures
$container->singleton(SiteRepository::class, fn($c) => new SiteRepository(
    $c->make(Connection::class)
));

// Controllers are auto-wired (no explicit registration needed)
```

---

## 8. Middleware Pipeline

### MiddlewareInterface

Every middleware implements `Origen\Http\Middleware\MiddlewareInterface`:

```php
<?php

namespace Origen\Http\Middleware;

use Origen\Http\Request;
use Origen\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

### Writing middleware

A middleware can:
- **Short-circuit** by returning a Response without calling `$next`
- **Modify the request** before calling `$next`
- **Modify the response** after calling `$next`

```php
class RateLimit implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Short-circuit: reject if rate limited
        if ($this->isRateLimited($request)) {
            return Response::json(['error' => 'Too many requests.'], 429);
        }

        // Modify request before passing through
        $request->setAttribute('rate_limit_remaining', 99);

        // Call next middleware (or the controller)
        $response = $next($request);

        // Modify response after
        $response->header('X-RateLimit-Remaining', '99');

        return $response;
    }
}
```

### Per-route middleware

Each route declares its own middleware stack. The Kernel resolves middleware classes from the Container, builds a `MiddlewarePipeline`, and runs the request through it before dispatching to the controller:

```php
// No middleware (public)
$router->get('/api/health', fn() => Response::json(['status' => 'ok']), []);

// Tenant-only
$router->get('/api/content-types', [ContentTypeController::class, 'index'], $tenantMiddleware);

// Tenant + action token
$router->post('/api/content/save', [ContentController::class, 'save'], $actionMiddleware);
```

The pipeline executes middleware in order. Each calls `$next($request)` to proceed to the next middleware, or the controller if it is the last one.

---

## 9. The Edge/Rufinus Relationship

Rufinus and the Edge runtime share the same codebase. They are the same library under different namespaces:

- **Rufinus** uses the `Rufinus\` namespace (defined in `composer.json`)
- **Edge** uses the `EdgeHTX\` namespace

The relationship is a direct find-and-replace: `EdgeHTX\` maps to `Rufinus\`. The code, logic, and behavior are identical.

**Development workflow:**
1. Active development happens in the `rufinus/` directory under the `Rufinus\` namespace
2. When changes are stable, they are backfilled to the Edge package by replacing the namespace
3. Both produce identical runtime behavior

You will notice that `Rufinus\EdgeHTX` is the main facade class -- this name is a vestige of the original Edge namespace. Internally, it orchestrates the DSL parser, API client, and executors.

---

## 10. Configuration

### The `.env` file

Environment variables are loaded from `.env` at the project root. The `Config` class parses this file on boot, setting values into `$_ENV` and `putenv()`:

```dotenv
APP_KEY=your-secret-key-here
CONTENT_PATH=./content
SCHEMA_PATH=./schemas
DB_PATH=./storage/index/origen.db
SERVER_HOST=127.0.0.1
SERVER_PORT=8080
DEBUG=true
```

Supported value types:
- Strings: `VALUE=hello` or `VALUE="hello"`
- Booleans: `DEBUG=true` / `DEBUG=false` (case-insensitive, converted to PHP `true`/`false`)
- Comments: lines starting with `#` are ignored

### The `Config` class

The `Config` class loads `.env`, then reads defaults from `origen/config/origen.php`:

```php
<?php
// origen/config/origen.php
return [
    'app_key'     => env('APP_KEY', ''),
    'content_path' => env('CONTENT_PATH', dirname(__DIR__, 2) . '/content'),
    'schema_path'  => env('SCHEMA_PATH', dirname(__DIR__, 2) . '/schemas'),
    'db_path'      => env('DB_PATH', dirname(__DIR__, 2) . '/storage/index/origen.db'),
    'server_host'  => env('SERVER_HOST', '127.0.0.1'),
    'server_port'  => env('SERVER_PORT', '8080'),
    'debug'        => env('DEBUG', false),
];
```

Access config values anywhere you have the `Config` instance:

```php
$config->get('app_key');              // Returns the value
$config->get('missing_key', 'fallback'); // Returns 'fallback' if not set
$config->all();                       // Returns the entire config array
```

### The `env()` helper

The global `env()` function reads from `$_ENV` / `getenv()` with automatic type casting:

```php
env('DEBUG');             // Returns true (boolean), not the string "true"
env('MISSING');           // Returns null
env('MISSING', 'default'); // Returns 'default'
```

Cast values:
- `'true'`, `'(true)'` become `true`
- `'false'`, `'(false)'` become `false`
- `'null'`, `'(null)'` become `null`
- Everything else is returned as a string
