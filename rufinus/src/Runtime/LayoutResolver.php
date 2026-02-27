<?php

namespace Rufinus\Runtime;

class LayoutResolver
{
    private const LAYOUT_FILE = '_layout.htx';
    private const CONTENT_PLACEHOLDER = '__content__';

    /**
     * Wrap page content with nested layouts from the matched file up to site root.
     *
     * @param string $content The rendered page content
     * @param string $filePath Absolute path to the matched .htx file
     * @param string $siteRoot Absolute path to the site root directory
     * @return string Fully wrapped HTML
     */
    public function wrap(string $content, string $filePath, string $siteRoot, bool $skipRoot = false): string
    {
        $layouts = $this->collectLayouts($filePath, $siteRoot);

        // The outermost layout is always last in the array (collected innermost → root).
        // For HTMX fragments, skip it — the HTML shell is already on the page.
        if ($skipRoot && !empty($layouts)) {
            $lastLayout = end($layouts);
            $lastContent = file_get_contents($lastLayout);
            if ($lastContent !== false && stripos($lastContent, '<!doctype html') !== false) {
                array_pop($layouts);
            }
        }

        // Apply remaining layouts innermost-first
        foreach ($layouts as $layoutPath) {
            $layoutContent = file_get_contents($layoutPath);
            if ($layoutContent === false) {
                continue;
            }
            $content = str_replace(self::CONTENT_PLACEHOLDER, $content, $layoutContent);
        }

        return $content;
    }

    /**
     * Walk from the matched file's directory up to site root, collecting _layout.htx files.
     * Returns them ordered innermost-first (closest to page → root).
     *
     * @return string[] Array of absolute paths to layout files
     */
    private function collectLayouts(string $filePath, string $siteRoot): array
    {
        $siteRoot = rtrim(realpath($siteRoot) ?: $siteRoot, '/');
        $currentDir = dirname(realpath($filePath) ?: $filePath);
        $layouts = [];

        while (true) {
            $layoutFile = $currentDir . '/' . self::LAYOUT_FILE;
            if (file_exists($layoutFile)) {
                $layouts[] = $layoutFile;

                // Stop walk if this layout is a complete HTML document.
                // This lets admin layouts prevent the marketing root layout from wrapping them.
                $layoutContent = file_get_contents($layoutFile);
                if ($layoutContent !== false && stripos($layoutContent, '<!doctype html') !== false) {
                    break;
                }
            }

            // Stop if we've reached the site root
            if (rtrim($currentDir, '/') === $siteRoot) {
                break;
            }

            // Move up one directory
            $parentDir = dirname($currentDir);

            // Safety: stop if we can't go higher or we've gone above site root
            if ($parentDir === $currentDir || strlen($parentDir) < strlen($siteRoot)) {
                break;
            }

            $currentDir = $parentDir;
        }

        // Innermost first — layouts[0] is closest to the page, last is root
        return $layouts;
    }
}
