<?php
/**
 * MCP Resource Interface
 * 
 * Resources expose data that AI assistants can read directly.
 * Unlike tools (which perform actions), resources are read-only data.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

interface ResourceInterface
{
    /**
     * Get the URI pattern this resource handles
     * Can include parameters like {type} or {slug}
     * 
     * Examples:
     * - "hcms://content/{type}"
     * - "hcms://content/{type}/{slug}"
     * - "hcms://schemas"
     */
    public function getUriPattern(): string;

    /**
     * Get human-readable name for this resource
     */
    public function getName(): string;

    /**
     * Get description of what this resource provides
     */
    public function getDescription(): string;

    /**
     * Get MIME type of the resource content
     */
    public function getMimeType(): string;

    /**
     * Check if this resource matches a given URI
     */
    public function matches(string $uri): bool;

    /**
     * Extract parameters from a URI
     * 
     * @return array<string, string> Parameter name => value
     */
    public function extractParams(string $uri): array;

    /**
     * Read the resource content
     * 
     * @param array $params Extracted URI parameters
     * @return array Resource content with 'uri', 'mimeType', 'text' or 'blob'
     */
    public function read(array $params): array;

    /**
     * List available instances of this resource (for discovery)
     * Return empty array if resource is template-only
     * 
     * @param int $limit Maximum number of instances to return
     * @return array Array of resource descriptors
     */
    public function listInstances(int $limit = 10): array;

    /**
     * Whether this is a template resource (parameterized)
     */
    public function isTemplate(): bool;
}
