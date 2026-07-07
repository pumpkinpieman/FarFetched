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

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true) ?: [];
// Binary exports arrive as multipart/form-data (a Blob upload), where the body
// isn't JSON — fall back to $_POST for the scalar fields (csrf/action/id/name).
if (!$in && !empty($_POST)) {
    $in = $_POST;
}
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403); pj_out(['ok' => false, 'error' => 'csrf']);
}

$action = (string) ($in['action'] ?? '');

/** Validate a binary STL by geometry (header + uint32 count + 50 B/tri). */
function ff_validate_binstl(string $bin): array
{
    $len = strlen($bin);
    if ($len < 84) { return [false, 'Uploaded mesh too small (' . $len . ' bytes).']; }
    $tri = (int) (unpack('V', substr($bin, 80, 4))[1] ?? 0);
    if ($tri < 1 || $len !== 84 + $tri * 50) {
        return [false, 'Corrupt binary STL (declared ' . $tri . ' tris, ' . $len . ' bytes).'];
    }
    return [true, null];
}

/** Write mesh bytes to a fresh /models/poses/{name} folder. */
function ff_pose_write(string $name, string $bin): array
{
    $name = trim(preg_replace('/[^A-Za-z0-9 _.\-]+/', '', $name) ?: 'pose');
    $poses = poses_dir();
    if (!is_dir($poses)) { @mkdir($poses, 0775, true); }
    $folder = $poses . '/' . $name; $final = $folder; $n = 2;
    while (is_dir($final)) { $final = $folder . ' (' . $n . ')'; $n++; }
    if (!@mkdir($final, 0775, true)) { return ['ok' => false, 'error' => 'Could not create export folder.']; }
    if (@file_put_contents($final . '/' . $name . '.stl', $bin) === false) {
        return ['ok' => false, 'error' => 'Could not write file.'];
    }
    return ['ok' => true, 'folder' => basename($final), 'file' => $name . '.stl'];
}

/** Directory for buffered chunked uploads (writable, never /tmp). */
function ff_upload_dir(): string
{
    $d = PRIVATE_DIR . '/tmp/uploads';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    // Opportunistic GC of stale (>1h) partial uploads.
    foreach (glob($d . '/*.part') ?: [] as $f) {
        if (@filemtime($f) < time() - 3600) { @unlink($f); }
    }
    return $d;
}

switch ($action) {
    case 'create':
        $r = project_create(
            (string) ($in['name'] ?? ''),
            (string) ($in['src'] ?? ''),
            (string) ($in['folder'] ?? '')
        );
        pj_out($r);

    case 'create_design':
        $r = project_create_blank(
            (string) ($in['name'] ?? ''),
            (string) ($in['designMode'] ?? 'code')
        );
        pj_out($r);

    case 'write_scad':
        $id = (int) ($in['id'] ?? 0);
        $code = (string) ($in['code'] ?? '');
        if (!project_get($id)) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        if (strlen($code) > 2 * 1024 * 1024) { pj_out(['ok' => false, 'error' => 'Script too large.']); }
        if (!project_write_scad($id, $code)) { pj_out(['ok' => false, 'error' => 'Could not write script.']); }
        if (isset($in['state']) && is_array($in['state'])) {
            project_set_state($id, (array) $in['state']);
        }
        pj_out(['ok' => true]);

    case 'write_parts': {
        // Write one parts/{eid}.scad per top-level node element so each becomes an
        // individually selectable mesh. Only rewrite a file when its content
        // actually changed (keeps mtime stable -> render cache stays warm), and
        // prune part files for elements that no longer exist.
        $id = (int) ($in['id'] ?? 0);
        $p = project_get($id);
        if (!$p) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        $work = realpath((string) $p['work_dir']);
        if ($work === false || !is_dir($work)) { pj_out(['ok' => false, 'error' => 'Workspace missing.']); }

        $parts = is_array($in['parts'] ?? null) ? $in['parts'] : [];
        if (count($parts) > 200) { pj_out(['ok' => false, 'error' => 'Too many elements.']); }

        $dir = $work . '/parts';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            pj_out(['ok' => false, 'error' => 'Could not create parts folder.']);
        }

        $keep = []; $order = [];
        foreach ($parts as $part) {
            $eid = preg_replace('/[^A-Za-z0-9]/', '', (string) ($part['eid'] ?? ''));
            $code = (string) ($part['code'] ?? '');
            if ($eid === '' || strlen($code) > 1024 * 1024) { continue; }
            $file = $dir . '/' . $eid . '.scad';
            // Write only on change so unchanged elements keep their mtime (cache hit).
            if (!is_file($file) || (string) @file_get_contents($file) !== $code) {
                if (@file_put_contents($file, $code, LOCK_EX) === false) {
                    pj_out(['ok' => false, 'error' => 'Could not write part ' . $eid . '.']);
                }
            }
            $keep[$eid] = true; $order[] = $eid;
        }

        // Prune orphaned part scripts (and their cached renders indirectly).
        foreach (glob($dir . '/*.scad') ?: [] as $existing) {
            $eid = basename($existing, '.scad');
            if (!isset($keep[$eid])) { @unlink($existing); }
        }

        if (isset($in['state']) && is_array($in['state'])) {
            project_set_state($id, (array) $in['state']);
        }
        pj_out(['ok' => true, 'parts' => $order]);
    }

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

    case 'export_chunk': {
        // Buffer a base64 chunk of a binary STL to PRIVATE_DIR (writable). Used
        // for meshes too large for a single JSON body (post_max_size).
        $uploadId = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($in['uploadId'] ?? ''));
        $seq   = (int) ($in['seq'] ?? -1);
        $total = (int) ($in['total'] ?? 0);
        $data  = base64_decode((string) ($in['data'] ?? ''), true);
        if ($uploadId === '' || $seq < 0 || $total < 1 || $total > 100000 || $data === false) {
            pj_out(['ok' => false, 'error' => 'Bad chunk.']);
        }
        $path = ff_upload_dir() . '/' . $uploadId . '.part';
        if ($seq === 0) { @unlink($path); }
        $cur = is_file($path) ? (int) filesize($path) : 0;
        if ($cur + strlen($data) > 104857600) { @unlink($path); pj_out(['ok' => false, 'error' => 'Upload exceeds 100 MB.']); }
        if (@file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) {
            pj_out(['ok' => false, 'error' => 'Could not buffer chunk.']);
        }
        pj_out(['ok' => true, 'seq' => $seq]);
    }

    case 'export_finalize': {
        $id = (int) ($in['id'] ?? 0);
        $name = (string) ($in['name'] ?? 'pose');
        $uploadId = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($in['uploadId'] ?? ''));
        if (!project_get($id)) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        if (!poses_writable()) { pj_out(['ok' => false, 'error' => 'The /models/poses folder is not writable.']); }
        $path = ff_upload_dir() . '/' . $uploadId . '.part';
        if ($uploadId === '' || !is_file($path)) { pj_out(['ok' => false, 'error' => 'Upload not found or expired.']); }
        $bin = (string) @file_get_contents($path);
        @unlink($path);
        [$ok, $err] = ff_validate_binstl($bin);
        if (!$ok) { pj_out(['ok' => false, 'error' => $err]); }
        pj_out(ff_pose_write($name, $bin));
    }

    case 'export_raw': {
        // Single-body save (small meshes): base64 in JSON, else multipart, else
        // legacy ASCII. Large meshes use export_chunk/export_finalize.
        $id = (int) ($in['id'] ?? 0);
        $name = (string) ($in['name'] ?? 'pose');
        if (!project_get($id)) { pj_out(['ok' => false, 'error' => 'Project not found.']); }
        if (!poses_writable()) { pj_out(['ok' => false, 'error' => 'The /models/poses folder is not writable.']); }

        $bin = null;
        if (!empty($in['stl_b64'])) {
            $bin = base64_decode((string) $in['stl_b64'], true);
            if ($bin === false) { pj_out(['ok' => false, 'error' => 'Malformed base64 mesh.']); }
            [$ok, $err] = ff_validate_binstl($bin);
            if (!$ok) { pj_out(['ok' => false, 'error' => $err]); }
        } elseif (($up = $_FILES['stl'] ?? null) !== null) {
            $e = (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($e !== UPLOAD_ERR_OK) { pj_out(['ok' => false, 'error' => 'Upload failed (PHP code ' . $e . ').']); }
            if (!is_uploaded_file($up['tmp_name'] ?? '')) { pj_out(['ok' => false, 'error' => 'Upload not received.']); }
            $bin = (string) @file_get_contents($up['tmp_name']);
            [$ok, $err] = ff_validate_binstl($bin);
            if (!$ok) { pj_out(['ok' => false, 'error' => $err]); }
        } else {
            $stl = (string) ($in['stl'] ?? '');
            if ($stl === '') { pj_out(['ok' => false, 'error' => 'No mesh data received.']); }
            if (strlen($stl) < 50 || stripos($stl, 'solid') === false) {
                pj_out(['ok' => false, 'error' => 'Invalid mesh data.']);
            }
            $bin = $stl;
        }
        pj_out(ff_pose_write($name, $bin));
    }

    case 'rename':
        project_rename((int) ($in['id'] ?? 0), (string) ($in['name'] ?? ''));
        pj_out(['ok' => true]);

    case 'delete':
        project_delete((int) ($in['id'] ?? 0));
        pj_out(['ok' => true]);

    default:
        pj_out(['ok' => false, 'error' => 'Unknown action']);
}
