<?php

namespace Rufinus\Executors;

use Rufinus\Parser\DSLParser;
use Rufinus\Services\CentralApiClient;
use Rufinus\Services\Hydrator;
use Rufinus\Expressions\ExpressionEngine;

/**
 * Executor for getContent operations
 *
 * Handles the two-phase execution for retrieving and displaying content
 * from the central server.
 */
class GetContentExecutor
{
    private DSLParser $parser;
    private CentralApiClient $apiClient;
    private Hydrator $hydrator;
    private ExpressionEngine $expressionEngine;

    public function __construct(DSLParser $parser, CentralApiClient $apiClient, Hydrator $hydrator, ExpressionEngine $expressionEngine)
    {
        $this->parser = $parser;
        $this->apiClient = $apiClient;
        $this->hydrator = $hydrator;
        $this->expressionEngine = $expressionEngine;
    }

    /**
     * Execute getContent operation
     * 
     * @param string $dsl The HTX DSL content
     * @return string Final HTML output
     * @throws \Exception If execution fails
     */
    public function execute(string $dsl): string
    {
        // Phase 1: Parse DSL
        $parsed = $this->parser->parse($dsl);
        $meta = $parsed['meta'];
        $template = $parsed['template'];

        if (empty($template)) {
            throw new \Exception('No template found in DSL');
        }

        // Phase 2: Get data from central server
        $response = $this->apiClient->get($meta);
        
        if (!isset($response['rows']) || !is_array($response['rows'])) {
            throw new \Exception('Invalid response from central server');
        }

        $rows = $response['rows'];

        // Phase 3: Hydrate template with data
        if (empty($rows)) {
            return $this->handleEmptyResult($template, $parsed['responses']);
        }

        return $this->hydrateWithData($template, $rows, $parsed['responses']);
    }

    /**
     * Handle empty result set
     * 
     * @param string $template The main template
     * @param array $responses Response templates
     * @return string HTML for empty result
     */
    private function handleEmptyResult(string $template, array $responses): string
    {
        // Check for <htx:none> tag in template
        if (preg_match('/<htx:none>(.*?)<\/htx:none>/s', $template, $matches)) {
            return trim($matches[1]);
        }

        // Check for none response template
        if (isset($responses['none']['content'])) {
            return $responses['none']['content'];
        }

        // Default empty message
        return '<div class="no-content">No content found.</div>';
    }

    /**
     * Hydrate template with data rows
     * 
     * @param string $template The main template
     * @param array $rows Data rows from central server
     * @param array $responses Response templates
     * @return string Hydrated HTML
     */
    private function hydrateWithData(string $template, array $rows, array $responses): string
    {
        // Check for <htx:each> loop in template
        if (preg_match('/<htx:each>(.*?)<\/htx:each>/s', $template, $matches)) {
            $itemTemplate = $matches[1];
            $itemsHtml = '';
            $hasExpressions = $this->expressionEngine->hasExpressions($itemTemplate);

            foreach ($rows as $row) {
                // Process nest blocks FIRST (they have their own expression context)
                $evaluated = $this->processNestBlocks($itemTemplate, $row);
                $evaluated = $this->processRelBlocks($evaluated, $row);
                // Then evaluate remaining expressions with row data
                $evaluated = $hasExpressions
                    ? $this->expressionEngine->evaluate($evaluated, $row)
                    : $evaluated;
                $itemsHtml .= $this->hydrator->hydrate($evaluated, $row);
            }

            // Replace the <htx:each> block with hydrated items
            $template = str_replace($matches[0], $itemsHtml, $template);

            // Strip <htx:none> block — it only applies when rows are empty
            $template = preg_replace('/<htx:none>.*?<\/htx:none>/s', '', $template);
        } else {
            // Single item template - use first row
            $data = $rows[0] ?? [];
            // Process nest blocks FIRST (they have their own expression context)
            $template = $this->processNestBlocks($template, $data);
            $template = $this->processRelBlocks($template, $data);
            // Then evaluate remaining expressions with parent data
            if ($this->expressionEngine->hasExpressions($template)) {
                $template = $this->expressionEngine->evaluate($template, $data);
            }
            $template = $this->hydrator->hydrate($template, $data);
        }

        return $template;
    }

    /**
     * Process <htx:nest name="fieldName"> blocks for embedded object rendering.
     *
     * For cardinality=many: iterates over array, renders inner template for each item.
     * For cardinality=one: renders once with the single object's context.
     * Supports nested nests via recursion.
     */
    private function processNestBlocks(string $template, array $data): string
    {
        // Find outermost htx:nest tags with proper nesting support
        while (($nestInfo = $this->findOutermostNest($template)) !== null) {
            $fullMatch = $nestInfo['match'];
            $fieldName = $nestInfo['name'];
            $innerTemplate = $nestInfo['content'];

            // Get the nested data
            $nested = $data[$fieldName] ?? null;

            // Handle string (stored JSON that wasn't decoded upstream)
            if (is_string($nested)) {
                $nested = json_decode($nested, true);
            }

            if (empty($nested) || !is_array($nested)) {
                $template = str_replace($fullMatch, '', $template);
                continue;
            }

            // Detect cardinality: if it has sequential numeric keys, it's many
            // If it has string keys (like 'src', 'alt'), it's one
            $isMany = array_keys($nested) === range(0, count($nested) - 1);

            if (!$isMany) {
                // Cardinality=one: wrap single object for uniform iteration
                $nested = [$nested];
            }

            $output = '';
            $total = count($nested);
            $hasExpressions = $this->expressionEngine->hasExpressions($innerTemplate);

            foreach ($nested as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }

                // Inject loop metadata
                $item['loop'] = [
                    'index' => $i,
                    'count' => $i + 1,
                    'first' => $i === 0,
                    'last' => $i === $total - 1,
                    'length' => $total,
                ];

                // Inject parent reference for bubbling access
                $item['$parent'] = $data;

                // Recurse for nested <htx:nest> blocks FIRST
                // (they have their own expression context)
                $evaluated = $this->processNestBlocks($innerTemplate, $item);

                // Then evaluate expressions with current item context
                $evaluated = $hasExpressions
                    ? $this->expressionEngine->evaluate($evaluated, $item)
                    : $evaluated;

                // Placeholder hydration (__field__)
                $output .= $this->hydrator->hydrate($evaluated, $item);
            }

            $template = str_replace($fullMatch, $output, $template);
        }

        return $template;
    }

    /**
     * Find the first outermost <htx:nest> tag with proper nesting support.
     *
     * @return array|null ['match' => full tag, 'name' => field name, 'content' => inner content]
     */
    private function findOutermostNest(string $template): ?array
    {
        // Find opening tag
        if (!preg_match('/<htx:nest\s+name="([^"]+)">/', $template, $openMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $fieldName = $openMatch[1][0];
        $startPos = $openMatch[0][1];
        $openTagEnd = $startPos + strlen($openMatch[0][0]);

        // Now find the matching closing tag, respecting nesting
        $depth = 1;
        $pos = $openTagEnd;
        $len = strlen($template);

        while ($pos < $len && $depth > 0) {
            // Find next opening or closing tag
            $nextOpen = strpos($template, '<htx:nest ', $pos);
            $nextClose = strpos($template, '</htx:nest>', $pos);

            if ($nextClose === false) {
                // No closing tag found - malformed template
                return null;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                // Found another opening tag first
                $depth++;
                $pos = $nextOpen + 10; // Skip past '<htx:nest '
            } else {
                // Found closing tag
                $depth--;
                if ($depth === 0) {
                    // This is our matching close
                    $innerContent = substr($template, $openTagEnd, $nextClose - $openTagEnd);
                    $fullMatch = substr($template, $startPos, $nextClose + 11 - $startPos); // 11 = strlen('</htx:nest>')
                    return [
                        'match' => $fullMatch,
                        'name' => $fieldName,
                        'content' => $innerContent,
                    ];
                }
                $pos = $nextClose + 11;
            }
        }

        return null;
    }

    /**
     * Process <htx:rel name="fieldName"> blocks for relationship rendering.
     *
     * Each related item goes through expression evaluation → hydration.
     * For cardinality=one (single object with 'id' key), wraps in array for uniform iteration.
     */
    private function processRelBlocks(string $template, array $data): string
    {
        return preg_replace_callback(
            '/<htx:rel\s+name="([^"]+)">(.*?)<\/htx:rel>/s',
            function ($matches) use ($data) {
                $fieldName = $matches[1];
                $innerTemplate = $matches[2];

                if (!isset($data[$fieldName]) || !is_array($data[$fieldName])) {
                    return '';
                }

                $related = $data[$fieldName];

                // Empty array or null
                if (empty($related)) {
                    return '';
                }

                // Cardinality=one: single object with 'id' key — wrap in array
                if (isset($related['id'])) {
                    $related = [$related];
                }

                $output = '';
                $hasExpressions = $this->expressionEngine->hasExpressions($innerTemplate);

                foreach ($related as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $evaluated = $hasExpressions
                        ? $this->expressionEngine->evaluate($innerTemplate, $item)
                        : $innerTemplate;
                    $output .= $this->hydrator->hydrate($evaluated, $item);
                }

                return $output;
            },
            $template
        );
    }

    /**
     * Execute and return structured data instead of HTML
     * 
     * @param string $dsl The HTX DSL content
     * @return array Structured data with meta, template, and rows
     * @throws \Exception If execution fails
     */
    public function executeForData(string $dsl): array
    {
        // Phase 1: Parse DSL
        $parsed = $this->parser->parse($dsl);
        $meta = $parsed['meta'];
        $template = $parsed['template'];

        if (empty($template)) {
            throw new \Exception('No template found in DSL');
        }

        // Phase 2: Get data from central server
        $response = $this->apiClient->get($meta);
        
        if (!isset($response['rows']) || !is_array($response['rows'])) {
            throw new \Exception('Invalid response from central server');
        }

        return [
            'meta' => $meta,
            'template' => $template,
            'responses' => $parsed['responses'],
            'rows' => $response['rows'],
            'total' => $response['total'] ?? count($response['rows']),
        ];
    }
}
