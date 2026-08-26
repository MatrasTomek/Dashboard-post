<?php

declare(strict_types=1);

// Dev-only router for `php -S`, mirroring what Apache's public/.htaccess + public/api/.htaccess
// do in production: real static files (uploads, Angular build output) are served as-is;
// everything under /api/ goes through the Slim front controller. Not used in deployment.

$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicRoot = dirname(__DIR__, 2) . '/public';

if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require $publicRoot . '/api/index.php';

    return true;
}

$file = $publicRoot . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server serve the static file directly
}

return false;
