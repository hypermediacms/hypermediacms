<?php
/**
 * Rufinus runtime entry point for the proof-of-concept site.
 *
 * Usage:
 *   cd cms/rufinus/site
 *   php -S localhost:8081 serve.php
 *
 * Requires Origen running on localhost:8080.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Rufinus\Runtime\RequestHandler;

$handler = new RequestHandler();
$response = $handler->handle(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    getallheaders(),
    __DIR__,                           // site root (pages live here)
    'http://localhost:8080',           // Origen API server URL
    'htx-starter-key-001'              // site API key (matches _site.yaml)
);

// Null response = static file, let PHP built-in server handle it
if ($response === null) {
    return false;
}

http_response_code($response->status);
foreach ($response->headers as $k => $v) {
    header("{$k}: {$v}");
}
echo $response->body;
