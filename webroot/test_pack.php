<?php
declare(strict_types=1);

/**
 * test_pack.php — TEMPORARY end-to-end test of ZIP/pack mode.
 * Usage (in container): php /var/www/html/webroot/test_pack.php <modelId>
 * Proves: getModelPacks -> getPackLink -> downloadToFile, with no per-file loop.
 * Delete after verifying.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

$modelId = $argv[1] ?? '1743150';
$svc = new PrintablesService();

if (!$svc->isAuthed()) {
    fwrite(STDERR, "No token set — add one in Settings first.\n");
    exit(1);
}

echo "1) Fetching packs for model $modelId ...\n";
$packs = $svc->getModelPacks($modelId);
if ($packs === []) {
    fwrite(STDERR, "   No packs / error: {$svc->lastError}\n");
    exit(1);
}
foreach ($packs as $p) {
    printf("   pack id=%s type=%s size=%d\n", $p['id'], $p['fileType'], $p['fileSize']);
}

echo "2) Resolving MODEL_FILES pack link ...\n";
$link = $svc->getPackLink($modelId, 'MODEL_FILES');
if ($link === '') {
    fwrite(STDERR, "   Link refused: {$svc->lastError}\n");
    exit(1);
}
echo "   link: " . substr($link, 0, 90) . "...\n";

echo "3) Downloading the ZIP ...\n";
$dest = '/tmp/pack_test_' . $modelId . '.zip';
$ok = $svc->downloadToFile($link, $dest);
if (!$ok || !is_file($dest)) {
    fwrite(STDERR, "   Download failed: {$svc->lastError}\n");
    exit(1);
}
printf("   SAVED %s (%d bytes)\n", $dest, filesize($dest));
echo "DONE — pack mode works end to end.\n";
