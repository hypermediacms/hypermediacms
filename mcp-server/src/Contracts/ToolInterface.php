<?php

declare(strict_types=1);

namespace HyperMedia\McpServer\Contracts;

interface ToolInterface
{
    /**
     * Get the tool name.
     */
    public function getName(): string;

    /**
     * Get the tool definition for MCP.
     *
     * @return array{name: string, description: string, inputSchema: array}
     */
    public function getDefinition(): array;

    /**
     * Execute the tool with given input.
     *
     * @param array $input The validated input parameters
     * @return array{content: array, isError?: bool}
     */
    public function execute(array $input): array;
}
