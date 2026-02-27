#!/usr/bin/env php
<?php
/**
 * Hypermedia CMS - MCP Server
 * 
 * Model Context Protocol server for AI-assisted content management.
 * Exposes tools for creating HTX templates, managing content, and previewing.
 * Exposes resources for reading content, schemas, and templates.
 * 
 * Usage: php mcp/server.php
 * 
 * @see https://modelcontextprotocol.io/
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HyperMediaCMS\MCP\MCPServer;

// Tools
use HyperMediaCMS\MCP\Tools\CreateHTXTool;
use HyperMediaCMS\MCP\Tools\ListContentTypesTool;
use HyperMediaCMS\MCP\Tools\PreviewContentTool;
use HyperMediaCMS\MCP\Tools\CreateSchemaTool;
use HyperMediaCMS\MCP\Tools\ListRoutesTool;
use HyperMediaCMS\MCP\Tools\CreateContentTool;
use HyperMediaCMS\MCP\Tools\UpdateContentTool;
use HyperMediaCMS\MCP\Tools\DeleteContentTool;
use HyperMediaCMS\MCP\Tools\ScaffoldSectionTool;
use HyperMediaCMS\MCP\Tools\ReadHTXTool;
use HyperMediaCMS\MCP\Tools\UpdateHTXTool;
use HyperMediaCMS\MCP\Tools\GetContentTool;

// Resources
use HyperMediaCMS\MCP\Resources\ContentListResource;
use HyperMediaCMS\MCP\Resources\ContentItemResource;
use HyperMediaCMS\MCP\Resources\SchemaResource;
use HyperMediaCMS\MCP\Resources\TemplateResource;
use HyperMediaCMS\MCP\Resources\SiteResource;

// Prompts
use HyperMediaCMS\MCP\Prompts\CreateBlogSectionPrompt;
use HyperMediaCMS\MCP\Prompts\CreateLandingPagePrompt;
use HyperMediaCMS\MCP\Prompts\CreatePortfolioPrompt;
use HyperMediaCMS\MCP\Prompts\SetupDocsPrompt;
use HyperMediaCMS\MCP\Prompts\AuditSitePrompt;
use HyperMediaCMS\MCP\Prompts\QuickContentPrompt;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$server = new MCPServer(
    name: 'hypermedia-cms',
    version: '0.6.0'
);

// ============================================
// Register Tools (12)
// ============================================

// Discovery
$server->registerTool(new ListRoutesTool());
$server->registerTool(new ListContentTypesTool());

// Scaffolding  
$server->registerTool(new ScaffoldSectionTool());
$server->registerTool(new CreateHTXTool());
$server->registerTool(new CreateSchemaTool());

// Template Management
$server->registerTool(new ReadHTXTool());
$server->registerTool(new UpdateHTXTool());

// Content Management
$server->registerTool(new GetContentTool());
$server->registerTool(new CreateContentTool());
$server->registerTool(new UpdateContentTool());
$server->registerTool(new DeleteContentTool());

// Preview
$server->registerTool(new PreviewContentTool());

// ============================================
// Register Resources
// ============================================

// Content resources
$server->registerResource(new ContentListResource());      // hcms://content/{type}
$server->registerResource(new ContentItemResource());      // hcms://content/{type}/{slug}

// Schema resources
$server->registerResource(new SchemaResource(isList: true));  // hcms://schemas
$server->registerResource(new SchemaResource(isList: false)); // hcms://schema/{type}

// Template resources
$server->registerResource(new TemplateResource(isList: true));  // hcms://templates
$server->registerResource(new TemplateResource(isList: false)); // hcms://template/{path}

// Site resources
$server->registerResource(new SiteResource('routes'));     // hcms://site/routes
$server->registerResource(new SiteResource('config'));     // hcms://site/config
$server->registerResource(new SiteResource('stats'));      // hcms://site/stats

// ============================================
// Register Prompts (6)
// ============================================

$server->registerPrompt(new CreateBlogSectionPrompt());    // create_blog_section
$server->registerPrompt(new CreateLandingPagePrompt());    // create_landing_page
$server->registerPrompt(new CreatePortfolioPrompt());      // create_portfolio
$server->registerPrompt(new SetupDocsPrompt());            // setup_docs
$server->registerPrompt(new AuditSitePrompt());            // audit_site
$server->registerPrompt(new QuickContentPrompt());         // quick_content

// Run the server (stdio transport)
$server->run();
