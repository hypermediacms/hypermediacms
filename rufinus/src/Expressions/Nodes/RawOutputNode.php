<?php

namespace Rufinus\Expressions\Nodes;

class RawOutputNode implements Node
{
    public Node $expression;

    public function __construct(Node $expression)
    {
        $this->expression = $expression;
    }
}
