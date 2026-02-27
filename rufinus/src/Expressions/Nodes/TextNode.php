<?php

namespace Rufinus\Expressions\Nodes;

class TextNode implements Node
{
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
