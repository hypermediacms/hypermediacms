<?php
/**
 * MCP Tool Interface
 * 
 * All MCP tools must implement this interface.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Tools;

interface ToolInterface
{
    /**
     * Get the tool name (used in tools/call)
     */
    public function getName(): string;

    /**
     * Get the tool description
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema for the tool's input parameters
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with the given arguments
     * 
     * @param array $arguments The input arguments
     * @return mixed The result (will be JSON-encoded if not a string)
     */
    public function execute(array $arguments): mixed;
}
