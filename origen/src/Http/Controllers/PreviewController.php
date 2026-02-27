<?php

namespace Origen\Http\Controllers;

use Origen\Http\Request;
use Origen\Http\Response;
use Rufinus\Services\PreviewService;
use Origen\Config;

/**
 * Preview Controller
 * 
 * Handles preview requests from the admin interface.
 * Renders content through HTX templates without persisting.
 */
class PreviewController
{
    private PreviewService $previewService;

    public function __construct(Config $config)
    {
        $siteRoot = dirname($config->get('base_path')) . '/rufinus/site';
        $this->previewService = new PreviewService($siteRoot);
    }

    /**
     * POST /api/preview
     * 
     * Preview content by rendering through an HTX template.
     * 
     * Request body:
     * {
     *   "content_type": "article",
     *   "content": { "title": "...", "body": "...", ... },
     *   "template": "articles/[slug].htx" (optional)
     * }
     */
    public function preview(Request $request): Response
    {
        $data = $request->json();
        
        $contentType = $data['content_type'] ?? null;
        $content = $data['content'] ?? [];
        $template = $data['template'] ?? null;

        if (empty($contentType)) {
            return Response::json([
                'success' => false,
                'error' => 'content_type is required'
            ], 400);
        }

        if (empty($content)) {
            return Response::json([
                'success' => false,
                'error' => 'content is required'
            ], 400);
        }

        $result = $this->previewService->preview($contentType, $content, $template);

        if (!$result['success']) {
            return Response::json($result, 400);
        }

        // Return just the HTML for HTMX swapping
        if ($request->header('HX-Request')) {
            return Response::html($result['html']);
        }

        return Response::json($result);
    }

    /**
     * GET /api/preview/status/:content_type
     * 
     * Check if preview is available for a content type.
     */
    public function status(Request $request): Response
    {
        $contentType = $request->param('content_type');

        if (empty($contentType)) {
            return Response::json([
                'available' => false,
                'reason' => 'Content type not specified'
            ]);
        }

        // Check if a template exists for this content type
        $result = $this->previewService->preview($contentType, ['title' => 'test']);

        return Response::json([
            'content_type' => $contentType,
            'available' => $result['success'],
            'template' => $result['template'] ?? null,
            'reason' => $result['success'] ? null : ($result['message'] ?? 'No template found')
        ]);
    }
}
