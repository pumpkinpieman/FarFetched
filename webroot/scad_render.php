<?php
declare(strict_types=1);

/**
 * scad_render.php — parametric engine for projects.
 *
 * GET  ?id=<projectId>&params=1   -> {ok, available, script, params:[...]}
 * POST { id, values:{...}, fmt:'stl'|'3mf', csrf } -> renders & returns
 *        { ok, file } (relative path in the project work dir, served via
 *        project_file.php) — cached by a hash of (script mtime + values + fmt).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json');

function sr_out(array $p): void { echo json_encode($p); exit; }

// Read the JSON body up front (POST renders send id/values/csrf as JSON, which
// PHP does NOT populate into $_POST). id may arrive via query string (GET) or
// the JSON body (POST).
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

$id = (int) ($_GET['id'] ?? ($in['id'] ?? ($_POST['id'] ?? 0)));
$p = project_get($id);
if (!$p) { http_response_code(404); sr_out(['ok' => false, 'error' => 'Project not found.']); }

$work = realpath((string) $p['work_dir']);
if ($work === false || !is_dir($work)) { http_response_code(404); sr_out(['ok' => false, 'error' => 'Workspace missing.']); }

// Locate the project's .scad script.
$cust = model_customization($work);
$scadRel = ($cust['mode'] === 'parametric' && $cust['engine'] === 'openscad') ? $cust['script'] : null;

// --- GET: parameter list ---
if (isset($_GET['params'])) {
    if ($scadRel === null) {
        sr_out(['ok' => true, 'available' => openscad_available(), 'script' => null, 'params' => []]);
    }
    $params = scad_parse_params($work . '/' . $scadRel);
    sr_out(['ok' => true, 'available' => openscad_available(), 'script' => $scadRel, 'params' => $params]);
}

// --- POST: render ---
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403); sr_out(['ok' => false, 'error' => 'csrf']);
}
if (!openscad_available()) {
    sr_out(['ok' => false, 'error' => 'OpenSCAD is not installed on the server.']);
}
if ($scadRel === null) {
    sr_out(['ok' => false, 'error' => 'This project has no OpenSCAD script.']);
}

$values = (array) ($in['values'] ?? []);
$fmt = ($in['fmt'] ?? 'stl') === '3mf' ? '3mf' : 'stl';
$scadPath = $work . '/' . $scadRel;

// Cache key: script mtime + sorted values + fmt.
ksort($values);
$key = substr(sha1((string) @filemtime($scadPath) . json_encode($values) . $fmt), 0, 16);
$outDir = $work . '/.renders';
@mkdir($outDir, 0775, true);
$outRel = '.renders/' . $key . '.' . $fmt;
$outPath = $work . '/' . $outRel;

if (is_file($outPath) && filesize($outPath) > 0) {
    sr_out(['ok' => true, 'file' => $outRel, 'cached' => true]);
}

@set_time_limit(180);
$r = scad_render($scadPath, $values, $outPath);
if (!$r['ok']) {
    sr_out(['ok' => false, 'error' => $r['error']]);
}
sr_out(['ok' => true, 'file' => $outRel, 'cached' => false]);
