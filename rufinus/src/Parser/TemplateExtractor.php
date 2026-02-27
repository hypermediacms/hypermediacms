<?php

namespace Rufinus\Parser;

/**
 * Extracts the main template body from HTX DSL
 * 
 * Parses <htx>...</htx> tags and extracts the template content
 * that will be hydrated with data from the central server.
 */
class TemplateExtractor
{
    /**
     * Extract the main template body from HTX DSL
     * 
     * @param string $dsl The HTX DSL content
     * @return string The template body
     */
    public function extract(string $dsl): string
    {
        // Pattern to match <htx>...</htx> tags
        $pattern = '/<htx(?:\s+([^>]*))?>(.*?)<\/htx>/s';
        
        if (preg_match($pattern, $dsl, $matches)) {
            return trim($matches[2]);
        }
        
        return '';
    }

    /**
     * Extract template with attributes
     * 
     * @param string $dsl The HTX DSL content
     * @return array Template content and attributes
     */
    public function extractWithAttributes(string $dsl): array
    {
        // Pattern to match <htx>...</htx> tags with attributes
        $pattern = '/<htx(?:\s+([^>]*))?>(.*?)<\/htx>/s';
        
        if (preg_match($pattern, $dsl, $matches)) {
            $attributes = $this->parseAttributes($matches[1] ?? '');
            $content = trim($matches[2]);
            
            return [
                'content' => $content,
                'attributes' => $attributes,
            ];
        }
        
        return [
            'content' => '',
            'attributes' => [],
        ];
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
     * Check if the DSL contains a template
     * 
     * @param string $dsl The HTX DSL content
     * @return bool True if a template is found
     */
    public function hasTemplate(string $dsl): bool
    {
        return preg_match('/<htx(?:\s+[^>]*)?>.*?<\/htx>/s', $dsl) === 1;
    }

    /**
     * Extract multiple templates if they exist
     * 
     * @param string $dsl The HTX DSL content
     * @return array Array of templates with their attributes
     */
    public function extractAll(string $dsl): array
    {
        $templates = [];
        
        // Pattern to match all <htx>...</htx> tags
        $pattern = '/<htx(?:\s+([^>]*))?>(.*?)<\/htx>/s';
        
        if (preg_match_all($pattern, $dsl, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $attributes = $this->parseAttributes($match[1] ?? '');
                $content = trim($match[2]);
                
                $templates[$index] = [
                    'content' => $content,
                    'attributes' => $attributes,
                ];
            }
        }
        
        return $templates;
    }
}
