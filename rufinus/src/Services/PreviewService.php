<?php
/**
 * Preview Service
 * 
 * Renders content through HTX templates for live preview without persisting.
 * Used by the admin interface for WYSIWYG preview during content editing.
 */

declare(strict_types=1);

namespace Rufinus\Services;

use Rufinus\EdgeHTX;
use Rufinus\Expressions\ExpressionEngine;

class PreviewService
{
    private string $siteRoot;
    private ExpressionEngine $expressionEngine;

    public function __construct(string $siteRoot)
    {
        $this->siteRoot = rtrim($siteRoot, '/');
        $this->expressionEngine = new ExpressionEngine();
    }

    /**
     * Preview content by rendering it through an HTX template
     * 
     * @param string $contentType The content type being previewed
     * @param array $content The content data (field => value pairs)
     * @param string|null $templatePath Optional specific template to use
     * @return array Preview result with rendered HTML
     */
    public function preview(string $contentType, array $content, ?string $templatePath = null): array
    {
        // Find the appropriate template
        $htxFile = $templatePath 
            ? $this->siteRoot . '/' . ltrim($templatePath, '/')
            : $this->findTemplateForType($contentType);

        if ($htxFile === null || !file_exists($htxFile)) {
            return [
                'success' => false,
                'error' => 'no_template',
                'message' => "No HTX template found for content type: {$contentType}",
                'suggestion' => 'Create an HTX file that displays this content type first.'
            ];
        }

        try {
            $htxContent = file_get_contents($htxFile);
            $rendered = $this->renderPreview($htxContent, $content);

            return [
                'success' => true,
                'html' => $rendered,
                'template' => str_replace($this->siteRoot . '/', '', $htxFile),
                'content_type' => $contentType
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'render_error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Find an HTX template that displays a given content type
     */
    private function findTemplateForType(string $contentType): ?string
    {
        // Common patterns for content type templates
        $candidates = [
            // Public single view
            $contentType . 's/[slug].htx',
            $contentType . 's/[id].htx',
            $contentType . '/[slug].htx',
            $contentType . '/[id].htx',
            // Public list view
            $contentType . 's/index.htx',
            $contentType . 's.htx',
        ];

        foreach ($candidates as $candidate) {
            $fullPath = $this->siteRoot . '/' . $candidate;
            if (file_exists($fullPath)) {
                // Verify it's for the right content type
                $content = file_get_contents($fullPath);
                if (preg_match('/<htx:type>\s*' . preg_quote($contentType, '/') . '\s*<\/htx:type>/i', $content)) {
                    return $fullPath;
                }
            }
        }

        // Fallback: scan all HTX files for one that uses this content type (single view preferred)
        return $this->scanForTemplate($contentType);
    }

    /**
     * Scan site for a template that displays the content type
     */
    private function scanForTemplate(string $contentType): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->siteRoot)
        );

        $singleViewCandidates = [];
        $listViewCandidates = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'htx') {
                continue;
            }

            // Skip admin, layouts, and error pages
            $relativePath = str_replace($this->siteRoot . '/', '', $file->getPathname());
            if (str_starts_with($relativePath, 'admin/') || str_starts_with(basename($relativePath), '_')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            
            // Check if this HTX uses our content type
            if (!preg_match('/<htx:type>\s*' . preg_quote($contentType, '/') . '\s*<\/htx:type>/i', $content)) {
                continue;
            }

            // Prefer single views (howmany=1) over list views
            if (preg_match('/<htx:howmany>\s*1\s*<\/htx:howmany>/i', $content)) {
                $singleViewCandidates[] = $file->getPathname();
            } else {
                $listViewCandidates[] = $file->getPathname();
            }
        }

        // Return single view if available, otherwise list view
        return $singleViewCandidates[0] ?? $listViewCandidates[0] ?? null;
    }

    /**
     * Render content through an HTX template for preview
     */
    private function renderPreview(string $htxContent, array $content): string
    {
        // Extract the template part (inside <htx>...</htx>)
        if (!preg_match('/<htx>(.*)<\/htx>/s', $htxContent, $matches)) {
            $template = $htxContent;
        } else {
            $template = $matches[1];
        }

        // Find <htx:each> block - this is what we render for a single item
        if (preg_match('/<htx:each>(.*)<\/htx:each>/s', $template, $eachMatches)) {
            $template = $eachMatches[1];
        }

        // Process Markdown body to HTML if present
        if (isset($content['body']) && !isset($content['body_html'])) {
            $content['body_html'] = $this->markdownToHtml($content['body']);
        }

        // Hydrate the template with content
        $rendered = $template;

        // Replace __field__ placeholders
        foreach ($content as $field => $value) {
            if (is_scalar($value)) {
                $rendered = str_replace("__{$field}__", htmlspecialchars((string)$value), $rendered);
            }
        }

        // Replace {{ field }} Twig-style placeholders
        foreach ($content as $field => $value) {
            if (is_scalar($value)) {
                $rendered = preg_replace(
                    '/\{\{\s*' . preg_quote($field, '/') . '\s*\}\}/',
                    htmlspecialchars((string)$value),
                    $rendered
                );
            }
        }

        // Replace {{! field }} (unescaped) placeholders
        foreach ($content as $field => $value) {
            if (is_scalar($value)) {
                $rendered = preg_replace(
                    '/\{\{!\s*' . preg_quote($field, '/') . '\s*\}\}/',
                    (string)$value,
                    $rendered
                );
            }
        }

        // Process expression functions like time_ago(), truncate()
        $rendered = $this->processExpressions($rendered, $content);

        // Clean up any remaining placeholders
        $rendered = preg_replace('/__[a-z_]+__/i', '', $rendered);
        $rendered = preg_replace('/\{\{[^}]+\}\}/', '', $rendered);

        return trim($rendered);
    }

    /**
     * Process expression functions in template
     */
    private function processExpressions(string $template, array $content): string
    {
        // Handle time_ago(field)
        $template = preg_replace_callback(
            '/\{\{\s*time_ago\((\w+)\)\s*\}\}/',
            function ($matches) use ($content) {
                $field = $matches[1];
                $value = $content[$field] ?? null;
                if ($value) {
                    return $this->timeAgo($value);
                }
                return 'just now';
            },
            $template
        );

        // Handle truncate(field, length)
        $template = preg_replace_callback(
            '/\{\{\s*truncate\((\w+),\s*(\d+)\)\s*\}\}/',
            function ($matches) use ($content) {
                $field = $matches[1];
                $length = (int)$matches[2];
                $value = $content[$field] ?? '';
                if (strlen($value) > $length) {
                    return htmlspecialchars(substr($value, 0, $length)) . '...';
                }
                return htmlspecialchars($value);
            },
            $template
        );

        return $template;
    }

    /**
     * Convert timestamp to "time ago" string
     */
    private function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        if ($time === false) {
            return 'unknown';
        }

        $diff = time() - $time;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }

    /**
     * Basic Markdown to HTML conversion
     */
    private function markdownToHtml(string $markdown): string
    {
        // Very basic markdown conversion for preview
        $html = htmlspecialchars($markdown);
        
        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        
        // Italic
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
        
        // Links
        $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);
        
        // Paragraphs
        $html = '<p>' . preg_replace('/\n\n+/', '</p><p>', $html) . '</p>';
        
        // Line breaks
        $html = str_replace("\n", '<br>', $html);

        return $html;
    }
}
