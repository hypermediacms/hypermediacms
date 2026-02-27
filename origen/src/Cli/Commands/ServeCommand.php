<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Config;
use Origen\Container;

class ServeCommand implements CommandInterface
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Start the development server';
    }

    public function run(Container $container, array $args): int
    {
        $config = $container->make(Config::class);
        $host = $args[0] ?? $config->get('server_host', '127.0.0.1');
        $port = $args[1] ?? $config->get('server_port', '8080');
        $docroot = dirname(__DIR__, 3) . '/public';

        echo "Origen server starting on http://{$host}:{$port}\n";
        echo "Document root: {$docroot}\n";
        echo "Press Ctrl+C to stop.\n\n";

        passthru("php -S {$host}:{$port} -t {$docroot}");
        return 0;
    }
}
