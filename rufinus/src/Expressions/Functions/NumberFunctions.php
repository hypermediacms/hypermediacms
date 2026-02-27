<?php

namespace Rufinus\Expressions\Functions;

use Rufinus\Expressions\FunctionRegistry;

class NumberFunctions
{
    public static function register(FunctionRegistry $registry): void
    {
        $registry->register('clamp', function (float $value, float $min, float $max): float {
            return max($min, min($max, $value));
        });

        $registry->register('round', function (float $value, int $decimals = 0): float {
            return round($value, $decimals);
        });

        $registry->register('floor', function (float $value): float {
            return floor($value);
        });

        $registry->register('ceil', function (float $value): float {
            return ceil($value);
        });

        $registry->register('abs', function (float $value): float {
            return abs($value);
        });

        $registry->register('number_format', function (float $value, int $decimals = 0, string $thousandsSep = ','): string {
            return number_format($value, $decimals, '.', $thousandsSep);
        });

        $registry->register('percent', function (float $value, float $total): float {
            if ($total == 0) {
                return 0.0;
            }
            return ($value / $total) * 100;
        });
    }
}
