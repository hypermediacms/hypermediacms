<?php

namespace Rufinus\Expressions\Nodes;

class FieldRef implements Node
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
