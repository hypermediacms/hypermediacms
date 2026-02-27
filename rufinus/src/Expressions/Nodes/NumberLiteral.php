<?php

namespace Rufinus\Expressions\Nodes;

class NumberLiteral implements Node
{
    public float $value;

    public function __construct(float $value)
    {
        $this->value = $value;
    }
}
