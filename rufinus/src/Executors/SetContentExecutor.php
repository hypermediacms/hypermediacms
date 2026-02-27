<?php

namespace Rufinus\Executors;

use Rufinus\Parser\DSLParser;
use Rufinus\Services\CentralApiClient;
use Rufinus\Services\Hydrator;
use Rufinus\Expressions\ExpressionEngine;

/**
 * Executor for setContent operations
 *
 * Handles the two-phase execution for creating and updating content
 * through the central server.
 */
class SetContentExecutor
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
     * Execute setContent operation (prepare phase)
     * 
     * @param string $dsl The HTX DSL content
     * @return string Hydrated HTML form
     * @throws \Exception If execution fails
     */
    public function execute(string $dsl): string
    {
        // Phase 1: Parse DSL
        $parsed = $this->parser->parse($dsl);
        $meta = $parsed['meta'];
        $template = $parsed['template'];
        $responses = $parsed['responses'];

        if (empty($template)) {
            throw new \Exception('No template found in DSL');
        }

        // Phase 2: Prepare with central server
        $response = $this->apiClient->prepare($meta, $responses);
        
        if (!isset($response['endpoint']) || !isset($response['payload'])) {
            throw new \Exception('Invalid prepare response from central server');
        }

        // Phase 3: Hydrate template with prepare response
        $hydrationData = [
            'endpoint' => $response['endpoint'],
            'payload' => $response['payload'],
            'recordId' => $response['recordId'] ?? '',
            'title' => $response['title'] ?? '',
            'body' => $response['body'] ?? '',
        ];

        // Add any additional values from the response
        if (isset($response['values']) && is_array($response['values'])) {
            $hydrationData = array_merge($hydrationData, $response['values']);
        }

        if ($this->expressionEngine->hasExpressions($template)) {
            $template = $this->expressionEngine->evaluate($template, $hydrationData);
        }

        return $this->hydrator->hydrateHtmx($template, $hydrationData);
    }

    /**
     * Execute and return structured data instead of HTML
     * 
     * @param string $dsl The HTX DSL content
     * @return array Structured data with meta, template, and prepare response
     * @throws \Exception If execution fails
     */
    public function executeForData(string $dsl): array
    {
        // Phase 1: Parse DSL
        $parsed = $this->parser->parse($dsl);
        $meta = $parsed['meta'];
        $template = $parsed['template'];
        $responses = $parsed['responses'];

        if (empty($template)) {
            throw new \Exception('No template found in DSL');
        }

        // Phase 2: Prepare with central server
        $response = $this->apiClient->prepare($meta, $responses);
        
        if (!isset($response['endpoint']) || !isset($response['payload'])) {
            throw new \Exception('Invalid prepare response from central server');
        }

        return [
            'meta' => $meta,
            'template' => $template,
            'responses' => $responses,
            'prepareResponse' => $response,
            'hydrationData' => [
                'endpoint' => $response['endpoint'],
                'payload' => $response['payload'],
                'recordId' => $response['recordId'] ?? '',
                'title' => $response['title'] ?? '',
                'body' => $response['body'] ?? '',
            ] + ($response['values'] ?? []),
        ];
    }

    /**
     * Create a simple form template for setContent
     * 
     * @param string $dsl The HTX DSL content
     * @return string Simple form HTML
     * @throws \Exception If execution fails
     */
    public function createSimpleForm(string $dsl): string
    {
        $data = $this->executeForData($dsl);
        $hydrationData = $data['hydrationData'];

        // Create a simple form if no template is provided
        $formTemplate = $this->generateDefaultForm($data['meta']);
        
        return $this->hydrator->hydrateHtmx($formTemplate, $hydrationData);
    }

    /**
     * Generate a default form template based on meta
     * 
     * @param array $meta Meta directives
     * @return string Default form template
     */
    private function generateDefaultForm(array $meta): string
    {
        $action = $meta['action'] ?? 'save';
        $type = $meta['type'] ?? 'article';
        
        $form = '<form hx-post="__endpoint__" hx-vals=\'__payload__\'>';
        $form .= '<input type="hidden" name="type" value="' . htmlspecialchars($type) . '">';
        
        if ($action === 'update' && isset($meta['recordId'])) {
            $form .= '<input type="hidden" name="recordId" value="__recordId__">';
        }
        
        $form .= '<div class="form-group">';
        $form .= '<label for="title">Title:</label>';
        $form .= '<input type="text" id="title" name="title" value="__title__" required>';
        $form .= '</div>';
        
        $form .= '<div class="form-group">';
        $form .= '<label for="body">Content:</label>';
        $form .= '<textarea id="body" name="body" required>__body__</textarea>';
        $form .= '</div>';
        
        $form .= '<div class="form-group">';
        $form .= '<label for="status">Status:</label>';
        $form .= '<select id="status" name="status">';
        $form .= '<option value="draft">Draft</option>';
        $form .= '<option value="published">Published</option>';
        $form .= '</select>';
        $form .= '</div>';
        
        $form .= '<button type="submit">' . ucfirst($action) . ' Content</button>';
        $form .= '</form>';
        
        return $form;
    }
}
