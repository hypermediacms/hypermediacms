<?php

namespace Rufinus\Expressions\Functions;

use Rufinus\Expressions\FunctionRegistry;

class StringFunctions
{
    public static function register(FunctionRegistry $registry): void
    {
        $registry->register('truncate', function (string $value, int $length, string $suffix = '...'): string {
            if (mb_strlen($value) <= $length) {
                return $value;
            }
            return mb_substr($value, 0, $length) . $suffix;
        });

        $registry->register('split', function (string $value, string $delimiter): array {
            if ($value === '') {
                return [];
            }
            return explode($delimiter, $value);
        });

        $registry->register('join', function (array $array, string $delimiter): string {
            return implode($delimiter, $array);
        });

        $registry->register('trim', function (string $value): string {
            return trim($value);
        });

        $registry->register('uppercase', function (string $value): string {
            return mb_strtoupper($value);
        });

        $registry->register('lowercase', function (string $value): string {
            return mb_strtolower($value);
        });

        $registry->register('capitalize', function (string $value): string {
            return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
        });

        $registry->register('replace', function (string $value, string $search, string $replacement): string {
            return str_replace($search, $replacement, $value);
        });

        $registry->register('contains', function (string $value, string $search): bool {
            return str_contains($value, $search);
        });

        $registry->register('starts_with', function (string $value, string $prefix): bool {
            return str_starts_with($value, $prefix);
        });

        $registry->register('length', function (mixed $value): int {
            if (is_array($value)) {
                return count($value);
            }
            return mb_strlen((string) $value);
        });

        $registry->register('default', function (mixed $value, mixed $fallback): mixed {
            if ($value === null || $value === '' || $value === false) {
                return $fallback;
            }
            return $value;
        });

        $registry->register('slug', function (string $value): string {
            $slug = mb_strtolower($value);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            return trim($slug, '-');
        });

        $registry->register('md', function (string $value): string {
            // Inline markdown: **bold**, *italic*, `code`, [link](url)
            $value = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $value);
            $value = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $value);
            $value = preg_replace('/`(.+?)`/', '<code>$1</code>', $value);
            $value = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $value);
            return $value;
        });
    }
}
