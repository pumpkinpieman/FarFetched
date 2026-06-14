<?php
declare(strict_types=1);
/**
 * print_images.php — lazy gallery fetch for Printables hover slider.
 * GET ?id={printId}
 * Returns JSON array of full image URLs, or [] on failure.
 * Called once per card on first mouseenter (not on page load).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

header('Content-Type: application/json');

$id = preg_replace('/[^0-9]/', '', (string) ($_GET['id'] ?? ''));
if ($id === '') {
    echo '[]';
    exit;
}

$svc = new PrintablesService(cfg('printables_token'));
if (!$svc->isAuthed()) {
    echo '[]';
    exit;
}

$query = <<<'GQL'
query ModelImages($id: ID!, $limit: Int!, $cursor: String) {
  modelImages: morePrintImages(cursor: $cursor, limit: $limit, printId: $id) {
    items {
      filePath
    }
  }
}
GQL;

$data = $svc->gqlPublic($query, ['id' => $id, 'limit' => 8, 'cursor' => null]);
if (!is_array($data)) {
    echo '[]';
    exit;
}

$items = $data['modelImages']['items'] ?? [];
$urls  = [];
foreach ($items as $img) {
    $fp = (string) ($img['filePath'] ?? '');
    if ($fp === '') continue;
    if (!preg_match('#^https?://#', $fp)) {
        $fp = 'https://media.printables.com/' . ltrim($fp, '/');
    }
    $urls[] = $fp;
}

echo json_encode(array_values(array_unique($urls)));
