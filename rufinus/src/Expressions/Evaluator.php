<?php

namespace Rufinus\Expressions;

use Rufinus\Expressions\Exceptions\ExpressionLimitException;
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

class Evaluator
{
    private const MAX_LOOP_ITERATIONS = 1000;
    private const MAX_NESTING_DEPTH = 10;
    private const MAX_FUNCTION_CALL_DEPTH = 5;
    private const MAX_OUTPUT_SIZE = 1_048_576; // 1 MB

    private FunctionRegistry $functionRegistry;

    /** @var array[] Scope stack — innermost scope on top */
    private array $scopeStack = [];
    private int $loopIterations = 0;
    private int $nestingDepth = 0;
    private int $functionCallDepth = 0;
    private int $outputSize = 0;

    public function __construct(FunctionRegistry $functionRegistry)
    {
        $this->functionRegistry = $functionRegistry;
    }

    /**
     * Evaluate an AST against a data context and return the rendered string.
     */
    public function evaluate(Node $ast, array $data): string
    {
        $this->scopeStack = [$data];
        $this->loopIterations = 0;
        $this->nestingDepth = 0;
        $this->functionCallDepth = 0;
        $this->outputSize = 0;

        return $this->renderNode($ast);
    }

    private function renderNode(Node $node): string
    {
        if ($node instanceof TemplateNode) {
            $output = '';
            foreach ($node->children as $child) {
                $output .= $this->renderNode($child);
            }
            return $output;
        }

        if ($node instanceof TextNode) {
            $this->trackOutput(strlen($node->text));
            return $node->text;
        }

        if ($node instanceof OutputNode) {
            $value = $this->resolveValue($node->expression);
            $str = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
            $this->trackOutput(strlen($str));
            return $str;
        }

        if ($node instanceof RawOutputNode) {
            $value = $this->resolveValue($node->expression);
            $str = (string) ($value ?? '');
            $this->trackOutput(strlen($str));
            return $str;
        }

        if ($node instanceof IfNode) {
            return $this->evaluateIf($node);
        }

        if ($node instanceof EachNode) {
            return $this->evaluateEach($node);
        }

        return '';
    }

    private function evaluateIf(IfNode $node): string
    {
        $this->nestingDepth++;
        if ($this->nestingDepth > self::MAX_NESTING_DEPTH) {
            throw new ExpressionLimitException('Maximum nesting depth of ' . self::MAX_NESTING_DEPTH . ' exceeded');
        }

        $output = '';

        if ($this->isTruthy($this->resolveValue($node->condition))) {
            foreach ($node->body as $child) {
                $output .= $this->renderNode($child);
            }
        } else {
            $matched = false;
            foreach ($node->elseifClauses as $clause) {
                if ($this->isTruthy($this->resolveValue($clause['condition']))) {
                    foreach ($clause['body'] as $child) {
                        $output .= $this->renderNode($child);
                    }
                    $matched = true;
                    break;
                }
            }

            if (!$matched && $node->elseBody !== null) {
                foreach ($node->elseBody as $child) {
                    $output .= $this->renderNode($child);
                }
            }
        }

        $this->nestingDepth--;
        return $output;
    }

    private function evaluateEach(EachNode $node): string
    {
        $this->nestingDepth++;
        if ($this->nestingDepth > self::MAX_NESTING_DEPTH) {
            throw new ExpressionLimitException('Maximum nesting depth of ' . self::MAX_NESTING_DEPTH . ' exceeded');
        }

        $iterable = $this->resolveValue($node->iterable);

        // Coerce to array
        if (!is_array($iterable)) {
            if ($iterable === null || $iterable === '') {
                $iterable = [];
            } else {
                $iterable = [$iterable];
            }
        }

        $total = count($iterable);
        $output = '';

        foreach ($iterable as $i => $element) {
            if ($this->loopIterations >= self::MAX_LOOP_ITERATIONS) {
                throw new ExpressionLimitException('Maximum loop iterations of ' . self::MAX_LOOP_ITERATIONS . ' exceeded');
            }

            $loopMeta = [
                'index' => $i,
                'count' => $i + 1,
                'first' => $i === 0,
                'last' => $i === $total - 1,
            ];

            // Push scope
            $this->scopeStack[] = [
                $node->variableName => $element,
                'loop' => $loopMeta,
            ];

            foreach ($node->body as $child) {
                $output .= $this->renderNode($child);
            }

            // Pop scope
            array_pop($this->scopeStack);
            $this->loopIterations++;
        }

        $this->nestingDepth--;
        return $output;
    }

    /**
     * Resolve a node to its PHP value.
     */
    private function resolveValue(Node $node): mixed
    {
        if ($node instanceof StringLiteral) {
            return $node->value;
        }

        if ($node instanceof NumberLiteral) {
            return $node->value;
        }

        if ($node instanceof BooleanLiteral) {
            return $node->value;
        }

        if ($node instanceof NullLiteral) {
            return null;
        }

        if ($node instanceof FieldRef) {
            return $this->lookup($node->name);
        }

        if ($node instanceof DotAccess) {
            $obj = $this->lookup($node->object);
            if (is_array($obj)) {
                return $obj[$node->property] ?? null;
            }
            return null;
        }

        if ($node instanceof FunctionCall) {
            return $this->evaluateFunction($node);
        }

        if ($node instanceof BinaryOp) {
            return $this->evaluateBinaryOp($node);
        }

        if ($node instanceof UnaryOp) {
            if ($node->operator === 'not') {
                return !$this->isTruthy($this->resolveValue($node->operand));
            }
        }

        return null;
    }

    private function evaluateFunction(FunctionCall $node): mixed
    {
        $this->functionCallDepth++;
        if ($this->functionCallDepth > self::MAX_FUNCTION_CALL_DEPTH) {
            throw new ExpressionLimitException('Maximum function call depth of ' . self::MAX_FUNCTION_CALL_DEPTH . ' exceeded');
        }

        $args = [];
        foreach ($node->arguments as $argNode) {
            $args[] = $this->resolveValue($argNode);
        }

        $result = $this->functionRegistry->call($node->name, $args);

        $this->functionCallDepth--;
        return $result;
    }

    private function evaluateBinaryOp(BinaryOp $node): mixed
    {
        // Short-circuit for and/or
        if ($node->operator === 'and') {
            $leftVal = $this->resolveValue($node->left);
            if (!$this->isTruthy($leftVal)) {
                return false;
            }
            return $this->isTruthy($this->resolveValue($node->right));
        }

        if ($node->operator === 'or') {
            $leftVal = $this->resolveValue($node->left);
            if ($this->isTruthy($leftVal)) {
                return true;
            }
            return $this->isTruthy($this->resolveValue($node->right));
        }

        $left = $this->resolveValue($node->left);
        $right = $this->resolveValue($node->right);

        return match ($node->operator) {
            '==' => (string) $left === (string) $right,
            '!=' => (string) $left !== (string) $right,
            '>' => $this->numericCompare($left, $right, fn($a, $b) => $a > $b),
            '<' => $this->numericCompare($left, $right, fn($a, $b) => $a < $b),
            '>=' => $this->numericCompare($left, $right, fn($a, $b) => $a >= $b),
            '<=' => $this->numericCompare($left, $right, fn($a, $b) => $a <= $b),
            default => null,
        };
    }

    private function numericCompare(mixed $left, mixed $right, callable $compare): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return $compare((float) $left, (float) $right);
        }
        return $compare((string) $left, (string) $right);
    }

    /**
     * Look up a variable name from the scope stack.
     */
    private function lookup(string $name): mixed
    {
        // Walk from innermost (top) to outermost (bottom)
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopeStack[$i])) {
                return $this->scopeStack[$i][$name];
            }
        }
        return null;
    }

    /**
     * Determine if a value is truthy.
     */
    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === '0' || $value === 'false' ||
            $value === false || $value === 0 || $value === 0.0) {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }
        return true;
    }

    private function trackOutput(int $bytes): void
    {
        $this->outputSize += $bytes;
        if ($this->outputSize > self::MAX_OUTPUT_SIZE) {
            throw new ExpressionLimitException('Output size exceeds maximum of ' . self::MAX_OUTPUT_SIZE . ' bytes');
        }
    }
}
