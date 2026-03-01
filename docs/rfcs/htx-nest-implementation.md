# HTX Nest — Implementation Sketch

*Concrete code changes mapped to existing files*

---

## Overview

This document maps the `<htx:nest>` feature to specific files and functions in the codebase. It's a working sketch, not final code.

---

## 1. Schema Changes

### File: `origen/src/Services/SchemaService.php`

Add after `validateRelationshipConstraints()`:

```php
/**
 * Validate object field constraints at schema-definition time.
 */
public function validateObjectConstraints(array $field, int $depth = 0): array
{
    $errors = [];
    $constraints = $field['constraints'] ?? [];
    $maxDepth = 5;

    if ($depth > $maxDepth) {
        $errors[] = "Object nesting exceeds maximum depth of {$maxDepth}.";
        return $errors;
    }

    if (!isset($constraints['schema']) || !is_array($constraints['schema'])) {
        $errors[] = 'Object fields require a "schema" array defining nested fields.';
    }

    if (!isset($constraints['cardinality']) || 
        !in_array($constraints['cardinality'], ['one', 'many'], true)) {
        $errors[] = 'Object fields require cardinality of "one" or "many".';
    }

    // Validate each nested field
    foreach ($constraints['schema'] ?? [] as $i => $nestedField) {
        if (empty($nestedField['field_name'])) {
            $errors[] = "Nested field at index {$i} requires field_name.";
            continue;
        }
        if (empty($nestedField['field_type'])) {
            $errors[] = "Nested field '{$nestedField['field_name']}' requires field_type.";
            continue;
        }

        // Recurse for nested objects
        if ($nestedField['field_type'] === 'object') {
            $nestedErrors = $this->validateObjectConstraints($nestedField, $depth + 1);
            foreach ($nestedErrors as $err) {
                $errors[] = "{$nestedField['field_name']}: {$err}";
            }
        }
    }

    return $errors;
}

/**
 * Validate a value against an object field's nested schema.
 */
public function validateObjectValue(array $field, $value): array
{
    $errors = [];
    $constraints = $field['constraints'] ?? [];
    $cardinality = $constraints['cardinality'] ?? 'one';
    $schema = $constraints['schema'] ?? [];

    if ($value === null || $value === '' || $value === []) {
        // Empty is allowed unless required
        if (!empty($constraints['required'])) {
            $errors[] = 'This field is required.';
        }
        return $errors;
    }

    if ($cardinality === 'one') {
        if (!is_array($value) || isset($value[0])) {
            $errors[] = 'Expected a single object, not an array.';
            return $errors;
        }
        $errors = array_merge($errors, $this->validateObjectItem($schema, $value, ''));
    } else {
        if (!is_array($value)) {
            $errors[] = 'Expected an array of objects.';
            return $errors;
        }
        foreach ($value as $i => $item) {
            $itemErrors = $this->validateObjectItem($schema, $item, "[{$i}]");
            $errors = array_merge($errors, $itemErrors);
        }
    }

    return $errors;
}

private function validateObjectItem(array $schema, $item, string $prefix): array
{
    $errors = [];

    if (!is_array($item)) {
        $errors[] = "{$prefix} must be an object.";
        return $errors;
    }

    foreach ($schema as $fieldDef) {
        $name = $fieldDef['field_name'];
        $type = $fieldDef['field_type'];
        $fieldConstraints = $fieldDef['constraints'] ?? [];
        $value = $item[$name] ?? null;
        $path = $prefix ? "{$prefix}.{$name}" : $name;

        // Required check
        if (!empty($fieldConstraints['required']) && ($value === null || $value === '')) {
            $errors[] = "{$path} is required.";
        }

        // Type-specific validation
        if ($value !== null && $value !== '') {
            if ($type === 'number' && !is_numeric($value)) {
                $errors[] = "{$path} must be a number.";
            }
            if ($type === 'object') {
                $nestedErrors = $this->validateObjectValue($fieldDef, $value);
                foreach ($nestedErrors as $err) {
                    $errors[] = "{$path}: {$err}";
                }
            }
        }
    }

    return $errors;
}
```

### File: `origen/src/Http/Controllers/ContentTypeController.php`

Update `store()` to handle object fields:

```php
// Inside the foreach ($fields as $field) loop, after relationship handling:

if ($field['field_type'] === 'object') {
    $constraints['schema'] = $field['schema'] ?? [];
    $constraints['cardinality'] = $field['cardinality'] ?? 'many';

    $objErrors = $this->schemaService->validateObjectConstraints([
        'constraints' => $constraints,
    ]);
    if (!empty($objErrors)) {
        return Response::json([
            'message' => 'Invalid object field "' . $fieldName . '": ' . implode(' ', $objErrors),
        ], 422);
    }
}
```

---

## 2. Content Storage

### File: `origen/src/Services/ContentService.php`

Objects are stored as JSON strings. The existing `syncFieldValues()` flow handles this:

```php
// In SchemaService::syncFieldValues(), object fields serialize naturally:

public function syncFieldValues(int $contentId, int $siteId, array $values): void
{
    foreach ($values as $fieldName => $fieldValue) {
        // Arrays (including object fields) become JSON
        if (is_array($fieldValue)) {
            $fieldValue = json_encode($fieldValue, JSON_UNESCAPED_SLASHES);
        }

        $this->contentRepo->upsertFieldValue($contentId, $siteId, $fieldName, $fieldValue);
    }
}
```

### File: `origen/src/Http/Controllers/ContentController.php`

Update `get()` to parse object fields back to arrays:

```php
// In the response building loop, after adding field values:

// Parse JSON object fields back to arrays
$schema = $this->schemaService->getSchemaForType($site, $row['type']);
foreach ($schema as $fieldDef) {
    if ($fieldDef['field_type'] === 'object' && isset($responseRow[$fieldDef['field_name']])) {
        $stored = $responseRow[$fieldDef['field_name']];
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $responseRow[$fieldDef['field_name']] = $decoded;
            }
        }
    }
}
```

---

## 3. Template Processing (Rufinus)

### File: `rufinus/src/Executors/GetContentExecutor.php`

Add `processNestBlocks()` method and call it in the hydration flow:

```php
/**
 * Process <htx:nest name="fieldName"> blocks for embedded object rendering.
 *
 * For cardinality=many: iterates over array, renders inner template for each item.
 * For cardinality=one: renders once with the single object's context.
 * Supports nested nests via recursion.
 */
private function processNestBlocks(string $template, array $data): string
{
    return preg_replace_callback(
        '/<htx:nest\s+name="([^"]+)">(.*?)<\/htx:nest>/s',
        function ($matches) use ($data) {
            $fieldName = $matches[1];
            $innerTemplate = $matches[2];

            // Get the nested data
            $nested = $data[$fieldName] ?? null;

            // Handle string (stored JSON that wasn't decoded upstream)
            if (is_string($nested)) {
                $nested = json_decode($nested, true);
            }

            if (empty($nested) || !is_array($nested)) {
                return '';
            }

            // Detect cardinality: if it has sequential numeric keys, it's many
            // If it has string keys (like 'src', 'alt'), it's one
            $isMany = array_keys($nested) === range(0, count($nested) - 1);
            
            if (!$isMany) {
                // Cardinality=one: wrap single object for uniform iteration
                $nested = [$nested];
            }

            $output = '';
            $total = count($nested);

            foreach ($nested as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }

                // Inject loop metadata
                $item['loop'] = [
                    'index' => $i,
                    'count' => $i + 1,
                    'first' => $i === 0,
                    'last' => $i === $total - 1,
                    'length' => $total,
                ];

                // Inject parent reference for bubbling access
                $item['$parent'] = $data;

                // Expression evaluation ({{ if }}, {{ each }}, functions)
                $evaluated = $this->expressionEngine->hasExpressions($innerTemplate)
                    ? $this->expressionEngine->evaluate($innerTemplate, $item)
                    : $innerTemplate;

                // Recurse for nested <htx:nest> blocks
                $evaluated = $this->processNestBlocks($evaluated, $item);

                // Placeholder hydration (__field__)
                $output .= $this->hydrator->hydrate($evaluated, $item);
            }

            return $output;
        },
        $template
    );
}
```

Update `hydrateWithData()` to call it:

```php
private function hydrateWithData(string $template, array $rows, array $responses): string
{
    if (preg_match('/<htx:each>(.*?)<\/htx:each>/s', $template, $matches)) {
        $itemTemplate = $matches[1];
        $itemsHtml = '';
        $hasExpressions = $this->expressionEngine->hasExpressions($itemTemplate);

        foreach ($rows as $row) {
            $evaluated = $hasExpressions
                ? $this->expressionEngine->evaluate($itemTemplate, $row)
                : $itemTemplate;
            
            // Process relationship blocks
            $evaluated = $this->processRelBlocks($evaluated, $row);
            
            // Process nest blocks (NEW)
            $evaluated = $this->processNestBlocks($evaluated, $row);
            
            $itemsHtml .= $this->hydrator->hydrate($evaluated, $row);
        }

        $template = str_replace($matches[0], $itemsHtml, $template);
        $template = preg_replace('/<htx:none>.*?<\/htx:none>/s', '', $template);
    } else {
        $data = $rows[0] ?? [];
        if ($this->expressionEngine->hasExpressions($template)) {
            $template = $this->expressionEngine->evaluate($template, $data);
        }
        $template = $this->processRelBlocks($template, $data);
        
        // Process nest blocks (NEW)
        $template = $this->processNestBlocks($template, $data);
        
        $template = $this->hydrator->hydrate($template, $data);
    }

    return $template;
}
```

---

## 4. YAML Schema Loading

### File: `origen/src/Storage/FlatFile/SchemaFileManager.php`

Object field schemas need to preserve nested structure:

```php
// When loading a YAML schema, object fields should keep their nested schema intact.
// The current implementation already passes through constraints as-is,
// so nested schema arrays should work without changes.

// Example YAML that should parse correctly:
//
// fields:
//   - field_name: gallery
//     field_type: object
//     constraints:
//       cardinality: many
//       schema:
//         - field_name: src
//           field_type: text
//         - field_name: caption
//           field_type: textarea
```

---

## 5. Admin UI (Future)

The admin UI needs a repeater component for cardinality=many object fields. This is a larger undertaking involving:

- `ContentTypeController::renderFieldRow()` — render nested field builder
- `ContentController::renderFieldInput()` — render repeater with add/remove/reorder
- JavaScript for dynamic field management

Rough structure:

```html
<div class="object-field" data-field="gallery">
  <div class="object-items">
    <div class="object-item" data-index="0">
      <div class="item-header">
        <span>Item 1</span>
        <button class="move-up">↑</button>
        <button class="move-down">↓</button>
        <button class="remove-item">×</button>
      </div>
      <div class="item-fields">
        <input name="gallery[0][src]" placeholder="Source URL">
        <input name="gallery[0][alt]" placeholder="Alt text">
        <textarea name="gallery[0][caption]" placeholder="Caption"></textarea>
      </div>
    </div>
    <!-- More items... -->
  </div>
  <button class="add-item">+ Add Item</button>
</div>
```

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/SchemaServiceObjectTest.php

public function test_validates_object_field_requires_schema(): void
{
    $errors = $this->schemaService->validateObjectConstraints([
        'constraints' => ['cardinality' => 'many']
    ]);
    
    $this->assertContains('Object fields require a "schema" array', $errors[0]);
}

public function test_validates_nested_object_fields(): void
{
    $field = [
        'constraints' => [
            'cardinality' => 'many',
            'schema' => [
                ['field_name' => 'title', 'field_type' => 'text'],
                [
                    'field_name' => 'items',
                    'field_type' => 'object',
                    'constraints' => [
                        'cardinality' => 'many',
                        'schema' => [
                            ['field_name' => 'name', 'field_type' => 'text']
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    $errors = $this->schemaService->validateObjectConstraints($field);
    $this->assertEmpty($errors);
}

public function test_enforces_max_nesting_depth(): void
{
    // Build 6-level deep nesting
    $field = $this->buildDeeplyNested(6);
    $errors = $this->schemaService->validateObjectConstraints($field);
    
    $this->assertStringContainsString('maximum depth', $errors[0]);
}
```

### Integration Tests

```php
// tests/Integration/ObjectFieldsTest.php

public function test_stores_and_retrieves_object_field(): void
{
    // Create content with object field
    $response = $this->post('/api/content/save', [
        'title' => 'Test Article',
        'type' => 'article',
        'gallery' => [
            ['src' => '/img/1.jpg', 'caption' => 'First'],
            ['src' => '/img/2.jpg', 'caption' => 'Second'],
        ]
    ]);
    
    $id = $response['id'];
    
    // Retrieve and verify
    $content = $this->get("/api/content?type=article&id={$id}");
    
    $this->assertIsArray($content['rows'][0]['gallery']);
    $this->assertCount(2, $content['rows'][0]['gallery']);
    $this->assertEquals('/img/1.jpg', $content['rows'][0]['gallery'][0]['src']);
}
```

---

## Migration Notes

- No database schema changes required
- Object fields use existing `content_field_values` table
- Existing content unaffected (new field type, not modifying existing)
- Rollback: remove field type support, data remains as JSON strings

---

## Open Questions

1. **Querying nested fields** — Should `?filter[gallery.caption]=sunset` work? Requires SQLite JSON functions or index strategy.

2. **Partial updates** — JSON patch operations (`gallery[0].caption = "new"`) vs full replacement. Full replacement is simpler for v1.

3. **Ordering UI** — Drag-and-drop reordering in admin? Button-based up/down? Both?

4. **Empty vs null** — Is `gallery: []` different from no gallery field? Probably: empty array = intentionally empty, missing = never set.

5. **Validation timing** — Validate on prepare (preview errors) or only on execute (save)?

---

*Implementation target: 2-3 days for schema + storage + template. Admin UI is a separate phase.*
