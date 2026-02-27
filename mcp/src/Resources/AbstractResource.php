<?php
/**
 * Abstract Resource Base Class
 * 
 * Provides common functionality for MCP resources.
 */

declare(strict_types=1);

namespace HyperMediaCMS\MCP\Resources;

abstract class AbstractResource implements ResourceInterface
{
    protected string $uriPattern;
    protected string $name;
    protected string $description;
    protected string $mimeType = 'application/json';
    protected bool $isTemplate = false;
    protected ?string $regexPattern = null;
    protected array $paramNames = [];

    public function getUriPattern(): string
    {
        return $this->uriPattern;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function isTemplate(): bool
    {
        return $this->isTemplate;
    }

    /**
     * Build regex pattern from URI pattern
     */
    protected function buildRegex(): void
    {
        $pattern = preg_quote($this->uriPattern, '/');
        
        // Extract parameter names and replace with capture groups
        $this->paramNames = [];
        $pattern = preg_replace_callback(
            '/\\\{(\w+)\\\}/',
            function ($matches) {
                $this->paramNames[] = $matches[1];
                return '([^\/]+)';
            },
            $pattern
        );

        $this->regexPattern = '/^' . $pattern . '$/';
        $this->isTemplate = !empty($this->paramNames);
    }

    public function matches(string $uri): bool
    {
        if ($this->regexPattern === null) {
            $this->buildRegex();
        }

        return (bool) preg_match($this->regexPattern, $uri);
    }

    public function extractParams(string $uri): array
    {
        if ($this->regexPattern === null) {
            $this->buildRegex();
        }

        if (!preg_match($this->regexPattern, $uri, $matches)) {
            return [];
        }

        array_shift($matches); // Remove full match
        
        $params = [];
        foreach ($this->paramNames as $i => $name) {
            $params[$name] = $matches[$i] ?? null;
        }

        return $params;
    }

    /**
     * Format resource descriptor for MCP
     */
    protected function formatDescriptor(string $uri, ?string $name = null, ?string $description = null): array
    {
        return [
            'uri' => $uri,
            'name' => $name ?? $this->name,
            'description' => $description ?? $this->description,
            'mimeType' => $this->mimeType
        ];
    }

    /**
     * Format resource content for MCP response
     */
    protected function formatContent(string $uri, $content): array
    {
        $text = is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        return [
            'uri' => $uri,
            'mimeType' => $this->mimeType,
            'text' => $text
        ];
    }
}
