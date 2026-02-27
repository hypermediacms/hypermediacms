# HTX DSL Reference

## Overview

HTX (Hypermedia Template eXtension) is a declarative domain-specific language for building dynamic web pages in Hypermedia CMS. An `.htx` file contains two logical parts: **meta directives** that specify what data to fetch and how to operate on it, and a **template block** that defines how to render the result as HTML.

At runtime, the Rufinus edge server parses each `.htx` file, communicates with Origen's content API to retrieve or mutate data, and produces HTML output. Pages that contain no data-requiring directives are treated as static and rendered directly without any API call.

HTX is designed to be minimal. There is no build step, no JavaScript framework, and no compilation. You write HTML with a small set of declarative constructs, and Rufinus handles the rest.

---

## File Structure

Every `.htx` file has two parts:

1. **Meta directives** (optional) -- `<htx:directive>value</htx:directive>` tags that appear before the template block. These tell Rufinus what data to fetch or what operation to perform.

2. **Template block** -- an `<htx>...</htx>` tag containing the HTML template that will be rendered with data.

```
<htx:type>article</htx:type>
<htx:order>recent</htx:order>
<htx:howmany>10</htx:howmany>

<htx>
  <h1>Articles</h1>
  <htx:each>
    <article class="card">
      <h3>__title__</h3>
      <p>__body__</p>
    </article>
  </htx:each>
</htx>
```

Files with no meta directives are treated as **static pages**. They are rendered without calling the Origen API. The template block content is extracted and served directly (wrapped in any applicable layouts).

---

## Meta Directives

Meta directives appear as `<htx:name>value</htx:name>` tags before the template block. They configure what data Rufinus should request from Origen.

| Directive | Description | Example |
|-----------|-------------|---------|
| `<htx:type>` | Content type to query | `<htx:type>article</htx:type>` |
| `<htx:action>` | Mutation action: `save`, `update`, `delete` | `<htx:action>save</htx:action>` |
| `<htx:recordId>` | Specific record ID to operate on | `<htx:recordId>42</htx:recordId>` |
| `<htx:slug>` | Filter by slug | `<htx:slug>hello-world</htx:slug>` |
| `<htx:id>` | Filter by ID | `<htx:id>42</htx:id>` |
| `<htx:status>` | Filter by content status | `<htx:status>published</htx:status>` |
| `<htx:howmany>` | Limit number of results returned | `<htx:howmany>10</htx:howmany>` |
| `<htx:order>` | Sort order: `recent`, `oldest`, `alphabetical` | `<htx:order>recent</htx:order>` |
| `<htx:where>` | Additional key=value filters | `<htx:where>status=published</htx:where>` |
| `<htx:fields>` | Comma-separated list of specific fields to return | `<htx:fields>title,body</htx:fields>` |

**Notes:**

- The presence of any data-requiring directive (`type`, `action`, `recordId`, `slug`, `id`, `status`, `howmany`, `order`, `where`, `fields`) causes Rufinus to call the Origen API. If none are present, the page is static.
- `<htx:howmany>` defaults to `10` if the value cannot be parsed as an integer.
- `<htx:fields>` accepts a comma-separated string that is split into an array (e.g., `title,body,slug` becomes `["title", "body", "slug"]`).
- Any unrecognized directive name is stored as a generic key-value meta entry and forwarded to the API.

---

## Template Block

The `<htx>...</htx>` block contains HTML with special constructs for data binding.

### Placeholders

Double-underscore tokens are replaced with field values from the API response:

```html
<h1>__title__</h1>
<p>__body__</p>
<small>__created_at__ -- __status__</small>
<a href="/articles/__slug__">Read more</a>
```

Common placeholders include:

| Placeholder | Description |
|-------------|-------------|
| `__title__` | Content title |
| `__body__` | Content body (uses `body_html` automatically when available) |
| `__body_html__` | Pre-rendered HTML from Markdown |
| `__slug__` | URL-friendly identifier |
| `__status__` | Content status (draft, published, etc.) |
| `__created_at__` | Creation timestamp |
| `__updated_at__` | Last update timestamp |
| `__id__` | Record ID |
| `__recordId__` | Record ID (alias) |

Placeholders support **dot notation** for nested data. For example, `__author.name__` resolves to the `name` property of the `author` object in the data.

**Escaping:** All placeholder values are HTML-escaped by default. Fields listed as trusted HTML (`body_html`, `status_options`, `type_options`, `custom_fields_html`) are output without escaping because Origen is responsible for sanitizing them. To include a literal `__placeholder__` in your output (without replacement), escape it with a backslash: `\__placeholder__`.

### Repeating Blocks

`<htx:each>...</htx:each>` repeats its inner HTML for each row in the result set:

```html
<htx:each>
  <article class="card">
    <h3>__title__</h3>
    <p>__body__</p>
  </article>
</htx:each>
```

If the template has no `<htx:each>` block, the first row of data is used to hydrate the entire template as a single-item view.

### Empty State

`<htx:none>...</htx:none>` is rendered when the result set is empty:

```html
<htx:none>
  <p>No articles found.</p>
</htx:none>
```

If no `<htx:none>` block is provided and the result set is empty, Rufinus outputs a default message: `<div class="no-content">No content found.</div>`.

### Relationship Blocks

`<htx:rel name="fieldName">...</htx:rel>` renders related content. The inner template is repeated for each related item:

```html
<htx:rel name="tags">
  <span class="tag">__name__</span>
</htx:rel>
```

For single-object relationships (cardinality of one), the object is automatically wrapped in an array for uniform iteration.

### htmx Attributes

Standard HTML and htmx attributes are fully supported within templates:

```html
<a href="/articles/__slug__"
   hx-get="/articles/__slug__"
   hx-target="main"
   hx-push-url="true">
  __title__
</a>
```

---

## Expression Language

The `{{ expression }}` syntax provides dynamic logic within templates. Expressions are evaluated after data is fetched but before placeholder hydration.

### Variable Output

Output a field value:

```html
<p>{{ title }}</p>
<p>{{ summary }}</p>
```

### Raw HTML Output

Use `{{! expression }}` to output without HTML escaping. This is necessary for pre-rendered HTML content such as Markdown output:

```html
<div class="doc-body">
  {{! body_html }}
</div>
```

### Conditionals

```html
{{ if status == "published" }}
  <span class="badge">Published</span>
{{ endif }}

{{ if status == "draft" }}
  <span class="badge draft">Draft</span>
{{ else }}
  <span class="badge">{{ status }}</span>
{{ endif }}

{{ if not empty(summary) }}
  <div class="summary">{{ summary }}</div>
{{ endif }}
```

The `{{ elif condition }}` keyword is also supported for multi-branch conditionals:

```html
{{ if status == "published" }}
  <span class="badge published">Live</span>
{{ elif status == "draft" }}
  <span class="badge draft">Draft</span>
{{ else }}
  <span class="badge">{{ status }}</span>
{{ endif }}
```

### Inline Each

The expression language also supports `{{ each items }}...{{ endeach }}` for iterating over sub-collections within expressions.

### Built-in Functions

#### String Functions

| Function | Description | Example |
|----------|-------------|---------|
| `truncate(field, length)` | Truncate text to `length` characters, appending `...` | `{{ truncate(body, 100) }}` |
| `truncate(field, length, suffix)` | Truncate with a custom suffix | `{{ truncate(body, 100, " [more]") }}` |
| `uppercase(field)` | Convert to uppercase | `{{ uppercase(status) }}` |
| `lowercase(field)` | Convert to lowercase | `{{ lowercase(title) }}` |
| `capitalize(field)` | Capitalize first character | `{{ capitalize(name) }}` |
| `trim(field)` | Remove leading/trailing whitespace | `{{ trim(title) }}` |
| `replace(field, search, replacement)` | Replace substring | `{{ replace(slug, "-", " ") }}` |
| `contains(field, search)` | Check if string contains substring | `{{ if contains(title, "draft") }}` |
| `starts_with(field, prefix)` | Check if string starts with prefix | `{{ if starts_with(slug, "intro") }}` |
| `length(field)` | String length (or array count) | `{{ length(title) }}` |
| `default(field, fallback)` | Return `fallback` if field is null/empty | `{{ default(subtitle, "Untitled") }}` |
| `slug(field)` | Convert to URL-friendly slug | `{{ slug(title) }}` |
| `split(field, delimiter)` | Split string into array | `{{ split(tags, ",") }}` |
| `join(array, delimiter)` | Join array into string | `{{ join(tags, ", ") }}` |
| `md(field)` | Inline Markdown to HTML (bold, italic, code, links) | `{{! md(summary) }}` |

#### Date Functions

| Function | Description | Example |
|----------|-------------|---------|
| `time_ago(field)` | Relative time (e.g., "3 hours ago", "2 days ago") | `{{ time_ago(updated_at) }}` |
| `format_date(field, format)` | Format date using PHP date format string | `{{ format_date(created_at, "M j, Y") }}` |
| `days_since(field)` | Number of days since the given date | `{{ days_since(created_at) }}` |
| `is_past(field)` | Check if date is in the past | `{{ if is_past(expires_at) }}` |
| `is_future(field)` | Check if date is in the future | `{{ if is_future(publish_at) }}` |
| `year(field)` | Extract the four-digit year | `{{ year(created_at) }}` |

#### Number Functions

| Function | Description | Example |
|----------|-------------|---------|
| `round(value, decimals)` | Round to N decimal places | `{{ round(score, 2) }}` |
| `floor(value)` | Round down | `{{ floor(rating) }}` |
| `ceil(value)` | Round up | `{{ ceil(rating) }}` |
| `abs(value)` | Absolute value | `{{ abs(diff) }}` |
| `clamp(value, min, max)` | Constrain value to range | `{{ clamp(score, 0, 100) }}` |
| `number_format(value, decimals, thousands)` | Format number with separators | `{{ number_format(price, 2, ",") }}` |
| `percent(value, total)` | Calculate percentage | `{{ percent(completed, total) }}` |

#### Array / Utility Functions

| Function | Description | Example |
|----------|-------------|---------|
| `empty(field)` | Check if field is null, empty string, 0, or empty array | `{{ if empty(subtitle) }}` |
| `not empty(field)` | Check if field has a value | `{{ if not empty(subtitle) }}` |
| `defined(field)` | Check if field is not null | `{{ if defined(author) }}` |
| `count(field)` | Count array items (or string length) | `{{ count(tags) }}` |
| `first(array)` | First element of array | `{{ first(items) }}` |
| `last(array)` | Last element of array | `{{ last(items) }}` |
| `reverse(array)` | Reverse array order | `{{ each reverse(items) }}` |
| `sort(array)` | Sort array ascending | `{{ each sort(tags) }}` |
| `unique(array)` | Remove duplicate values | `{{ each unique(categories) }}` |
| `slice(array, start, length)` | Extract a sub-array | `{{ each slice(items, 0, 3) }}` |
| `in_list(value, csv)` | Check if value exists in comma-separated list | `{{ if in_list(status, "published,featured") }}` |

---

## Layouts

The `_layout.htx` system provides automatic page wrapping.

### How It Works

1. Place a `_layout.htx` file in any directory.
2. The layout must contain the `__content__` placeholder where page content is inserted.
3. All pages in that directory (and subdirectories without their own layout) are wrapped by it.

```html
<!-- /articles/_layout.htx -->
<section style="border-left: 3px solid #1a1a2e; padding-left: 1.5rem;">
  <h2>Articles</h2>
  __content__
</section>
```

### Nesting

Layouts nest from innermost to outermost. A page at `/docs/intro.htx` is processed as follows:

1. Page content is rendered.
2. Wrapped by `/docs/_layout.htx` (if it exists).
3. Wrapped by `/_layout.htx` (the root layout).

The root layout typically contains the full HTML document shell:

```html
<!-- /_layout.htx -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Site</title>
  <script src="https://unpkg.com/htmx.org@2"></script>
</head>
<body>
  <nav>...</nav>
  <main>__content__</main>
  <footer>...</footer>
</body>
</html>
```

### htmx Fragment Requests

When Rufinus detects an htmx request (`HX-Request: true` header), the **root layout** is skipped if it contains `<!DOCTYPE html>`. This means htmx navigations receive only the inner page fragment (already wrapped by any intermediate layouts), avoiding a redundant full-document response. The browser already has the outer shell from the initial page load.

### Layout Walk Termination

If a layout itself contains `<!DOCTYPE html>`, the layout walk stops there. This allows isolated sections of the site (e.g., an admin panel) to define their own complete HTML document without being wrapped by the marketing root layout.

---

## File-Based Routing

The filesystem is the router. Every `.htx` file in the site directory maps to a URL:

| File Path | Serves URL |
|-----------|------------|
| `/index.htx` | `/` |
| `/about.htx` | `/about` |
| `/articles/index.htx` | `/articles` |
| `/articles/[slug].htx` | `/articles/{anything}` (dynamic route) |
| `/docs/index.htx` | `/docs` |
| `/docs/[slug].htx` | `/docs/{anything}` (dynamic route) |

### Resolution Order

For a given URL path, Rufinus tries matches in this order:

1. **Exact file match:** `{siteRoot}/{path}.htx`
2. **Directory index:** `{siteRoot}/{path}/index.htx`
3. **Dynamic segment:** `{siteRoot}/{dir}/[param].htx`

### Dynamic Routes

Filenames wrapped in square brackets create dynamic route segments. The matched URL value is captured as a named parameter:

- `/articles/[slug].htx` -- a request to `/articles/hello-world` captures `slug = "hello-world"`
- `/users/[id].htx` -- a request to `/users/42` captures `id = "42"`

Route parameters are automatically injected into the DSL as meta directives before parsing. For example, visiting `/articles/hello-world` with `[slug].htx` causes Rufinus to prepend `<htx:slug>hello-world</htx:slug>` to the file contents. Numeric `id` parameters also generate a `<htx:recordId>` directive.

### Excluded Paths

The following paths are not routable:

- **Underscore-prefixed files:** `_layout.htx`, `_error.htx`, and any file or directory starting with `_`.
- **The `public/` directory:** Reserved for static assets (CSS, images, etc.), served directly by the web server.

### Error Pages

Place a `_error.htx` file in the site root to customize error responses. Use the `__status_code__` placeholder:

```html
<!-- /_error.htx -->
<div style="text-align: center; padding: 4rem;">
  <h1>__status_code__</h1>
  <p>Page not found</p>
  <a href="/" hx-get="/" hx-target="main" hx-push-url="true">Go home</a>
</div>
```

---

## Static Pages

Pages with no data-requiring meta directives are rendered directly without calling the Origen API. A page is considered static if it contains none of the following directives: `type`, `action`, `recordId`, `slug`, `id`, `status`, `howmany`, `order`, `where`, `fields`.

Static pages still benefit from the layout system, htmx fragment detection, and file-based routing. They simply skip the data-fetching phase.

```html
<!-- /about.htx -- no meta directives, rendered as static -->
<htx>
  <div class="card">
    <h1>About Us</h1>
    <p>This content is hardcoded in the template. No API call is made.</p>
  </div>
</htx>
```

---

## Operations

The `<htx:action>` directive determines what operation Rufinus performs.

### Get Content (no action directive)

When no `action` directive is present, Rufinus fetches data from Origen and hydrates the template. This is the default read operation.

**Flow:**

1. Parse the `.htx` file into meta directives and template.
2. Send the meta directives to Origen's API as a query.
3. Receive rows of data.
4. If rows exist, evaluate expressions and hydrate the `<htx:each>` block (or the full template for single-item views).
5. If no rows exist, render the `<htx:none>` block.
6. Wrap in layouts and return HTML.

### Set Content (action = save or update)

When the action is `save`, `prepare-save`, `update`, or `prepare-update`, Rufinus calls the **prepare** endpoint first to obtain an action token, then hydrates a form template with the endpoint URL and payload token.

**Flow:**

1. Parse the `.htx` file.
2. Call Origen's prepare endpoint with the meta directives.
3. Receive an `endpoint`, `payload` (containing the action token), and optionally current field values.
4. Hydrate the template with the prepare response, replacing `__endpoint__`, `__payload__`, `__title__`, `__body__`, and other placeholders.
5. Return the hydrated form HTML.

The form template typically uses htmx attributes to submit:

```html
<form hx-post="__endpoint__" hx-vals='__payload__'>
  <input type="text" name="title" value="__title__">
  <textarea name="body">__body__</textarea>
  <button type="submit">Save</button>
</form>
```

### Delete Content (action = delete)

When the action is `delete` or `prepare-delete`, the flow is similar to set content but for deletion confirmation. Rufinus calls prepare, receives the action token, and hydrates a confirmation template.

---

## Examples

### Article Listing Page

A page that fetches the 20 most recent articles and renders them as a list with links:

```html
<!-- /articles/index.htx -->
<htx:type>article</htx:type>
<htx:order>recent</htx:order>
<htx:howmany>20</htx:howmany>

<htx>
  <htx:each>
    <article class="card">
      <h3>
        <a href="/articles/__slug__"
           hx-get="/articles/__slug__"
           hx-target="main"
           hx-push-url="true">__title__</a>
      </h3>
      <p>__body__</p>
      <small>__created_at__ -- __status__</small>
    </article>
  </htx:each>

  <htx:none>
    <p>No articles found.</p>
  </htx:none>
</htx>
```

### Single Article Detail Page (Dynamic Route)

A dynamic route that displays a single article based on the URL slug:

```html
<!-- /articles/[slug].htx -->
<htx:type>article</htx:type>
<htx:howmany>1</htx:howmany>

<htx>
  <htx:each>
    <article class="card" style="padding: 2rem;">
      <h1>__title__</h1>
      <div style="color: #888; margin-bottom: 1rem;">
        __created_at__ -- __status__
      </div>
      <div style="line-height: 1.8;">__body__</div>
    </article>
  </htx:each>

  <htx:none>
    <div class="card" style="text-align: center; padding: 3rem;">
      <p>Article not found.</p>
      <a href="/articles"
         hx-get="/articles"
         hx-target="main"
         hx-push-url="true">All articles</a>
    </div>
  </htx:none>
</htx>
```

When a user visits `/articles/hello-world`, the router matches `[slug].htx` and injects `<htx:slug>hello-world</htx:slug>` before parsing. Origen returns the matching article, and the template is hydrated with its data.

### Static About Page

A page with no meta directives, rendered without an API call:

```html
<!-- /about.htx -->
<htx>
  <div class="card" style="padding: 2rem;">
    <h1>About Hypermedia CMS</h1>
    <p>
      Hypermedia CMS is an open-source content management system built on
      <strong>flat-file Markdown as source of truth</strong> and
      <strong>hypermedia-driven rendering</strong>.
    </p>

    <h2>Architecture</h2>
    <ul>
      <li><strong>Origen</strong> -- Backend. Markdown files + SQLite index.</li>
      <li><strong>Rufinus</strong> -- Edge runtime. HTX DSL parser + HTML renderer.</li>
    </ul>
  </div>
</htx>
```

### Documentation Page Using Expressions

A documentation detail page that uses the expression language for conditional rendering, relative timestamps, and raw HTML output:

```html
<!-- /docs/[slug].htx -->
<htx:type>documentation</htx:type>
<htx:howmany>1</htx:howmany>
<htx:where>status=published</htx:where>

<htx>
  <htx:each>
    <article class="card" style="padding: 2rem;">
      <h1>__title__</h1>
      <div style="font-size: 0.85rem; color: #999; margin-bottom: 1.5rem;">
        Last updated {{ time_ago(updated_at) }}
      </div>
      <div class="doc-body" style="line-height: 1.8;">
        {{! body_html }}
      </div>
    </article>
  </htx:each>

  <htx:none>
    <div class="card" style="text-align: center; padding: 3rem;">
      <h2>404</h2>
      <p>This documentation page was not found.</p>
      <a href="/docs"
         hx-get="/docs"
         hx-target="main"
         hx-push-url="true">Browse all documentation</a>
    </div>
  </htx:none>
</htx>
```

Key expression features demonstrated:

- `{{ time_ago(updated_at) }}` -- Converts the `updated_at` timestamp to a human-readable relative string like "3 hours ago".
- `{{! body_html }}` -- Outputs the pre-rendered Markdown HTML without escaping (the `!` flag disables HTML escaping).
- `{{ if not empty(summary) }}...{{ endif }}` -- Conditionally renders content only when the `summary` field has a value (used in the docs index page).
