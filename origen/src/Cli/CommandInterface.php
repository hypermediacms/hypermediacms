<?php

namespace Origen\Cli;

use Origen\Container;

interface CommandInterface
{
    public function name(): string;
    public function description(): string;
    public function run(Container $container, array $args): int;
}
