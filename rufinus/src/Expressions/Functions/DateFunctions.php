<?php

namespace Rufinus\Expressions\Functions;

use Rufinus\Expressions\FunctionRegistry;

class DateFunctions
{
    public static function register(FunctionRegistry $registry): void
    {
        $registry->register('format_date', function (string $value, string $format): string {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return $value; // Return raw value if unparseable
            }
            return date($format, $timestamp);
        });

        $registry->register('time_ago', function (string $value): string {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return $value;
            }

            $now = time();
            $diff = $now - $timestamp;
            $abs = abs($diff);
            $future = $diff < 0;

            if ($abs < 60) {
                return 'just now';
            }

            $units = [
                [60, 'minute'],
                [3600, 'hour'],
                [86400, 'day'],
                [2592000, 'month'], // 30 days
                [31536000, 'year'], // 365 days
            ];

            $label = '';
            $count = 0;

            for ($i = count($units) - 1; $i >= 0; $i--) {
                if ($abs >= $units[$i][0]) {
                    $divisor = $units[$i][0];
                    $label = $units[$i][1];
                    $count = (int) floor($abs / $divisor);
                    break;
                }
            }

            // Fallback for < 60 seconds (shouldn't reach here, but be safe)
            if ($label === '') {
                return 'just now';
            }

            $plural = $count !== 1 ? 's' : '';

            if ($future) {
                return "in {$count} {$label}{$plural}";
            }

            return "{$count} {$label}{$plural} ago";
        });

        $registry->register('days_since', function (string $value): int {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return 0;
            }
            return (int) floor((time() - $timestamp) / 86400);
        });

        $registry->register('is_past', function (string $value): bool {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return false;
            }
            return $timestamp < time();
        });

        $registry->register('is_future', function (string $value): bool {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return false;
            }
            return $timestamp > time();
        });

        $registry->register('year', function (string $value): string {
            $timestamp = self::toTimestamp($value);
            if ($timestamp === false) {
                return '';
            }
            return date('Y', $timestamp);
        });
    }

    private static function toTimestamp(string $value): int|false
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : false;
    }
}
