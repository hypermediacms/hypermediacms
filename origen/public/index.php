<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Origen\Bootstrap;
use Origen\Http\Request;

$kernel = Bootstrap::boot(dirname(__DIR__, 2));
$response = $kernel->handle(Request::capture());
$response->send();
