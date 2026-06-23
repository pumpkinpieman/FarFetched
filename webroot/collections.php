<?php
declare(strict_types=1);

/**
 * collections.php — manage collections and membership.
 *
 * GET  ?list=1                          -> {ok, collections:[{id,name,count}]}
 * GET  ?for=1&src=&folder=              -> {ok, ids:[collectionId,...]}
 * POST {action:'create', name, csrf}
 * POST {action:'add'|'remove', collection_id, src, folder, csrf}
 * POST {action:'delete', collection_id, csrf}
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
header('Content-Type: application/json');

// Resume the session so $_SESSION['csrf'] is available for the token check.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


function co_out(array $p): void { echo json_encode($p); exit; }

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['list'])) {
        $rows = $db->query(
            'SELECT c.id, c.name, COUNT(ci.id) AS cnt
             FROM collections c LEFT JOIN collection_items ci ON ci.collection_id = c.id
             GROUP BY c.id ORDER BY c.name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'count' => (int) $r['cnt']], $rows);
        co_out(['ok' => true, 'collections' => $out]);
    }
    if (isset($_GET['for'])) {
        $st = $db->prepare('SELECT collection_id FROM collection_items WHERE source = :s AND folder = :f');
        $st->execute([':s' => (string) ($_GET['src'] ?? ''), ':f' => (string) ($_GET['folder'] ?? '')]);
        co_out(['ok' => true, 'ids' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))]);
    }
    co_out(['ok' => false]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403); co_out(['ok' => false, 'error' => 'csrf']);
}

$action = (string) ($in['action'] ?? '');

if ($action === 'create') {
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') { http_response_code(400); co_out(['ok' => false]); }
    $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (:n)')->execute([':n' => $name]);
    $id = (int) $db->lastInsertId();
    if ($id === 0) {
        $st = $db->prepare('SELECT id FROM collections WHERE name = :n');
        $st->execute([':n' => $name]); $id = (int) $st->fetchColumn();
    }
    co_out(['ok' => true, 'id' => $id, 'name' => $name]);

} elseif ($action === 'add') {
    $db->prepare('INSERT OR IGNORE INTO collection_items (collection_id, source, folder) VALUES (:c, :s, :f)')
       ->execute([':c' => (int) $in['collection_id'], ':s' => (string) $in['src'], ':f' => (string) $in['folder']]);
    co_out(['ok' => true]);

} elseif ($action === 'remove') {
    $db->prepare('DELETE FROM collection_items WHERE collection_id = :c AND source = :s AND folder = :f')
       ->execute([':c' => (int) $in['collection_id'], ':s' => (string) $in['src'], ':f' => (string) $in['folder']]);
    co_out(['ok' => true]);

} elseif ($action === 'delete') {
    $db->prepare('DELETE FROM collections WHERE id = :c')->execute([':c' => (int) $in['collection_id']]);
    co_out(['ok' => true]);

} elseif ($action === 'rename') {
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') { http_response_code(400); co_out(['ok' => false]); }
    $db->prepare('UPDATE collections SET name = :n WHERE id = :c')
       ->execute([':n' => $name, ':c' => (int) $in['collection_id']]);
    co_out(['ok' => true]);
}

co_out(['ok' => false]);
