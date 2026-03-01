# Nested Object Filtering

*Querying inside the nest*

---

## The Challenge

Object fields store JSON blobs. Finding content where `ingredients` contains an item with `item = "flour"` requires reaching *inside* the JSON.

SQLite has `json_extract()`. Let's use it.

---

## Query Syntax

### Current Where Syntax

The existing `where` meta directive uses simple key=value pairs:

```html
<htx:where>status=published,type=article</htx:where>
```

This maps to:

```sql
WHERE status = 'published' AND type = 'article'
```

### Proposed Nested Syntax

Extend the syntax with dot notation for nested access:

```html
<htx:where>ingredients.item=flour</htx:where>
```

This should find any content where the `ingredients` array contains an object with `item = "flour"`.

---

## SQLite JSON Functions

SQLite provides powerful JSON functions:

| Function | Purpose |
|----------|---------|
| `json_extract(json, path)` | Extract value at path |
| `json_each(json)` | Table-valued function, one row per array element |
| `json_tree(json)` | Recursive table-valued function |

### The Problem

Object field values are stored in `content_field_values`:

```sql
SELECT * FROM content_field_values 
WHERE field_name = 'ingredients';

-- Returns:
-- content_id | field_value
-- 42         | [{"amount":"2","unit":"cups","item":"flour"},{"amount":"1","unit":"tsp","item":"salt"}]
```

To query "find content where ingredients contains item=flour", we need:

```sql
SELECT DISTINCT cfv.content_id
FROM content_field_values cfv, json_each(cfv.field_value) AS je
WHERE cfv.field_name = 'ingredients'
  AND json_extract(je.value, '$.item') = 'flour';
```

The `json_each()` expands the array into rows. We then filter on the nested property.

---

## Implementation

### QueryBuilder Extension

Add a `whereNested()` method to `QueryBuilder`:

```php
class QueryBuilder
{
    // Existing methods...

    /**
     * Filter on a nested field within a JSON object/array.
     *
     * @param string $field The object field name (e.g., 'ingredients')
     * @param string $nestedPath The path within objects (e.g., 'item')
     * @param string $operator Comparison operator
     * @param mixed $value The value to match
     */
    public function whereNested(string $field, string $nestedPath, string $operator, $value): self
    {
        $this->nestedConditions[] = [
            'field' => $field,
            'path' => $nestedPath,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    public function buildQuery(): array
    {
        $sql = "SELECT c.* FROM content c";
        $bindings = [];

        // Join for nested conditions
        foreach ($this->nestedConditions as $i => $cond) {
            $alias = "cfv_nested_{$i}";
            $jeAlias = "je_{$i}";
            
            $sql .= " INNER JOIN content_field_values {$alias} ON c.id = {$alias}.content_id";
            $sql .= " AND {$alias}.field_name = ?";
            $bindings[] = $cond['field'];
            
            $sql .= ", json_each({$alias}.field_value) AS {$jeAlias}";
        }

        $sql .= " WHERE c.site_id = ?";
        $bindings[] = $this->siteId;

        // Regular conditions
        foreach ($this->conditions as $cond) {
            $sql .= " AND c.{$cond['field']} {$cond['operator']} ?";
            $bindings[] = $cond['value'];
        }

        // Nested conditions
        foreach ($this->nestedConditions as $i => $cond) {
            $jeAlias = "je_{$i}";
            $sql .= " AND json_extract({$jeAlias}.value, ?) {$cond['operator']} ?";
            $bindings[] = '$.' . $cond['path'];
            $bindings[] = $cond['value'];
        }

        // Deduplicate (one content may have multiple matching array items)
        $sql = "SELECT DISTINCT * FROM ({$sql}) AS filtered";

        return [$sql, $bindings];
    }
}
```

### Where Clause Parsing

Update `applyWhereConditions()` in `ContentService`:

```php
private function applyWhereConditions(QueryBuilder $builder, string $where): void
{
    $conditions = explode(',', $where);
    
    foreach ($conditions as $condition) {
        $condition = trim($condition);
        
        // Check for nested syntax: field.nested=value
        if (preg_match('/^([a-z_]+)\.([a-z_]+)\s*(=|!=|>|<|>=|<=|~)\s*(.+)$/i', $condition, $m)) {
            $field = $m[1];
            $nestedPath = $m[2];
            $operator = $this->normalizeOperator($m[3]);
            $value = trim($m[4]);
            
            $builder->whereNested($field, $nestedPath, $operator, $value);
        }
        // Regular field condition
        elseif (preg_match('/^([a-z_]+)\s*(=|!=|>|<|>=|<=|~)\s*(.+)$/i', $condition, $m)) {
            $field = $m[1];
            $operator = $this->normalizeOperator($m[2]);
            $value = trim($m[3]);
            
            $builder->where($field, $value, $operator);
        }
    }
}

private function normalizeOperator(string $op): string
{
    return match($op) {
        '~' => 'LIKE',
        '=' => '=',
        '!=' => '!=',
        default => $op,
    };
}
```

---

## Template Syntax

### Simple Nested Filter

```html
<htx:get type="recipe">
  <htx:where>ingredients.item=flour</htx:where>
  
  <htx:each>
    <h2>__title__</h2>
  </htx:each>
</htx:get>
```

### Multiple Nested Conditions

```html
<htx:where>ingredients.item=flour,ingredients.unit=cups</htx:where>
```

This finds recipes where:
- At least one ingredient has `item = "flour"`
- AND at least one ingredient has `unit = "cups"`

Note: These don't have to be the *same* ingredient. For that, see "Deep Path Matching" below.

### Combined with Regular Filters

```html
<htx:where>status=published,ingredients.item=chocolate</htx:where>
```

---

## Advanced: Deep Path Matching

What if you want: "find recipes where a single ingredient has BOTH item=flour AND amount>1"?

This requires keeping the array element in scope:

```sql
SELECT DISTINCT c.*
FROM content c
JOIN content_field_values cfv ON c.id = cfv.content_id
  AND cfv.field_name = 'ingredients'
, json_each(cfv.field_value) AS je
WHERE c.site_id = ?
  AND json_extract(je.value, '$.item') = 'flour'
  AND CAST(json_extract(je.value, '$.amount') AS REAL) > 1;
```

### Proposed Syntax

Group conditions with parentheses:

```html
<htx:where>(ingredients.item=flour,ingredients.amount>1)</htx:where>
```

The parentheses signal: "these conditions apply to the same array element."

Implementation:

```php
// Grouped nested conditions share the same json_each alias
if (preg_match('/^\((.+)\)$/', $condition, $groupMatch)) {
    $groupConditions = explode(',', $groupMatch[1]);
    $builder->whereNestedGroup($groupConditions);
}
```

---

## Deeply Nested Objects

For objects within objects:

```yaml
# Schema
sections:
  field_type: object
  constraints:
    cardinality: many
    schema:
      - field_name: title
        field_type: text
      - field_name: features
        field_type: object
        constraints:
          cardinality: many
          schema:
            - field_name: icon
              field_type: text
            - field_name: label
              field_type: text
```

Query syntax with full path:

```html
<htx:where>sections.features.icon=star</htx:where>
```

SQL generation:

```sql
SELECT DISTINCT c.*
FROM content c
JOIN content_field_values cfv ON c.id = cfv.content_id
  AND cfv.field_name = 'sections'
, json_each(cfv.field_value) AS je_sections
, json_each(json_extract(je_sections.value, '$.features')) AS je_features
WHERE c.site_id = ?
  AND json_extract(je_features.value, '$.icon') = 'star';
```

Each level of nesting adds another `json_each()` join.

---

## Performance Considerations

### The Reality

`json_each()` is a table scan. For each content row, SQLite expands the JSON array and checks every element. This is O(rows × avg_array_length).

For small-to-medium datasets (< 10k content items, < 100 items per array), this is fast enough. SQLite is remarkably efficient.

### When It Gets Slow

If you have:
- Millions of content items
- Arrays with hundreds of elements
- Complex multi-level nesting
- High query volume

...you'll feel it.

### Optimization Options

**1. Computed Columns (SQLite 3.31+)**

```sql
ALTER TABLE content_field_values 
ADD COLUMN extracted_values TEXT 
GENERATED ALWAYS AS (
  (SELECT GROUP_CONCAT(json_extract(value, '$.item'))
   FROM json_each(field_value))
) STORED;

CREATE INDEX idx_extracted ON content_field_values(extracted_values);
```

This pre-extracts searchable values. Queries become simple string matching:

```sql
WHERE extracted_values LIKE '%flour%'
```

**2. Separate Index Table**

Create a denormalized index on write:

```sql
CREATE TABLE object_field_index (
  content_id INTEGER,
  field_name TEXT,
  array_index INTEGER,
  nested_key TEXT,
  nested_value TEXT,
  PRIMARY KEY (content_id, field_name, array_index, nested_key)
);

CREATE INDEX idx_nested_lookup 
ON object_field_index(field_name, nested_key, nested_value);
```

On content save, populate this table. Queries become simple joins.

**3. Full-Text Search**

For text matching across nested fields, SQLite FTS5:

```sql
CREATE VIRTUAL TABLE object_fts USING fts5(
  content_id,
  field_name,
  searchable_text
);
```

---

## Implementation Phases

### Phase 1: Basic Nested Filtering (Ship It)

- Single-level dot notation: `ingredients.item=flour`
- Simple equality operator
- `json_each()` + `json_extract()` queries

### Phase 2: Operators & Grouping

- Comparison operators: `>`, `<`, `>=`, `<=`, `!=`
- LIKE operator: `ingredients.item~%flour%`
- Grouped conditions: `(ingredients.item=flour,ingredients.amount>1)`

### Phase 3: Deep Nesting

- Multi-level paths: `sections.features.icon=star`
- Recursive `json_each()` joins

### Phase 4: Performance (If Needed)

- Index table for hot paths
- Computed columns
- Query analysis tooling

---

## API Examples

### REST API

```bash
# Filter via query params
curl "http://localhost:8080/api/content?type=recipe&where=ingredients.item=flour"

# Filter in POST body
curl -X POST http://localhost:8080/api/content/get \
  -d '{"meta": {"type": "recipe", "where": "ingredients.item=flour"}}'
```

### HTX DSL

```html
<htx:get type="recipe">
  <htx:where>ingredients.item=flour</htx:where>
  <htx:howmany>10</htx:howmany>
  
  <htx:each>
    <article>
      <h2>__title__</h2>
      <htx:nest name="ingredients">
        <span>__amount__ __unit__ __item__</span>
      </htx:nest>
    </article>
  </htx:each>
</htx:get>
```

---

## The Beauty

With nested filtering, object fields become *queryable structured data*. You can:

- Find all recipes with a specific ingredient
- Find all products with a feature containing certain keywords
- Find all articles with a pull quote by a specific author
- Filter on any property at any nesting level

The data lives with its parent (no separate content type), but remains fully searchable.

This is the best of both worlds: **embedded data with relational queryability**.

---

*Phase 3 of #24 — Nested object filtering*
