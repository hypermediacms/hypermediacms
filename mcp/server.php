#!/usr/bin/env php
<?php
/**
 * Hypermedia CMS - MCP Server
 * 
 * Model Context Protocol server for AI-assisted content management.
 * Exposes tools for creating HTX templates, managing content, and previewing.
 * 
 * Usage: php mcp/server.php
 * 
 * @see https://modelcontextprotocol.io/
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HyperMediaCMS\MCP\MCPServer;
use HyperMediaCMS\MCP\Tools\CreateHTXTool;
use HyperMediaCMS\MCP\Tools\ListContentTypesTool;
use HyperMediaCMS\MCP\Tools\PreviewContentTool;
use HyperMediaCMS\MCP\Tools\CreateSchemaTool;
use HyperMediaCMS\MCP\Tools\ListRoutesTool;
use HyperMediaCMS\MCP\Tools\CreateContentTool;
use HyperMediaCMS\MCP\Tools\UpdateContentTool;
use HyperMediaCMS\MCP\Tools\ScaffoldSectionTool;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$server = new MCPServer(
    name: 'hypermedia-cms',
    version: '0.2.0'
);

// Register tools - Discovery
$server->registerTool(new ListRoutesTool());
$server->registerTool(new ListContentTypesTool());

// Register tools - Scaffolding  
$server->registerTool(new ScaffoldSectionTool());
$server->registerTool(new CreateHTXTool());
$server->registerTool(new CreateSchemaTool());

// Register tools - Content Management
$server->registerTool(new CreateContentTool());
$server->registerTool(new UpdateContentTool());

// Register tools - Preview
$server->registerTool(new PreviewContentTool());

// Run the server (stdio transport)
$server->run();
