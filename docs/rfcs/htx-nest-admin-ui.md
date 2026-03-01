# Admin UI for Object Fields

*Repeaters without the framework*

---

## The Problem

Object fields are arrays of structured data. The admin needs to:

1. Display existing items
2. Add new items
3. Remove items
4. Reorder items (maybe)
5. Edit fields within each item

Most CMSes solve this with React/Vue components, drag-and-drop libraries, and a mountain of JavaScript. We're not most CMSes.

---

## The Hypermedia Way

htmx gives us everything we need. The server renders HTML. The browser updates fragments. No client-side state management. No build step. No framework.

The pattern: **progressive disclosure with server-rendered fragments**.

---

## UI Structure

### Collapsed View (List Mode)

When an object field is rendered, show a compact list of items:

```
┌─────────────────────────────────────────────────────────────┐
│ Ingredients                                          [+ Add]│
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 2 1/4 cups all-purpose flour                   [Edit][×]│ │
│ └─────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 1 tsp baking soda                              [Edit][×]│ │
│ └─────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 1 cup butter, softened                         [Edit][×]│ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

Each item shows a **summary** — the first text field, or a concatenation of key fields. The summary is schema-defined or auto-derived.

### Expanded View (Edit Mode)

Clicking "Edit" expands that item inline:

```
┌─────────────────────────────────────────────────────────────┐
│ Ingredients                                          [+ Add]│
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 2 1/4 cups all-purpose flour                   [Edit][×]│ │
│ └─────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ ▼ Editing item 2                                        │ │
│ │ ┌─────────────────────────────────────────────────────┐ │ │
│ │ │ Amount: [1        ]                                 │ │ │
│ │ │ Unit:   [tsp      ]                                 │ │ │
│ │ │ Item:   [baking soda                              ] │ │ │
│ │ │                                      [Done][Cancel] │ │ │
│ │ └─────────────────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 1 cup butter, softened                         [Edit][×]│ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

The "Done" button collapses back to summary view. All state lives in hidden inputs — no JavaScript state management needed.

---

## Implementation: Server-Side Rendering

### Hidden State Pattern

The actual data lives in hidden inputs with JSON payloads. The visible UI is purely for display.

```html
<div class="object-field" data-field="ingredients">
  <!-- The actual data (posted with form) -->
  <input type="hidden" 
         name="ingredients" 
         id="ingredients-data"
         value='[{"amount":"2 1/4","unit":"cups","item":"flour"},...]'>
  
  <!-- Visual representation (htmx-swapped) -->
  <div class="object-items" id="ingredients-items">
    <!-- Server-rendered item cards -->
  </div>
  
  <button type="button"
          hx-get="/admin/object-field/ingredients/add"
          hx-target="#ingredients-items"
          hx-swap="beforeend"
          class="btn btn-secondary">
    + Add Ingredient
  </button>
</div>
```

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/admin/object-field/{field}/add` | GET | Return HTML for a new blank item |
| `/admin/object-field/{field}/edit/{index}` | GET | Return expanded edit form for item |
| `/admin/object-field/{field}/summary/{index}` | GET | Return collapsed summary for item |
| `/admin/object-field/{field}/remove/{index}` | DELETE | Remove item, return updated list |

All endpoints receive the current JSON state via `hx-vals` or query params. The server parses, modifies, and returns the updated HTML + hidden input.

### Add Item Flow

```html
<button type="button"
        hx-get="/admin/object-field/ingredients/add"
        hx-vals='js:{"current": document.getElementById("ingredients-data").value}'
        hx-target="#ingredients-items"
        hx-swap="beforeend">
  + Add Ingredient
</button>
```

Server response:

```html
<div class="object-item" data-index="3">
  <div class="item-summary" style="display:none;">
    <span class="summary-text">(new item)</span>
    <button hx-get="..." class="btn-sm">Edit</button>
    <button hx-delete="..." class="btn-sm">×</button>
  </div>
  <div class="item-form">
    <input name="_obj_ingredients_3_amount" value="">
    <input name="_obj_ingredients_3_unit" value="">
    <input name="_obj_ingredients_3_item" value="">
    <button type="button" onclick="collapseObjectItem(this)">Done</button>
  </div>
</div>
<script>
  // Update hidden JSON immediately
  updateObjectFieldData('ingredients');
</script>
```

### The Minimal JavaScript

We need exactly one utility function:

```javascript
function updateObjectFieldData(fieldName) {
  const container = document.getElementById(fieldName + '-items');
  const items = [];
  
  container.querySelectorAll('.object-item').forEach((item, index) => {
    const obj = {};
    item.querySelectorAll('[name^="_obj_' + fieldName + '_"]').forEach(input => {
      // Extract field name from _obj_ingredients_0_amount -> amount
      const parts = input.name.split('_');
      const key = parts[parts.length - 1];
      obj[key] = input.value;
    });
    items.push(obj);
  });
  
  document.getElementById(fieldName + '-data').value = JSON.stringify(items);
}

function collapseObjectItem(btn) {
  const item = btn.closest('.object-item');
  item.querySelector('.item-form').style.display = 'none';
  item.querySelector('.item-summary').style.display = 'flex';
  
  // Update summary text
  const inputs = item.querySelectorAll('input');
  const values = Array.from(inputs).map(i => i.value).filter(v => v).join(' ');
  item.querySelector('.summary-text').textContent = values || '(empty)';
  
  // Sync to hidden field
  const fieldName = item.closest('.object-field').dataset.field;
  updateObjectFieldData(fieldName);
}
```

That's it. ~30 lines of JavaScript. No framework. No build step.

---

## Reordering (Optional)

Drag-and-drop is nice but adds complexity. For V1: up/down buttons.

```html
<div class="item-actions">
  <button hx-post="/admin/object-field/ingredients/move/2/up" 
          hx-target="#ingredients-items"
          hx-swap="innerHTML"
          class="btn-icon">↑</button>
  <button hx-post="/admin/object-field/ingredients/move/2/down"
          hx-target="#ingredients-items"  
          hx-swap="innerHTML"
          class="btn-icon">↓</button>
</div>
```

Server swaps array indices and returns the full list with updated positions.

---

## Schema-Driven Summary

The schema can specify which field(s) to use for the summary display:

```yaml
fields:
  - field_name: ingredients
    field_type: object
    constraints:
      cardinality: many
      schema:
        - field_name: amount
          field_type: text
        - field_name: unit
          field_type: text
        - field_name: item
          field_type: text
    ui_hints:
      summary_template: "{amount} {unit} {item}"  # Custom summary
      # Or: summary_fields: [item]  # Just show the item name
```

If no summary template is specified, concatenate all text fields.

---

## Nested Objects

Objects within objects follow the same pattern, recursively:

```html
<div class="object-field" data-field="sections">
  <div class="object-item" data-index="0">
    <div class="item-form">
      <input name="_obj_sections_0_title" value="Features">
      
      <!-- Nested object field -->
      <div class="object-field" data-field="sections_0_features">
        <input type="hidden" name="_obj_sections_0_features" value='[...]'>
        <div class="object-items" id="sections_0_features-items">
          <!-- Nested items -->
        </div>
      </div>
    </div>
  </div>
</div>
```

The naming convention `sections_0_features` keeps the hierarchy flat while preserving structure.

---

## CSS (Minimal)

```css
.object-field {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.object-item {
  background: #f8fafc;
  border-radius: 6px;
  padding: 0.75rem;
  margin-bottom: 0.5rem;
}

.item-summary {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.summary-text {
  flex: 1;
  font-size: 0.9rem;
}

.item-form {
  display: grid;
  gap: 0.5rem;
}

.item-form input {
  padding: 0.5rem;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}
```

---

## The Tradeoffs

### What we gain

- **No build step** — Edit the controller, refresh the browser
- **Server authority** — All state lives on the server
- **Progressive enhancement** — Works without JavaScript (just less pretty)
- **Simplicity** — ~100 lines of PHP, ~30 lines of JS, ~30 lines of CSS

### What we lose

- **Drag-and-drop** — Up/down buttons aren't as slick
- **Instant feedback** — Each action round-trips to the server (~50ms on localhost)
- **Offline editing** — Requires server connection

These tradeoffs align with the Hypermedia CMS philosophy. We're not building Google Docs. We're building a CMS that a single developer can understand in a day.

---

## Implementation Checklist

1. [ ] Add `/admin/object-field/*` routes
2. [ ] Create `ObjectFieldController` with add/edit/summary/remove/move
3. [ ] Update `ContentController::renderFieldInput()` for object fields
4. [ ] Add `updateObjectFieldData()` utility to admin JS
5. [ ] Add CSS for object field styling
6. [ ] Support nested objects (recursive rendering)
7. [ ] Schema-driven summary templates

---

*Phase 2 of #24 — Admin UI for nested object fields*
