<?php

return [
    'app_key' => env('APP_KEY', ''),
    'content_path' => env('CONTENT_PATH', dirname(__DIR__, 2) . '/content'),
    'schema_path' => env('SCHEMA_PATH', dirname(__DIR__, 2) . '/schemas'),
    'db_path' => env('DB_PATH', dirname(__DIR__, 2) . '/storage/index/origen.db'),
    'server_host' => env('SERVER_HOST', '127.0.0.1'),
    'server_port' => env('SERVER_PORT', '8080'),
    'debug' => env('DEBUG', false),
];
