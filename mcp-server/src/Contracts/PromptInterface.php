<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Contracts;

interface PromptInterface
{
    /**
     * Get the prompt name.
     */
    public function getName(): string;

    /**
     * Get the prompt definition for MCP.
     *
     * @return array{name: string, description: string, arguments?: array}
     */
    public function getDefinition(): array;

    /**
     * Render the prompt with given arguments.
     *
     * @param array $arguments The prompt arguments
     * @return array{messages: array}
     */
    public function render(array $arguments): array;
}
