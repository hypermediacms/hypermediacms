<?php

namespace Rufinus\Expressions\Nodes;

class FunctionCall implements Node
{
    public string $name;

    /** @var Node[] */
    public array $arguments;

    public function __construct(string $name, array $arguments)
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
