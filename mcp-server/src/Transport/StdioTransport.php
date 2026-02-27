<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Transport;

use HyperMedia\McpServer\Contracts\TransportInterface;
use HyperMedia\McpServer\Server;

class StdioTransport implements TransportInterface
{
    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    public function __construct(
        private readonly Server $server
    ) {
        $this->stdin = fopen('php://stdin', 'r');
        $this->stdout = fopen('php://stdout', 'w');
        $this->stderr = fopen('php://stderr', 'w');

        if (!$this->stdin || !$this->stdout || !$this->stderr) {
            throw new \RuntimeException('Failed to open stdio streams');
        }

        // Disable output buffering
        stream_set_blocking($this->stdin, true);
    }

    public function listen(): void
    {
        $this->log('MCP Server started, waiting for requests...');

        while (($line = fgets($this->stdin)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $this->log("Received: {$line}");

            $request = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->send($this->parseError(json_last_error_msg()));
                continue;
            }

            $response = $this->server->handle($request);

            if (!empty($response)) {
                $this->send($response);
            }
        }

        $this->log('MCP Server shutting down');
    }

    public function send(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->log("Sending: {$json}");

        fwrite($this->stdout, $json . "\n");
        fflush($this->stdout);
    }

    public function notify(string $method, array $params = []): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];

        $this->send($notification);
    }

    private function parseError(string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => "Parse error: {$message}",
            ],
        ];
    }

    private function log(string $message): void
    {
        fwrite($this->stderr, "[MCP] {$message}\n");
        fflush($this->stderr);
    }

    public function __destruct()
    {
        if (is_resource($this->stdin)) fclose($this->stdin);
        if (is_resource($this->stdout)) fclose($this->stdout);
        if (is_resource($this->stderr)) fclose($this->stderr);
    }
}
