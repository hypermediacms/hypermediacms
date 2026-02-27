<?php

namespace Rufinus\Expressions\Nodes;

class OutputNode implements Node
{
    public Node $expression;

    public function __construct(Node $expression)
    {
        $this->expression = $expression;
    }
}
