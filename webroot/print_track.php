<?php
declare(strict_types=1);

/**
 * print_track.php — manual print journal.
 *
 * GET  ?src=&folder=                  -> {ok, count, notes, last_printed}
 * POST {action:'inc'|'dec'|'set', src, folder, count?, notes?, csrf}
 */

require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json');

// Resume the session so $_SESSION['csrf'] is available for the token check.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


function pt_out(array $p): void { echo json_encode($p); exit; }

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $src = (string) ($_GET['src'] ?? '');
    $folder = (string) ($_GET['folder'] ?? '');
    $st = $db->prepare('SELECT print_count, notes, last_printed FROM prints WHERE source = :s AND folder = :f');
    $st->execute([':s' => $src, ':f' => $folder]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    pt_out(['ok' => true, 'count' => (int) ($r['print_count'] ?? 0),
            'notes' => (string) ($r['notes'] ?? ''), 'last_printed' => (string) ($r['last_printed'] ?? '')]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403); pt_out(['ok' => false, 'error' => 'csrf']);
}

$action = (string) ($in['action'] ?? '');
$src = (string) ($in['src'] ?? '');
$folder = (string) ($in['folder'] ?? '');
if ($src === '' || $folder === '') { http_response_code(400); pt_out(['ok' => false]); }

// Ensure a row exists.
$db->prepare('INSERT OR IGNORE INTO prints (source, folder) VALUES (:s, :f)')
   ->execute([':s' => $src, ':f' => $folder]);

if ($action === 'inc') {
    $db->prepare("UPDATE prints SET print_count = print_count + 1, last_printed = datetime('now') WHERE source = :s AND folder = :f")
       ->execute([':s' => $src, ':f' => $folder]);
} elseif ($action === 'dec') {
    $db->prepare('UPDATE prints SET print_count = MAX(0, print_count - 1) WHERE source = :s AND folder = :f')
       ->execute([':s' => $src, ':f' => $folder]);
} elseif ($action === 'set') {
    $count = max(0, (int) ($in['count'] ?? 0));
    $notes = (string) ($in['notes'] ?? '');
    $db->prepare('UPDATE prints SET print_count = :c, notes = :n WHERE source = :s AND folder = :f')
       ->execute([':c' => $count, ':n' => $notes, ':s' => $src, ':f' => $folder]);
}

$st = $db->prepare('SELECT print_count, notes, last_printed FROM prints WHERE source = :s AND folder = :f');
$st->execute([':s' => $src, ':f' => $folder]);
$r = $st->fetch(PDO::FETCH_ASSOC);
pt_out(['ok' => true, 'count' => (int) ($r['print_count'] ?? 0),
        'notes' => (string) ($r['notes'] ?? ''), 'last_printed' => (string) ($r['last_printed'] ?? '')]);
