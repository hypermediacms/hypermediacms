# EdgeHTX Expression Engine Internals

## Architecture

The EdgeHTX expression engine is a three-stage pipeline that transforms template syntax into rendered HTML:

```
Template String  -->  [Lexer]  -->  Token Stream  -->  [Parser]  -->  AST  -->  [Evaluator]  -->  HTML
```

All components live under `Rufinus\Expressions`. The `ExpressionEngine` class is a simple facade that orchestrates the pipeline:

```php
$engine = new ExpressionEngine();
$html   = $engine->evaluate($template, $data);
```

Internally, `evaluate()` calls:

1. `Lexer::tokenize($template)` -- raw string to token stream
2. `Parser::parse($tokens)` -- token stream to AST
3. `Evaluator::evaluate($ast, $data)` -- AST to HTML string

A convenience method `hasExpressions()` performs a cheap `str_contains($template, '{{')` check to skip the pipeline entirely for templates with no expression syntax.

---

## Stage 1: Lexer

**File:** `rufinus/src/Expressions/Lexer.php`

The lexer scans the template string and splits it into segments (tokens). Each segment has a `type` and a `content` field.

### Token Types

| Type | Trigger | Description |
|------|---------|-------------|
| `text` | Anything outside `{{ }}` | Literal HTML/text content |
| `expression` | `{{ expr }}` | Escaped output expression |
| `raw_expression` | `{{! expr }}` | Unescaped (raw HTML) output |
| `block_open` | `{{ if ... }}`, `{{ each ... }}`, `{{ elif ... }}`, `{{ else }}` | Control flow opening tag |
| `block_close` | `{{ endif }}`, `{{ endeach }}` | Control flow closing tag |

Block tokens also carry a `keyword` field (`if`, `elif`, `else`, `each`, `endif`, `endeach`).

### How Tokenization Works

1. Scan forward for `{{`.
2. Everything before `{{` becomes a `text` token.
3. Find the matching `}}` via `findClosingBraces()`, which respects quoted strings so `"a {{ b }}"` inside an expression does not split prematurely.
4. If the expression starts with `!`, strip it and mark as `raw_expression`.
5. Classify the trimmed expression body via `classifyExpression()`:
   - Regex matches for keywords (`if`, `elif`, `else`, `each VAR in EXPR`, `endif`, `endeach`) produce `block_open` or `block_close`.
   - Everything else is an `expression` (or `raw_expression`).
6. Repeat until end of template.

### Limits

- **MAX_EXPRESSION_LENGTH = 2000** -- Any single `{{ }}` block exceeding 2000 characters throws `ExpressionParseException`.

### Example

Input:
```
Hello {{ user.name }}!{{ if admin }}<b>Admin</b>{{ endif }}
```

Output tokens:
```
text:            "Hello "
expression:      "user.name"
text:            "!"
block_open:      keyword="if", content="admin"
text:            "<b>Admin</b>"
block_close:     keyword="endif"
```

---

## Stage 2: Parser

**File:** `rufinus/src/Expressions/Parser.php`

The parser converts the token stream into an Abstract Syntax Tree (AST) using recursive descent. There are two parsing levels:

1. **Template-level** -- handles block structure (if/each nesting)
2. **Expression-level** -- handles operators, function calls, and literals within `{{ }}`

### Template-Level Parsing

`parse(segments)` calls `parseBody()`, which iterates through tokens and builds structure:

- `text` --> `TextNode`
- `expression` --> `OutputNode` wrapping a parsed expression
- `raw_expression` --> `RawOutputNode` wrapping a parsed expression
- `block_open` keyword=`if` --> calls `parseIf()` to build `IfNode`
- `block_open` keyword=`each` --> calls `parseEach()` to build `EachNode`
- `block_close` matching the expected stop keyword --> returns

**`parseIf()`** builds an `IfNode` with:
- `condition` -- the parsed expression from the `if` content
- `body` -- nodes between `if` and the next `elif`/`else`/`endif`
- `elseifClauses` -- array of `{condition, body}` pairs
- `elseBody` -- nodes in the `else` branch (or null)

**`parseEach()`** extracts the variable name and iterable expression from the syntax `each VAR in EXPR`:
- Regex: `/^(\w+)\s+in\s+(.+)$/`
- Builds `EachNode` with `variableName`, `iterable` (parsed expression), and `body`

### Expression-Level Parsing (Operator Precedence)

When the parser encounters an expression string (from `{{ }}` content), it tokenizes it into micro-tokens and parses using recursive descent with this precedence (lowest to highest):

| Level | Parser Method | Operators |
|-------|---------------|-----------|
| 1 (lowest) | `parseOrExpr()` | `or` |
| 2 | `parseAndExpr()` | `and` |
| 3 | `parseComparison()` | `==` `!=` `>` `<` `>=` `<=` |
| 4 | `parseUnary()` | `not` |
| 5 (highest) | `parsePrimary()` | literals, identifiers, function calls, parens |

### `parsePrimary()` Outcomes

| Input Pattern | Node Type |
|---------------|-----------|
| `(expr)` | Recursively parsed inner expression |
| `"text"` | `StringLiteral` |
| `123` or `45.67` | `NumberLiteral` (stored as float) |
| `true` / `false` | `BooleanLiteral` |
| `null` | `NullLiteral` |
| `func(a, b)` | `FunctionCall` with resolved arguments |
| `object.property` | `DotAccess` |
| `field` | `FieldRef` |

### Expression Micro-Tokenizer

`tokenizeExpression()` splits an expression string into:

| Token Type | Examples |
|------------|----------|
| `string` | `"hello world"` |
| `number` | `42`, `3.14` |
| `identifier` | `title`, `user_name` |
| `keyword` | `and`, `or`, `not`, `true`, `false`, `null` |
| `operator` | `==`, `!=`, `>`, `<`, `>=`, `<=` |
| `paren` | `(`, `)` |
| `dot` | `.` |
| `comma` | `,` |

### Limits

- **MAX_NESTING_DEPTH = 10** -- Nested if/each blocks beyond 10 levels throw `ExpressionParseException`.

---

## Stage 3: Evaluator

**File:** `rufinus/src/Expressions/Evaluator.php`

The evaluator walks the AST and produces the final HTML string. It maintains a **scope stack** for variable lookup and enforces resource limits.

### Node Dispatch

`renderNode()` routes each node type to its handler:

| Node Type | Behavior |
|-----------|----------|
| `TemplateNode` | Concatenate all rendered children |
| `TextNode` | Output literal text, track output size |
| `OutputNode` | Resolve expression, HTML-escape with `htmlspecialchars()`, output |
| `RawOutputNode` | Resolve expression, output without escaping |
| `IfNode` | Evaluate condition; render matching branch |
| `EachNode` | Iterate collection; render body per element |

### Value Resolution

`resolveValue()` evaluates expression nodes to PHP values:

| Node Type | Resolution |
|-----------|------------|
| `StringLiteral` | `$node->value` |
| `NumberLiteral` | `$node->value` (float) |
| `BooleanLiteral` | `$node->value` (bool) |
| `NullLiteral` | `null` |
| `FieldRef` | Scope stack lookup by name |
| `DotAccess` | Scope lookup for object, then array key access for property |
| `FunctionCall` | Resolve args, call function via registry |
| `BinaryOp` | Evaluate binary operation |
| `UnaryOp` | Evaluate unary operation |

### Scope Stack

The evaluator maintains an array of scopes, searched from innermost (top) to outermost (bottom). The initial scope is the data array passed to `evaluate()`.

Each `{{ each }}` block pushes a new scope containing:
- The loop variable (e.g., `item` in `each item in items`)
- A `loop` metadata object

```php
$scope = [
    'item' => $currentElement,
    'loop' => [
        'index' => 0,     // 0-based
        'count' => 1,     // 1-based
        'first' => true,
        'last'  => false,
    ],
];
```

After the loop body renders, the scope is popped. This means inner loops can shadow outer variable names, and `loop.index` always refers to the nearest enclosing loop.

### Binary Operations

**Short-circuit evaluation** for logical operators:
- `and` -- if left is falsy, return `false` immediately (right is never evaluated)
- `or` -- if left is truthy, return `true` immediately

**Comparison operators:**
- `==`, `!=` -- string comparison (both sides coerced to string)
- `>`, `<`, `>=`, `<=` -- numeric comparison if both sides are numeric, otherwise string comparison

### Truthiness

The evaluator treats these values as **falsy**:
- `null`
- `''` (empty string)
- `'0'` (string zero)
- `'false'` (string "false")
- `false`
- `0` (integer)
- `0.0` (float)
- `[]` (empty array)

Everything else is truthy.

### Resource Limits

| Constant | Value | Purpose |
|----------|-------|---------|
| `MAX_LOOP_ITERATIONS` | 1,000 | Prevents infinite/runaway loops |
| `MAX_NESTING_DEPTH` | 10 | Prevents stack overflow from deep if/each nesting |
| `MAX_FUNCTION_CALL_DEPTH` | 5 | Prevents deeply nested function calls |
| `MAX_OUTPUT_SIZE` | 1,048,576 (1 MB) | Prevents memory exhaustion |

All limits throw `ExpressionLimitException` when exceeded.

---

## AST Node Types

All nodes implement the `Node` marker interface (no methods). Located in `Rufinus\Expressions\Nodes`.

### Structure Nodes

| Node | Properties | Purpose |
|------|------------|---------|
| `TemplateNode` | `children: Node[]` | Root node; contains all top-level elements |
| `TextNode` | `text: string` | Literal HTML/text |
| `OutputNode` | `expression: Node` | Escaped output (`{{ }}`) |
| `RawOutputNode` | `expression: Node` | Raw output (`{{! }}`) |
| `IfNode` | `condition: Node`, `body: Node[]`, `elseifClauses: array`, `elseBody: ?Node[]` | Conditional block |
| `EachNode` | `variableName: string`, `iterable: Node`, `body: Node[]` | Loop block |

### Expression Nodes

| Node | Properties | Purpose |
|------|------------|---------|
| `FieldRef` | `name: string` | Variable reference |
| `DotAccess` | `object: string`, `property: string` | Nested property access |
| `StringLiteral` | `value: string` | String constant |
| `NumberLiteral` | `value: float` | Numeric constant |
| `BooleanLiteral` | `value: bool` | Boolean constant |
| `NullLiteral` | (none) | Null constant |
| `BinaryOp` | `operator: string`, `left: Node`, `right: Node` | Binary operation |
| `UnaryOp` | `operator: string`, `operand: Node` | Unary operation |
| `FunctionCall` | `name: string`, `arguments: Node[]` | Function invocation |

---

## Function Registry

**File:** `rufinus/src/Expressions/FunctionRegistry.php`

The registry maps function names to PHP callables. `registerDefaults()` loads all four built-in function groups. Custom functions can be added via `register(name, callable)`.

When the evaluator encounters a `FunctionCall` node, it resolves all argument nodes to values, then calls `$registry->call($name, $args)`. If the function is not registered, `ExpressionParseException` is thrown.

### Built-in Functions (37 total)

#### String Functions (14)

| Function | Signature | Description |
|----------|-----------|-------------|
| `truncate` | `(string, int, ?string)` | Truncate to length with optional suffix (default `...`) |
| `uppercase` | `(string)` | Multibyte uppercase |
| `lowercase` | `(string)` | Multibyte lowercase |
| `capitalize` | `(string)` | Capitalize first character |
| `trim` | `(string)` | Strip whitespace |
| `replace` | `(string, string, string)` | Substring replacement |
| `contains` | `(string, string)` | Substring check (returns bool) |
| `starts_with` | `(string, string)` | Prefix check (returns bool) |
| `length` | `(mixed)` | Array count or string length |
| `default` | `(mixed, mixed)` | Return fallback if value is null/empty/false |
| `slug` | `(string)` | URL-safe slug (lowercase, hyphens, alphanumeric) |
| `split` | `(string, string)` | Split string into array |
| `join` | `(array, string)` | Join array into string |
| `md` | `(string)` | Inline Markdown to HTML (bold, italic, code, links) |

#### Date Functions (6)

| Function | Signature | Description |
|----------|-----------|-------------|
| `format_date` | `(string, string)` | PHP `date()` format |
| `time_ago` | `(string)` | Relative time ("3 hours ago") |
| `days_since` | `(string)` | Days since date (integer) |
| `is_past` | `(string)` | Is date in the past? |
| `is_future` | `(string)` | Is date in the future? |
| `year` | `(string)` | Extract 4-digit year |

All date functions accept timestamps or `strtotime()`-compatible strings.

#### Number Functions (7)

| Function | Signature | Description |
|----------|-----------|-------------|
| `round` | `(float, ?int)` | Round to N decimals (default 0) |
| `floor` | `(float)` | Round down |
| `ceil` | `(float)` | Round up |
| `abs` | `(float)` | Absolute value |
| `clamp` | `(float, float, float)` | Constrain to [min, max] |
| `number_format` | `(float, ?int, ?string)` | Format with separators |
| `percent` | `(float, float)` | `(value / total) * 100`; returns 0 if total is 0 |

#### Array / Utility Functions (10)

| Function | Signature | Description |
|----------|-----------|-------------|
| `empty` | `(mixed)` | Checks null, `""`, `0`, `false`, `[]` |
| `defined` | `(mixed)` | Checks value is not null |
| `count` | `(mixed)` | Array count or string length; scalars return 0 |
| `first` | `(array)` | First element or null |
| `last` | `(array)` | Last element or null |
| `reverse` | `(array)` | Reverse order |
| `sort` | `(array)` | Sort ascending |
| `unique` | `(array)` | Deduplicate (reindexed) |
| `slice` | `(array, int, ?int)` | Extract sub-array |
| `in_list` | `(string, string)` | Check if value is in comma-separated list |

---

## Hydrator

**File:** `rufinus/src/Services/Hydrator.php`

The Hydrator handles `__placeholder__` replacement (the double-underscore syntax), which operates independently from the expression engine. In the full pipeline, expression evaluation happens first, then hydration replaces remaining placeholders with API response data.

### Placeholder Syntax

| Syntax | Behavior |
|--------|----------|
| `__field__` | Replace with HTML-escaped value from data |
| `__object.property__` | Dot-notation: `$data['object']['property']` |
| `\__field__` | Escaped: outputs literal `__field__` text |
| `__body__` | Auto-swaps with `body_html` if present in data |

### Trusted HTML Fields

These fields are inserted **without** HTML escaping because Origen sanitizes them:

- `body_html`
- `status_options`
- `type_options`
- `custom_fields_html`

All other fields are escaped via `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.

### Hydration Process

1. **Extract escaped placeholders** -- find `\__word__` patterns, replace with HTML comment markers (`<!--ESCAPED_0-->`)
2. **Swap body/body_html** -- if data has both `body` and `body_html`, substitute `body_html` for `__body__` references
3. **Replace simple placeholders** -- regex match `__field__`, look up in data, escape (or not for trusted fields)
4. **Resolve dot-notation** -- regex match `__a.b__`, look up nested data
5. **Restore escaped placeholders** -- swap comment markers back to literal `__word__` text

---

## Exception Types

**Namespace:** `Rufinus\Expressions\Exceptions`

### `ExpressionParseException`

Thrown during lexing or parsing for syntax errors:

- Unclosed expression (missing `}}`)
- Expression exceeds maximum length
- Unexpected token during parsing
- Unknown function name
- Invalid `each` syntax (missing `in` keyword)
- Max nesting depth exceeded (parser-level)

Constructor accepts a message and optional line number.

### `ExpressionLimitException`

Thrown during evaluation when resource limits are exceeded:

- Maximum nesting depth exceeded
- Maximum loop iterations exceeded
- Maximum function call depth exceeded
- Output size exceeds maximum (1 MB)

---

## Integration: EdgeHTX Orchestrator

**File:** `rufinus/src/EdgeHTX.php`

`EdgeHTX` is the top-level class that ties everything together. Its constructor takes a central API URL and site key, and creates:

- `DSLParser` -- parses `.htx` files into meta directives + template
- `CentralApiClient` -- HTTP client for Origen API
- `Hydrator` -- placeholder replacement
- `ExpressionEngine` -- this expression system
- Three executors (`GetContentExecutor`, `SetContentExecutor`, `DeleteContentExecutor`)

The full rendering pipeline for a page request:

1. **Route resolution** -- `RequestHandler` maps URL to `.htx` file
2. **DSL parsing** -- `DSLParser` extracts meta directives and template HTML
3. **API request** -- executor sends meta directives to Origen, gets back data
4. **Expression evaluation** -- `ExpressionEngine::evaluate($template, $data)` processes `{{ }}` syntax
5. **Placeholder hydration** -- `Hydrator::hydrate($result, $data)` replaces `__field__` tokens
6. **Layout wrapping** -- layouts are applied from innermost to outermost
7. **HTML response** -- final HTML returned to client

---

## Complete Example: From Template to Output

### Input Template

```html
<h1>{{ uppercase(title) }}</h1>
{{ if not empty(tags) }}
  <div class="tags">
    {{ each tag in tags }}
      <span class="tag {{ if loop.first }}first{{ endif }}">{{ tag }}</span>
    {{ endeach }}
  </div>
{{ endif }}
<p>Published {{ time_ago(created_at) }}</p>
```

### Data

```php
$data = [
    'title'      => 'Hello World',
    'tags'       => ['php', 'htmx', 'cms'],
    'created_at' => '2026-02-25 10:00:00',
];
```

### Stage 1: Lexer Output

```
text:            "<h1>"
expression:      "uppercase(title)"
text:            "</h1>\n"
block_open:      keyword="if", content="not empty(tags)"
text:            "\n  <div class=\"tags\">\n    "
block_open:      keyword="each", content="tag in tags"
text:            "\n      <span class=\"tag "
block_open:      keyword="if", content="loop.first"
text:            "first"
block_close:     keyword="endif"
text:            "\">"
expression:      "tag"
text:            "</span>\n    "
block_close:     keyword="endeach"
text:            "\n  </div>\n"
block_close:     keyword="endif"
text:            "\n<p>Published "
expression:      "time_ago(created_at)"
text:            "</p>"
```

### Stage 2: Parser Output (AST)

```
TemplateNode
  TextNode("<h1>")
  OutputNode
    FunctionCall("uppercase", [FieldRef("title")])
  TextNode("</h1>\n")
  IfNode
    condition: UnaryOp("not", FunctionCall("empty", [FieldRef("tags")]))
    body:
      TextNode("\n  <div ...>\n    ")
      EachNode
        variableName: "tag"
        iterable: FieldRef("tags")
        body:
          TextNode("\n      <span class=\"tag ")
          IfNode
            condition: DotAccess("loop", "first")
            body: [TextNode("first")]
          TextNode("\">")
          OutputNode(FieldRef("tag"))
          TextNode("</span>\n    ")
      TextNode("\n  </div>\n")
  TextNode("\n<p>Published ")
  OutputNode
    FunctionCall("time_ago", [FieldRef("created_at")])
  TextNode("</p>")
```

### Stage 3: Evaluator Output

```html
<h1>HELLO WORLD</h1>
  <div class="tags">
      <span class="tag first">php</span>
      <span class="tag ">htmx</span>
      <span class="tag ">cms</span>
  </div>
<p>Published 1 day ago</p>
```

The evaluator:
1. Calls `uppercase("Hello World")` --> `"HELLO WORLD"` (HTML-escaped)
2. Evaluates `not empty(["php","htmx","cms"])` --> `not false` --> `true` (enters if-body)
3. Iterates `tags` array, pushing scope `{tag: "php", loop: {index:0, count:1, first:true, last:false}}` etc.
4. For the inner if, `loop.first` is `true` on first iteration only
5. Calls `time_ago("2026-02-25 10:00:00")` --> `"1 day ago"`

---

## Safety Model

The expression engine is designed to be safe for user-authored templates:

- **No arbitrary code execution** -- only registered functions can be called; there is no `eval()` or dynamic dispatch.
- **HTML escaping by default** -- `{{ }}` always escapes output. Raw output requires explicit `{{! }}`.
- **Resource limits at every level** -- expression length, nesting depth, loop iterations, function call depth, and total output size are all bounded.
- **Scope isolation** -- each loop iteration gets its own scope; inner scopes cannot modify outer data.
- **Trusted HTML allowlist** -- only specific fields known to be pre-sanitized by Origen bypass escaping in the Hydrator.
