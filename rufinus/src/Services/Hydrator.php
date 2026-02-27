<?php

namespace Rufinus\Services;

/**
 * Template hydrator for replacing placeholders with actual data
 * 
 * Safely replaces __placeholder__ tokens in HTML templates with
 * values from the central server response.
 */
class Hydrator
{
    /**
     * Fields that contain pre-sanitized HTML from central.
     * These skip escaping — central is responsible for safety.
     */
    private const TRUSTED_HTML_FIELDS = ['body_html', 'status_options', 'type_options', 'custom_fields_html'];

    /**
     * Hydrate a template with data.
     *
     * If the template contains __body__ and the data includes body_html,
     * the pre-sanitized HTML version is used instead of the raw markdown.
     *
     * @param string $template The template string
     * @param array $data Data to replace placeholders with
     * @return string Hydrated template
     */
    public function hydrate(string $template, array $data): string
    {
        $hydrated = $template;

        // Extract escaped placeholders (\__word__) as markers BEFORE any replacement
        $escaped = [];
        $hydrated = $this->extractEscapedPlaceholders($hydrated, $escaped);

        // If template uses __body__ and central provided body_html, swap it in
        if (str_contains($hydrated, '__body__') && isset($data['body_html'])) {
            $hydrated = str_replace('__body__', $data['body_html'], $hydrated);
            unset($data['body'], $data['body_html']);
        }

        foreach ($data as $key => $value) {
            $placeholder = "__{$key}__";
            if (in_array($key, self::TRUSTED_HTML_FIELDS, true)) {
                $hydrated = str_replace($placeholder, (string) $value, $hydrated);
            } else {
                $hydrated = str_replace($placeholder, $this->escapeValue($value), $hydrated);
            }
        }

        // Resolve dot-notation placeholders (e.g. __author.title__)
        $hydrated = $this->resolveDotNotation($hydrated, $data);

        // Restore escaped placeholders as literal text
        $hydrated = $this->restoreEscapedPlaceholders($hydrated, $escaped);

        return $hydrated;
    }

    /**
     * Hydrate multiple templates
     * 
     * @param array $templates Array of templates
     * @param array $data Data to replace placeholders with
     * @return array Array of hydrated templates
     */
    public function hydrateMultiple(array $templates, array $data): array
    {
        $hydrated = [];
        
        foreach ($templates as $key => $template) {
            $hydrated[$key] = $this->hydrate($template, $data);
        }
        
        return $hydrated;
    }

    /**
     * Hydrate HTMX attributes specifically
     * 
     * @param string $template The template string
     * @param array $data Data to replace placeholders with
     * @return string Template with hydrated HTMX attributes
     */
    public function hydrateHtmx(string $template, array $data): string
    {
        $hydrated = $template;

        // Extract escaped placeholders (\__word__) as markers BEFORE any replacement
        $escaped = [];
        $hydrated = $this->extractEscapedPlaceholders($hydrated, $escaped);

        // Hydrate hx-post attributes
        if (isset($data['endpoint'])) {
            $hydrated = str_replace('__endpoint__', $this->escapeValue($data['endpoint']), $hydrated);
        }

        // Hydrate hx-vals attributes (don't escape JSON)
        if (isset($data['payload'])) {
            $hydrated = str_replace('__payload__', $data['payload'], $hydrated);
        }

        // Swap __body__ for pre-sanitized body_html if available
        if (str_contains($hydrated, '__body__') && isset($data['body_html'])) {
            $hydrated = str_replace('__body__', $data['body_html'], $hydrated);
        }

        // Hydrate other common placeholders
        $commonPlaceholders = ['recordId', 'title', 'id', 'slug', 'type', 'status'];
        foreach ($commonPlaceholders as $placeholder) {
            if (isset($data[$placeholder])) {
                $hydrated = str_replace("__{$placeholder}__", $this->escapeValue($data[$placeholder]), $hydrated);
            }
        }

        // Hydrate remaining placeholders from data (selected_*, custom values, etc.)
        foreach ($data as $key => $value) {
            $placeholder = "__{$key}__";
            if (str_contains($hydrated, $placeholder) && is_scalar($value)) {
                $escaped_val = in_array($key, self::TRUSTED_HTML_FIELDS, true) ? (string) $value : $this->escapeValue($value);
                $hydrated = str_replace($placeholder, $escaped_val, $hydrated);
            }
        }

        // Resolve dot-notation placeholders (e.g. __author.title__)
        $hydrated = $this->resolveDotNotation($hydrated, $data);

        // Restore escaped placeholders as literal text
        $hydrated = $this->restoreEscapedPlaceholders($hydrated, $escaped);

        return $hydrated;
    }

    /**
     * Escape a value for safe HTML output
     * 
     * @param mixed $value The value to escape
     * @return string Escaped value
     */
    private function escapeValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
        }
        
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Extract placeholders from a template (including dot-notation)
     *
     * @param string $template The template string
     * @return array Array of placeholder names
     */
    public function extractPlaceholders(string $template): array
    {
        $placeholders = [];

        if (preg_match_all('/__([a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)?)__/', $template, $matches)) {
            $placeholders = array_unique($matches[1]);
        }

        return $placeholders;
    }

    /**
     * Check if a template has placeholders
     * 
     * @param string $template The template string
     * @return bool True if placeholders are found
     */
    public function hasPlaceholders(string $template): bool
    {
        return preg_match('/__[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)?__/', $template) === 1;
    }

    /**
     * Validate that all placeholders in a template have corresponding data
     *
     * @param string $template The template string
     * @param array $data Available data
     * @return array Missing placeholders
     */
    public function validatePlaceholders(string $template, array $data): array
    {
        $placeholders = $this->extractPlaceholders($template);
        $missing = [];

        foreach ($placeholders as $placeholder) {
            if (str_contains($placeholder, '.')) {
                [$object, $property] = explode('.', $placeholder, 2);
                if (!isset($data[$object]) || !is_array($data[$object]) || !array_key_exists($property, $data[$object])) {
                    $missing[] = $placeholder;
                }
            } elseif (!array_key_exists($placeholder, $data)) {
                $missing[] = $placeholder;
            }
        }

        return $missing;
    }

    /**
     * Extract escaped placeholders (\__word__) and replace them with markers.
     * This allows template authors to use literal __placeholder__ text.
     */
    private function extractEscapedPlaceholders(string $html, array &$escaped): string
    {
        return preg_replace_callback('/\\\\__([a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)?)__/', function ($match) use (&$escaped) {
            $marker = '<!--ESCAPED_' . count($escaped) . '-->';
            $escaped[$marker] = '__' . $match[1] . '__';
            return $marker;
        }, $html);
    }

    /**
     * Resolve dot-notation placeholders like __author.title__ using nested data.
     */
    private function resolveDotNotation(string $html, array $data): string
    {
        return preg_replace_callback(
            '/__([a-zA-Z][a-zA-Z0-9_]*)\.([a-zA-Z][a-zA-Z0-9_]*)__/',
            function ($matches) use ($data) {
                $object = $matches[1];
                $property = $matches[2];
                if (isset($data[$object]) && is_array($data[$object]) && array_key_exists($property, $data[$object])) {
                    return $this->escapeValue($data[$object][$property]);
                }
                return $matches[0]; // Leave unresolved
            },
            $html
        );
    }

    /**
     * Restore escaped placeholders from their markers as literal text.
     */
    private function restoreEscapedPlaceholders(string $html, array $escaped): string
    {
        return str_replace(array_keys($escaped), array_values($escaped), $html);
    }
}
