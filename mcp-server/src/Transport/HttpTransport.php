<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Transport;

use HyperMedia\McpServer\Contracts\TransportInterface;
use HyperMedia\McpServer\Server;

class HttpTransport implements TransportInterface
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly Server $server,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8808,
        private readonly ?string $apiKey = null
    ) {}

    public function listen(): void
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $this->socket = stream_socket_server($address, $errno, $errstr);

        if (!$this->socket) {
            throw new \RuntimeException("Failed to start HTTP server on {$address}: {$errstr} ({$errno})");
        }

        $this->log("HTTP server listening on http://{$this->host}:{$this->port}");

        while ($conn = @stream_socket_accept($this->socket, -1)) {
            try {
                $this->handleConnection($conn);
            } catch (\Throwable $e) {
                $this->log("Connection error: {$e->getMessage()}");
            } finally {
                if (is_resource($conn)) {
                    fclose($conn);
                }
            }
        }
    }

    public function send(array $response): void
    {
        // No-op: responses are sent inline during connection handling
    }

    public function notify(string $method, array $params = []): void
    {
        $this->log("Notification (discarded, no push channel): {$method}");
    }

    private function handleConnection($conn): void
    {
        $raw = '';
        $contentLength = 0;
        $headersComplete = false;

        // Read headers
        while (($line = fgets($conn)) !== false) {
            $raw .= $line;
            if (trim($line) === '') {
                $headersComplete = true;
                break;
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($line, 15));
            }
        }

        if (!$headersComplete) {
            return;
        }

        // Read body
        $body = '';
        if ($contentLength > 0) {
            $body = fread($conn, $contentLength);
        }

        $request = $this->parseHttpRequest($raw, $body);

        if ($request === null) {
            return;
        }

        // Handle CORS preflight
        if ($request['method'] === 'OPTIONS') {
            $this->writeHttpResponse($conn, 204, '');
            return;
        }

        // Check API key authentication
        if ($this->apiKey !== null) {
            $providedKey = $request['headers']['x-mcp-key'] ?? '';
            if ($providedKey !== $this->apiKey) {
                $this->writeJsonResponse($conn, 401, [
                    'error' => 'Unauthorized: invalid or missing X-MCP-Key header',
                ]);
                return;
            }
        }

        // Route HTTP request to MCP method
        $mcpRequest = $this->routeToMcp($request);

        if ($mcpRequest === null) {
            $this->writeJsonResponse($conn, 404, [
                'error' => "Not found: {$request['path']}",
            ]);
            return;
        }

        $response = $this->server->handle($mcpRequest);

        // Map MCP response to HTTP
        if (isset($response['error'])) {
            $httpStatus = $this->mapErrorToStatus($response['error']['code'] ?? -32603);
            $this->writeJsonResponse($conn, $httpStatus, $response['error']);
        } else {
            $this->writeJsonResponse($conn, 200, $response['result'] ?? []);
        }
    }

    private function parseHttpRequest(string $rawHeaders, string $body): ?array
    {
        $lines = explode("\n", $rawHeaders);
        $requestLine = trim($lines[0] ?? '');

        if (!$requestLine) {
            return null;
        }

        $parts = explode(' ', $requestLine, 3);
        if (count($parts) < 2) {
            return null;
        }

        $method = strtoupper($parts[0]);
        $fullPath = $parts[1];

        // Parse path and query string
        $urlParts = parse_url($fullPath);
        $path = $urlParts['path'] ?? '/';
        $queryString = $urlParts['query'] ?? '';

        parse_str($queryString, $query);

        // Parse headers
        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') break;

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($line, 0, $colonPos)));
                $value = trim(substr($line, $colonPos + 1));
                $headers[$key] = $value;
            }
        }

        return [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    private function routeToMcp(array $request): ?array
    {
        $method = $request['method'];
        $path = rtrim($request['path'], '/');

        // GET /mcp/status → initialize
        if ($method === 'GET' && $path === '/mcp/status') {
            return $this->mcpRequest('initialize', []);
        }

        // GET /mcp/tools → tools/list
        if ($method === 'GET' && $path === '/mcp/tools') {
            return $this->mcpRequest('tools/list', []);
        }

        // POST /mcp/tools/{name} → tools/call
        if ($method === 'POST' && preg_match('#^/mcp/tools/([a-zA-Z_]+)$#', $path, $m)) {
            $arguments = json_decode($request['body'], true) ?? [];
            return $this->mcpRequest('tools/call', [
                'name' => $m[1],
                'arguments' => $arguments,
            ]);
        }

        // GET /mcp/resources → resources/list or resources/read
        if ($method === 'GET' && $path === '/mcp/resources') {
            $uri = $request['query']['uri'] ?? null;
            if ($uri) {
                return $this->mcpRequest('resources/read', ['uri' => $uri]);
            }
            return $this->mcpRequest('resources/list', []);
        }

        // GET /mcp/prompts → prompts/list
        if ($method === 'GET' && $path === '/mcp/prompts') {
            return $this->mcpRequest('prompts/list', []);
        }

        // POST /mcp/prompts/{name} → prompts/get
        if ($method === 'POST' && preg_match('#^/mcp/prompts/([a-zA-Z_]+)$#', $path, $m)) {
            $arguments = json_decode($request['body'], true) ?? [];
            return $this->mcpRequest('prompts/get', [
                'name' => $m[1],
                'arguments' => $arguments,
            ]);
        }

        return null;
    }

    private function mcpRequest(string $method, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ];
    }

    private function mapErrorToStatus(int $mcpCode): int
    {
        return match (true) {
            $mcpCode === -32700 => 400,  // Parse error
            $mcpCode === -32600 => 400,  // Invalid request
            $mcpCode === -32601 => 404,  // Method not found
            $mcpCode === -32602 => 422,  // Invalid params
            default => 500,              // Internal error
        };
    }

    private function writeJsonResponse($conn, int $status, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->writeHttpResponse($conn, $status, $json, 'application/json');
    }

    private function writeHttpResponse($conn, int $status, string $body, string $contentType = 'text/plain'): void
    {
        $reason = $this->httpReason($status);
        $length = strlen($body);

        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: {$contentType}\r\n"
            . "Content-Length: {$length}\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
            . "Access-Control-Allow-Headers: Content-Type, X-MCP-Key\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;

        fwrite($conn, $response);
        fflush($conn);
    }

    private function httpReason(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            default => 'Unknown',
        };
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[MCP/HTTP] {$message}\n");
        fflush(STDERR);
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
