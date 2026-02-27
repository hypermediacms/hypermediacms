<?php

namespace Rufinus\Expressions\Nodes;

class BinaryOp implements Node
{
    public string $operator;
    public Node $left;
    public Node $right;

    public function __construct(string $operator, Node $left, Node $right)
    {
        $this->operator = $operator;
        $this->left = $left;
        $this->right = $right;
    }
}
