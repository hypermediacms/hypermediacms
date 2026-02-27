<?php

/**
 * MCP Server Configuration
 * 
 * Copy this file to config.php and customize as needed.
 */

return [
    // Origen API URL (for content operations)
    'origen_url' => getenv('ORIGEN_URL') ?: 'http://localhost:8082',

    // Site API key (must match _site.yaml)
    'site_key' => getenv('SITE_KEY') ?: 'htx-default-key',

    // Audit log path (null to disable)
    'audit_log' => __DIR__ . '/../storage/logs/mcp-audit.log',

    // Paths that can be read/written (glob patterns)
    'allowed_paths' => [
        '*.htx',
        '**/*.htx',
        'public/**/*',
    ],

    // Paths that are always denied (takes precedence)
    'denied_paths' => [
        '_*',           // Layout files (optional)
        '.env*',
        'vendor/**',
        'node_modules/**',
    ],

    // Custom schema path (defaults to <project-root>/schemas)
    'schema_path' => null,

    // Tools to enable (empty array = all tools)
    'enabled_tools' => [],
];
