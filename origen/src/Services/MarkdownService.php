<?php

namespace Origen\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownService
{
    private MarkdownConverter $converter;

    private const ALLOWED_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr',
        'strong', 'em', 'b', 'i', 'u', 's', 'del', 'ins', 'mark',
        'a', 'code', 'pre', 'blockquote',
        'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img',
        'div', 'span',
        'sup', 'sub', 'small',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['align'],
        'th' => ['align'],
        'code' => ['class'],
        'pre' => ['class'],
        'div' => ['class'],
        'span' => ['class'],
    ];

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        $html = $this->converter->convert($markdown)->getContent();
        $html = $this->escapeCodeBlockPlaceholders($html);
        return $this->sanitize($html);
    }

    private function escapeCodeBlockPlaceholders(string $html): string
    {
        return preg_replace_callback('/<pre\b[^>]*>.*?<\/pre>/si', function ($match) {
            return preg_replace('/__([a-zA-Z][a-zA-Z0-9_]*)__/', '\\\\__$1__', $match[0]);
        }, $html);
    }

    private function sanitize(string $html): string
    {
        $allowedTagString = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $html = strip_tags($html, $allowedTagString);

        $html = preg_replace_callback(
            '/<(\w+)([^>]*)>/',
            function ($match) {
                $tag = strtolower($match[1]);
                $attrString = $match[2];

                if (!in_array($tag, self::ALLOWED_TAGS)) {
                    return '';
                }

                $allowedAttrs = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

                if (empty($allowedAttrs) || empty(trim($attrString))) {
                    return "<{$tag}>";
                }

                $cleanAttrs = '';
                if (preg_match_all('/(\w[\w-]*)=["\']([^"\']*)["\']/', $attrString, $attrMatches, PREG_SET_ORDER)) {
                    foreach ($attrMatches as $attr) {
                        $attrName = strtolower($attr[1]);
                        $attrValue = $attr[2];

                        if (!in_array($attrName, $allowedAttrs)) {
                            continue;
                        }

                        if (in_array($attrName, ['href', 'src'])) {
                            $normalized = strtolower(trim($attrValue));
                            if (preg_match('/^(javascript|data|vbscript):/i', $normalized)) {
                                continue;
                            }
                        }

                        $cleanAttrs .= " {$attrName}=\"" . htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8') . '"';
                    }
                }

                return "<{$tag}{$cleanAttrs}>";
            },
            $html
        );

        return trim($html);
    }
}
