<?php

declare(strict_types=1);

namespace HyperMedia\McpServer;

use HyperMedia\McpServer\Contracts\ResourceInterface;
use HyperMedia\McpServer\Contracts\ToolInterface;
use HyperMedia\McpServer\Contracts\PromptInterface;

class Server
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'hypermediacms';
    private const SERVER_VERSION = '1.0.0';

    /** @var array<string, ResourceInterface> */
    private array $resources = [];

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, PromptInterface> */
    private array $prompts = [];

    public function __construct(
        private readonly string $siteRoot,
        private readonly array $config = []
    ) {}

    public function registerResource(ResourceInterface $resource): self
    {
        $this->resources[$resource->getScheme()] = $resource;
        return $this;
    }

    public function registerTool(ToolInterface $tool): self
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    public function registerPrompt(PromptInterface $prompt): self
    {
        $this->prompts[$prompt->getName()] = $prompt;
        return $this;
    }

    /**
     * Handle an incoming JSON-RPC request.
     */
    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'initialized' => null, // Notification, no response
                'ping' => $this->handlePing(),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($params),
                'resources/subscribe' => $this->handleResourcesSubscribe($params),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($params),
                default => throw new \InvalidArgumentException("Unknown method: {$method}")
            };

            if ($result === null) {
                return []; // No response for notifications
            }

            return $this->success($id, $result);
        } catch (\Throwable $e) {
            return $this->error($id, $e->getCode() ?: -32603, $e->getMessage());
        }
    }

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'resources' => [
                    'subscribe' => true,
                    'listChanged' => true,
                ],
                'tools' => new \stdClass(), // Empty object
                'prompts' => [
                    'listChanged' => true,
                ],
            ],
        ];
    }

    private function handlePing(): array
    {
        return new \stdClass(); // Empty object
    }

    private function handleResourcesList(): array
    {
        $resources = [];
        foreach ($this->resources as $provider) {
            foreach ($provider->list() as $resource) {
                $resources[] = $resource;
            }
        }
        return ['resources' => $resources];
    }

    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';
        $scheme = parse_url($uri, PHP_URL_SCHEME) ?? '';

        if (!isset($this->resources[$scheme])) {
            throw new \InvalidArgumentException("Unknown resource scheme: {$scheme}");
        }

        $content = $this->resources[$scheme]->read($uri);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => $this->getMimeType($uri),
                    'text' => $content,
                ]
            ]
        ];
    }

    private function handleResourcesSubscribe(array $params): array
    {
        // TODO: Implement subscription tracking
        return new \stdClass();
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = $tool->getDefinition();
        }
        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        $result = $this->tools[$name]->execute($arguments);

        return [
            'content' => $result['content'] ?? [['type' => 'text', 'text' => json_encode($result)]],
            'isError' => $result['isError'] ?? false,
        ];
    }

    private function handlePromptsList(): array
    {
        $prompts = [];
        foreach ($this->prompts as $prompt) {
            $prompts[] = $prompt->getDefinition();
        }
        return ['prompts' => $prompts];
    }

    private function handlePromptsGet(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->prompts[$name])) {
            throw new \InvalidArgumentException("Unknown prompt: {$name}");
        }

        return $this->prompts[$name]->render($arguments);
    }

    private function getMimeType(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'htx' => 'text/x-htx',
            'yaml', 'yml' => 'application/x-yaml',
            'json' => 'application/json',
            'md' => 'text/markdown',
            default => 'text/plain',
        };
    }

    private function success(?int $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function error(?int $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    public function getSiteRoot(): string
    {
        return $this->siteRoot;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
