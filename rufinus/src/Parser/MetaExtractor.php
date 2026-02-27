<?php

namespace Rufinus\Parser;

/**
 * Extracts meta directives from HTX DSL
 * 
 * Parses <htx:*> tags and converts them into structured meta data
 * for the prepare request to the central server.
 */
class MetaExtractor
{
    /**
     * Strip the <htx>...</htx> template body so inner tags like
     * <htx:each> and <htx:none> are not captured as meta directives.
     */
    private function stripTemplateBody(string $dsl): string
    {
        return preg_replace('/<htx(?:\s+[^>]*)?>.*?<\/htx>/s', '', $dsl);
    }

    /**
     * Extract meta directives from HTX DSL
     *
     * @param string $dsl The HTX DSL content
     * @return array Meta directives
     */
    public function extract(string $dsl): array
    {
        $meta = [];
        $rootDsl = $this->stripTemplateBody($dsl);

        // Pattern to match <htx:*> tags with content or attributes
        $pattern = '/<htx:([a-zA-Z][a-zA-Z0-9_-]*)(?:\s+([^>]*))?>(.*?)<\/htx:\1>|<htx:([a-zA-Z][a-zA-Z0-9_-]*)(?:\s+([^>]*))?\/>/s';

        if (preg_match_all($pattern, $rootDsl, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!empty($match[1])) {
                    // Tag with content: <htx:tag>content</htx:tag>
                    $tagName = $match[1];
                    $attributes = $this->parseAttributes($match[2] ?? '');
                    $content = trim($match[3]);
                    
                    $value = $content ?: ($attributes['value'] ?? $attributes['name'] ?? '');
                    
                    // If no content and no value attribute, check for other common attributes
                    if (empty($value)) {
                        $value = $attributes['condition'] ?? $attributes['by'] ?? $attributes['count'] ?? $attributes['list'] ?? '';
                    }
                } else {
                    // Self-closing tag: <htx:tag/>
                    $tagName = $match[4];
                    $attributes = $this->parseAttributes($match[5] ?? '');
                    $value = $attributes['value'] ?? $attributes['name'] ?? '';
                }
                
                // Handle special meta tags
                switch ($tagName) {
                    case 'action':
                        $meta['action'] = $value;
                        break;
                        
                    case 'type':
                        $meta['type'] = $value;
                        break;
                        
                    case 'recordId':
                        $meta['recordId'] = $value;
                        break;
                        
                    case 'order':
                        $meta['order'] = $value;
                        break;
                        
                    case 'howmany':
                        $meta['howmany'] = (int)$value ?: 10;
                        break;
                        
                    case 'where':
                        $meta['where'] = $value;
                        break;
                        
                    case 'fields':
                        $meta['fields'] = $this->parseFields($value);
                        break;
                        
                    default:
                        // Generic meta tag
                        $meta[$tagName] = $value;
                        break;
                }
            }
        }
        
        return $meta;
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
     * Parse fields list from attributes
     * 
     * @param string $fieldsString Comma-separated fields
     * @return array Array of field names
     */
    private function parseFields(string $fieldsString): array
    {
        if (empty($fieldsString)) {
            return [];
        }
        
        return array_map('trim', explode(',', $fieldsString));
    }
}
