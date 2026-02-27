# Hypermedia CMS MCP Server

Model Context Protocol (MCP) server for Hypermedia CMS. Allows AI assistants like Claude to read, write, and manage HTX templates and content.

## What is MCP?

MCP is [Anthropic's open protocol](https://modelcontextprotocol.io) for connecting AI models to external data sources and tools. This server exposes Hypermedia CMS resources and tools through MCP.

## Quick Start

### 1. Install Dependencies

```bash
cd mcp-server
composer install
```

### 2. Configure Claude Desktop

Add to your `claude_desktop_config.json`:

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

### 3. Start Using

Open Claude Desktop and you can now:
- "List all HTX templates in the site"
- "Read the home page template"
- "Create a new contact page with a form"
- "Update the blog template to show excerpts"

## Available Resources

### htx://

HTX template files from the site directory.

```
htx://index.htx
htx://blog/index.htx
htx://blog/[slug].htx
```

## Available Tools

### read_file

Read the contents of any file in the site.

```json
{
  "name": "read_file",
  "arguments": {
    "path": "blog/index.htx"
  }
}
```

### write_file

Create or update files. Use for building new pages.

```json
{
  "name": "write_file",
  "arguments": {
    "path": "contact.htx",
    "content": "<htx>...</htx>"
  }
}
```

### list_files

Explore the site structure.

```json
{
  "name": "list_files",
  "arguments": {
    "directory": "blog",
    "pattern": "*.htx",
    "recursive": true
  }
}
```

## Security

The MCP server includes several security measures:

- **Path sandboxing**: All file operations are restricted to the site root
- **Path traversal prevention**: `../` and similar patterns are blocked
- **Dangerous path blocking**: Vendor, node_modules, .env files are protected
- **Audit logging**: All write operations can be logged

## Configuration

Copy `config.example.php` to `config.php` and customize:

```php
return [
    'origen_url' => 'http://localhost:8082',
    'site_key' => 'your-site-key',
    'audit_log' => __DIR__ . '/../storage/logs/mcp-audit.log',
    'allowed_paths' => ['*.htx', '**/*.htx'],
    'denied_paths' => ['_*', '.env*', 'vendor/**'],
];
```

## Development

### Running Tests

```bash
composer test
```

### Adding New Tools

1. Create a class implementing `ToolInterface`
2. Register in `bin/mcp-serve`
3. Add tests

## Roadmap

- [ ] Content query tool (via Origen API)
- [ ] Content create/update/delete tools
- [ ] Preview page tool
- [ ] Schema introspection
- [ ] HTTP SSE transport for remote connections
- [ ] Subscription support for file watching

## License

MIT - See LICENSE file.
