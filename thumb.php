<?php
declare(strict_types=1);

/**
 * thumb.php — serves a model's thumbnail PNG (rendered from its local PDF).
 * GET: src=<source slug>, model=<folder name>
 * Renders+caches on first hit via model_thumb(); 404s if no PDF/preview.
 * LOCAL ONLY — reads the user's own downloaded PDF, never any server.
 */

require_once __DIR__ . '/bootstrap.php';

$src   = (string) ($_GET['src'] ?? '');
$model = (string) ($_GET['model'] ?? '');

$srcPath = source_path($src);
// Validate model folder name as a single safe segment.
if ($srcPath === null || $model === '' || !preg_match('/^[A-Za-z0-9._ +-]+$/', $model)) {
    http_response_code(404);
    exit;
}
$modelPath = $srcPath . '/' . $model;
if (!is_dir($modelPath)) {
    http_response_code(404);
    exit;
}

$thumb = model_thumb($src, $model, $modelPath);
if ($thumb === null || !is_file($thumb)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
readfile($thumb);
