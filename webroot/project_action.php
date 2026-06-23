<?php
declare(strict_types=1);

/**
 * project_action.php — project lifecycle endpoint for the Customize page.
 *
 * POST (JSON), CSRF-guarded:
 *   { action:'create', name, src, folder, csrf }   -> copy source into a project
 *   { action:'state',  id, state, csrf }            -> save pose/param state
 *   { action:'export', id, file, name, csrf }       -> write a file to /models/poses
 *   { action:'delete', id, csrf }                   -> remove a project
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

function pj_out(array $p): void { echo json_encode($p); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); pj_out(['ok' => false, 'error' => 'POST required']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403); pj_out(['ok' => false, 'error' => 'csrf']);
}

$action = (string) ($in['action'] ?? '');

switch ($action) {
    case 'create':
        $r = project_create(
            (string) ($in['name'] ?? ''),
            (string) ($in['src'] ?? ''),
            (string) ($in['folder'] ?? '')
        );
        pj_out($r);

    case 'state':
        $id = (int) ($in['id'] ?? 0);
        if (!project_get($id)) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        project_set_state($id, (array) ($in['state'] ?? []));
        pj_out(['ok' => true]);

    case 'export':
        $r = project_export(
            (int) ($in['id'] ?? 0),
            (string) ($in['file'] ?? ''),
            (string) ($in['name'] ?? 'pose')
        );
        pj_out($r);

    case 'export_raw':
        // Save a raw STL string (from the arrange engine) into /models/poses.
        $id = (int) ($in['id'] ?? 0);
        $name = (string) ($in['name'] ?? 'pose');
        $stl = (string) ($in['stl'] ?? '');
        if (strlen($stl) < 50 || stripos($stl, 'solid') === false) {
            pj_out(['ok' => false, 'error' => 'Invalid mesh data.']);
        }
        if (!project_get($id)) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        if (!poses_writable()) { pj_out(['ok' => false, 'error' => 'The /models/poses folder is not writable.']); }
        $name = trim(preg_replace('/[^A-Za-z0-9 _.\-]+/', '', $name) ?: 'pose');
        $poses = poses_dir();
        if (!is_dir($poses)) { @mkdir($poses, 0775, true); }
        $folder = $poses . '/' . $name; $final = $folder; $n = 2;
        while (is_dir($final)) { $final = $folder . ' (' . $n . ')'; $n++; }
        if (!@mkdir($final, 0775, true)) { pj_out(['ok' => false, 'error' => 'Could not create export folder.']); }
        if (@file_put_contents($final . '/' . $name . '.stl', $stl) === false) {
            pj_out(['ok' => false, 'error' => 'Could not write file.']);
        }
        pj_out(['ok' => true, 'folder' => basename($final), 'file' => $name . '.stl']);

    case 'delete':
        project_delete((int) ($in['id'] ?? 0));
        pj_out(['ok' => true]);

    default:
        pj_out(['ok' => false, 'error' => 'Unknown action']);
}
