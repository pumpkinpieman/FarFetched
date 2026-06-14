<?php
declare(strict_types=1);
/**
 * thing_images.php — lazy gallery fetch for Thingiverse hover slider.
 * GET ?id={thingId}
 * Returns JSON array of full image URLs, or [] on failure.
 * Called once per card on first mouseenter.
 *
 * Endpoint: GET /things/{id}/images
 * Returns: [{url, sizes:{medium:{url}, large:{url}}, ...}]
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ThingiverseService.php';

header('Content-Type: application/json');

$id = preg_replace('/[^0-9]/', '', (string) ($_GET['id'] ?? ''));
if ($id === '') { echo '[]'; exit; }

$tv = new ThingiverseService();
if (!$tv->isAuthed()) { echo '[]'; exit; }

$urls = $tv->getThingImages($id);
echo json_encode(array_values(array_unique($urls)));
