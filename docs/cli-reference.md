# CLI Reference

Hypermedia CMS ships a single entry point for all command-line operations:

```
php hcms <command> [options]
```

---

## serve

Start the Origen API development server.

```
php hcms serve [host] [port]
```

| Argument | Default       | Description                  |
|----------|---------------|------------------------------|
| host     | 127.0.0.1     | Address the server binds to  |
| port     | 8080          | Port the server listens on   |

The server serves from the `origen/public/` directory using PHP's built-in web server. Intended for local development only.

**Example:**

```bash
php hcms serve                  # http://127.0.0.1:8080
php hcms serve 0.0.0.0 9000    # http://0.0.0.0:9000
```

---

## serve:site

Start the Rufinus site development server.

```
php hcms serve:site [host] [port]
```

| Argument | Default       | Description                  |
|----------|---------------|------------------------------|
| host     | 127.0.0.1     | Address the server binds to  |
| port     | 8081          | Port the server listens on   |

The server serves from `rufinus/site/` using `serve.php` as the router script. This is the front-end site layer that consumes the Origen API.

**Example:**

```bash
php hcms serve:site                  # http://127.0.0.1:8081
php hcms serve:site 0.0.0.0 3000    # http://0.0.0.0:3000
```

---

## serve:all

Start both the Origen API server and the Rufinus site server simultaneously.

```
php hcms serve:all
```

This command starts two servers:

- **Origen API** on `:8080` — launched as a background process
- **Rufinus site** on `:8081` — launched in the foreground

Press `Ctrl+C` to stop both servers. The background Origen process is terminated automatically when the foreground Rufinus process exits.

---

## index:rebuild

Rebuild the SQLite index from flat files on disk.

```
php hcms index:rebuild
```

The rebuild process scans three layers of flat files:

1. **Sites** — reads every `content/{site-slug}/_site.yaml` to index site records.
2. **Schemas** — reads every `schemas/{site-slug}/*.yaml` to index field schema definitions.
3. **Content** — reads every `content/{site-slug}/{type}/*.md` to index content entries.

IDs stored in each file's YAML frontmatter are preserved during the rebuild (the command uses `INSERT OR REPLACE` so existing IDs are never reassigned).

**When to use:**

- After deleting or replacing the SQLite database file.
- After restoring content from a backup or another environment.
- After making manual edits to `.md` or `.yaml` flat files outside of the API.

---

## user:create

Create a new user with site membership.

```
php hcms user:create
```

This is an interactive command. It prompts for:

| Prompt   | Description                                          |
|----------|------------------------------------------------------|
| Name     | Display name for the user                            |
| Email    | Email address (used for login)                       |
| Password | Account password (hashed with bcrypt before storage) |
| Site     | Which site the user belongs to                       |
| Role     | Permission level for the user                        |

**Available roles:**

| Role          | Description                              |
|---------------|------------------------------------------|
| super_admin   | Full access across all sites             |
| admin         | Full access within the assigned site     |
| editor        | Can edit and publish content             |
| author        | Can create and edit own content          |
| viewer        | Read-only access                         |

---

## site:create

Create a new site.

```
php hcms site:create
```

This is an interactive command. It prompts for:

| Prompt | Description                                      |
|--------|--------------------------------------------------|
| Name   | Human-readable site name                         |
| Slug   | URL-safe identifier (used in file paths)         |
| Domain | Primary domain for the site                      |

On completion the command:

1. Generates a random API key for the site.
2. Creates the file `content/{slug}/_site.yaml` with the site configuration.
3. Inserts the site record into SQLite.

---

## token:cleanup

Purge expired action tokens from the database.

```
php hcms token:cleanup
```

Deletes all rows from `used_tokens` where `expires_at` is in the past. This keeps the token table small and prevents unbounded growth.

**Recommended:** run this on a cron schedule (daily or hourly) in any long-lived environment.

```cron
# Example: run every hour
0 * * * * cd /path/to/cms && php hcms token:cleanup
```
