<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Contracts;

interface ResourceInterface
{
    /**
     * Get the URI scheme this resource handles (e.g., 'htx', 'content', 'schema').
     */
    public function getScheme(): string;

    /**
     * List all available resources.
     *
     * @return array<array{uri: string, name: string, mimeType: string, description?: string}>
     */
    public function list(): array;

    /**
     * Read a specific resource by URI.
     */
    public function read(string $uri): string;

    /**
     * Check if this resource supports subscriptions.
     */
    public function supportsSubscription(): bool;
}
