# Getting Started with Hypermedia CMS

A practical guide to installing, configuring, and running Hypermedia CMS locally.

---

## Prerequisites

- **PHP 8.2+** with the following extensions:
  - `ext-pdo_sqlite`
  - `ext-mbstring`
- **SQLite3**
- **Composer** (PHP dependency manager)

---

## Installation

```bash
git clone <repo>
cd hypermedia-cms/cms
composer install
```

---

## Configuration

All configuration lives in the `.env` file at `cms/.env`. Copy the example file if one exists, or create it directly.

| Variable        | Description                                      | Default               |
|-----------------|--------------------------------------------------|-----------------------|
| `APP_KEY`       | Secret key used for JWT signing. **Change this in production.** | _(set during setup)_ |
| `CONTENT_PATH`  | Path to the flat-file content directory           | `./content`           |
| `SCHEMA_PATH`   | Path to schema files                              | `./schemas`           |
| `DB_PATH`       | Path to the SQLite database                       | `./storage/index/origen.db` |
| `SERVER_HOST`   | Origen server host                                | `127.0.0.1`           |
| `SERVER_PORT`   | Origen server port                                | `8080`                |
| `DEBUG`         | Enable debug mode                                 | `true`                |

---

## Creating a Site

There are two ways to create a site.

### Option A: CLI (recommended)

```bash
php hcms site:create
```

This walks you through interactive prompts for the site name and domain. An `api_key` is generated automatically.

### Option B: Manual

Create a YAML file at `content/{site-slug}/_site.yaml` with the following fields:

```yaml
name: My Site
domain: mysite.local
api_key: my-secret-api-key
active: true
settings:
  # site-specific settings here
```

---

## Creating a User

```bash
php hcms user:create
```

The command prompts you interactively for:

- Name
- Email
- Password
- Site assignment
- Role

---

## Starting the Servers

Hypermedia CMS has two servers: **Origen** (the API server) and **Rufinus** (the site renderer).

| Command               | What it starts                          |
|-----------------------|-----------------------------------------|
| `php hcms serve`      | Origen only (API server on `:8080`)     |
| `php hcms serve:site` | Rufinus only (site server on `:8081`)   |
| `php hcms serve:all`  | Both servers simultaneously             |

> **Note:** Rufinus must be able to reach Origen. With the default configuration, Rufinus runs on `:8081` and calls Origen on `:8080`.

---

## Your First Content

Once both servers are running, follow these steps to create and view your first piece of content.

### 1. Prepare a save (get an action token)

```bash
curl -X POST http://localhost:8080/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"meta":{"action":"prepare-save","type":"article"},"responseTemplates":[]}'
```

### 2. Extract the token

The response payload contains an `htx-token` value. Copy it for the next step.

### 3. Save the content

```bash
curl -X POST http://localhost:8080/api/content/save \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: htx-starter-key-001" \
  -H "X-HTX-Version: 1" \
  -d '{"htx-token":"<token>","htx-context":"save","title":"Hello World","body":"My first post!","status":"published","type":"article"}'
```

Replace `<token>` with the actual token from step 2.

### 4. Verify the flat file

Check that the content was persisted to disk:

```
content/poc/article/hello-world.md
```

### 5. View the rendered article

Open your browser and visit:

```
http://localhost:8081
```

You should see the article rendered on the site.

---

## Demo Site Structure

The Rufinus demo site lives at `rufinus/site/` and provides a working example of how a front-end connects to the Origen API.

```
rufinus/site/
├── serve.php          Entry point (configures Origen URL and site API key)
├── _layout.htx        Root HTML layout with nav, main, and footer
├── index.htx          Homepage showing recent articles
├── about.htx          Static about page
├── articles/          Articles section with list and detail views
├── docs/              Documentation section with list and detail views
└── _error.htx         Error page template
```

- **serve.php** — Bootstraps the site and configures the connection to Origen (URL and API key).
- **_layout.htx** — The root layout template. All pages render inside this shell (navigation, main content area, footer).
- **index.htx** — The homepage. Fetches and displays recent articles from the API.
- **about.htx** — A static page with no API calls.
- **articles/** — Contains templates for listing articles and rendering individual article detail pages.
- **docs/** — Contains templates for listing documentation pages and rendering individual doc pages.
- **_error.htx** — Fallback template for error states (404, 500, etc.).

---

## Index Rebuild

The SQLite database at `storage/index/origen.db` is an index over the flat-file content. If you ever need to rebuild it (for example, after deleting the database or manually editing content files), run:

```bash
rm storage/index/origen.db
php hcms index:rebuild
```

This scans the content directory and reconstructs the full index from the flat files on disk.
