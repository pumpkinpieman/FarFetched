<?php
declare(strict_types=1);

/**
 * job_action.php — per-row queue actions for the WebUI (AJAX, JSON response).
 *   action=delete   -> remove the job from the queue
 *   action=restart  -> re-queue it (status=queued, attempts/error reset)
 * Restart also rescues a job "stuck" in 'working' after a worker died.
 */

require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'POST only']);
    exit;
}
if (!csrf_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Session expired — reload the page.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Missing job id.']);
    exit;
}

$pdo = db();

if ($action === 'delete') {
    $st = $pdo->prepare("DELETE FROM download_jobs WHERE id = :id");
    $st->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'msg' => $st->rowCount() ? 'Removed.' : 'Already gone.']);
    exit;
}

if ($action === 'restart') {
    $st = $pdo->prepare(
        "UPDATE download_jobs
         SET status = 'queued', attempts = 0, last_error = '', saved_path = '',
             updated_at = datetime('now')
         WHERE id = :id"
    );
    $st->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'msg' => $st->rowCount() ? 'Re-queued.' : 'Not found.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
