<?php

namespace Origen\Exceptions;

class SlugConflictException extends \RuntimeException
{
    public string $slug;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
        parent::__construct("The slug \"{$slug}\" is already in use. Please choose a different slug.");
    }
}
