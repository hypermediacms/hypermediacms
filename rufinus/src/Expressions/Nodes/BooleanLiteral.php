<?php

namespace Rufinus\Expressions\Nodes;

class BooleanLiteral implements Node
{
    public bool $value;

    public function __construct(bool $value)
    {
        $this->value = $value;
    }
}
