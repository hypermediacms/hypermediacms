# Origen API Reference

Complete REST API reference for the Origen content engine powering Hypermedia CMS.

---

## Common Headers

All endpoints (except `/api/health`) require the following headers:

| Header           | Value                  | Description                    |
|------------------|------------------------|--------------------------------|
| `Content-Type`   | `application/json`     | Request body format            |
| `X-Site-Key`     | `<api-key>`            | Multi-tenant site identifier   |
| `X-HTX-Version`  | `1`                    | Protocol version               |

---

## Two-Phase Mutation Flow

All write operations (create, update, delete) follow a **prepare then execute** pattern to prevent replay attacks and enforce server-side validation.

```
1. Client sends POST /api/content/prepare with the desired action.
2. Server returns an action token (JWT, 5-minute expiry, unique jti).
3. Client sends POST /api/content/save|update|delete with the token.
4. Server validates the token, checks replay (jti against used_tokens), and executes the mutation.
5. The token is marked as used and cannot be replayed.
```

The action token is a single-use JWT. If a token is submitted a second time, the server rejects it with `403 Forbidden` (replay detected). Tokens expire after 5 minutes regardless of use.

---

## Endpoints

### Health Check

#### `GET /api/health`

Returns server health status. No headers required.

**Request:**

```bash
curl http://localhost:3000/api/health
```

**Response:**

```json
{
  "status": "ok"
}
```

---

### Content

#### `POST /api/content/prepare`

Prepare a mutation by obtaining an action token and, optionally, a form contract with pre-built HTML fields.

This is **phase 1** of the two-phase mutation flow. The returned `htx-token` must be included in the subsequent save, update, or delete request.

**Request body:**

| Field                | Type     | Required | Description                                                              |
|----------------------|----------|----------|--------------------------------------------------------------------------|
| `meta.action`        | string   | Yes      | One of `prepare-save`, `prepare-update`, `prepare-delete`                |
| `meta.type`          | string   | Yes      | Content type (e.g. `article`)                                            |
| `meta.recordId`      | string   | No       | Record ID (required for `prepare-update` and `prepare-delete`)           |
| `responseTemplates`  | array    | No       | Template definitions for pre-built form HTML                             |

The `action` value **must** start with `prepare-`.

**Request:**

```bash
curl -X POST http://localhost:3000/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "action": "prepare-save",
      "type": "article"
    },
    "responseTemplates": []
  }'
```

**Response:**

```json
{
  "data": {
    "endpoint": "/api/content/save",
    "payload": "{\"htx-token\":\"eyJhbGciOiJIUzI1NiIs...\",\"htx-context\":\"save\",\"htx-recordId\":null}",
    "values": {},
    "html": {}
  }
}
```

| Response field   | Description                                                        |
|------------------|--------------------------------------------------------------------|
| `endpoint`       | The URL to send the execute request to                             |
| `payload`        | JSON string containing the `htx-token` needed for phase 2         |
| `values`         | Current field values for the record (populated for update/delete)  |
| `html`           | Pre-built form field HTML for each response template               |

**Preparing an update:**

```bash
curl -X POST http://localhost:3000/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "action": "prepare-update",
      "type": "article",
      "recordId": "42"
    }
  }'
```

---

#### `POST /api/content/get`

Query content records. Supports filtering by type, slug, status, and custom where clauses. Relationships are resolved automatically with related objects nested inline.

**Request body:**

| Field            | Type     | Required | Description                                          |
|------------------|----------|----------|------------------------------------------------------|
| `meta.type`      | string   | No*      | Content type to query (*needed for meaningful results)|
| `meta.slug`      | string   | No       | Filter by slug                                       |
| `meta.status`    | string   | No       | Filter by status (e.g. `published`, `draft`)         |
| `meta.howmany`   | number   | No       | Limit number of results                              |
| `meta.order`     | string   | No       | Sort order (e.g. `recent`)                           |
| `meta.where`     | string   | No       | Custom filter expression (e.g. `status=published`)   |
| `meta.recordId`  | string   | No       | Fetch a specific record by ID                        |

**Request:**

```bash
curl -X POST http://localhost:3000/api/content/get \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "type": "article",
      "status": "published",
      "howmany": 10,
      "order": "recent"
    }
  }'
```

**Response:**

```json
{
  "rows": [
    {
      "id": 1,
      "title": "Hello World",
      "slug": "hello-world",
      "body": "Raw markdown content...",
      "body_html": "<p>Rendered HTML content...</p>",
      "status": "published",
      "type": "article",
      "created_at": "2026-01-15T10:30:00Z",
      "updated_at": "2026-02-01T14:22:00Z"
    }
  ]
}
```

Custom fields and resolved relationships appear as additional keys on each row object.

**Fetch a single record by slug:**

```bash
curl -X POST http://localhost:3000/api/content/get \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "type": "article",
      "slug": "hello-world"
    }
  }'
```

**Fetch a single record by ID:**

```bash
curl -X POST http://localhost:3000/api/content/get \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "type": "article",
      "recordId": "42"
    }
  }'
```

---

#### `POST /api/content/save`

Create a new content record. Requires an action token obtained from the prepare step.

This is **phase 2** of the two-phase mutation flow.

**Request body:**

| Field          | Type     | Required | Description                                      |
|----------------|----------|----------|--------------------------------------------------|
| `htx-token`    | string   | Yes      | Action token from the prepare step               |
| `htx-context`  | string   | Yes      | Must be `save`                                   |
| `title`        | string   | No       | Record title                                     |
| `body`         | string   | No       | Record body content                              |
| `status`       | string   | No       | Record status (e.g. `draft`, `published`)        |
| `type`         | string   | No       | Content type                                     |
| *custom fields*| varies   | No       | Any additional fields defined on the content type |

**Request:**

```bash
# Step 1: Prepare
PREPARE_RESPONSE=$(curl -s -X POST http://localhost:3000/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "action": "prepare-save",
      "type": "article"
    }
  }')

# Extract the token from the payload
TOKEN=$(echo "$PREPARE_RESPONSE" | jq -r '.data.payload' | jq -r '.["htx-token"]')

# Step 2: Execute
curl -X POST http://localhost:3000/api/content/save \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "htx-token": "'"$TOKEN"'",
    "htx-context": "save",
    "title": "New Post",
    "body": "Content here",
    "status": "draft",
    "type": "article"
  }'
```

**Response:**

```
Content created!
```

The response is an HTML string by default, or a configured response template if one was defined.

---

#### `POST /api/content/update`

Update an existing content record. Requires an action token obtained from the prepare step.

**Request body:**

| Field            | Type     | Required | Description                                      |
|------------------|----------|----------|--------------------------------------------------|
| `htx-token`      | string   | Yes      | Action token from the prepare step               |
| `htx-context`    | string   | Yes      | Must be `update`                                 |
| `htx-recordId`   | string   | Yes      | ID of the record to update                       |
| *field values*   | varies   | No       | Any fields to update                             |

**Request:**

```bash
# Step 1: Prepare
PREPARE_RESPONSE=$(curl -s -X POST http://localhost:3000/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "action": "prepare-update",
      "type": "article",
      "recordId": "42"
    }
  }')

TOKEN=$(echo "$PREPARE_RESPONSE" | jq -r '.data.payload' | jq -r '.["htx-token"]')

# Step 2: Execute
curl -X POST http://localhost:3000/api/content/update \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "htx-token": "'"$TOKEN"'",
    "htx-context": "update",
    "htx-recordId": "42",
    "title": "Updated Title"
  }'
```

**Response:**

```
Content updated!
```

The response is an HTML string by default, or a configured response template if one was defined.

---

#### `POST /api/content/delete`

Delete a content record. Requires an action token obtained from the prepare step.

**Request body:**

| Field            | Type     | Required | Description                                      |
|------------------|----------|----------|--------------------------------------------------|
| `htx-token`      | string   | Yes      | Action token from the prepare step               |
| `htx-context`    | string   | Yes      | Must be `delete`                                 |
| `htx-recordId`   | string   | Yes      | ID of the record to delete                       |

**Request:**

```bash
# Step 1: Prepare
PREPARE_RESPONSE=$(curl -s -X POST http://localhost:3000/api/content/prepare \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "meta": {
      "action": "prepare-delete",
      "type": "article",
      "recordId": "42"
    }
  }')

TOKEN=$(echo "$PREPARE_RESPONSE" | jq -r '.data.payload' | jq -r '.["htx-token"]')

# Step 2: Execute
curl -X POST http://localhost:3000/api/content/delete \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "htx-token": "'"$TOKEN"'",
    "htx-context": "delete",
    "htx-recordId": "42"
  }'
```

**Response:**

```
Content deleted.
```

The response is an HTML string by default, or a configured response template if one was defined.

---

### Content Types

#### `GET /api/content-types`

List all content types defined for the site.

**Request:**

```bash
curl http://localhost:3000/api/content-types \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1"
```

**Response:**

```json
{
  "types": ["article", "documentation", "author"]
}
```

---

#### `GET /api/content-types/{type}`

Get the full schema for a content type, including field definitions and form HTML.

**Request:**

```bash
curl http://localhost:3000/api/content-types/article \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1"
```

**Response:**

The response contains the schema with field definitions and pre-built form HTML for the content type.

---

#### `POST /api/content-types`

Create a new content type or update an existing one.

**Request body:**

| Field          | Type     | Required | Description                                |
|----------------|----------|----------|--------------------------------------------|
| `type`         | string   | Yes      | Name of the content type                   |
| `fields`       | array    | Yes      | Array of field definition objects          |

Each field definition object:

| Field                        | Type     | Required | Description                                      |
|------------------------------|----------|----------|--------------------------------------------------|
| `name`                       | string   | Yes      | Field name                                       |
| `type`                       | string   | Yes      | Field type (e.g. `text`, `textarea`, `relationship`) |
| `constraints`                | object   | No       | Type-specific constraints                        |
| `constraints.target_type`    | string   | No       | For relationships: the related content type       |
| `constraints.cardinality`    | string   | No       | For relationships: `one` or `many`               |

**Request:**

```bash
curl -X POST http://localhost:3000/api/content-types \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "type": "article",
    "fields": [
      {
        "name": "author",
        "type": "relationship",
        "constraints": {
          "target_type": "author",
          "cardinality": "one"
        }
      }
    ]
  }'
```

---

#### `DELETE /api/content-types/{type}`

Delete a content type and its schema.

**Request:**

```bash
curl -X DELETE http://localhost:3000/api/content-types/article \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1"
```

---

### Authentication

#### `POST /api/auth/login`

Authenticate a user and receive a JWT.

**Request body:**

| Field      | Type     | Required | Description         |
|------------|----------|----------|---------------------|
| `email`    | string   | Yes      | User email address  |
| `password` | string   | Yes      | User password       |

**Request:**

```bash
curl -X POST http://localhost:3000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "email": "user@example.com",
    "password": "secret"
  }'
```

**Response:**

```json
{
  "token": "eyJhbGciOiJIUzI1NiIs...",
  "user": {
    "email": "user@example.com",
    "name": "Jane Editor",
    "role": "editor"
  }
}
```

---

#### `GET /api/auth/me`

Get the currently authenticated user's information.

Requires an additional `Authorization` header with the JWT obtained from login.

**Request:**

```bash
curl http://localhost:3000/api/auth/me \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIs..."
```

**Response:**

```json
{
  "user": {
    "email": "user@example.com",
    "name": "Jane Editor",
    "role": "editor"
  }
}
```

---

#### `POST /api/auth/logout`

Log out the current user. This endpoint is stateless on the server side and exists primarily for Rufinus cookie clearing.

**Request:**

```bash
curl -X POST http://localhost:3000/api/auth/logout \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1"
```

---

## Error Responses

All error responses follow a consistent format. The HTTP status code indicates the category of error.

| Status | Meaning              | Common Causes                                              |
|--------|----------------------|------------------------------------------------------------|
| 400    | Bad Request          | Missing required fields, invalid `X-HTX-Version` header    |
| 401    | Unauthorized         | Missing or invalid `X-Site-Key`, missing auth token        |
| 403    | Forbidden            | Replay detected (reused action token), action token expired|
| 404    | Not Found            | Record or content type does not exist                      |
| 422    | Validation Error     | Slug conflict, invalid field values                        |
| 500    | Internal Server Error| Unexpected server failure                                  |

**Example error (401):**

```bash
curl http://localhost:3000/api/content-types \
  -H "Content-Type: application/json" \
  -H "X-HTX-Version: 1"
# Missing X-Site-Key header
```

**Example error (403 -- replay detected):**

```bash
# Attempting to reuse a spent action token
curl -X POST http://localhost:3000/api/content/save \
  -H "Content-Type: application/json" \
  -H "X-Site-Key: my-site-key" \
  -H "X-HTX-Version: 1" \
  -d '{
    "htx-token": "<already-used-token>",
    "htx-context": "save",
    "title": "Duplicate attempt",
    "type": "article"
  }'
# 403 Forbidden -- token jti found in used_tokens
```
