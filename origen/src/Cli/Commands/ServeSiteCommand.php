<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Container;

class ServeSiteCommand implements CommandInterface
{
    public function name(): string
    {
        return 'serve:site';
    }

    public function description(): string
    {
        return 'Start the Rufinus site development server';
    }

    public function run(Container $container, array $args): int
    {
        $host = $args[0] ?? '127.0.0.1';
        $port = $args[1] ?? '8081';
        $docroot = dirname(__DIR__, 3) . '/rufinus/site';

        if (!is_dir($docroot)) {
            echo "Error: Rufinus site directory not found at {$docroot}\n";
            return 1;
        }

        if (!file_exists($docroot . '/serve.php')) {
            echo "Error: serve.php not found in {$docroot}\n";
            return 1;
        }

        echo "Rufinus site server starting on http://{$host}:{$port}\n";
        echo "Site root: {$docroot}\n";
        echo "Press Ctrl+C to stop.\n\n";

        passthru("php -S {$host}:{$port} {$docroot}/serve.php");
        return 0;
    }
}
