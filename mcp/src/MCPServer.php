<?php
/**
 * MCP Server Implementation
 * 
 * Handles JSON-RPC communication over stdio for the Model Context Protocol.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP;

use HyperMediaCMS\MCP\Tools\ToolInterface;

class MCPServer
{
    private string $name;
    private string $version;
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(string $name, string $version)
    {
        $this->name = $name;
        $this->version = $version;
    }

    /**
     * Register a tool with the server
     */
    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Run the server (stdio transport)
     */
    public function run(): void
    {
        // Set up stdio
        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');
        
        if (!$stdin || !$stdout) {
            throw new \RuntimeException('Failed to open stdio streams');
        }

        // Read JSON-RPC messages line by line
        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $request = json_decode($line, true);
            if ($request === null) {
                $this->writeResponse($stdout, $this->errorResponse(null, -32700, 'Parse error'));
                continue;
            }

            $response = $this->handleRequest($request);
            if ($response !== null) {
                $this->writeResponse($stdout, $response);
            }
        }

        fclose($stdin);
        fclose($stdout);
    }

    /**
     * Handle a JSON-RPC request
     */
    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        // Notifications (no id) don't get responses
        $isNotification = !isset($request['id']);

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleListTools(),
                'tools/call' => $this->handleCallTool($params),
                'resources/list' => $this->handleListResources(),
                'prompts/list' => $this->handleListPrompts(),
                default => throw new \InvalidArgumentException("Unknown method: {$method}")
            };

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result
            ];
        } catch (\Exception $e) {
            if ($isNotification) {
                return null;
            }

            return $this->errorResponse($id, -32603, $e->getMessage());
        }
    }

    /**
     * Handle initialize request
     */
    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'serverInfo' => [
                'name' => $this->name,
                'version' => $this->version
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false]
            ]
        ];
    }

    /**
     * Handle tools/list request
     */
    private function handleListTools(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema()
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Handle tools/call request
     */
    private function handleCallTool(array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException("Unknown tool: {$toolName}");
        }

        $tool = $this->tools[$toolName];
        $result = $tool->execute($arguments);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT)
                ]
            ]
        ];
    }

    /**
     * Handle resources/list request (placeholder)
     */
    private function handleListResources(): array
    {
        return ['resources' => []];
    }

    /**
     * Handle prompts/list request (placeholder)
     */
    private function handleListPrompts(): array
    {
        return ['prompts' => []];
    }

    /**
     * Write a JSON-RPC response
     */
    private function writeResponse($stdout, array $response): void
    {
        fwrite($stdout, json_encode($response) . "\n");
        fflush($stdout);
    }

    /**
     * Create an error response
     */
    private function errorResponse(?int $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }
}
