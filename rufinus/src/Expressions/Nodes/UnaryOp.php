<?php

namespace Rufinus\Expressions\Nodes;

class UnaryOp implements Node
{
    public string $operator;
    public Node $operand;

    public function __construct(string $operator, Node $operand)
    {
        $this->operator = $operator;
        $this->operand = $operand;
    }
}
