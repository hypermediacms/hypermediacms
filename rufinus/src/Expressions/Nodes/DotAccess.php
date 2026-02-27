<?php

namespace Rufinus\Expressions\Nodes;

class DotAccess implements Node
{
    public string $object;
    public string $property;

    public function __construct(string $object, string $property)
    {
        $this->object = $object;
        $this->property = $property;
    }
}
