<?php

namespace Rufinus\Expressions;

use Rufinus\Expressions\Exceptions\ExpressionParseException;
use Rufinus\Expressions\Nodes\{
    Node,
    TemplateNode,
    TextNode,
    OutputNode,
    RawOutputNode,
    IfNode,
    EachNode,
    FieldRef,
    DotAccess,
    StringLiteral,
    NumberLiteral,
    BooleanLiteral,
    NullLiteral,
    BinaryOp,
    UnaryOp,
    FunctionCall
};

class Parser
{
    private const MAX_NESTING_DEPTH = 10;

    private FunctionRegistry $functionRegistry;
    private int $nestingDepth = 0;

    public function __construct(FunctionRegistry $functionRegistry)
    {
        $this->functionRegistry = $functionRegistry;
    }

    /**
     * Parse lexer segments into an AST.
     */
    public function parse(array $segments): TemplateNode
    {
        $this->nestingDepth = 0;
        $pos = 0;
        $children = $this->parseBody($segments, $pos, null);
        return new TemplateNode($children);
    }

    /**
     * Parse body segments until a stop keyword is encountered.
     *
     * @param string|null $stopAt Expected block_close keyword, or null for top-level
     * @return Node[]
     */
    private function parseBody(array $segments, int &$pos, ?string $stopAt): array
    {
        $nodes = [];

        while ($pos < count($segments)) {
            $seg = $segments[$pos];

            if ($seg['type'] === 'text') {
                $nodes[] = new TextNode($seg['content']);
                $pos++;
                continue;
            }

            if ($seg['type'] === 'expression') {
                $nodes[] = new OutputNode($this->parseExpression($seg['content']));
                $pos++;
                continue;
            }

            if ($seg['type'] === 'raw_expression') {
                $nodes[] = new RawOutputNode($this->parseExpression($seg['content']));
                $pos++;
                continue;
            }

            if ($seg['type'] === 'block_close') {
                if ($stopAt !== null && $seg['keyword'] === $stopAt) {
                    // Don't consume — let the caller handle it
                    return $nodes;
                }
                throw new ExpressionParseException(
                    "Unexpected closing tag: {$seg['keyword']}" . ($stopAt ? " (expected {$stopAt})" : '')
                );
            }

            if ($seg['type'] === 'block_open') {
                $keyword = $seg['keyword'];

                // elif / else are consumed by the if handler — stop here
                if ($keyword === 'elif' || $keyword === 'else') {
                    if ($stopAt !== null) {
                        return $nodes;
                    }
                    throw new ExpressionParseException("Unexpected {$keyword} without matching if");
                }

                if ($keyword === 'if') {
                    $nodes[] = $this->parseIf($segments, $pos);
                    continue;
                }

                if ($keyword === 'each') {
                    $nodes[] = $this->parseEach($segments, $pos);
                    continue;
                }

                throw new ExpressionParseException("Unknown block keyword: {$keyword}");
            }

            $pos++;
        }

        if ($stopAt !== null) {
            throw new ExpressionParseException("Unclosed block — expected {$stopAt}");
        }

        return $nodes;
    }

    /**
     * Parse an if/elif/else/endif structure.
     */
    private function parseIf(array $segments, int &$pos): IfNode
    {
        $this->nestingDepth++;
        if ($this->nestingDepth > self::MAX_NESTING_DEPTH) {
            throw new ExpressionParseException('Maximum nesting depth of ' . self::MAX_NESTING_DEPTH . ' exceeded');
        }

        // Current segment is block_open(if, condition)
        $condition = $this->parseExpression($segments[$pos]['content']);
        $pos++;

        // parseBody stops AT (without consuming) endif, elif, or else
        $body = $this->parseBody($segments, $pos, 'endif');

        $elseifClauses = [];
        $elseBody = null;

        // $pos now points at elif, else, or endif
        while ($pos < count($segments)) {
            $seg = $segments[$pos];

            if ($seg['type'] === 'block_close' && $seg['keyword'] === 'endif') {
                $pos++; // consume endif
                break;
            }

            if ($seg['type'] === 'block_open' && $seg['keyword'] === 'elif') {
                $elifCondition = $this->parseExpression($seg['content']);
                $pos++; // consume elif
                $elifBody = $this->parseBody($segments, $pos, 'endif');
                $elseifClauses[] = ['condition' => $elifCondition, 'body' => $elifBody];
                continue;
            }

            if ($seg['type'] === 'block_open' && $seg['keyword'] === 'else') {
                $pos++; // consume else
                $elseBody = $this->parseBody($segments, $pos, 'endif');
                // Now $pos should be at endif
                if ($pos < count($segments) && $segments[$pos]['type'] === 'block_close' && $segments[$pos]['keyword'] === 'endif') {
                    $pos++; // consume endif
                }
                break;
            }

            throw new ExpressionParseException("Unexpected segment in if block");
        }

        $this->nestingDepth--;
        return new IfNode($condition, $body, $elseifClauses, $elseBody);
    }

    /**
     * Parse an each/endeach structure.
     */
    private function parseEach(array $segments, int &$pos): EachNode
    {
        $this->nestingDepth++;
        if ($this->nestingDepth > self::MAX_NESTING_DEPTH) {
            throw new ExpressionParseException('Maximum nesting depth of ' . self::MAX_NESTING_DEPTH . ' exceeded');
        }

        $content = $segments[$pos]['content'];
        $pos++;

        // Parse "VAR in EXPR"
        if (!preg_match('/^(\w+)\s+in\s+(.+)$/s', $content, $m)) {
            throw new ExpressionParseException("Invalid each syntax: expected 'variable in expression'");
        }

        $variableName = $m[1];
        $iterable = $this->parseExpression(trim($m[2]));

        $body = $this->parseBody($segments, $pos, 'endeach');

        // Consume the endeach close tag
        if ($pos < count($segments) && $segments[$pos]['type'] === 'block_close' && $segments[$pos]['keyword'] === 'endeach') {
            $pos++;
        }

        $this->nestingDepth--;
        return new EachNode($variableName, $iterable, $body);
    }

    // =========================================================================
    // Expression string parser (recursive descent)
    // =========================================================================

    /** @var array Expression tokens */
    private array $tokens = [];
    private int $tokenPos = 0;

    /**
     * Parse an expression string into an AST node.
     */
    public function parseExpression(string $expr): Node
    {
        $this->tokens = $this->tokenizeExpression($expr);
        $this->tokenPos = 0;

        if (empty($this->tokens)) {
            throw new ExpressionParseException("Empty expression");
        }

        $node = $this->parseOrExpr();

        if ($this->tokenPos < count($this->tokens)) {
            $remaining = $this->tokens[$this->tokenPos]['value'] ?? '?';
            throw new ExpressionParseException("Unexpected token: {$remaining}");
        }

        return $node;
    }

    private function parseOrExpr(): Node
    {
        $left = $this->parseAndExpr();

        while ($this->matchKeyword('or')) {
            $right = $this->parseAndExpr();
            $left = new BinaryOp('or', $left, $right);
        }

        return $left;
    }

    private function parseAndExpr(): Node
    {
        $left = $this->parseComparison();

        while ($this->matchKeyword('and')) {
            $right = $this->parseComparison();
            $left = new BinaryOp('and', $left, $right);
        }

        return $left;
    }

    private function parseComparison(): Node
    {
        $left = $this->parseUnary();

        $operators = ['==', '!=', '>=', '<=', '>', '<'];
        foreach ($operators as $op) {
            if ($this->matchOperator($op)) {
                $right = $this->parseUnary();
                return new BinaryOp($op, $left, $right);
            }
        }

        return $left;
    }

    private function parseUnary(): Node
    {
        if ($this->matchKeyword('not')) {
            $operand = $this->parseUnary();
            return new UnaryOp('not', $operand);
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $token = $this->currentToken();

        if ($token === null) {
            throw new ExpressionParseException("Unexpected end of expression");
        }

        // Parenthesized expression
        if ($token['type'] === 'paren' && $token['value'] === '(') {
            $this->tokenPos++;
            $node = $this->parseOrExpr();
            $this->expectToken('paren', ')');
            return $node;
        }

        // String literal
        if ($token['type'] === 'string') {
            $this->tokenPos++;
            return new StringLiteral($token['value']);
        }

        // Number literal
        if ($token['type'] === 'number') {
            $this->tokenPos++;
            return new NumberLiteral((float) $token['value']);
        }

        // Boolean literal
        if ($token['type'] === 'keyword' && ($token['value'] === 'true' || $token['value'] === 'false')) {
            $this->tokenPos++;
            return new BooleanLiteral($token['value'] === 'true');
        }

        // Null literal
        if ($token['type'] === 'keyword' && $token['value'] === 'null') {
            $this->tokenPos++;
            return new NullLiteral();
        }

        // Identifier — could be function call, dot access, or field ref
        if ($token['type'] === 'identifier') {
            $name = $token['value'];
            $this->tokenPos++;

            // Function call: identifier(
            $next = $this->currentToken();
            if ($next !== null && $next['type'] === 'paren' && $next['value'] === '(') {
                return $this->parseFunctionCall($name);
            }

            // Dot access: identifier.identifier
            if ($next !== null && $next['type'] === 'dot') {
                $this->tokenPos++; // consume dot
                $propToken = $this->currentToken();
                if ($propToken === null || $propToken['type'] !== 'identifier') {
                    throw new ExpressionParseException("Expected property name after '.'");
                }
                $this->tokenPos++;
                return new DotAccess($name, $propToken['value']);
            }

            return new FieldRef($name);
        }

        throw new ExpressionParseException("Unexpected token: {$token['value']}");
    }

    private function parseFunctionCall(string $name): FunctionCall
    {
        if (!$this->functionRegistry->has($name)) {
            throw new ExpressionParseException("Unknown function: {$name}");
        }

        $this->tokenPos++; // consume (

        $args = [];
        $first = true;

        while (true) {
            $token = $this->currentToken();
            if ($token !== null && $token['type'] === 'paren' && $token['value'] === ')') {
                $this->tokenPos++;
                break;
            }

            if (!$first) {
                $this->expectToken('comma', ',');
            }
            $first = false;

            $args[] = $this->parseOrExpr();
        }

        return new FunctionCall($name, $args);
    }

    // =========================================================================
    // Token helpers
    // =========================================================================

    private function currentToken(): ?array
    {
        return $this->tokens[$this->tokenPos] ?? null;
    }

    private function matchKeyword(string $keyword): bool
    {
        $token = $this->currentToken();
        if ($token !== null && $token['type'] === 'keyword' && $token['value'] === $keyword) {
            $this->tokenPos++;
            return true;
        }
        return false;
    }

    private function matchOperator(string $op): bool
    {
        $token = $this->currentToken();
        if ($token !== null && $token['type'] === 'operator' && $token['value'] === $op) {
            $this->tokenPos++;
            return true;
        }
        return false;
    }

    private function expectToken(string $type, string $value): void
    {
        $token = $this->currentToken();
        if ($token === null || $token['type'] !== $type || $token['value'] !== $value) {
            $found = $token ? $token['value'] : 'end of expression';
            throw new ExpressionParseException("Expected '{$value}', got '{$found}'");
        }
        $this->tokenPos++;
    }

    // =========================================================================
    // Expression tokenizer
    // =========================================================================

    /**
     * Split an expression string into tokens.
     */
    private function tokenizeExpression(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $pos = 0;

        while ($pos < $len) {
            // Skip whitespace
            if (ctype_space($expr[$pos])) {
                $pos++;
                continue;
            }

            // String literal
            if ($expr[$pos] === '"') {
                $start = $pos;
                $pos++; // skip opening quote
                $value = '';
                while ($pos < $len && $expr[$pos] !== '"') {
                    if ($expr[$pos] === '\\' && $pos + 1 < $len) {
                        $value .= $expr[$pos + 1];
                        $pos += 2;
                        continue;
                    }
                    $value .= $expr[$pos];
                    $pos++;
                }
                if ($pos >= $len) {
                    throw new ExpressionParseException("Unterminated string literal");
                }
                $pos++; // skip closing quote
                $tokens[] = ['type' => 'string', 'value' => $value];
                continue;
            }

            // Multi-character operators
            if ($pos + 1 < $len) {
                $two = substr($expr, $pos, 2);
                if (in_array($two, ['==', '!=', '>=', '<='], true)) {
                    $tokens[] = ['type' => 'operator', 'value' => $two];
                    $pos += 2;
                    continue;
                }
            }

            // Single-character operators
            if ($expr[$pos] === '>' || $expr[$pos] === '<') {
                $tokens[] = ['type' => 'operator', 'value' => $expr[$pos]];
                $pos++;
                continue;
            }

            // Parentheses
            if ($expr[$pos] === '(' || $expr[$pos] === ')') {
                $tokens[] = ['type' => 'paren', 'value' => $expr[$pos]];
                $pos++;
                continue;
            }

            // Comma
            if ($expr[$pos] === ',') {
                $tokens[] = ['type' => 'comma', 'value' => ','];
                $pos++;
                continue;
            }

            // Dot
            if ($expr[$pos] === '.') {
                // Check if it's a decimal number (previous token was a number and next is digit)
                $tokens[] = ['type' => 'dot', 'value' => '.'];
                $pos++;
                continue;
            }

            // Number
            if (ctype_digit($expr[$pos])) {
                $start = $pos;
                while ($pos < $len && ctype_digit($expr[$pos])) {
                    $pos++;
                }
                // Check for decimal part
                if ($pos < $len && $expr[$pos] === '.' && $pos + 1 < $len && ctype_digit($expr[$pos + 1])) {
                    $pos++; // skip dot
                    // Remove the dot token if we just added one
                    while ($pos < $len && ctype_digit($expr[$pos])) {
                        $pos++;
                    }
                }
                $tokens[] = ['type' => 'number', 'value' => substr($expr, $start, $pos - $start)];
                continue;
            }

            // Identifier or keyword
            if (ctype_alpha($expr[$pos]) || $expr[$pos] === '_') {
                $start = $pos;
                while ($pos < $len && (ctype_alnum($expr[$pos]) || $expr[$pos] === '_')) {
                    $pos++;
                }
                $word = substr($expr, $start, $pos - $start);

                $keywords = ['and', 'or', 'not', 'true', 'false', 'null'];
                if (in_array($word, $keywords, true)) {
                    $tokens[] = ['type' => 'keyword', 'value' => $word];
                } else {
                    $tokens[] = ['type' => 'identifier', 'value' => $word];
                }
                continue;
            }

            throw new ExpressionParseException("Unexpected character: {$expr[$pos]}");
        }

        return $tokens;
    }
}
