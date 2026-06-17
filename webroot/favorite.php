<?php
/**
 * favorite.php — AJAX endpoint to star/unstar a model.
 *
 * POST JSON: { action: 'add'|'remove'|'toggle', source, model_id,
 *              slug?, name?, creator?, thumb?, price? }
 * Returns:   { ok: bool, favorited: bool, error?: string }
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Accept JSON body or form-encoded.
$raw = file_get_contents('php://input');
$in  = json_decode((string) $raw, true);
if (!is_array($in)) { $in = $_POST; }

$action  = strtolower(trim((string) ($in['action'] ?? 'toggle')));
$source  = strtolower(trim((string) ($in['source'] ?? '')));
$modelId = trim((string) ($in['model_id'] ?? $in['id'] ?? ''));

if ($source === '' || $modelId === '') {
    echo json_encode(['ok' => false, 'error' => 'source and model_id required']);
    exit;
}

$allowed = ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix', 'creality'];
if (!in_array($source, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'unknown source']);
    exit;
}

try {
    if ($action === 'toggle') {
        $action = favorite_exists($source, $modelId) ? 'remove' : 'add';
    }

    if ($action === 'add') {
        favorite_add([
            'source'  => $source,
            'id'      => $modelId,
            'slug'    => (string) ($in['slug'] ?? ''),
            'name'    => (string) ($in['name'] ?? ''),
            'creator' => (string) ($in['creator'] ?? ''),
            'thumb'   => (string) ($in['thumb'] ?? ''),
            'price'   => (int) ($in['price'] ?? 0),
        ]);
        echo json_encode(['ok' => true, 'favorited' => true]);
    } elseif ($action === 'remove') {
        favorite_remove($source, $modelId);
        echo json_encode(['ok' => true, 'favorited' => false]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
} catch (\Throwable $ex) {
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
