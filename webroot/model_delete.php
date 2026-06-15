<?php
declare(strict_types=1);

/**
 * model_delete.php — delete one or more model folders/zips from a source.
 *
 * POST (JSON): { csrf, src, models: ["folder name", "another.zip", ...] }
 *
 * Security:
 *   - POST + CSRF token required.
 *   - Source slug validated to a single safe segment (no traversal).
 *   - Each model name validated to a single path segment, then confirmed to
 *     resolve INSIDE the source dir via realpath() before any deletion.
 *   - Recursive delete is bounded to the resolved model path.
 */

require_once __DIR__ . '/bootstrap.php';

// Resume the session so $_SESSION['csrf'] (minted by the viewer page) is
// available for the token check below. Without this the session is empty and
// every request fails CSRF validation.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST required.', 405);
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    fail('Invalid JSON body.');
}

// CSRF.
$csrf = (string) ($in['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    fail('Bad CSRF token.', 403);
}

$src    = (string) ($in['src'] ?? '');
$models = $in['models'] ?? [];
if (!is_array($models) || $models === []) {
    fail('No models specified.');
}

// Validate source -> absolute path (rejects traversal + non-existent).
$srcPath = source_path($src);
if ($srcPath === null) {
    fail('Invalid source.');
}
$srcReal = realpath($srcPath);
if ($srcReal === false) {
    fail('Source path not resolvable.');
}

$deleted = [];
$errors  = [];

foreach ($models as $model) {
    $model = (string) $model;

    // Must be a single safe path segment (no slashes, no traversal).
    if ($model === '' || strpbrk($model, "/\\") !== false || $model === '.' || $model === '..') {
        $errors[] = ['model' => $model, 'error' => 'invalid name'];
        continue;
    }

    $target     = $srcReal . DIRECTORY_SEPARATOR . $model;
    $targetReal = realpath($target);

    // Confirm the resolved target sits directly inside the source dir.
    if ($targetReal === false
        || strncmp($targetReal . DIRECTORY_SEPARATOR, $srcReal . DIRECTORY_SEPARATOR, strlen($srcReal) + 1) !== 0
        || dirname($targetReal) !== $srcReal) {
        $errors[] = ['model' => $model, 'error' => 'outside source / not found'];
        continue;
    }

    if (rrmdir_safe($targetReal)) {
        $deleted[] = $model;
    } else {
        $errors[] = ['model' => $model, 'error' => 'delete failed (check permissions)'];
    }
}

echo json_encode([
    'ok'      => $errors === [],
    'deleted' => $deleted,
    'errors'  => $errors,
]);

/**
 * Recursively delete a directory or a single file, bounded to $path.
 * Returns true on full success.
 */
function rrmdir_safe(string $path): bool
{
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return false;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $ok = true;
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir()) {
            $ok = @rmdir($item->getPathname()) && $ok;
        } else {
            $ok = @unlink($item->getPathname()) && $ok;
        }
    }
    return @rmdir($path) && $ok;
}
