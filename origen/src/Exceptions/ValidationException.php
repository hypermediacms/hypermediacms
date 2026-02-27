<?php

namespace Origen\Exceptions;

class ValidationException extends \RuntimeException
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed.')
    {
        $this->errors = $errors;
        parent::__construct($message);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
