# Hypermedia CMS — Open Source Documentation

**An open-source, file-based, htmx-powered content management system.**

Hypermedia CMS consists of two components:

- **Origen** — Framework-free PHP backend with flat-file Markdown storage and SQLite indexing
- **Rufinus** — Edge runtime that parses the HTX DSL, communicates with Origen's API, and renders HTML

## Documentation Index

| Document | Description |
|----------|-------------|
| [Architecture](architecture.md) | System overview, design decisions, and component diagrams |
| [Getting Started](getting-started.md) | Installation, configuration, and running the servers |
| [HTX DSL Reference](htx-dsl.md) | Complete reference for the HTX template language |
| [API Reference](api-reference.md) | All REST API endpoints with request/response examples |
| [CLI Reference](cli-reference.md) | Command-line tools for server management |
| [Content Model](content-model.md) | Content types, schemas, relationships, and flat-file format |
| [Developer Guide](developer-guide.md) | Extending the system, adding features, and contributing |

## Quick Start

```bash
cd cms

# Install dependencies
composer install

# Start both servers
php hcms serve:all

# Origen API:   http://localhost:8080
# Rufinus Site: http://localhost:8081
```

## Requirements

- PHP 8.2+
- SQLite3 (ext-pdo_sqlite)
- Composer
