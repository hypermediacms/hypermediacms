# Hypermedia CMS -- Architecture

## 1. System Overview

Hypermedia CMS is a two-component content management system designed around hypermedia principles. Content is authored as flat Markdown files and served as HTML fragments driven by htmx, eliminating the need for JavaScript frameworks on the frontend.

```
 Browser                     Rufinus (Edge Runtime)                Origen (Backend)
+--------+   HTML/htmx      +---------------------+   JSON API   +------------------+
|        | ----------------> |  File-based routing  | -----------> |  SQLite (WAL)    |
|  htmx  | <--------------- |  HTX DSL parsing     | <----------- |  Flat-file .md   |
|        |   HTML fragments  |  Template hydration  |   rows/data  |  YAML schemas    |
+--------+                   +---------------------+              +------------------+
```

**Origen** is the backend API server. It stores content as Markdown files with YAML frontmatter and uses SQLite in WAL mode as a read-optimized index. Every mutation writes to both storage layers through a coordinated write-through sync mechanism.

**Rufinus** is the edge runtime. It parses HTX DSL templates (`.htx` files), calls Origen's API to fetch or mutate content, and renders HTML output. For htmx partial requests, it returns fragments; for full page loads, it wraps content in nested layouts.

---

## 2. Design Principles

### Flat-file Markdown as source of truth

The canonical representation of all content is a `.md` file with YAML frontmatter on disk. The database is never the source of truth -- it is always rebuildable from flat files via `hcms index:rebuild`.

### SQLite as index/cache

SQLite (WAL mode) serves as a fast, queryable index. It supports filtering by type, slug, status, and custom field values. If the database is lost or corrupted, the `IndexRebuildCommand` re-ingests every `.md` and `.yaml` file to reconstruct it.

### Framework-free PHP 8.2+

No Laravel, no Symfony HTTP kernel, no ORM. The entire stack is plain PHP with a hand-rolled DI container (`Origen\Container`), router, middleware pipeline, and query builder. External dependencies are limited to `firebase/php-jwt`, `symfony/yaml`, and `league/commonmark`.

### Plain arrays throughout

There are no Eloquent models or ORM entity objects. Every data record -- content rows, site configs, user records -- flows through the system as a plain associative array. DTOs exist only at API boundaries (`PrepareRequestDTO`, `PrepareResponseDTO`, `ExecuteRequestDTO`).

### Write-through sync

Every mutation writes SQLite first (to obtain an auto-increment ID), then writes the corresponding `.md` or `.yaml` file. The entire operation runs inside a database transaction. On failure, the transaction rolls back and any orphaned file is deleted:

```
beginTransaction()
  -> SQLite INSERT (get ID)
  -> Write .md file with frontmatter containing ID
  -> UPDATE file_path in SQLite
commit()

On failure:
  rollBack()
  -> delete orphaned .md if written
```

### Two-phase mutations (prepare / execute)

Write operations use a two-phase protocol to prevent CSRF and replay attacks:

1. **Prepare** -- Client sends meta directives. Origen returns an action token (HS256 JWT, 5-minute TTL, UUID `jti`) and form contract (endpoint, payload, current values).
2. **Execute** -- Client submits the form with the action token. `VerifyActionToken` middleware validates the token, checks replay via `used_tokens` table, then allows the controller to proceed.

### Multi-tenancy via X-Site-Key

Every API request must include an `X-Site-Key` header. The `ResolveTenant` middleware looks up the site by API key and attaches it to the request. All queries are scoped to `site_id`.

### File-based routing in Rufinus

URL paths map directly to `.htx` files on disk. Dynamic segments use bracket notation (`[slug].htx`). The router walks the directory tree, trying exact matches first, then dynamic segment matches at each level.

### Hypermedia-driven

The frontend uses htmx for SPA-like navigation. Full page loads get a complete HTML document (with layouts); htmx partial requests (`HX-Request: true`) receive only the inner fragment, skipping the root `<!DOCTYPE html>` layout.

---

## 3. Component Architecture

### Origen (Backend)

#### HTTP Layer

```
Request -> Kernel -> Router::match() -> MiddlewarePipeline -> Controller -> Response
```

| Class | Responsibility |
|-------|---------------|
| `Http\Kernel` | Matches route, builds middleware pipeline, dispatches to controller |
| `Http\Router` | Registers routes with `{param}` segments, matches method + path |
| `Http\MiddlewarePipeline` | Chains middleware, calls final controller handler |
| `Http\Request` | Wraps superglobals, provides `input()`, `header()`, `setAttribute()` |
| `Http\Response` | JSON and HTML response builder with status codes and headers |

#### Middleware

| Middleware | Applied To | Purpose |
|-----------|-----------|---------|
| `ResolveTenant` | All `/api/*` routes | Resolves `X-Site-Key` header to a site record via `SiteRepository` |
| `EnforceHtxVersion` | All `/api/*` routes | Requires `X-HTX-Version: 1` header |
| `VerifyActionToken` | `/api/content/save`, `/update`, `/delete` | Validates action JWT, checks replay guard, marks token used |

#### Controllers

| Controller | Endpoints |
|-----------|----------|
| `ContentController` | `POST /api/content/prepare`, `/get`, `/save`, `/update`, `/delete` |
| `ContentTypeController` | `GET/POST/DELETE /api/content-types`, `GET /api/content-types/{type}/fields` |
| `AuthController` | `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout` |

#### Services

| Service | Responsibility |
|---------|---------------|
| `ContentService` | CRUD operations on content; delegates writes to `WriteThrough`, queries to `QueryBuilder` |
| `SchemaService` | Manages field schemas per content type; syncs custom field values to `content_field_values` |
| `AuthTokenService` | Issues and validates auth JWTs (HS256, 24h expiry) with user/site/role claims |
| `ActionTokenService` | Issues and validates action JWTs (HS256, 5min expiry) with `jti` UUID and `htx-context` claims |
| `ReplayGuardService` | Checks `used_tokens` table to prevent action token reuse; provides cleanup of expired entries |
| `RelationshipResolver` | Resolves `relationship` type fields by fetching referenced content records |
| `MarkdownService` | Converts Markdown to sanitized HTML via CommonMark; applies allowlist-based tag/attribute sanitization |
| `WorkflowService` | Governs allowed status transitions (draft -> published, etc.) based on content type and user role |
| `TemplateHydratorService` | Server-side response template resolution for success/error/redirect modes |

#### Storage / Database

| Class | Responsibility |
|-------|---------------|
| `Connection` | PDO wrapper; sets WAL journal mode and foreign keys on construction |
| `Migrator` | Creates all 7 tables via `CREATE TABLE IF NOT EXISTS` |
| `QueryBuilder` | Fluent builder scoped to `site_id`; supports `type()`, `slug()`, `status()`, `where()`, `orderBy()`, `limit()` |
| `ContentRepository` | CRUD on `content` and `content_field_values` tables |
| `SchemaRepository` | CRUD on `field_schemas` table |
| `SiteRepository` | Lookup and upsert on `sites` table |
| `UserRepository` | Lookup on `users` table; password verification |
| `TokenRepository` | Insert/lookup/cleanup on `used_tokens` table |

#### Storage / FlatFile

| Class | Responsibility |
|-------|---------------|
| `FrontmatterParser` | Parses `---` delimited YAML frontmatter + Markdown body; serializes back |
| `ContentFileManager` | Read/write/delete/rename `.md` files at `content/{site-slug}/{type}/{slug}.md` |
| `SchemaFileManager` | Read/write/delete `.yaml` files at `schemas/{site-slug}/{type}.yaml` |
| `SiteConfigManager` | Reads `_site.yaml` files from each site directory for boot-time site registration |

#### Sync

| Class | Responsibility |
|-------|---------------|
| `WriteThrough` | Coordinates transactional SQLite + flat-file writes for content and schema mutations; handles rollback and orphan cleanup |

#### CLI

| Command | Purpose |
|---------|---------|
| `ServeCommand` | Starts the Origen API server via PHP built-in server |
| `ServeSiteCommand` | Starts a Rufinus site server |
| `ServeAllCommand` | Starts both Origen and a Rufinus site concurrently |
| `IndexRebuildCommand` | Rebuilds SQLite index from all flat files on disk |
| `UserCreateCommand` | Creates a user with hashed password and site membership |
| `SiteCreateCommand` | Creates a new site with `_site.yaml` and SQLite record |
| `TokenCleanupCommand` | Purges expired entries from `used_tokens` |

#### DTOs

| DTO | Purpose |
|-----|---------|
| `PrepareRequestDTO` | Extracts `meta` and `responseTemplates` from a prepare request; normalizes action name |
| `PrepareResponseDTO` | Structures the prepare response: endpoint, payload, values, labels, response templates |
| `ExecuteRequestDTO` | Extracts `htx-recordId`, `htx-context`, `htx-token`, and remaining form data from an execute request |

---

### Rufinus (Edge Runtime)

#### Runtime

| Class | Responsibility |
|-------|---------------|
| `RequestHandler` | Main entry point; dispatches static files, API proxy, auth guard, and DSL execution |
| `Router` | File-based routing; resolves URL paths to `.htx` files with `[param]` dynamic segments |
| `LayoutResolver` | Walks from matched file directory up to site root collecting `_layout.htx` files; wraps content innermost-first; skips root `<!DOCTYPE>` layout for htmx requests |
| `AuthGuard` | Protects `/admin/*` routes; manages auth cookie (`hcms_token`) |
| `ApiProxy` | Forwards `/api/*` requests to Origen with `X-Site-Key` and auth headers |
| `Response` | Simple response object with status, body, and headers |

#### Parser

| Class | Responsibility |
|-------|---------------|
| `DSLParser` | Orchestrates parsing of HTX DSL; coordinates `MetaExtractor`, `ResponseExtractor`, `TemplateExtractor` |
| `MetaExtractor` | Extracts `<htx:type>`, `<htx:action>`, `<htx:howmany>`, and other meta directives |
| `ResponseExtractor` | Extracts `<htx:response-*>` blocks (success, error, redirect templates) |
| `TemplateExtractor` | Extracts the `<htx>...</htx>` template body |

#### Executors

| Executor | Responsibility |
|----------|---------------|
| `GetContentExecutor` | Calls `POST /api/content/get`, hydrates `<htx:each>` loops and single-item templates, handles `<htx:none>` empty state, processes `<htx:rel>` relationship blocks |
| `SetContentExecutor` | Calls `POST /api/content/prepare`, hydrates form templates with action token payload and current values |
| `DeleteContentExecutor` | Calls `POST /api/content/prepare` with delete action, hydrates confirmation template |

#### Services

| Service | Responsibility |
|---------|---------------|
| `CentralApiClient` | HTTP client for Origen; sends `X-Site-Key` and `X-HTX-Version` headers on every request |
| `Hydrator` | Replaces `__placeholder__` tokens in templates with data values; HTML-escapes by default; trusts pre-sanitized fields (`body_html`, `status_options`, etc.); supports dot-notation (`__author.title__`) |

#### Expressions

| Class | Responsibility |
|-------|---------------|
| `ExpressionEngine` | Evaluates `{{ expression }}` syntax in templates; composes Lexer, Parser, Evaluator |
| `Lexer` | Tokenizes template strings into text segments and expression segments |
| `Parser` | Builds AST nodes: `FieldRef`, `FunctionCall`, `BinaryOp`, `IfNode`, `EachNode`, `DotAccess`, etc. |
| `Evaluator` | Tree-walks the AST with a data context to produce output strings |
| `FunctionRegistry` | Registers built-in functions: `StringFunctions`, `ArrayFunctions`, `DateFunctions`, `NumberFunctions` |

#### Facade

| Class | Responsibility |
|-------|---------------|
| `EdgeHTX` | Convenience facade; instantiates parser, API client, hydrator, expression engine, and all three executors; provides `getContent()`, `setContent()`, `deleteContent()` methods |

---

## 4. Data Flow

### Read Path (page request)

```
1. Browser requests GET /articles/my-post

2. Rufinus: RequestHandler::handle()
   a. Router resolves /articles/my-post -> site/articles/[slug].htx
      params: {slug: "my-post"}
   b. Injects <htx:slug>my-post</htx:slug> into DSL
   c. Detects data-requiring meta directives -> not a static page

3. Rufinus: EdgeHTX::getContent()
   a. DSLParser extracts meta: {type: "article", slug: "my-post"}
   b. DSLParser extracts template body from <htx>...</htx>
   c. CentralApiClient sends POST /api/content/get to Origen
      Headers: X-Site-Key, X-HTX-Version: 1
      Body: {"meta": {"type": "article", "slug": "my-post"}}

4. Origen: Kernel::handle()
   a. Router matches POST /api/content/get -> ContentController::get
   b. Middleware pipeline:
      - ResolveTenant: X-Site-Key -> site record
      - EnforceHtxVersion: validates version 1
   c. ContentController::get():
      - ContentService::query() builds QueryBuilder
      - QueryBuilder: SELECT * FROM content WHERE site_id=? AND type=? AND slug=?
      - RelationshipResolver resolves any relationship fields
      - MarkdownService converts body to sanitized HTML (body_html)
   d. Returns JSON: {"rows": [{id, type, slug, title, body, body_html, status, ...}]}

5. Rufinus: GetContentExecutor
   a. Single row (no <htx:each>) -> hydrates template with first row
   b. ExpressionEngine evaluates any {{ expressions }}
   c. Hydrator replaces __title__, __body__, __slug__ etc.
   d. Returns hydrated HTML string

6. Rufinus: RequestHandler
   a. LayoutResolver collects _layout.htx files:
      - site/articles/_layout.htx (innermost)
      - site/_layout.htx (root)
   b. Wraps content: inner layout replaces __content__ -> root layout replaces __content__
   c. If HX-Request header present: skips root <!DOCTYPE> layout (returns fragment only)

7. Browser receives HTML; htmx swaps it into the DOM
```

### Write Path (two-phase mutation)

```
Phase 1 -- Prepare:
  Rufinus -> POST /api/content/prepare
  Origen issues action token (5min JWT with jti UUID)
  Returns: {endpoint, payload (with htx-token), current values}
  Rufinus hydrates form template with values + hidden payload

Phase 2 -- Execute:
  Browser submits form -> Rufinus proxies to POST /api/content/save
  VerifyActionToken middleware:
    - Validates JWT signature and expiry
    - Checks jti not in used_tokens (replay guard)
    - Inserts jti into used_tokens
  ContentController::save():
    - ContentService::create() -> WriteThrough::createContent()
    - SQLite INSERT -> get ID -> write .md with frontmatter -> commit
  Returns success/redirect response
```

---

## 5. Directory Structure

```
cms/
|-- hcms                          # CLI entry point
|-- composer.json
|-- phpunit.xml
|
|-- origen/                       # Backend API server
|   |-- config/
|   |   |-- origen.php            # Config: db_path, content_path, schema_path, app_key
|   |-- public/
|   |   |-- index.php             # HTTP entry point (Bootstrap::boot)
|   |-- src/
|   |   |-- Bootstrap.php         # Wires container, registers routes, runs migrations
|   |   |-- Config.php            # Config loader (.env + config file)
|   |   |-- Container.php         # Minimal DI container with auto-wiring
|   |   |-- Cli/
|   |   |   |-- Application.php   # CLI dispatcher
|   |   |   |-- CommandInterface.php
|   |   |   |-- Commands/
|   |   |       |-- ServeCommand.php
|   |   |       |-- ServeSiteCommand.php
|   |   |       |-- ServeAllCommand.php
|   |   |       |-- IndexRebuildCommand.php
|   |   |       |-- UserCreateCommand.php
|   |   |       |-- SiteCreateCommand.php
|   |   |       |-- TokenCleanupCommand.php
|   |   |-- DTOs/
|   |   |   |-- PrepareRequestDTO.php
|   |   |   |-- PrepareResponseDTO.php
|   |   |   |-- ExecuteRequestDTO.php
|   |   |-- Enums/
|   |   |   |-- ContentStatus.php
|   |   |   |-- Role.php
|   |   |-- Exceptions/
|   |   |   |-- HttpException.php
|   |   |   |-- SlugConflictException.php
|   |   |   |-- ValidationException.php
|   |   |-- Http/
|   |   |   |-- Kernel.php
|   |   |   |-- Router.php
|   |   |   |-- MiddlewarePipeline.php
|   |   |   |-- Request.php
|   |   |   |-- Response.php
|   |   |   |-- Controllers/
|   |   |   |   |-- AuthController.php
|   |   |   |   |-- ContentController.php
|   |   |   |   |-- ContentTypeController.php
|   |   |   |-- Middleware/
|   |   |       |-- MiddlewareInterface.php
|   |   |       |-- ResolveTenant.php
|   |   |       |-- EnforceHtxVersion.php
|   |   |       |-- VerifyActionToken.php
|   |   |-- Services/
|   |   |   |-- ActionTokenService.php
|   |   |   |-- AuthTokenService.php
|   |   |   |-- ContentService.php
|   |   |   |-- MarkdownService.php
|   |   |   |-- RelationshipResolver.php
|   |   |   |-- ReplayGuardService.php
|   |   |   |-- SchemaService.php
|   |   |   |-- TemplateHydratorService.php
|   |   |   |-- WorkflowService.php
|   |   |-- Storage/
|   |   |   |-- Database/
|   |   |   |   |-- Connection.php
|   |   |   |   |-- Migrator.php
|   |   |   |   |-- QueryBuilder.php
|   |   |   |   |-- ContentRepository.php
|   |   |   |   |-- SchemaRepository.php
|   |   |   |   |-- SiteRepository.php
|   |   |   |   |-- UserRepository.php
|   |   |   |   |-- TokenRepository.php
|   |   |   |-- FlatFile/
|   |   |       |-- FrontmatterParser.php
|   |   |       |-- ContentFileManager.php
|   |   |       |-- SchemaFileManager.php
|   |   |       |-- SiteConfigManager.php
|   |   |-- Sync/
|   |       |-- WriteThrough.php
|   |-- tests/
|       |-- Unit/
|       |   |-- ContainerTest.php
|       |   |-- FrontmatterParserTest.php
|       |   |-- QueryBuilderTest.php
|       |   |-- RouterTest.php
|       |   |-- SchemaServiceTest.php
|       |   |-- WriteThroughTest.php
|       |-- Integration/
|           |-- AuthFlowTest.php
|           |-- ContentApiTest.php
|
|-- rufinus/                      # Edge runtime
|   |-- config/                   # Site configuration
|   |-- site/                     # File-based routes (pages)
|   |   |-- serve.php             # HTTP entry point
|   |   |-- _layout.htx           # Root layout (<!DOCTYPE html> shell)
|   |   |-- _error.htx            # Error page template
|   |   |-- index.htx             # Home page
|   |   |-- about.htx             # Static page example
|   |   |-- public/               # Static assets (CSS, JS, images)
|   |   |-- articles/
|   |   |   |-- _layout.htx       # Articles section layout
|   |   |   |-- index.htx         # Article listing
|   |   |   |-- [slug].htx        # Single article (dynamic segment)
|   |   |-- docs/
|   |       |-- _layout.htx       # Docs section layout
|   |       |-- index.htx         # Docs listing
|   |       |-- [slug].htx        # Single doc page
|   |-- src/
|       |-- EdgeHTX.php           # Facade: parser + executors + API client
|       |-- Parser/
|       |   |-- DSLParser.php     # Orchestrates meta, response, template extraction
|       |   |-- MetaExtractor.php
|       |   |-- ResponseExtractor.php
|       |   |-- TemplateExtractor.php
|       |-- Executors/
|       |   |-- GetContentExecutor.php
|       |   |-- SetContentExecutor.php
|       |   |-- DeleteContentExecutor.php
|       |-- Runtime/
|       |   |-- RequestHandler.php
|       |   |-- Router.php
|       |   |-- LayoutResolver.php
|       |   |-- AuthGuard.php
|       |   |-- ApiProxy.php
|       |   |-- Response.php
|       |-- Services/
|       |   |-- CentralApiClient.php
|       |   |-- Hydrator.php
|       |-- Expressions/
|           |-- ExpressionEngine.php
|           |-- Lexer.php
|           |-- Parser.php
|           |-- Evaluator.php
|           |-- FunctionRegistry.php
|           |-- Functions/
|           |   |-- StringFunctions.php
|           |   |-- ArrayFunctions.php
|           |   |-- DateFunctions.php
|           |   |-- NumberFunctions.php
|           |-- Nodes/
|           |   |-- Node.php
|           |   |-- FieldRef.php
|           |   |-- FunctionCall.php
|           |   |-- BinaryOp.php
|           |   |-- UnaryOp.php
|           |   |-- IfNode.php
|           |   |-- EachNode.php
|           |   |-- DotAccess.php
|           |   |-- StringLiteral.php
|           |   |-- NumberLiteral.php
|           |   |-- BooleanLiteral.php
|           |   |-- NullLiteral.php
|           |   |-- TemplateNode.php
|           |   |-- TextNode.php
|           |   |-- OutputNode.php
|           |   |-- RawOutputNode.php
|           |-- Exceptions/
|               |-- ExpressionParseException.php
|               |-- ExpressionLimitException.php
|
|-- content/                      # Flat-file content storage
|   |-- {site-slug}/
|       |-- _site.yaml            # Site configuration
|       |-- {type}/
|           |-- {slug}.md         # Content files (YAML frontmatter + Markdown body)
|
|-- schemas/                      # Field schema definitions
|   |-- {site-slug}/
|       |-- {type}.yaml           # Per-type field schema
|
|-- storage/                      # Runtime storage
|   |-- *.sqlite                  # SQLite database files
```

---

## 6. SQLite Schema

All tables are created by `Origen\Storage\Database\Migrator::run()`.

### sites

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `slug` | TEXT | NOT NULL, UNIQUE |
| `name` | TEXT | NOT NULL |
| `domain` | TEXT | NOT NULL, UNIQUE |
| `api_key` | TEXT | NOT NULL, UNIQUE |
| `settings` | TEXT | DEFAULT '{}' |
| `active` | INTEGER | DEFAULT 1 |
| `created_at` | TEXT | DEFAULT datetime('now') |
| `updated_at` | TEXT | DEFAULT datetime('now') |

### content

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `site_id` | INTEGER | NOT NULL, FK -> sites(id) ON DELETE CASCADE |
| `type` | TEXT | NOT NULL |
| `slug` | TEXT | NOT NULL |
| `title` | TEXT | NOT NULL |
| `body` | TEXT | DEFAULT '' |
| `status` | TEXT | DEFAULT 'draft' |
| `file_path` | TEXT | |
| `created_at` | TEXT | DEFAULT datetime('now') |
| `updated_at` | TEXT | DEFAULT datetime('now') |

Unique constraint: `(site_id, slug)`

### content_field_values

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `site_id` | INTEGER | NOT NULL, FK -> sites(id) ON DELETE CASCADE |
| `content_id` | INTEGER | NOT NULL, FK -> content(id) ON DELETE CASCADE |
| `field_name` | TEXT | NOT NULL |
| `field_value` | TEXT | |

Unique constraint: `(content_id, field_name)`

### field_schemas

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `site_id` | INTEGER | NOT NULL, FK -> sites(id) ON DELETE CASCADE |
| `content_type` | TEXT | NOT NULL |
| `field_name` | TEXT | NOT NULL |
| `field_type` | TEXT | NOT NULL |
| `constraints` | TEXT | DEFAULT '{}' |
| `ui_hints` | TEXT | DEFAULT '{}' |
| `sort_order` | INTEGER | DEFAULT 0 |

Unique constraint: `(site_id, content_type, field_name)`

### users

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `name` | TEXT | NOT NULL |
| `email` | TEXT | NOT NULL, UNIQUE |
| `password_hash` | TEXT | NOT NULL |
| `created_at` | TEXT | DEFAULT datetime('now') |
| `updated_at` | TEXT | DEFAULT datetime('now') |

### memberships

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `site_id` | INTEGER | NOT NULL, FK -> sites(id) ON DELETE CASCADE |
| `user_id` | INTEGER | NOT NULL, FK -> users(id) ON DELETE CASCADE |
| `role` | TEXT | DEFAULT 'viewer' |

Unique constraint: `(site_id, user_id)`

### used_tokens

| Column | Type | Constraints |
|--------|------|------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `jti` | TEXT | NOT NULL, UNIQUE |
| `site_id` | INTEGER | NOT NULL, FK -> sites(id) ON DELETE CASCADE |
| `expires_at` | TEXT | NOT NULL |

---

## 7. Flat-File Layout

### Content files

Path pattern: `content/{site-slug}/{type}/{slug}.md`

Example file at `content/poc/article/hello-world.md`:

```markdown
---
id: 1
title: Hello World
slug: hello-world
status: published
created_at: '2025-06-01 12:00:00'
updated_at: '2025-06-01 12:00:00'
author: 3
tags:
  - 5
  - 8
---
This is the **body** of the article written in Markdown.

It supports all standard CommonMark syntax plus tables.
```

The YAML frontmatter contains core fields (`id`, `title`, `slug`, `status`, `created_at`, `updated_at`) plus any custom field values defined by the content type's schema. The body below the closing `---` is raw Markdown.

### Schema files

Path pattern: `schemas/{site-slug}/{type}.yaml`

Example file at `schemas/poc/article.yaml`:

```yaml
fields:
  - name: author
    type: relationship
    constraints:
      target_type: author
      cardinality: one
  - name: tags
    type: relationship
    constraints:
      target_type: tag
      cardinality: many
  - name: excerpt
    type: textarea
    constraints: {}
```

### Site configuration

Path pattern: `content/{site-slug}/_site.yaml`

Example file at `content/starter/_site.yaml`:

```yaml
name: Starter Site
domain: localhost
api_key: htx-starter-key-001
active: true
settings: {}
```

On boot, `SiteConfigManager` scans all `_site.yaml` files and upserts them into the `sites` SQLite table.

---

## 8. Security Model

### Auth Tokens

- **Algorithm**: HS256 (HMAC-SHA256)
- **Expiry**: 24 hours (`exp: time() + 86400`)
- **Claims**: `sub` (user:{id}), `user_id`, `email`, `name`, `tenant_id` (site ID), `role`, `type: "auth"`
- **Signing key**: Shared `app_key` from configuration
- **Transport**: `Authorization: Bearer {token}` header; Rufinus stores in `hcms_token` cookie

### Action Tokens

- **Algorithm**: HS256 (HMAC-SHA256)
- **Expiry**: 5 minutes (`exp: time() + 300`)
- **Claims**: `sub` (site:{id}), `tenant_id`, `htx-context` (save/update/delete), `htx-recordId`, `jti` (UUID v4)
- **Signing key**: Same shared `app_key`
- **Purpose**: CSRF protection for mutations; binds a form submission to a specific action and record

### Replay Guard

The `used_tokens` table stores the `jti` of every consumed action token. Before processing a mutation, `VerifyActionToken` middleware checks:

1. JWT signature and expiry are valid
2. `tenant_id` matches the resolved site
3. `htx-context` matches the expected action
4. `htx-recordId` matches (if applicable)
5. `jti` has not been used before (not in `used_tokens`)

If all checks pass, the `jti` is inserted into `used_tokens` with its `expires_at` timestamp. The `TokenCleanupCommand` periodically purges expired entries.

### Tenant Isolation

Every API request passes through `ResolveTenant` middleware, which:

1. Requires the `X-Site-Key` header (returns 401 if missing)
2. Looks up the site by API key (returns 403 if invalid)
3. Attaches the site record to the request as `current_site`

All repository queries are scoped to `site_id`, preventing cross-tenant data access.

### HTML Sanitization

`MarkdownService` converts Markdown to HTML with an allowlist-based sanitizer:

- **Allowed tags**: `h1`-`h6`, `p`, `br`, `hr`, `strong`, `em`, `b`, `i`, `u`, `s`, `del`, `ins`, `mark`, `a`, `code`, `pre`, `blockquote`, `ul`, `ol`, `li`, `table`, `thead`, `tbody`, `tr`, `th`, `td`, `img`, `div`, `span`, `sup`, `sub`, `small`
- **Allowed attributes**: Per-tag allowlist (e.g., `a[href, title, rel, target]`, `img[src, alt, title, width, height]`)
- **Blocked protocols**: `javascript:`, `data:`, `vbscript:` URIs are stripped from `href` and `src` attributes
- **CommonMark config**: `html_input: escape`, `allow_unsafe_links: false`

The Rufinus `Hydrator` HTML-escapes all placeholder values by default (`htmlspecialchars` with `ENT_QUOTES`). Only fields pre-sanitized by Origen (listed in `TRUSTED_HTML_FIELDS`: `body_html`, `status_options`, `type_options`, `custom_fields_html`) bypass escaping.
