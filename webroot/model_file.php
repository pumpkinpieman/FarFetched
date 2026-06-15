<?php
declare(strict_types=1);

/**
 * model_file.php — serves local 3D files to the in-browser viewer.
 *
 *   List mode  : ?src=<source>&model=<folder>&list=1
 *                -> JSON [ {rel,name,ext,size}, ... ] of .stl/.3mf files
 *   Stream mode: ?src=<source>&model=<folder>&file=<relative path>
 *                -> raw bytes of one validated file
 *
 * LOCAL ONLY — reads the user's own downloaded files. Hard anti-traversal:
 * the resolved target must be a real file *inside* the model folder, with an
 * .stl/.3mf extension. Mirrors the validation pattern in thumb.php.
 */

require_once __DIR__ . '/bootstrap.php';

$src   = (string) ($_GET['src'] ?? '');
$model = (string) ($_GET['model'] ?? '');

$srcPath = source_path($src); // null unless a valid, existing source slug
if ($srcPath === null || $model === '' || !preg_match('/^[A-Za-z0-9._ +&,\'()!-]+$/', $model)) {
    http_response_code(404);
    exit;
}

$modelReal = realpath($srcPath . '/' . $model);
if ($modelReal === false || !is_dir($modelReal)) {
    http_response_code(404);
    exit;
}

// ---- List mode -------------------------------------------------------------
if (isset($_GET['list'])) {
    header('Content-Type: application/json');
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modelReal, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $ext = strtolower($f->getExtension());
        if ($ext !== 'stl' && $ext !== '3mf') {
            continue;
        }
        $rel = ltrim(substr($f->getPathname(), strlen($modelReal)), '/\\');
        $out[] = [
            'rel'  => str_replace('\\', '/', $rel),
            'name' => $f->getFilename(),
            'ext'  => $ext,
            'size' => $f->getSize(),
        ];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['rel'], $b['rel']));
    echo json_encode($out);
    exit;
}

// ---- Stream mode -----------------------------------------------------------
$rel = (string) ($_GET['file'] ?? '');
if ($rel === '') {
    http_response_code(404);
    exit;
}

$target = realpath($modelReal . '/' . $rel);
// Containment: target must be a real file strictly inside the model folder.
if ($target === false
    || !is_file($target)
    || strncmp($target, $modelReal . DIRECTORY_SEPARATOR, strlen($modelReal) + 1) !== 0) {
    http_response_code(403);
    exit;
}

$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if ($ext !== 'stl' && $ext !== '3mf') {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . ($ext === 'stl' ? 'model/stl' : 'model/3mf'));
header('Content-Length: ' . (string) filesize($target));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($target);
