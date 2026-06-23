<?php
declare(strict_types=1);

/**
 * project_file.php — serve a mesh file from a project's working directory.
 *
 * GET ?id=<projectId>&file=<relpath>
 *
 * Project files live under PRIVATE_DIR/projects/<id>/ (outside the web root),
 * so they need an explicit, containment-checked server endpoint to reach the
 * 3D viewer. Only STL/3MF/OBJ are served.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); exit; }

$id  = (int) ($_GET['id'] ?? 0);
$rel = (string) ($_GET['file'] ?? '');

$p = project_get($id);
if (!$p || ($rel === '' && !isset($_GET['list']))) {
    http_response_code(404); exit;
}

$work = realpath((string) $p['work_dir']);
if ($work === false || !is_dir($work)) {
    http_response_code(404); exit;
}

// List mode: enumerate the project's mesh/script files (for the editor).
if (isset($_GET['list'])) {
    header('Content-Type: application/json');
    $cust = model_customization($work);
    echo json_encode([
        'ok'       => true,
        'mode'     => $cust['mode'],
        'engine'   => $cust['engine'],
        'script'   => $cust['script'],
        'variants' => $cust['variants'],
    ]);
    exit;
}

$target = realpath($work . '/' . $rel);
if ($target === false || !is_file($target)
    || strncmp($target, $work . DIRECTORY_SEPARATOR, strlen($work) + 1) !== 0) {
    http_response_code(403); exit;
}

$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$types = ['stl' => 'model/stl', '3mf' => 'model/3mf', 'obj' => 'text/plain'];
if (!isset($types[$ext])) {
    http_response_code(415); exit;
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . (string) filesize($target));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($target);
