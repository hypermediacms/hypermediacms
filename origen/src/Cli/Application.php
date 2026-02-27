<?php

namespace Origen\Cli;

use Origen\Container;

class Application
{
    /** @var CommandInterface[] */
    private array $commands = [];

    public function __construct(private Container $container) {}

    public function register(CommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        $args = array_slice($argv, 2);

        if (!$command || $command === 'help' || $command === '--help') {
            $this->showHelp();
            return 0;
        }

        if (!isset($this->commands[$command])) {
            echo "Unknown command: {$command}\n\n";
            $this->showHelp();
            return 1;
        }

        return $this->commands[$command]->run($this->container, $args);
    }

    private function showHelp(): void
    {
        echo "HypermediaCMS - Origen Backend\n";
        echo "Usage: php hcms <command> [args]\n\n";
        echo "Available commands:\n";

        foreach ($this->commands as $name => $command) {
            echo "  " . str_pad($name, 20) . $command->description() . "\n";
        }
        echo "\n";
    }
}
