<?php

namespace Rufinus\Parser;

use Rufinus\Parser\MetaExtractor;
use Rufinus\Parser\ResponseExtractor;
use Rufinus\Parser\TemplateExtractor;

/**
 * Main DSL Parser for HTX (Hypermedia Templates)
 * 
 * Orchestrates the parsing of HTX DSL blocks by coordinating
 * MetaExtractor, ResponseExtractor, and TemplateExtractor.
 */
class DSLParser
{
    private MetaExtractor $metaExtractor;
    private ResponseExtractor $responseExtractor;
    private TemplateExtractor $templateExtractor;

    public function __construct()
    {
        $this->metaExtractor = new MetaExtractor();
        $this->responseExtractor = new ResponseExtractor();
        $this->templateExtractor = new TemplateExtractor();
    }

    /**
     * Parse an HTX DSL block and return structured data
     * 
     * @param string $dsl The HTX DSL content
     * @return array Parsed data with meta, responses, and template
     */
    public function parse(string $dsl): array
    {
        // Extract meta directives (<htx:*>)
        $meta = $this->metaExtractor->extract($dsl);
        
        // Extract response templates (<htx:response*>)
        $responses = $this->responseExtractor->extract($dsl);
        
        // Extract main template body (<htx>...</htx>)
        $template = $this->templateExtractor->extract($dsl);

        return [
            'meta' => $meta,
            'responses' => $responses,
            'template' => $template,
        ];
    }

    /**
     * Parse DSL and return only the meta directives
     * 
     * @param string $dsl The HTX DSL content
     * @return array Meta directives
     */
    public function extractMeta(string $dsl): array
    {
        return $this->metaExtractor->extract($dsl);
    }

    /**
     * Parse DSL and return only the response templates
     * 
     * @param string $dsl The HTX DSL content
     * @return array Response templates
     */
    public function extractResponses(string $dsl): array
    {
        return $this->responseExtractor->extract($dsl);
    }

    /**
     * Parse DSL and return only the template body
     * 
     * @param string $dsl The HTX DSL content
     * @return string Template body
     */
    public function extractTemplate(string $dsl): string
    {
        return $this->templateExtractor->extract($dsl);
    }
}
