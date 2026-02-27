<?php

namespace Rufinus;

use Rufinus\Parser\DSLParser;
use Rufinus\Executors\GetContentExecutor;
use Rufinus\Executors\SetContentExecutor;
use Rufinus\Executors\DeleteContentExecutor;
use Rufinus\Services\CentralApiClient;
use Rufinus\Services\Hydrator;
use Rufinus\Expressions\ExpressionEngine;

/**
 * Main EdgeHTX class - Simple interface for the HTX DSL Parser & Executors
 * 
 * This class provides a convenient interface for using the EdgeHTX library
 * to parse HTX DSL and execute content operations.
 */
class EdgeHTX
{
    private DSLParser $parser;
    private CentralApiClient $apiClient;
    private Hydrator $hydrator;
    private ExpressionEngine $expressionEngine;
    private GetContentExecutor $getExecutor;
    private SetContentExecutor $setExecutor;
    private DeleteContentExecutor $deleteExecutor;

    public function __construct(string $centralUrl, string $siteKey)
    {
        $this->parser = new DSLParser();
        $this->apiClient = new CentralApiClient($centralUrl, $siteKey);
        $this->hydrator = new Hydrator();
        $this->expressionEngine = new ExpressionEngine();

        $this->getExecutor = new GetContentExecutor($this->parser, $this->apiClient, $this->hydrator, $this->expressionEngine);
        $this->setExecutor = new SetContentExecutor($this->parser, $this->apiClient, $this->hydrator, $this->expressionEngine);
        $this->deleteExecutor = new DeleteContentExecutor($this->parser, $this->apiClient, $this->hydrator, $this->expressionEngine);
    }

    /**
     * Execute getContent operation
     * 
     * @param string $dsl The HTX DSL content
     * @return string Final HTML output
     * @throws \Exception If execution fails
     */
    public function getContent(string $dsl): string
    {
        return $this->getExecutor->execute($dsl);
    }

    /**
     * Execute setContent operation
     * 
     * @param string $dsl The HTX DSL content
     * @return string Hydrated HTML form
     * @throws \Exception If execution fails
     */
    public function setContent(string $dsl): string
    {
        return $this->setExecutor->execute($dsl);
    }

    /**
     * Execute deleteContent operation
     * 
     * @param string $dsl The HTX DSL content
     * @return string Hydrated HTML confirmation
     * @throws \Exception If execution fails
     */
    public function deleteContent(string $dsl): string
    {
        return $this->deleteExecutor->execute($dsl);
    }

    /**
     * Parse DSL and return structured data
     * 
     * @param string $dsl The HTX DSL content
     * @return array Parsed data with meta, responses, and template
     */
    public function parse(string $dsl): array
    {
        return $this->parser->parse($dsl);
    }

    /**
     * Get the DSL parser instance
     * 
     * @return DSLParser
     */
    public function getParser(): DSLParser
    {
        return $this->parser;
    }

    /**
     * Get the central API client instance
     * 
     * @return CentralApiClient
     */
    public function getApiClient(): CentralApiClient
    {
        return $this->apiClient;
    }

    /**
     * Get the hydrator instance
     *
     * @return Hydrator
     */
    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }

    /**
     * Get the expression engine instance
     *
     * @return ExpressionEngine
     */
    public function getExpressionEngine(): ExpressionEngine
    {
        return $this->expressionEngine;
    }

    /**
     * Get the get content executor instance
     * 
     * @return GetContentExecutor
     */
    public function getGetExecutor(): GetContentExecutor
    {
        return $this->getExecutor;
    }

    /**
     * Get the set content executor instance
     * 
     * @return SetContentExecutor
     */
    public function getSetExecutor(): SetContentExecutor
    {
        return $this->setExecutor;
    }

    /**
     * Get the delete content executor instance
     * 
     * @return DeleteContentExecutor
     */
    public function getDeleteExecutor(): DeleteContentExecutor
    {
        return $this->deleteExecutor;
    }

    /**
     * Set additional headers for API requests
     * 
     * @param array $headers Additional headers
     * @return void
     */
    public function setHeaders(array $headers): void
    {
        $this->apiClient->setHeaders($headers);
    }

    /**
     * Create a simple getContent call
     * 
     * @param string $type Content type
     * @param int $limit Number of items to retrieve
     * @param string $order Order by field
     * @return string HTML output
     * @throws \Exception If execution fails
     */
    public function getContentSimple(string $type = 'article', int $limit = 10, string $order = 'recent'): string
    {
        $dsl = $this->buildSimpleGetDSL($type, $limit, $order);
        return $this->getContent($dsl);
    }

    /**
     * Create a simple setContent call
     * 
     * @param string $type Content type
     * @param string|null $recordId Record ID for updates
     * @return string HTML form
     * @throws \Exception If execution fails
     */
    public function setContentSimple(string $type = 'article', ?string $recordId = null): string
    {
        $dsl = $this->buildSimpleSetDSL($type, $recordId);
        return $this->setContent($dsl);
    }

    /**
     * Create a simple deleteContent call
     * 
     * @param string $recordId Record ID to delete
     * @return string HTML confirmation
     * @throws \Exception If execution fails
     */
    public function deleteContentSimple(string $recordId): string
    {
        $dsl = $this->buildSimpleDeleteDSL($recordId);
        return $this->deleteContent($dsl);
    }

    /**
     * Build a simple get DSL
     * 
     * @param string $type Content type
     * @param int $limit Number of items
     * @param string $order Order by field
     * @return string HTX DSL
     */
    private function buildSimpleGetDSL(string $type, int $limit, string $order): string
    {
        return "<htx:type>{$type}</htx:type>
<htx:howmany>{$limit}</htx:howmany>
<htx:order>{$order}</htx:order>
<htx>
    <div class=\"content-item\">
        <h3>__title__</h3>
        <p>__body__</p>
        <small>Created: __created_at__</small>
    </div>
</htx>";
    }

    /**
     * Build a simple set DSL
     * 
     * @param string $type Content type
     * @param string|null $recordId Record ID for updates
     * @return string HTX DSL
     */
    private function buildSimpleSetDSL(string $type, ?string $recordId = null): string
    {
        $action = $recordId ? 'update' : 'save';
        $recordIdTag = $recordId ? "<htx:recordId>{$recordId}</htx:recordId>" : '';
        
        return "<htx:action>{$action}</htx:action>
<htx:type>{$type}</htx:type>
{$recordIdTag}
<htx>
    <form hx-post=\"__endpoint__\" hx-vals='__payload__'>
        <div class=\"form-group\">
            <label for=\"title\">Title:</label>
            <input type=\"text\" id=\"title\" name=\"title\" value=\"__title__\" required>
        </div>
        <div class=\"form-group\">
            <label for=\"body\">Content:</label>
            <textarea id=\"body\" name=\"body\" required>__body__</textarea>
        </div>
        <div class=\"form-group\">
            <label for=\"status\">Status:</label>
            <select id=\"status\" name=\"status\">
                <option value=\"draft\">Draft</option>
                <option value=\"published\">Published</option>
            </select>
        </div>
        <button type=\"submit\">" . ucfirst($action) . " Content</button>
    </form>
</htx>";
    }

    /**
     * Build a simple delete DSL
     * 
     * @param string $recordId Record ID to delete
     * @return string HTX DSL
     */
    private function buildSimpleDeleteDSL(string $recordId): string
    {
        return "<htx:action>delete</htx:action>
<htx:recordId>{$recordId}</htx:recordId>
<htx>
    <div class=\"delete-confirmation\">
        <h3>Confirm Deletion</h3>
        <p>Are you sure you want to delete \"__title__\"?</p>
        <div class=\"confirmation-actions\">
            <button type=\"button\" class=\"btn-cancel\" onclick=\"this.closest('.delete-confirmation').remove()\">Cancel</button>
            <button type=\"button\" class=\"btn-delete\" hx-delete=\"__endpoint__\" hx-vals='__payload__\'>Delete</button>
        </div>
    </div>
</htx>";
    }
}
