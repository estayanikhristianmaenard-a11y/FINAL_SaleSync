<?php
declare(strict_types=1);
// Router for: php -S 127.0.0.1:8080 -t public scripts/serve.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api' || str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require dirname(__DIR__) . '/public/api/index.php';
    return true;
}
$root = realpath(dirname(__DIR__) . '/public');
$file = realpath($root . '/' . $path);
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
if ($path === '/') { require $root . '/index.php'; return true; }
http_response_code(404);
echo 'Not found';
