<?php

namespace Rufinus\Expressions;

class ExpressionEngine
{
    private Lexer $lexer;
    private Parser $parser;
    private Evaluator $evaluator;

    public function __construct()
    {
        $this->lexer = new Lexer();
        $registry = new FunctionRegistry();
        $registry->registerDefaults();
        $this->parser = new Parser($registry);
        $this->evaluator = new Evaluator($registry);
    }

    /**
     * Evaluate a template string with the given data context.
     */
    public function evaluate(string $template, array $data): string
    {
        $segments = $this->lexer->tokenize($template);
        $ast = $this->parser->parse($segments);
        return $this->evaluator->evaluate($ast, $data);
    }

    /**
     * Check if a template contains expression syntax.
     */
    public function hasExpressions(string $template): bool
    {
        return str_contains($template, '{{');
    }
}
