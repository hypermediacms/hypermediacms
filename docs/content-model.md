# Content Model

Hypermedia CMS stores content as flat files on disk and maintains a SQLite index for fast queries. This document describes every layer of the content model and how they interact.

---

## Content Entries

Each piece of content is a Markdown file stored at:

```
content/{site-slug}/{type}/{slug}.md
```

A content file consists of YAML frontmatter followed by a Markdown body:

```yaml
---
id: 42
title: My First Post
slug: my-first-post
status: published
author: 17
tags: [3, 8, 12]
created_at: "2024-01-15T10:30:00Z"
updated_at: "2024-01-16T14:22:00Z"
---
Body content in **Markdown**.
```

### Key behaviors

- **ID** — assigned by SQLite auto-increment on creation, then written back into the frontmatter so the flat file carries its own identity.
- **Slug** — auto-generated from the title. Duplicates are deduplicated with a numeric suffix: `my-post`, `my-post-2`, `my-post-3`.
- **Custom fields** — stored as additional key-value pairs in the frontmatter (see Schemas below).
- **Body** — written and stored as Markdown. On read the API returns a `body_html` field containing the rendered HTML.

---

## Content Types

Content types are implicit. A type exists when either of the following is true:

- At least one content entry of that type exists on disk.
- A schema file is defined for that type.

There is no explicit type registration step. Creating a file at `content/my-site/recipe/pasta.md` is enough to establish the `recipe` type for `my-site`.

---

## Schemas

Schema files live at:

```
schemas/{site-slug}/{type}.yaml
```

A schema defines the custom fields and validation rules for a content type:

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
  - name: summary
    type: text
    constraints: {}
```

### Supported field types

| Type         | Description                                  |
|--------------|----------------------------------------------|
| text         | Short string value                           |
| textarea     | Long-form string value                       |
| number       | Numeric value                                |
| relationship | Reference to one or more entries of a type   |
| select       | Value from a predefined set of options       |
| boolean      | True or false                                |

Schemas are optional. Content can exist without a schema, but defining one enables field validation and relationship resolution.

---

## Relationships

Content entries can reference other content entries through relationship fields.

### Cardinality

| Mode   | Frontmatter storage          | Example              |
|--------|------------------------------|----------------------|
| `one`  | Single integer ID            | `author: 17`         |
| `many` | Array of integer IDs         | `tags: [3, 8, 12]`  |

### Resolution

When a content entry is fetched via the API, relationship fields are resolved automatically. The referenced content objects are nested inline in the response. Resolution is batched: one query is issued per target type regardless of how many entries reference it, keeping read performance efficient.

---

## Status Workflow

Every content entry has a `status` field. There are four valid statuses:

| Status    | Description                                |
|-----------|--------------------------------------------|
| draft     | Work in progress, not publicly visible     |
| review    | Pending editorial review                   |
| published | Live and publicly visible                  |
| archived  | Removed from public view, retained on disk |

The default status for new content is `draft`.

In the current version (V1) all transitions between statuses are allowed without restriction. Future versions will support configurable transition rules to enforce editorial workflows.

---

## Sites

Hypermedia CMS is multi-tenant. Each site is defined by a `_site.yaml` file at the root of its content directory:

```
content/{site-slug}/_site.yaml
```

```yaml
name: Marketing Site
domain: marketing.example.com
api_key: htx-marketing-key-001
active: true
settings: {}
```

### Key behaviors

- **Namespace isolation** — each site has its own content directory tree. Content from one site is never mixed with another.
- **Auto-discovery** — on boot the system scans `content/*/_site.yaml` to discover all active sites. No manual registration is needed.
- **API scoping** — API requests are scoped to a specific site via the `X-Site-Key` header. The value must match the site's `api_key`.

---

## Write-Through Sync

All mutations follow a write-through strategy that keeps SQLite and the flat files in sync within a single transaction.

### Create

1. `BEGIN` a SQLite transaction.
2. `INSERT` the new row into SQLite and obtain the auto-increment ID.
3. Write the `.md` file to disk with the ID embedded in the frontmatter.
4. `COMMIT` the transaction.

### Update

1. `BEGIN` a SQLite transaction.
2. `UPDATE` the row in SQLite.
3. If the slug changed, rename the `.md` file to match the new slug.
4. Write the updated frontmatter and body to the `.md` file.
5. `COMMIT` the transaction.

### Delete

1. `BEGIN` a SQLite transaction.
2. `DELETE` the row from SQLite.
3. Delete the `.md` file from disk.
4. `COMMIT` the transaction.

### Failure handling

If any step fails the transaction is rolled back (`ROLLBACK`). If a file was already written before the failure it is deleted to prevent orphaned files on disk.

---

## Index Rebuild

The SQLite database is an index, not the source of truth. The flat files on disk are authoritative. The database can be fully reconstructed at any time:

```bash
php hcms index:rebuild
```

The rebuild scans every `.md` file, reads the YAML frontmatter, and writes the data into SQLite using `INSERT OR REPLACE`. IDs stored in the frontmatter are preserved so that all relationships remain valid after a rebuild.

This design means you can:

- Delete the database and regenerate it.
- Copy flat files between environments and rebuild.
- Edit flat files by hand and rebuild to pick up the changes.
- Store the `content/` directory in version control as a complete, portable backup.
