# Hypermedia CMS — Agent Onboarding

Zero-friction setup for AI agents. Every step is non-interactive and has a verification command.

## Prerequisites

| Requirement | Verify | Expected |
|-------------|--------|----------|
| PHP 8.2+ | `php -v` | `PHP 8.2.x` or higher |
| Composer | `composer --version` | `Composer version 2.x` |
| ext-pdo_sqlite | `php -m \| grep pdo_sqlite` | `pdo_sqlite` |

## Setup

```bash
./bin/setup
```

This installs dependencies, creates `.env` with a random `APP_KEY`, ensures storage directories, and rebuilds the content index. Idempotent — safe to re-run.

**Verify:**
```bash
cat .env | grep APP_KEY
# APP_KEY=<64-char hex string>
```

## Start Servers

```bash
php hcms serve:all
```

| Service | URL | Purpose |
|---------|-----|---------|
| Origen API | `http://localhost:8080` | Backend API |
| Rufinus | `http://localhost:8081` | Frontend runtime |

**Verify:**
```bash
curl -s http://localhost:8080/api/health
# {"status":"ok"}
```

To start individually:
```bash
php hcms serve          # Origen only (:8080)
php hcms serve:site     # Rufinus only (:8081)
```

## Create a User (Non-Interactive)

```bash
php hcms user:create \
  --name=Admin \
  --email=admin@test.com \
  --password=secret \
  --site=starter \
  --role=super_admin
```

`--site` accepts a slug (`starter`) or numeric ID. `--role` defaults to `editor` when omitted.

**Verify:**
```bash
curl -s -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"email":"admin@test.com","password":"secret"}'
# {"token":"<jwt>","user":{...}}
```

## Create a Site (Non-Interactive)

```bash
php hcms site:create --name="My Site" --slug=my-site --domain=localhost
```

**Verify:**
```bash
ls content/my-site/_site.yaml
```

## MCP Server Configuration

```bash
cp .mcp.json.example .mcp.json
```

The MCP server connects via stdio and talks to Origen on `:8080` using the starter site key.

**Verify:** The agent's MCP client should successfully initialize the `hypermediacms` server.

## CLI Reference

| Command | Purpose |
|---------|---------|
| `php hcms serve` | Start Origen API (:8080) |
| `php hcms serve:site` | Start Rufinus site (:8081) |
| `php hcms serve:all` | Start both servers |
| `php hcms site:create` | Create a site (supports `--name`, `--slug`, `--domain`) |
| `php hcms user:create` | Create a user (supports `--name`, `--email`, `--password`, `--site`, `--role`) |
| `php hcms index:rebuild` | Rebuild SQLite index from flat files |
| `php hcms token:cleanup` | Purge expired action tokens |

## API Headers

All API requests (except `/api/health`) require:

| Header | Value |
|--------|-------|
| `X-Site-Key` | Site API key (e.g., `htx-starter-key-001`) |
| `X-HTX-Version` | `1` |
| `Authorization` | `Bearer <jwt>` (for authenticated endpoints) |

## Key Files

| File | Purpose |
|------|---------|
| `hcms` | CLI entry point |
| `.env` | Environment config (APP_KEY, DB_PATH, etc.) |
| `content/starter/_site.yaml` | Starter site config (key: `htx-starter-key-001`) |
| `origen/src/Bootstrap.php` | App bootstrap, routes, migrations |
| `origen/config/origen.php` | Default configuration |
| `rufinus/site/serve.php` | Rufinus runtime entry point |
| `mcp-server/bin/mcp-serve` | MCP server entry point |
| `schemas/` | Content type schema definitions |

## Ports

| Port | Service | Configurable via |
|------|---------|-----------------|
| 8080 | Origen API | `SERVER_PORT` in `.env` |
| 8081 | Rufinus site | `php hcms serve:site --port=XXXX` |

## Troubleshooting

- **Health check fails** — Origen not running. Start with `php hcms serve`.
- **"Not found" on routes** — Check `X-Site-Key` matches `_site.yaml` `api_key`.
- **"Invalid token"** — `APP_KEY` changed; existing JWTs are invalid.
- **Database missing** — Auto-created on boot, or run `php hcms index:rebuild`.
- **Content out of sync** — Run `php hcms index:rebuild` to rebuild from flat files.
