<?php

namespace Rufinus\Expressions\Functions;

use Rufinus\Expressions\FunctionRegistry;

class ArrayFunctions
{
    public static function register(FunctionRegistry $registry): void
    {
        $registry->register('count', function (mixed $value): int {
            if (is_array($value)) {
                return count($value);
            }
            if (is_string($value)) {
                return mb_strlen($value);
            }
            return 0;
        });

        $registry->register('first', function (array $array): mixed {
            if (empty($array)) {
                return null;
            }
            return reset($array);
        });

        $registry->register('last', function (array $array): mixed {
            if (empty($array)) {
                return null;
            }
            return end($array);
        });

        $registry->register('reverse', function (array $array): array {
            return array_reverse($array);
        });

        $registry->register('sort', function (array $array): array {
            sort($array);
            return $array;
        });

        $registry->register('unique', function (array $array): array {
            return array_values(array_unique($array));
        });

        $registry->register('slice', function (array $array, int $start, ?int $length = null): array {
            return array_slice($array, $start, $length);
        });

        $registry->register('empty', function (mixed $value): bool {
            if ($value === null || $value === '' || $value === '0' || $value === 'false' || $value === false || $value === 0 || $value === 0.0) {
                return true;
            }
            if (is_array($value) && count($value) === 0) {
                return true;
            }
            return false;
        });

        $registry->register('defined', function (mixed $value): bool {
            return $value !== null;
        });

        $registry->register('in_list', function (string $needle, string $haystack): bool {
            $items = array_map('trim', explode(',', $haystack));
            return in_array($needle, $items, true);
        });
    }
}
