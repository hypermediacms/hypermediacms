<?php

namespace Rufinus\Parser;

/**
 * Extracts response templates from HTX DSL
 * 
 * Parses <htx:response*> tags and extracts the response templates
 * that will be used by the central server to generate final HTML.
 */
class ResponseExtractor
{
    /**
     * Strip the <htx>...</htx> template body so inner tags
     * are not captured as response directives.
     */
    private function stripTemplateBody(string $dsl): string
    {
        return preg_replace('/<htx(?:\s+[^>]*)?>.*?<\/htx>/s', '', $dsl);
    }

    /**
     * Extract response templates from HTX DSL
     *
     * @param string $dsl The HTX DSL content
     * @return array Response templates
     */
    public function extract(string $dsl): array
    {
        $responses = [];
        $rootDsl = $this->stripTemplateBody($dsl);

        // Pattern to match <htx:response*> tags with their content
        $pattern = '/<htx:response([a-zA-Z]*)(?:\s+([^>]*))?>(.*?)<\/htx:response\1>/s';

        if (preg_match_all($pattern, $rootDsl, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes = $this->parseAttributes($match[2] ?? '');
                // Use 'name' attribute if present, otherwise use tag suffix
                $responseType = strtolower($attributes['name'] ?? $match[1] ?: 'default');
                $content = trim($match[3]);
                
                // Store just the content string for simpler consumption
                $responses[$responseType] = $content;
            }
        }
        
        return $responses;
    }

    /**
     * Parse attributes from a tag
     * 
     * @param string $attributeString The attribute string
     * @return array Parsed attributes
     */
    private function parseAttributes(string $attributeString): array
    {
        $attributes = [];
        
        if (empty($attributeString)) {
            return $attributes;
        }
        
        // Pattern to match key="value" or key='value' or key=value
        $pattern = '/([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*(["\']?)([^"\'>\s]+)\2/';
        
        if (preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[3];
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                $attributes[$key] = $value;
            }
        }
        
        return $attributes;
    }

    /**
     * Extract a specific response template
     * 
     * @param string $dsl The HTX DSL content
     * @param string $type The response type (success, error, redirect, etc.)
     * @return string|null The response template content
     */
    public function extractResponse(string $dsl, string $type): ?string
    {
        // extract() already strips the template body
        $responses = $this->extract($dsl);
        return $responses[$type]['content'] ?? null;
    }

    /**
     * Check if a specific response template exists
     *
     * @param string $dsl The HTX DSL content
     * @param string $type The response type
     * @return bool True if the response template exists
     */
    public function hasResponse(string $dsl, string $type): bool
    {
        // extract() already strips the template body
        $responses = $this->extract($dsl);
        return isset($responses[$type]);
    }
}
