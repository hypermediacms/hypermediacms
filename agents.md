# Hypermedia CMS — Agent Operations Guide

Everything an AI agent needs to set up, configure, and operate a Hypermedia CMS instance (Origen + Rufinus).

---

## Architecture Overview

Hypermedia CMS has two components:

- **Origen** — The backend API server. Stores content as flat-file Markdown with YAML frontmatter, indexed in SQLite (WAL mode). Serves a JSON API and an HTML super admin dashboard.
- **Rufinus** — The frontend runtime. Parses `.htx` template files (the HTX DSL), fetches content from Origen's API, and renders HTML with htmx attributes.

They run as separate PHP processes and communicate over HTTP.

---

## Prerequisites

- PHP 8.2+
- Composer (for dependency installation)
- No database server required (SQLite is embedded)

---

## Quick Start

### 1. Install dependencies

```bash
cd cms/
composer install
```

### 2. Create environment config

```bash
cp .env.example .env
```

Edit `.env` and set `APP_KEY` to a random secret string (used for JWT signing):

```
APP_KEY=your-random-secret-key-here
```

All paths in `.env` are resolved relative to the `cms/` directory.

### 3. Start the servers

```bash
php hcms serve:all
```

This starts:
- **Origen API** on `http://127.0.0.1:8080`
- **Rufinus site** on `http://127.0.0.1:8081`

To start them individually:
```bash
php hcms serve          # Origen only (:8080)
php hcms serve:site     # Rufinus only (:8081)
```

### 4. First-boot setup

Visit `http://127.0.0.1:8080` in a browser. If no users exist, a setup form appears to create the first super admin account. This account automatically gets access to all sites.

---

## Project Structure

```
cms/
├── hcms                        # CLI entry point (php hcms <command>)
├── .env                        # Environment config (not committed)
├── .env.example                # Template for .env
├── composer.json               # PHP dependencies
├── content/                    # Content files (one dir per site)
│   └── starter/                # Example site
│       └── _site.yaml          # Site configuration
├── schemas/                    # Content type schema files
├── storage/
│   └── index/
│       └── origen.db           # SQLite database (auto-created)
├── origen/                     # Backend API server
│   ├── config/origen.php       # Default configuration
│   ├── public/index.php        # HTTP entry point
│   ├── src/
│   │   ├── Bootstrap.php       # Application bootstrap (DI, routes, migrations)
│   │   ├── Config.php          # Environment + config loader
│   │   ├── Container.php       # Simple DI container
│   │   ├── Http/
│   │   │   ├── Kernel.php      # Request dispatcher
│   │   │   ├── Router.php      # Route matching
│   │   │   ├── Request.php     # Request object
│   │   │   ├── Response.php    # Response object (json, html)
│   │   │   ├── Controllers/    # AuthController, ContentController, ContentTypeController, StatusController
│   │   │   └── Middleware/     # ResolveTenant, EnforceHtxVersion, VerifyActionToken, VerifyAuthToken
│   │   ├── Services/           # AuthTokenService, ContentService, SchemaService, etc.
│   │   ├── Storage/
│   │   │   ├── Database/       # Connection, Migrator, Repositories
│   │   │   ├── FlatFile/       # ContentFileManager, SchemaFileManager, SiteConfigManager
│   │   │   └── Sync/          # WriteThrough (keeps DB + flat files in sync)
│   │   ├── Cli/               # CLI application and commands
│   │   ├── DTOs/              # Data transfer objects
│   │   ├── Enums/             # Role, ContentStatus
│   │   └── Exceptions/        # HttpException, ValidationException, etc.
│   └── tests/                 # PHPUnit tests
├── rufinus/                   # Frontend runtime
│   ├── site/                  # The actual website (starter template)
│   │   ├── serve.php          # Runtime entry point
│   │   ├── _layout.htx        # Root HTML layout
│   │   ├── _error.htx         # Error page template
│   │   ├── index.htx          # Home page
│   │   ├── about.htx          # Static about page
│   │   ├── articles/          # Article pages (listing + dynamic [slug])
│   │   ├── docs/              # Documentation pages (listing + dynamic [slug])
│   │   └── public/            # Static assets
│   └── src/
│       ├── EdgeHTX.php        # Main facade
│       ├── Runtime/           # Router, RequestHandler, AuthGuard, ApiProxy, LayoutResolver
│       ├── Parser/            # DSLParser, MetaExtractor, TemplateExtractor, ResponseExtractor
│       ├── Executors/         # GetContentExecutor, SetContentExecutor, DeleteContentExecutor
│       ├── Expressions/       # Expression engine (Lexer, Parser, Evaluator, functions)
│       └── Services/          # CentralApiClient, Hydrator
└── docs/                      # Project documentation
```

---

## Site Configuration

Each site is a directory under `content/` containing a `_site.yaml`:

```yaml
name: My Site
domain: localhost
api_key: htx-my-site-unique-key
active: true
settings: {}
```

- The **directory name** becomes the site slug (e.g., `content/my-site/` → slug `my-site`)
- The **api_key** is a shared secret between Rufinus and Origen
- Sites are auto-discovered on Origen boot — no manual registration needed
- Content files for the site live in the same directory as `_site.yaml`

### Creating a new site

```bash
mkdir -p content/my-site
```

Create `content/my-site/_site.yaml` with the config above, then restart Origen.

---

## Rufinus Site Setup

The Rufinus site lives in `rufinus/site/`. The key file is `serve.php`:

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Rufinus\Runtime\RequestHandler;

$handler = new RequestHandler();
$response = $handler->handle(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    getallheaders(),
    __DIR__,                        // site root (pages live here)
    'http://localhost:8080',        // Origen API URL
    'htx-my-site-unique-key'       // site API key (must match _site.yaml)
);

if ($response === null) {
    return false; // static file, let PHP handle it
}

http_response_code($response->status);
foreach ($response->headers as $k => $v) {
    header("{$k}: {$v}");
}
echo $response->body;
```

**The three parameters that matter:**
1. `__DIR__` — where `.htx` page files live
2. Origen API URL — where to fetch content from
3. API key — must match the `api_key` in `_site.yaml`

---

## HTX Template Files

### File-based routing

| File path | URL |
|-----------|-----|
| `index.htx` | `/` |
| `about.htx` | `/about` |
| `articles/index.htx` | `/articles` |
| `articles/[slug].htx` | `/articles/:slug` (dynamic) |

Files starting with `_` are not routable (layouts, errors).

### Static page

```htx
<htx>
  <h1>Hello World</h1>
  <p>This is a static page.</p>
</htx>
```

### Data-driven page

```htx
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
    <p>No articles yet.</p>
  </htx:none>
</htx>
```

### Meta directives

| Directive | Purpose |
|-----------|---------|
| `<htx:type>` | Content type to query |
| `<htx:howmany>` | Number of results |
| `<htx:order>` | Sort order (`recent`, `oldest`) |
| `<htx:where>` | Filter (e.g., `status=published`) |
| `<htx:fields>` | Specific fields to return |
| `<htx:action>` | Mutation action (`prepare-save`, `save`, `update`, `delete`) |

### Placeholders

- `__field_name__` — replaced with content field values inside `<htx:each>`
- `__content__` — replaced with page output inside `_layout.htx`
- `__status_code__` — replaced with HTTP status in `_error.htx`

### Expressions

```htx
{{ field_name }}              <!-- escaped output -->
{{! field_name }}             <!-- raw/unescaped output (for HTML) -->
{{ time_ago(updated_at) }}    <!-- function call -->
{{ if not empty(summary) }}   <!-- conditional -->
  <p>{{ summary }}</p>
{{ endif }}
```

### Layouts

`_layout.htx` files wrap pages automatically. They nest — a `_layout.htx` in a subdirectory wraps only pages in that directory, then the parent layout wraps the result. Use `__content__` as the insertion point.

---

## Origen API

All API requests require:
- `X-Site-Key: <api_key>` header
- `X-HTX-Version: 1` header

### Authentication

```bash
# Login
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"email":"admin@example.com","password":"secret"}'

# Response: {"token":"<jwt>","user":{...}}

# Check current user
curl http://localhost:8080/api/auth/me \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -H "Authorization: Bearer <jwt>"
```

### Content operations

Content mutations use a two-phase flow: **prepare** (get an action token) then **execute** (use the token).

```bash
# 1. Prepare a save
curl -X POST http://localhost:8080/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"meta":{"action":"prepare-save","type":"article"},"responseTemplates":[]}'

# 2. Save content (use the htx-token from step 1)
curl -X POST http://localhost:8080/api/content/save \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"htx-token":"<token>","htx-context":"save","title":"Hello World","body":"My first post!","status":"published","type":"article"}'

# Get content
curl -X POST http://localhost:8080/api/content/get \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"meta":{"type":"article","howmany":10,"order":"recent"}}'
```

### Content types (schemas)

```bash
# List content types
curl http://localhost:8080/api/content-types \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1"

# Create a content type
curl -X POST http://localhost:8080/api/content-types \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"type":"article","fields":[{"name":"title","type":"text","required":true},{"name":"body","type":"markdown","required":true},{"name":"summary","type":"text"}]}'
```

### Health check

```bash
curl http://localhost:8080/api/health
# {"status":"ok"}
```

---

## CLI Commands

All commands are run from the `cms/` directory:

```bash
php hcms serve              # Start Origen API server (:8080)
php hcms serve:site         # Start Rufinus site server (:8081)
php hcms serve:all          # Start both servers
php hcms user:create        # Create a user (interactive)
php hcms site:create        # Create a new site (interactive)
php hcms index:rebuild      # Rebuild SQLite index from flat files
php hcms token:cleanup      # Purge expired action tokens
```

---

## Authentication Model

- **JWT tokens** (HS256) signed with `APP_KEY`, 24-hour expiry
- **Roles**: `super_admin`, `tenant_admin`, `editor`, `author`, `viewer`
- **Super admin** gets automatic membership on every site (granted on Origen boot)
- **Rufinus auth** uses `htx_session` cookie — log in at `/admin/login` on the Rufinus site
- **Origen dashboard** uses `dashboard_token` cookie or `Authorization: Bearer` header

---

## Database

- SQLite with WAL mode and foreign keys enabled
- Auto-created on first boot at the path specified by `DB_PATH`
- Schema migrations run automatically on every boot (`Migrator.php`)
- Tables: `sites`, `users`, `memberships`, `content`, `content_field_values`, `field_schemas`, `used_tokens`
- Can be fully rebuilt from flat files: `php hcms index:rebuild`

---

## Content Storage

Content is stored in two places, kept in sync by the WriteThrough service:

1. **Flat files** — Markdown with YAML frontmatter in `content/<site-slug>/`
2. **SQLite** — Indexed for fast queries

The flat files are the source of truth. If the database is deleted, run `php hcms index:rebuild` to reconstruct it.

---

## Key Configuration

### .env variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_KEY` | (required) | JWT signing secret |
| `DB_PATH` | `./storage/index/origen.db` | SQLite database path |
| `CONTENT_PATH` | `./content` | Content flat files root |
| `SCHEMA_PATH` | `./schemas` | Schema definition files |
| `SERVER_HOST` | `127.0.0.1` | Origen server bind address |
| `SERVER_PORT` | `8080` | Origen server port |
| `DEBUG` | `false` | Show detailed error traces |

### Rufinus site config (serve.php)

The three arguments to `RequestHandler::handle()` that connect Rufinus to Origen:
1. **Site root** — directory containing `.htx` files
2. **Origen URL** — e.g., `http://localhost:8080`
3. **API key** — must match the site's `_site.yaml` `api_key` value

---

## Running Tests

```bash
cd cms/
vendor/bin/phpunit origen/tests/
```

---

## Setting Up a New Site (End-to-End)

1. Create the content directory and config:
   ```bash
   mkdir -p content/my-site
   ```
   Create `content/my-site/_site.yaml`:
   ```yaml
   name: My Site
   domain: my-site.com
   api_key: htx-my-site-001
   active: true
   settings: {}
   ```

2. Update `rufinus/site/serve.php` with the new API key, or create a second Rufinus site directory with its own `serve.php`.

3. Create `.htx` pages in the Rufinus site directory.

4. Restart Origen to discover the new site: `php hcms serve:all`

5. Visit `http://localhost:8080` — the super admin dashboard shows the new site.

6. Visit `http://localhost:8081` — the Rufinus site renders your pages.

7. Create content via the API or Rufinus admin interface.

---

## Troubleshooting

- **"Not found" on all routes** — Check that Origen is running and the API key in `serve.php` matches `_site.yaml`
- **Empty content pages** — Content hasn't been created yet. Use the API to create entries.
- **"Invalid token" errors** — The `APP_KEY` in `.env` may have changed. Existing tokens become invalid.
- **Database missing** — It auto-creates on boot. Just restart Origen.
- **Flat files out of sync** — Run `php hcms index:rebuild` to rebuild the SQLite index from flat files.
