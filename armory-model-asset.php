<?php
/**
 * Same-origin model assets, so the browser never needs a localhost service or
 * a generic CORS proxy. Deliberately no session/bootstrap: parallel model asset
 * requests must not hold the PHP session lock or contact the realm database.
 */
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/model-assets.php';
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (empty($config['enable_3d_viewer'])) {
    http_response_code(404);
    exit;
}
$path = $_GET['path'] ?? '';
if (!is_string($path) || !validModelAssetPath($path)) {
    http_response_code(400);
    exit('Invalid asset path.');
}

$directory = rtrim($config['cache_dir'] ?? (__DIR__ . '/cache'), '/\\') . '/model-assets';
$file = $directory . '/' . hash('sha256', $path) . '.asset';
$body = false;
if (is_file($file) && @filemtime($file) > time() - ARMORY_MODEL_ASSET_TTL
    && @filesize($file) <= ARMORY_MODEL_ASSET_MAX_BYTES) {
    $body = @file_get_contents($file);
}
try {
    if ($body === false) {
        $body = fetchModelAsset($path);
        cacheModelAsset($directory, $file, $body);
    }
} catch (Throwable $e) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($method === 'HEAD' ? '' : 'The optional 3D preview is unavailable. Equipment details are unaffected.');
}

$etag = '"' . hash('sha256', $body) . '"';
header('Content-Type: ' . modelAssetMime($path));
header('Cache-Control: public, max-age=' . ARMORY_MODEL_ASSET_TTL);
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($body));
if ($method !== 'HEAD') {
    echo $body;
}
