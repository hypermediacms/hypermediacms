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
                $evaluated = $hasExpressions
                    ? $this->expressionEngine->evaluate($itemTemplate, $row)
                    : $itemTemplate;
                $evaluated = $this->processRelBlocks($evaluated, $row);
                $evaluated = $this->processNestBlocks($evaluated, $row);
                $itemsHtml .= $this->hydrator->hydrate($evaluated, $row);
            }

            // Replace the <htx:each> block with hydrated items
            $template = str_replace($matches[0], $itemsHtml, $template);

            // Strip <htx:none> block — it only applies when rows are empty
            $template = preg_replace('/<htx:none>.*?<\/htx:none>/s', '', $template);
        } else {
            // Single item template - use first row
            $data = $rows[0] ?? [];
            if ($this->expressionEngine->hasExpressions($template)) {
                $template = $this->expressionEngine->evaluate($template, $data);
            }
            $template = $this->processRelBlocks($template, $data);
            $template = $this->processNestBlocks($template, $data);
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
        return preg_replace_callback(
            '/<htx:nest\s+name="([^"]+)">(.*?)<\/htx:nest>/s',
            function ($matches) use ($data) {
                $fieldName = $matches[1];
                $innerTemplate = $matches[2];

                // Get the nested data
                $nested = $data[$fieldName] ?? null;

                // Handle string (stored JSON that wasn't decoded upstream)
                if (is_string($nested)) {
                    $nested = json_decode($nested, true);
                }

                if (empty($nested) || !is_array($nested)) {
                    return '';
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

                    // Expression evaluation ({{ if }}, {{ each }}, functions)
                    $evaluated = $hasExpressions
                        ? $this->expressionEngine->evaluate($innerTemplate, $item)
                        : $innerTemplate;

                    // Recurse for nested <htx:nest> blocks
                    $evaluated = $this->processNestBlocks($evaluated, $item);

                    // Placeholder hydration (__field__)
                    $output .= $this->hydrator->hydrate($evaluated, $item);
                }

                return $output;
            },
            $template
        );
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
