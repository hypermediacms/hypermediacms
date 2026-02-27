# Hypermedia CMS - MCP Integration

Model Context Protocol (MCP) server for AI-assisted content management.

## Overview

This MCP server enables AI assistants to:

- **Create HTX templates** for displaying content at routes
- **Create schemas** defining custom fields for content types
- **List routes and content types** in the site
- **Preview content** through HTX templates without persisting

## Installation

The MCP server is included with Hypermedia CMS. Ensure dependencies are installed:

```bash
composer install
```

## Running the Server

```bash
php mcp/server.php
```

The server communicates via stdin/stdout using JSON-RPC (MCP protocol).

## Available Tools

### `create_htx`

Create an HTX template file for displaying content at a route.

**Parameters:**
- `route` (required): URL route (e.g., "/blog", "/products/:slug")
- `content_type` (required): Content type to display (e.g., "article", "product")
- `display_mode` (required): "list", "single", or "form"
- `template_style`: "card", "table", "minimal", or "custom"
- `fields_to_display`: Array of field names to include
- `include_admin`: Also create admin HTX files (default: false)

**Example:**
```json
{
  "name": "create_htx",
  "arguments": {
    "route": "/blog",
    "content_type": "article",
    "display_mode": "list",
    "template_style": "card"
  }
}
```

### `create_schema`

Create a YAML schema defining custom fields for a content type.

**Parameters:**
- `content_type` (required): Name of the content type
- `fields` (required): Array of field definitions
- `site`: Site namespace (default: "starter")

**Field definition:**
```json
{
  "name": "category",
  "type": "select",
  "options": ["tech", "news", "tutorial"],
  "required": true
}
```

**Supported field types:** text, textarea, number, select, checkbox, date, datetime, email, url, image, file

### `list_routes`

List all existing routes in the site.

**Parameters:**
- `include_admin`: Include admin routes (default: true)
- `include_meta`: Include HTX metadata for each route (default: false)

### `list_content_types`

List all content types (from schemas and HTX files).

**Parameters:**
- `include_fields`: Include field definitions (default: false)

### `preview_content`

Preview content by rendering through an HTX template.

**Parameters:**
- `route` (required): Route to preview
- `content` (required): Content data object
- `route_params`: Dynamic route parameters

**Example:**
```json
{
  "name": "preview_content",
  "arguments": {
    "route": "/blog/my-post",
    "content": {
      "title": "My First Post",
      "body": "Hello world!",
      "status": "draft"
    },
    "route_params": {
      "slug": "my-post"
    }
  }
}
```

## Workflow Example

1. **Create a schema** for your content type:
   ```
   create_schema(content_type="event", fields=[
     {name: "date", type: "date", required: true},
     {name: "location", type: "text"},
     {name: "capacity", type: "number"}
   ])
   ```

2. **Create an HTX template** to display events:
   ```
   create_htx(route="/events", content_type="event", display_mode="list", include_admin=true)
   ```

3. **Preview content** before publishing:
   ```
   preview_content(route="/events/my-event", content={
     title: "Community Meetup",
     date: "2024-03-15",
     location: "Downtown Hall"
   })
   ```

## Architecture

```
mcp/
├── server.php           # Entry point
├── src/
│   ├── MCPServer.php    # JSON-RPC server implementation
│   ├── Tools/           # MCP tool implementations
│   │   ├── CreateHTXTool.php
│   │   ├── CreateSchemaTool.php
│   │   ├── ListRoutesTool.php
│   │   ├── ListContentTypesTool.php
│   │   └── PreviewContentTool.php
│   └── Services/
│       ├── HTXGenerator.php    # Generates HTX template content
│       └── RouteResolver.php   # Maps routes to file paths
```

## Integration with Claude Desktop / AI Assistants

Add to your MCP configuration:

```json
{
  "mcpServers": {
    "hypermedia-cms": {
      "command": "php",
      "args": ["/path/to/hypermediacms/mcp/server.php"]
    }
  }
}
```

## Preview in Admin Interface

The preview feature integrates with Rufinus admin. When editing content:

1. Form fields update in real-time
2. Preview pane shows rendered output using `PreviewService`
3. Uses HTMX for seamless updates without page reload

Preview is available when:
- An HTX template exists for the content type
- Content is being created or edited

Preview is unavailable when:
- No HTX template displays this content type yet
