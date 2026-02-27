<?php

namespace Rufinus\Expressions\Exceptions;

class ExpressionParseException extends \RuntimeException
{
    public function __construct(string $message, int $line = 0)
    {
        $prefix = $line > 0 ? "Expression error at line {$line}" : "Expression error";
        parent::__construct("{$prefix}: {$message}");
    }
}
