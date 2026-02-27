# Hypermedia CMS MCP Server

Model Context Protocol (MCP) server for Hypermedia CMS. Enables AI assistants like Claude to read, write, and manage HTX templates and CMS content through the Origen API.

## What is MCP?

MCP is [Anthropic's open protocol](https://modelcontextprotocol.io) for connecting AI models to external data sources and tools. This server exposes Hypermedia CMS resources and tools through the MCP specification (`2024-11-05`).

## Quick Start

### 1. Install Dependencies

```bash
cd mcp-server
composer install
```

### 2. Configure Your MCP Client

Add to your Claude Desktop `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "hypermediacms": {
      "command": "php",
      "args": [
        "/path/to/hypermediacms/mcp-server/bin/mcp-serve",
        "/path/to/hypermediacms/rufinus/site"
      ],
      "env": {
        "ORIGEN_URL": "http://localhost:8082",
        "SITE_KEY": "your-site-key"
      }
    }
  }
}
```

### 3. Start Origen

The content tools require a running Origen backend:

```bash
php hcms serve        # Starts Origen API on :8080
```

### 4. Start Using

Open Claude Desktop and you can now:
- "List all HTX templates in the site"
- "Read the home page template"
- "Create a new blog article"
- "Query all published articles"
- "Show me the available content type schemas"

## Resources

### `htx://` — HTX Templates

Provides access to `.htx` template files from the site directory.

```
htx://index.htx
htx://blog/index.htx
htx://blog/[slug].htx
htx://contact.htx
```

Returns `text/x-htx` content. Includes display names (e.g. "Home Page", "Blog Index", "Dynamic Route").

### `content://` — CMS Content

Provides access to published content from the Origen backend.

```
content://article/my-blog-post
content://form_definition/contact-form
content://todo/urgent-task
```

Returns `application/json` content with title, slug, status, excerpt, and body.

## Tools

### File Tools

#### `read_file`

Read the contents of any file in the site directory.

```json
{
  "path": "blog/index.htx"
}
```

#### `write_file`

Create or update files in the site directory. Automatically creates parent directories.

```json
{
  "path": "contact.htx",
  "content": "<htx>...</htx>",
  "createDirectories": true
}
```

Security: blocks writes to protected paths (`vendor/`, `node_modules/`, `.git/`, `.env`). All writes are audit-logged when configured.

#### `list_files`

Explore the site directory structure with optional glob filtering.

```json
{
  "directory": "blog",
  "pattern": "*.htx",
  "recursive": true
}
```

### Content Tools

These tools communicate with the Origen API using a two-phase mutation protocol (prepare token, then execute).

#### `query_content`

Query CMS content with filters and sorting.

```json
{
  "type": "article",
  "status": "published",
  "order": "newest",
  "limit": 20
}
```

Supports filtering by `status` (`draft`, `published`, `archived`, `review`), sorting by `newest`, `oldest`, `recent`, or `alpha`, and lookup by `slug` or `id`.

#### `create_content`

Create new content entries.

```json
{
  "type": "article",
  "title": "My Blog Post",
  "slug": "my-blog-post",
  "status": "draft",
  "body": "Markdown content here",
  "excerpt": "Short summary",
  "fields": {}
}
```

Slug is auto-generated from title if not provided.

#### `update_content`

Update existing content. Requires either `id` or `slug` to identify the target. Only provided fields are updated.

```json
{
  "type": "article",
  "slug": "my-blog-post",
  "title": "Updated Title",
  "status": "published"
}
```

#### `delete_content`

Delete content. Requires explicit confirmation.

```json
{
  "type": "article",
  "slug": "my-blog-post",
  "confirm": true
}
```

#### `get_schema`

Introspect content type schemas. Lists available types or returns the schema for a specific type.

```json
{
  "type": "article"
}
```

```json
{
  "list_types": true
}
```

Built-in schemas: `article`, `form_definition`, `form_submission`, `todo`, `documentation`.

## Security

- **Path sandboxing** — All file operations restricted to the site root via `realpath()` validation
- **Path traversal prevention** — `../` and symlink escapes are blocked
- **Dangerous path blocking** — `vendor/`, `node_modules/`, `.git/`, `.env`, `composer.*`, `package.*` are protected from writes
- **Audit logging** — All write operations logged with timestamp, action, path, and byte size
- **Two-phase mutations** — Content changes require a prepare token before execution (CSRF protection)
- **API authentication** — Origen requests authenticated via `X-Site-Key` header
- **Delete confirmation** — Deletions require explicit `confirm: true`

## Configuration

Copy `config.example.php` to `config.php` and customize:

```php
return [
    'origen_url' => 'http://localhost:8082',
    'site_key' => 'your-site-key',
    'audit_log' => __DIR__ . '/../storage/logs/mcp-audit.log',
    'allowed_paths' => ['*.htx', '**/*.htx'],
    'denied_paths' => ['_*', '.env*', 'vendor/**'],
    'schema_path' => '/path/to/schemas',
];
```

Configuration can also be provided via environment variables (`ORIGEN_URL`, `SITE_KEY`) or passed as a config file argument:

```bash
./bin/mcp-serve /path/to/site /path/to/config.php
```

## Architecture

```
mcp-server/
├── bin/mcp-serve                    # Entry point
├── src/
│   ├── Server.php                   # JSON-RPC request handler
│   ├── Contracts/                   # Interfaces (Resource, Tool, Transport, Prompt)
│   ├── Resources/
│   │   ├── HtxResource.php          # htx:// template resources
│   │   └── ContentResource.php      # content:// CMS resources
│   ├── Tools/
│   │   ├── ReadFileTool.php         # Read site files
│   │   ├── WriteFileTool.php        # Create/update site files
│   │   ├── ListFilesTool.php        # Browse site structure
│   │   ├── QueryContentTool.php     # Query CMS content
│   │   ├── CreateContentTool.php    # Create content entries
│   │   ├── UpdateContentTool.php    # Update content entries
│   │   ├── DeleteContentTool.php    # Delete content entries
│   │   └── GetSchemaTool.php        # Introspect content types
│   ├── Services/
│   │   └── OrigenClient.php         # HTTP client for Origen API
│   └── Transport/
│       └── StdioTransport.php       # STDIO JSON-RPC transport
├── config.example.php               # Configuration template
└── composer.json                    # Dependencies (PHP 8.2+, zero runtime deps)
```

## Development

### Adding a New Tool

1. Create a class implementing `ToolInterface` in `src/Tools/`
2. Register it in `bin/mcp-serve`
3. Add tests in `tests/`

### Running Tests

```bash
composer test
```

## License

MIT — See LICENSE file.
