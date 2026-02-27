<?php

namespace Rufinus\Expressions\Nodes;

class IfNode implements Node
{
    public Node $condition;

    /** @var Node[] */
    public array $body;

    /** @var array<array{condition: Node, body: Node[]}> */
    public array $elseifClauses;

    /** @var Node[]|null */
    public ?array $elseBody;

    public function __construct(Node $condition, array $body, array $elseifClauses = [], ?array $elseBody = null)
    {
        $this->condition = $condition;
        $this->body = $body;
        $this->elseifClauses = $elseifClauses;
        $this->elseBody = $elseBody;
    }
}
