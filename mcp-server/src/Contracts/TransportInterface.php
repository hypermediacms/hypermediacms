<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Contracts;

interface TransportInterface
{
    /**
     * Start listening for incoming requests.
     */
    public function listen(): void;

    /**
     * Send a response.
     */
    public function send(array $response): void;

    /**
     * Send a notification (no response expected).
     */
    public function notify(string $method, array $params = []): void;
}
