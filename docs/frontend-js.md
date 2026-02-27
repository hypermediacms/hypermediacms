# Frontend JavaScript Guide

This guide defines how JavaScript is written in the Hypermedia CMS admin UI. It is opinionated by design. Follow these conventions exactly.

## 1. Philosophy

The server owns state. JavaScript owns UI behavior.

- **htmx** handles all server interactions — loading content, navigating, swapping HTML
- **JS** handles local UI state — dropdowns, modals, drag/drop, form field manipulation, visual feedback
- **Two-phase workflow** handles all mutations — save, update, delete go through `prepare` → `execute` via `ApiClient`
- **No frameworks** — vanilla JS with the conventions in this document
- **Explicit over terse** — AI agents are the primary developers; code must be predictable and easy to parse

All shared utilities live in `rufinus/site/public/js/admin.js`. Page-specific logic lives in `<script>` blocks inside `.htx` template files.

## 2. Decision Rule: htmx vs JS

Use this flowchart for every interaction:

```
Does this need server data?
  YES → htmx (hx-get, hx-post, hx-swap)
  NO  → Does this mutate server state?
          YES → ApiClient (two-phase prepare → execute)
          NO  → Vanilla JS (local UI only)
```

**htmx examples**: Loading a page, navigating between views, submitting a contact form, infinite scroll, search-as-you-type with server filtering.

**ApiClient examples**: Saving a form definition, updating a content record, deleting a submission, toggling a published/draft status.

**Vanilla JS examples**: Opening a dropdown, showing a modal, reordering fields via drag/drop, toggling a CSS class, expanding/collapsing a panel, client-side form validation before submit.

## 3. Component Pattern

Components use the registry in `admin.js`. Every component follows this structure:

```javascript
Components.myComponent = {
  init(el) {
    // 1. Query child elements
    var button = el.querySelector('[data-trigger]');
    var panel = el.querySelector('[data-panel]');

    // 2. Set up local state (closure-scoped, never global)
    var isOpen = false;

    // 3. Bind event listeners (on the component root or children)
    button.addEventListener('click', function() {
      isOpen = !isOpen;
      panel.classList.toggle('show', isOpen);
    });
  }
};
```

**HTML usage:**

```html
<div data-component="myComponent">
  <button data-trigger>Toggle</button>
  <div data-panel>Content</div>
</div>
```

**Rules:**
- One component per concern. A `sortable` component handles drag/drop. A `dropdown` component handles open/close. Do not combine unrelated behavior.
- Components auto-initialize on `DOMContentLoaded` and `htmx:afterSwap` — no manual init calls needed.
- Components communicate via `CustomEvent`, never by referencing each other directly:
  ```javascript
  // Emitting
  el.dispatchEvent(new CustomEvent('field-added', { bubbles: true, detail: { field: fieldData } }));

  // Listening (from a parent or document)
  document.addEventListener('field-added', function(e) { /* ... */ });
  ```
- The `el._componentInit` flag prevents double-initialization. The registry handles this automatically.

## 4. State Management

### Local UI state

State lives inside the component's `init()` closure. Never use global variables.

```javascript
// WRONG — global state
var formState = { fields: [], title: '' };

// RIGHT — closure-scoped state
Components.formBuilder = {
  init(el) {
    var state = { fields: [], title: '' };

    // Mutate state through named functions, not direct assignment
    function addField(field) {
      state.fields.push(field);
      markDirty();
      renderFieldList();
    }

    function removeField(index) {
      state.fields.splice(index, 1);
      markDirty();
      renderFieldList();
    }

    // Dirty tracking
    var dirty = false;
    function markDirty() { dirty = true; updateSaveIndicator(); }
    function clearDirty() { dirty = false; updateSaveIndicator(); }
    function isDirty() { return dirty; }

    function updateSaveIndicator() {
      var indicator = el.querySelector('[data-save-indicator]');
      if (!indicator) return;
      indicator.textContent = dirty ? 'Unsaved changes' : 'Saved';
      indicator.classList.toggle('save-indicator--dirty', dirty);
    }
  }
};
```

### Server state

Server state is never cached or duplicated in JS. When you need to mutate server state:

1. Use `ApiClient` to send the mutation
2. On success, either reload the page (`location.reload()`) or let htmx re-fetch the affected region
3. The server response is the source of truth — do not optimistically update the DOM before confirmation

## 5. Two-Phase Save Pattern (ApiClient)

Every server mutation goes through two phases:

1. **Prepare** — `POST /api/content/prepare` with the canonical format. Returns a signed JWT token.
2. **Execute** — `POST /api/content/save|update|delete` with the token. Performs the mutation.

`ApiClient` (defined in `admin.js`) encapsulates this flow. You never need to implement the two-phase handshake manually.

### API Key Handling

The Rufinus `ApiProxy` automatically injects `X-Site-Key` and `X-HTX-Version: 1` headers on every `/api/*` request (see `rufinus/src/Runtime/ApiProxy.php` lines 36-37). **JavaScript never includes API keys.** The only header JS sends is `Content-Type: application/json`.

### Usage

**Save a new record:**

```javascript
try {
  var result = await ApiClient.save('form_definition', {
    title: 'Contact Form',
    slug: 'contact',
    fields: JSON.stringify(fieldArray)
  });
  Toast.success('Form created');
  location.reload();
} catch (err) {
  Toast.error('Failed: ' + err.message);
}
```

**Update an existing record:**

```javascript
try {
  await ApiClient.update('form_definition', recordId, {
    title: newTitle,
    fields: JSON.stringify(fieldArray)
  });
  Toast.success('Saved');
  clearDirty();
} catch (err) {
  Toast.error('Save failed: ' + err.message);
}
```

**Delete a record:**

```javascript
var confirmed = await Modal.confirm('Delete this submission?', { confirmText: 'Delete', danger: true });
if (!confirmed) return;

try {
  await ApiClient.delete('form_submission', recordId);
  Toast.success('Deleted');
  location.reload();
} catch (err) {
  Toast.error('Failed: ' + err.message);
}
```

### Canonical Prepare Format

The prepare request always uses this shape:

```json
{
  "meta": {
    "action": "save|update|delete",
    "type": "content_type_slug",
    "recordId": "123"
  },
  "responseTemplates": []
}
```

Do not send flat keys like `{ "action": "delete", "recordId": "123" }`. The `meta` wrapper is the only supported format.

## 6. Event Delegation

Use container-level event listeners with `data-action` attributes. Never use inline `onclick` handlers.

```html
<!-- WRONG -->
<button onclick="addField('text')">Add Text</button>
<button onclick="addField('email')">Add Email</button>

<!-- RIGHT -->
<div data-component="fieldPalette">
  <button data-action="add-field" data-field-type="text">Add Text</button>
  <button data-action="add-field" data-field-type="email">Add Email</button>
</div>
```

```javascript
Components.fieldPalette = {
  init(el) {
    el.addEventListener('click', function(e) {
      var target = e.target.closest('[data-action]');
      if (!target) return;

      var action = target.dataset.action;

      switch (action) {
        case 'add-field':
          var fieldType = target.dataset.fieldType;
          addField(fieldType);
          break;
        case 'remove-field':
          var index = parseInt(target.dataset.index, 10);
          removeField(index);
          break;
      }
    });
  }
};
```

**Why:** Inline handlers create implicit global function dependencies, break when elements are re-rendered, and are harder for agents to trace. Delegated listeners survive DOM updates and keep behavior co-located with the component definition.

## 7. DOM Updates

### Prefer targeted updates over innerHTML

```javascript
// WRONG — destroys focus, scroll position, event listeners
container.innerHTML = buildFieldListHTML(state.fields);

// RIGHT — update only what changed
function updateFieldLabel(fieldEl, newLabel) {
  fieldEl.querySelector('[data-field-label]').textContent = newLabel;
}
```

### Rebuilding lists

When you need to rebuild an entire list (e.g., after reorder), use `replaceChildren`:

```javascript
function renderFieldList() {
  var items = state.fields.map(function(field, index) {
    var li = document.createElement('li');
    li.className = 'field-item';
    li.dataset.index = index;
    li.draggable = true;

    var label = document.createElement('span');
    label.className = 'field-item__label';
    label.textContent = field.label || 'Untitled';
    li.appendChild(label);

    var removeBtn = document.createElement('button');
    removeBtn.className = 'btn btn--ghost btn--sm';
    removeBtn.dataset.action = 'remove-field';
    removeBtn.dataset.index = index;
    removeBtn.textContent = 'Remove';
    li.appendChild(removeBtn);

    return li;
  });

  fieldListEl.replaceChildren.apply(fieldListEl, items);
}
```

### createElement over string concatenation

```javascript
// WRONG — XSS risk, hard to maintain
html += '<div class="field">' + field.label + '</div>';

// RIGHT — safe, explicit
var div = document.createElement('div');
div.className = 'field';
div.textContent = field.label;  // auto-escapes
```

**Exception:** `Toast.show()` and `Modal.show()` accept HTML strings because their content is controlled by the application, not user input.

## 8. Anti-Patterns

The form builder (`admin/forms/[slug]/edit.htx`) demonstrates what happens when these conventions are not followed. Use it as a reference for what to avoid:

| Anti-pattern | Location | Correct approach |
|---|---|---|
| Global `var formState = { ... }` | edit.htx top of script | Closure-scoped state inside component `init()` |
| `'X-API-Key': 'htx-starter-key-001'` hardcoded | Every fetch call | Use `ApiClient` — proxy handles keys |
| 4 duplicate save functions | `saveForm()`, `autoSave()`, etc. | Single `ApiClient.update()` call |
| `onclick="addField('text')"` | Field palette buttons | `data-action="add-field" data-field-type="text"` |
| `innerHTML` to rebuild entire field list | `renderFields()` | `replaceChildren()` with `createElement` |
| 488 lines of inline JS | `<script>` block | Extract to a component registered in admin.js, or at minimum structure as a self-contained component within the script block |

These anti-patterns are not hypothetical — they exist in the codebase today and will be refactored in a future PR using the patterns documented above.
