<?php

namespace Rufinus\Executors;

use Rufinus\Parser\DSLParser;
use Rufinus\Services\CentralApiClient;
use Rufinus\Services\Hydrator;
use Rufinus\Expressions\ExpressionEngine;

/**
 * Executor for deleteContent operations
 *
 * Handles the two-phase execution for deleting content
 * through the central server.
 */
class DeleteContentExecutor
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
     * Execute deleteContent operation (prepare phase)
     * 
     * @param string $dsl The HTX DSL content
     * @return string Hydrated HTML confirmation
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
     * Create a simple confirmation template for deleteContent
     * 
     * @param string $dsl The HTX DSL content
     * @return string Simple confirmation HTML
     * @throws \Exception If execution fails
     */
    public function createSimpleConfirmation(string $dsl): string
    {
        $data = $this->executeForData($dsl);
        $hydrationData = $data['hydrationData'];

        // Create a simple confirmation if no template is provided
        $confirmationTemplate = $this->generateDefaultConfirmation($data['meta']);
        
        return $this->hydrator->hydrateHtmx($confirmationTemplate, $hydrationData);
    }

    /**
     * Generate a default confirmation template based on meta
     * 
     * @param array $meta Meta directives
     * @return string Default confirmation template
     */
    private function generateDefaultConfirmation(array $meta): string
    {
        $recordId = $meta['recordId'] ?? '';
        
        $confirmation = '<div class="delete-confirmation">';
        $confirmation .= '<h3>Confirm Deletion</h3>';
        $confirmation .= '<p>Are you sure you want to delete this content?</p>';
        $confirmation .= '<p><strong>Title:</strong> __title__</p>';
        
        if (!empty($recordId)) {
            $confirmation .= '<p><strong>ID:</strong> __recordId__</p>';
        }
        
        $confirmation .= '<div class="confirmation-actions">';
        $confirmation .= '<button type="button" class="btn-cancel" onclick="this.closest(\'.delete-confirmation\').remove()">Cancel</button>';
        $confirmation .= '<button type="button" class="btn-delete" hx-delete="__endpoint__" hx-vals=\'__payload__\'>Delete</button>';
        $confirmation .= '</div>';
        $confirmation .= '</div>';
        
        return $confirmation;
    }

    /**
     * Create a delete button that triggers confirmation
     * 
     * @param string $dsl The HTX DSL content
     * @param string $buttonText Button text (default: "Delete")
     * @return string Delete button HTML
     * @throws \Exception If execution fails
     */
    public function createDeleteButton(string $dsl, string $buttonText = 'Delete'): string
    {
        $data = $this->executeForData($dsl);
        $hydrationData = $data['hydrationData'];

        $button = '<button type="button" class="btn-delete" ';
        $button .= 'hx-get="__endpoint__" ';
        $button .= 'hx-vals=\'__payload__\' ';
        $button .= 'hx-target="this" ';
        $button .= 'hx-swap="outerHTML">';
        $button .= htmlspecialchars($buttonText);
        $button .= '</button>';

        return $this->hydrator->hydrateHtmx($button, $hydrationData);
    }
}
