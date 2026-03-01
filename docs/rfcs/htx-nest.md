# Towards Nested Objects in HTX

*An exploration of `<htx:nest>` and what it means for content modeling*

---

## The Shape of Content

Content isn't flat. Never has been.

A recipe has ingredients. An article has pull quotes. A product page has feature cards, testimonials, specifications. We've been flattening these into relationship tables and foreign keys since the relational database was invented — but that's an implementation detail leaking into our mental model.

What if your CMS understood that some data *belongs inside* other data?

---

## Relationships vs. Nests

HTX already has relationships. They're powerful:

```html
<htx:get type="article" slug="my-post">
  <h1>__title__</h1>
  <htx:rel name="author">
    <p class="byline">By __title__</p>
  </htx:rel>
</htx:get>
```

The author exists independently. They have their own slug, their own page, their own life in the CMS. The article merely *points to* them.

But what about the article's gallery? Those images don't exist on their own. They're not content — they're *structure*. They live and die with their parent.

Enter the nest.

---

## What's in a Nest?

A nest is embedded structured data. It has a schema, but no independent identity. It's JSON with guardrails.

**In the schema:**

```yaml
# schemas/starter/article.yaml
fields:
  - field_name: gallery
    field_type: object
    constraints:
      cardinality: many
      schema:
        - field_name: src
          field_type: text
          constraints:
            required: true
        - field_name: alt
          field_type: text
        - field_name: caption
          field_type: textarea
```

We call it `object` in the schema because that's what it is — a JSON object with typed fields. Technical precision for the technical layer.

**In the template:**

```html
<htx:get type="article" slug="my-post">
  <h1>__title__</h1>
  
  <div class="gallery">
    <htx:nest name="gallery">
      <figure>
        <img src="__src__" alt="__alt__">
        <figcaption>__caption__</figcaption>
      </figure>
    </htx:nest>
  </div>
</htx:get>
```

We call it `<htx:nest>` because that's what you *do* — nest content inside content. Intuitive naming for the authoring layer.

---

## The Beauty of Scoped Context

Inside a nest block, placeholders resolve against the nested object. No prefixing, no dot-notation gymnastics:

```html
<!-- Outside the nest: article context -->
<h1>__title__</h1>

<htx:nest name="gallery">
  <!-- Inside: each gallery item's context -->
  <img src="__src__">           <!-- gallery[i].src -->
  <p>__caption__</p>            <!-- gallery[i].caption -->
  
  <!-- Need the parent? -->
  <small>From: __$parent.title__</small>
</htx:nest>
```

The scope stack handles this naturally. When you enter a nest, you push a new context. Placeholders resolve innermost-first, bubbling up to the parent when needed.

---

## Single Objects: The Quiet Case

Not every nest is a list. Sometimes you just want one structured object:

```yaml
fields:
  - field_name: hero
    field_type: object
    constraints:
      cardinality: one
      schema:
        - field_name: image
          field_type: text
        - field_name: headline
          field_type: text
        - field_name: cta_text
          field_type: text
        - field_name: cta_url
          field_type: text
```

```html
<htx:nest name="hero">
  <section class="hero" style="background-image: url(__image__)">
    <h1>__headline__</h1>
    <a href="__cta_url__" class="btn">__cta_text__</a>
  </section>
</htx:nest>
```

For cardinality=one, the nest block renders once (or not at all if empty). No iteration, just scoped access.

Or skip the block entirely and use dot notation:

```html
<img src="__hero.image__" alt="__hero.headline__">
```

Your choice. Both work.

---

## Nesting the Nests

Here's where it gets interesting. Objects can contain objects:

```yaml
fields:
  - field_name: sections
    field_type: object
    constraints:
      cardinality: many
      schema:
        - field_name: heading
          field_type: text
        - field_name: body
          field_type: textarea
        - field_name: features
          field_type: object
          constraints:
            cardinality: many
            schema:
              - field_name: icon
                field_type: text
              - field_name: title
                field_type: text
              - field_name: description
                field_type: textarea
```

```html
<htx:nest name="sections">
  <section>
    <h2>__heading__</h2>
    <p>__body__</p>
    
    <div class="features">
      <htx:nest name="features">
        <div class="feature">
          <i class="icon-__icon__"></i>
          <h3>__title__</h3>
          <p>__description__</p>
        </div>
      </htx:nest>
    </div>
  </section>
</htx:nest>
```

Each nesting level pushes its own scope. The evaluator already handles this — we're just giving it new shapes to chew on.

---

## Expressions Inside Nests

The expression engine works seamlessly:

```html
<htx:nest name="gallery">
  {{ if caption }}
    <figcaption>{{ truncate(caption, 100) }}</figcaption>
  {{ endif }}
  
  {{ if loop.first }}
    <span class="featured">★</span>
  {{ endif }}
</htx:nest>
```

Loop metadata (`loop.index`, `loop.first`, `loop.last`) comes free — same as `{{ each }}` in expressions.

---

## Storage: The Boring Brilliance

Under the hood, nests are just JSON in `content_field_values`:

```sql
INSERT INTO content_field_values (content_id, site_id, field_name, field_value)
VALUES (42, 1, 'gallery', '[
  {"src": "/img/sunset.jpg", "alt": "Sunset", "caption": "Golden hour"},
  {"src": "/img/mountain.jpg", "alt": "Mountain", "caption": "Summit view"}
]');
```

No schema migrations. No new tables. No relationship resolution queries. The data lives with its parent, atomic and complete.

Validation happens at write time against the nested schema. Type safety without the ceremony.

---

## What Nests Are Not

**Nests are not relationships.** If the data has its own identity — its own page, its own lifecycle — use a relationship.

**Nests are not components.** They're data, not presentation. The same nest can render as a grid, a carousel, or a list depending on the template.

**Nests are not infinitely deep.** We'll cap nesting depth (say, 5 levels) to keep things sane. If you need deeper, you probably need to rethink your model.

---

## The API Shape

Query responses include nests inline:

```json
{
  "rows": [{
    "id": 42,
    "title": "My Article",
    "gallery": [
      {"src": "/img/1.jpg", "alt": "One", "caption": "First"},
      {"src": "/img/2.jpg", "alt": "Two", "caption": "Second"}
    ]
  }]
}
```

Updates accept the full nested structure:

```json
{
  "title": "Updated Article",
  "gallery": [
    {"src": "/img/new.jpg", "alt": "New", "caption": "Replaced"}
  ]
}
```

Full replacement, atomic commits. No partial patches for now — that's a v2 concern.

---

## Implementation: Where We Touch

### 1. Schema Layer

`SchemaService` gains `validateObjectConstraints()`:

```php
public function validateObjectConstraints(array $field): array
{
    $errors = [];
    $constraints = $field['constraints'] ?? [];

    if (!isset($constraints['schema']) || !is_array($constraints['schema'])) {
        $errors[] = 'Object fields require a schema definition.';
    }

    if (!isset($constraints['cardinality']) || 
        !in_array($constraints['cardinality'], ['one', 'many'], true)) {
        $errors[] = 'Object fields require cardinality of "one" or "many".';
    }

    // Recurse for nested objects
    foreach ($constraints['schema'] ?? [] as $nested) {
        if ($nested['field_type'] === 'object') {
            $errors = array_merge($errors, $this->validateObjectConstraints($nested));
        }
    }

    return $errors;
}
```

### 2. Content Layer

`ContentService` stores object fields as JSON, validates on write:

```php
private function processObjectField(array $schema, $value): string
{
    $cardinality = $schema['constraints']['cardinality'] ?? 'one';
    $nestedSchema = $schema['constraints']['schema'] ?? [];
    
    if ($cardinality === 'one') {
        $this->validateObjectItem($nestedSchema, $value);
        return json_encode($value);
    }
    
    // cardinality=many
    foreach ($value as $item) {
        $this->validateObjectItem($nestedSchema, $item);
    }
    return json_encode(array_values($value));
}
```

### 3. Template Layer

`GetContentExecutor` gains `processNestBlocks()`:

```php
private function processNestBlocks(string $template, array $data): string
{
    return preg_replace_callback(
        '/<htx:nest\s+name="([^"]+)">(.*?)<\/htx:nest>/s',
        function ($matches) use ($data) {
            $fieldName = $matches[1];
            $innerTemplate = $matches[2];

            $nested = $data[$fieldName] ?? null;
            if (empty($nested)) {
                return '';
            }

            // Cardinality=one: single object with keys
            if (isset($nested['src']) || !isset($nested[0])) {
                // It's a single object, not an array of objects
                $nested = [$nested];
            }

            $output = '';
            $total = count($nested);

            foreach ($nested as $i => $item) {
                if (!is_array($item)) continue;
                
                // Inject loop metadata
                $item['loop'] = [
                    'index' => $i,
                    'count' => $i + 1,
                    'first' => $i === 0,
                    'last' => $i === $total - 1,
                ];
                
                // Inject parent reference
                $item['$parent'] = $data;

                $evaluated = $this->expressionEngine->hasExpressions($innerTemplate)
                    ? $this->expressionEngine->evaluate($innerTemplate, $item)
                    : $innerTemplate;
                
                // Recurse for nested nests
                $evaluated = $this->processNestBlocks($evaluated, $item);
                
                $output .= $this->hydrator->hydrate($evaluated, $item);
            }

            return $output;
        },
        $template
    );
}
```

The key insight: nests and relationships share the same output shape but differ in lifecycle. Both produce nested data in the API response. Templates can iterate both the same way. The difference is *storage and ownership*.

---

## A Concrete Example

Let's build a recipe content type:

```yaml
# schemas/starter/recipe.yaml
fields:
  - field_name: prep_time
    field_type: number
  - field_name: cook_time
    field_type: number
  - field_name: servings
    field_type: number
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
          constraints:
            required: true
  - field_name: steps
    field_type: object
    constraints:
      cardinality: many
      schema:
        - field_name: instruction
          field_type: textarea
          constraints:
            required: true
        - field_name: tip
          field_type: text
```

Template:

```html
<htx:get type="recipe" slug="grandmas-cookies">
  <article class="recipe">
    <h1>__title__</h1>
    
    <div class="meta">
      <span>Prep: __prep_time__ min</span>
      <span>Cook: __cook_time__ min</span>
      <span>Serves: __servings__</span>
    </div>
    
    <h2>Ingredients</h2>
    <ul class="ingredients">
      <htx:nest name="ingredients">
        <li>__amount__ __unit__ __item__</li>
      </htx:nest>
    </ul>
    
    <h2>Instructions</h2>
    <ol class="steps">
      <htx:nest name="steps">
        <li>
          __instruction__
          {{ if tip }}
            <em class="tip">💡 __tip__</em>
          {{ endif }}
        </li>
      </htx:nest>
    </ol>
  </article>
</htx:get>
```

No ingredient content type. No step content type. No junction tables. Just a recipe with its ingredients and steps, living together as they should.

---

## What's Next?

1. **Schema validation** — Enforce nested schemas on content save
2. **Admin UI** — Repeater fields for cardinality=many objects
3. **Query filtering** — Maybe `?filter[ingredients.item]=flour` someday
4. **Patch operations** — Update `steps[2].instruction` without rewriting the whole array

But first: ship the simple version. Full replacement, JSON storage, scoped templates. See what breaks, what sings, what surprises us.

---

## The Takeaway

Nests let you model content the way you think about it. Structured data that belongs together, stored together, rendered together. No artificial separation, no relationship overhead, no foreign keys for data that was never foreign.

Sometimes the simplest solution is letting things stay where they belong.

---

*HTX Nest — RFC Draft, February 2026*
