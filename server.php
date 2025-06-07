<?php

/**
 * Custom router script for PHP's built-in server
 * This ensures all requests are handled by our application,
 * even if they have file extensions that PHP would normally serve directly
 */

// Parse the URL
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// If the request is for a real file that exists, serve it directly
// EXCEPT for .yaml files which should always be handled by our application
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri) && !preg_match('/\.yaml$/i', $uri)) {
    return false;
}

// Otherwise, include the front controller
require_once __DIR__ . '/public/index.php';
