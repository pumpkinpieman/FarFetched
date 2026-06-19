<?php
declare(strict_types=1);

/**
 * save_thumb.php — store a generated thumbnail for one model.
 *
 * POST (JSON): { csrf, src, model, png }   where png is a data URL
 *   ("data:image/png;base64,....") produced by the client-side viewer capture.
 *
 * Writes to  MODELS_ROOT/<src>/<model>/.farfetched/thumb.png
 *
 * Security:
 *   - POST + CSRF required.
 *   - Source slug + model name validated; the model dir must resolve INSIDE
 *     the source dir via realpath() before anything is written.
 *   - Only PNG accepted; payload size capped; decoded bytes re-validated as PNG.
 */

require_once __DIR__ . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

function tfail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tfail('POST required.', 405);
}

// Cap the raw body to ~6 MB (a 512px PNG is well under 1 MB; generous margin).
$raw = file_get_contents('php://input', false, null, 0, 6 * 1024 * 1024) ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    tfail('Invalid JSON body.');
}

// CSRF.
$csrf = (string) ($in['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    tfail('Bad CSRF token.', 403);
}

$src   = (string) ($in['src'] ?? '');
$model = (string) ($in['model'] ?? '');
$png   = (string) ($in['png'] ?? '');

// Validate source -> absolute path.
$srcPath = source_path($src);
if ($srcPath === null) {
    tfail('Invalid source.');
}

// Model name must be a single safe segment (same charset as model_file.php).
if ($model === '' || !preg_match('/^[A-Za-z0-9._ +&,\'()!-]+$/', $model)) {
    tfail('Invalid model name.');
}

// Containment: resolved model dir must sit strictly inside the source dir.
$modelReal = realpath($srcPath . '/' . $model);
$srcReal   = realpath($srcPath);
if ($modelReal === false || $srcReal === false || !is_dir($modelReal)
    || strncmp($modelReal, $srcReal . DIRECTORY_SEPARATOR, strlen($srcReal) + 1) !== 0) {
    tfail('Model folder not found.');
}

// Decode the data URL. Accept only image/png.
if (!preg_match('#^data:image/png;base64,#', $png)) {
    tfail('Only PNG data URLs accepted.');
}
$b64  = substr($png, strpos($png, ',') + 1);
$bytes = base64_decode($b64, true);
if ($bytes === false || strlen($bytes) < 8) {
    tfail('Could not decode image.');
}

// Re-validate the decoded bytes really are a PNG (magic number).
if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
    tfail('Decoded data is not a PNG.');
}

// Cap decoded size at 4 MB.
if (strlen($bytes) > 4 * 1024 * 1024) {
    tfail('Image too large.', 413);
}

// Write into the hidden sidecar folder so it never mixes with model files.
$dir = $modelReal . '/.farfetched';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    tfail('Could not create thumbnail folder.', 500);
}

$tmp  = $dir . '/thumb.tmp';
$dest = $dir . '/thumb.png';
if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $dest)) {
    @unlink($tmp);
    tfail('Could not write thumbnail.', 500);
}

echo json_encode([
    'ok'  => true,
    'url' => 'model_file.php?src=' . rawurlencode($src)
           . '&model=' . rawurlencode($model) . '&thumb=1&t=' . time(),
]);
