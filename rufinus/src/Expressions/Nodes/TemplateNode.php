<?php

namespace Rufinus\Expressions\Nodes;

class TemplateNode implements Node
{
    /** @var Node[] */
    public array $children;

    public function __construct(array $children)
    {
        $this->children = $children;
    }
}
