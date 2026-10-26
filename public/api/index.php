<?php
declare(strict_types=1);

use SaleSync\Core\ApiError;
use SaleSync\Http\Api;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname($config['database']) . '/app.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $requestId);
try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { throw new ApiError(413, 'Request is too large.'); }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { throw new ApiError(413, 'Request is too large.'); }
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $path = '/' . ltrim(substr($uri, strlen($base)), '/');
    // index.php?route=/... is the rewrite-free fallback for Apache installations.
    if (isset($_GET['route'])) {
        if (!is_string($_GET['route'])) { throw new ApiError(422, 'Invalid route.'); }
        $path = '/' . ltrim($_GET['route'], '/');
    }
    $data = (new Api($config))->dispatch($_SERVER['REQUEST_METHOD'], $path, $raw);
    echo json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (ApiError $error) {
    http_response_code($error->status);
    echo json_encode(['error' => ['message' => $error->getMessage(), 'fields' => (object) $error->fields, 'request_id' => $requestId]]);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'Malformed JSON.', 'request_id' => $requestId]]);
} catch (Throwable $error) {
    error_log("SaleSync [$requestId] " . get_class($error) . ': ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'Something went wrong. Try again later.', 'request_id' => $requestId]]);
}
