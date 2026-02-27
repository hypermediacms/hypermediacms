<?php

namespace Rufinus\Expressions;

use Rufinus\Expressions\Exceptions\ExpressionParseException;

class Lexer
{
    private const MAX_EXPRESSION_LENGTH = 2000;

    /**
     * Tokenize a template string into segments.
     *
     * @return array<array{type: string, content?: string, keyword?: string}>
     */
    public function tokenize(string $template): array
    {
        if ($template === '') {
            return [];
        }

        $segments = [];
        $length = strlen($template);
        $pos = 0;

        while ($pos < $length) {
            // Find next {{
            $start = strpos($template, '{{', $pos);

            if ($start === false) {
                // Rest is plain text
                $text = substr($template, $pos);
                if ($text !== '') {
                    $segments[] = ['type' => 'text', 'content' => $text];
                }
                break;
            }

            // Text before {{
            if ($start > $pos) {
                $segments[] = ['type' => 'text', 'content' => substr($template, $pos, $start - $pos)];
            }

            // Check for raw output flag
            $exprStart = $start + 2;
            $isRaw = false;
            if ($exprStart < $length && $template[$exprStart] === '!') {
                $isRaw = true;
                $exprStart++;
            }

            // Find closing }}
            $end = $this->findClosingBraces($template, $exprStart, $length);

            if ($end === false) {
                throw new ExpressionParseException('Unclosed expression — missing }}');
            }

            $body = trim(substr($template, $exprStart, $end - $exprStart));

            if (strlen($body) > self::MAX_EXPRESSION_LENGTH) {
                throw new ExpressionParseException(
                    'Expression exceeds maximum length of ' . self::MAX_EXPRESSION_LENGTH . ' characters'
                );
            }

            $segments[] = $this->classifyExpression($body, $isRaw);

            $pos = $end + 2; // skip past }}
        }

        return $segments;
    }

    /**
     * Find the closing }} while respecting string literals.
     */
    private function findClosingBraces(string $template, int $from, int $length): int|false
    {
        $pos = $from;
        while ($pos < $length - 1) {
            $ch = $template[$pos];

            // Skip string literals
            if ($ch === '"') {
                $pos = $this->skipString($template, $pos, $length, '"');
                continue;
            }

            if ($template[$pos] === '}' && $template[$pos + 1] === '}') {
                return $pos;
            }

            $pos++;
        }

        return false;
    }

    /**
     * Skip past a quoted string starting at $pos.
     */
    private function skipString(string $template, int $pos, int $length, string $quote): int
    {
        $pos++; // skip opening quote
        while ($pos < $length) {
            if ($template[$pos] === '\\') {
                $pos += 2; // skip escaped char
                continue;
            }
            if ($template[$pos] === $quote) {
                return $pos + 1; // skip closing quote
            }
            $pos++;
        }
        return $pos;
    }

    /**
     * Classify an expression body into the appropriate segment type.
     */
    private function classifyExpression(string $body, bool $isRaw): array
    {
        // Block keywords
        if (preg_match('/^if\s+(.+)$/s', $body, $m)) {
            return ['type' => 'block_open', 'keyword' => 'if', 'content' => trim($m[1])];
        }
        if (preg_match('/^elif\s+(.+)$/s', $body, $m)) {
            return ['type' => 'block_open', 'keyword' => 'elif', 'content' => trim($m[1])];
        }
        if ($body === 'else') {
            return ['type' => 'block_open', 'keyword' => 'else', 'content' => ''];
        }
        if ($body === 'endif') {
            return ['type' => 'block_close', 'keyword' => 'endif'];
        }
        if (preg_match('/^each\s+(.+)$/s', $body, $m)) {
            return ['type' => 'block_open', 'keyword' => 'each', 'content' => trim($m[1])];
        }
        if ($body === 'endeach') {
            return ['type' => 'block_close', 'keyword' => 'endeach'];
        }

        // Expression output
        if ($isRaw) {
            return ['type' => 'raw_expression', 'content' => $body];
        }

        return ['type' => 'expression', 'content' => $body];
    }
}
