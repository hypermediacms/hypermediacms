<?php

namespace Origen\Cli\Commands;

use Origen\Cli\CommandInterface;
use Origen\Config;
use Origen\Container;

class ServeAllCommand implements CommandInterface
{
    public function name(): string
    {
        return 'serve:all';
    }

    public function description(): string
    {
        return 'Start both Origen API and Rufinus site servers';
    }

    public function run(Container $container, array $args): int
    {
        $config = $container->make(Config::class);

        $origenHost = $config->get('server_host', '127.0.0.1');
        $origenPort = $config->get('server_port', '8080');
        $origenDocroot = dirname(__DIR__, 3) . '/public';

        $siteHost = '127.0.0.1';
        $sitePort = '8081';
        $siteDocroot = dirname(__DIR__, 4) . '/rufinus/site';

        if (!is_dir($siteDocroot)) {
            echo "Error: Rufinus site directory not found at {$siteDocroot}\n";
            return 1;
        }

        echo "Starting both servers...\n";
        echo "  Origen API:    http://{$origenHost}:{$origenPort}\n";
        echo "  Rufinus Site:  http://{$siteHost}:{$sitePort}\n";
        echo "Press Ctrl+C to stop both.\n\n";

        // Start Origen in background
        $origenCmd = "php -S {$origenHost}:{$origenPort} -t {$origenDocroot}";
        $origenPid = $this->startBackground($origenCmd);

        if ($origenPid === null) {
            echo "Error: Failed to start Origen server.\n";
            return 1;
        }

        echo "Origen started (PID: {$origenPid})\n";

        // Register shutdown to kill background process
        register_shutdown_function(function () use ($origenPid) {
            if ($origenPid) {
                posix_kill($origenPid, SIGTERM);
                echo "\nStopped Origen (PID: {$origenPid})\n";
            }
        });

        // Handle SIGINT/SIGTERM
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($origenPid) {
                if ($origenPid) {
                    posix_kill($origenPid, SIGTERM);
                }
                echo "\nServers stopped.\n";
                exit(0);
            });
            pcntl_signal(SIGTERM, function () use ($origenPid) {
                if ($origenPid) {
                    posix_kill($origenPid, SIGTERM);
                }
                exit(0);
            });
        }

        // Run Rufinus in foreground
        echo "Rufinus starting...\n\n";
        passthru("php -S {$siteHost}:{$sitePort} {$siteDocroot}/serve.php");

        return 0;
    }

    private function startBackground(string $command): ?int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            // Fork failed, fall back to exec
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];
            $proc = proc_open($command, $descriptors, $pipes);
            if ($proc === false) {
                return null;
            }
            $status = proc_get_status($proc);
            return $status['pid'] ?? null;
        }

        if ($pid === 0) {
            // Child process
            exec($command);
            exit(0);
        }

        // Parent: return child PID
        usleep(200000); // Give child time to start
        return $pid;
    }
}
