<?php

namespace Rufinus\Expressions\Nodes;

class EachNode implements Node
{
    public string $variableName;
    public Node $iterable;

    /** @var Node[] */
    public array $body;

    public function __construct(string $variableName, Node $iterable, array $body)
    {
        $this->variableName = $variableName;
        $this->iterable = $iterable;
        $this->body = $body;
    }
}
