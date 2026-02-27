<?php

namespace Rufinus\Expressions\Nodes;

class StringLiteral implements Node
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
