<?php
declare(strict_types=1);
// Keep PHP's development-server index fallback from serving unknown paths.
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($path,['/','/index.html','/api.php','/assets/dashboard.js','/assets/dashboard.css'],true)) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo "Not found\n";
