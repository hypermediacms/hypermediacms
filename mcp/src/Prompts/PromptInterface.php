<?php
/**
 * MCP Prompt Interface
 * 
 * Prompts are pre-built workflows that guide AI assistants through
 * complex, multi-step tasks in Hypermedia CMS.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Prompts;

interface PromptInterface
{
    /**
     * Get the prompt name (identifier)
     */
    public function getName(): string;

    /**
     * Get human-readable description
     */
    public function getDescription(): string;

    /**
     * Get argument definitions
     * 
     * @return array Array of argument definitions with:
     *   - name: string
     *   - description: string
     *   - required: bool
     */
    public function getArguments(): array;

    /**
     * Get the prompt messages with arguments filled in
     * 
     * @param array $arguments User-provided arguments
     * @return array Array of message objects with 'role' and 'content'
     */
    public function getMessages(array $arguments): array;
}
