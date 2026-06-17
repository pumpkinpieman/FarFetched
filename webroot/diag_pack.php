<?php
/**
 * Diagnose a single Printables pack download/extract end-to-end.
 * Run inside the FarFetched container:
 *   docker exec FarFetched php /var/www/html/webroot/diag_pack.php 1755813
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

$modelId = $argv[1] ?? '1755813';
echo "=== Diagnosing Printables model $modelId ===\n\n";

$svc = new PrintablesService();

// 1. Resolve the pack link.
echo "[1] Resolving pack link...\n";
$link = $svc->getPackLink($modelId, 'MODEL_FILES');
if ($link === '') {
    echo "    FAILED: " . ($svc->lastError ?: 'no link') . "\n";
    echo "    Trying OTHER_FILES pack type...\n";
    $link = $svc->getPackLink($modelId, 'OTHER_FILES');
}
if ($link === '') {
    echo "    No pack link at all. lastError=" . $svc->lastError . "\n";
    exit(1);
}
echo "    OK: " . substr($link, 0, 90) . "...\n\n";

// 2. Download the ZIP to a temp file.
$tmp = sys_get_temp_dir() . "/diag_$modelId.zip";
@unlink($tmp);
echo "[2] Downloading pack to $tmp ...\n";
$ok = $svc->downloadToFile($link, $tmp, function ($dt, $dn) {
    static $last = 0;
    if ($dn > 0 && microtime(true) - $last > 1) {
        $last = microtime(true);
        printf("    ... %s / %s\n", number_format($dt), number_format($dn));
    }
});
if (!$ok) {
    echo "    DOWNLOAD FAILED: " . $svc->lastError . "\n";
    exit(1);
}
$size = filesize($tmp);
echo "    OK: downloaded " . number_format((int) $size) . " bytes\n\n";

// 3. Inspect the ZIP.
echo "[3] Inspecting the archive...\n";
if (!class_exists('ZipArchive')) {
    echo "    ZipArchive NOT AVAILABLE in this PHP build! (pack extraction can't work)\n";
    echo "    -> install/enable the zip extension.\n";
    exit(1);
}
$za = new ZipArchive();
$open = $za->open($tmp);
if ($open !== true) {
    echo "    ZIP OPEN FAILED (code $open). The file may not be a valid zip.\n";
    // Peek at the first bytes to see what it actually is.
    $fh = fopen($tmp, 'rb');
    $head = fread($fh, 16);
    fclose($fh);
    echo "    First bytes (hex): " . bin2hex($head) . "\n";
    echo "    (A real zip starts with 504b — 'PK'. HTML/JSON error pages won't.)\n";
    exit(1);
}
echo "    OK: archive opened, " . $za->numFiles . " entries:\n";
for ($i = 0; $i < min($za->numFiles, 25); $i++) {
    $name = $za->getNameIndex($i);
    $stat = $za->statIndex($i);
    echo "      - $name  (" . number_format((int) ($stat['size'] ?? 0)) . " bytes, method " . ($stat['comp_method'] ?? '?') . ")\n";
}
if ($za->numFiles > 25) echo "      ... and " . ($za->numFiles - 25) . " more\n";
$za->close();
echo "\n";

// 4. Try the actual extractor.
$target = sys_get_temp_dir() . "/diag_extract_$modelId";
@system('rm -rf ' . escapeshellarg($target));
echo "[4] Running extract_zip_safe() into $target ...\n";
$ex = extract_zip_safe($tmp, $target);
echo "    result: " . var_export($ex, true) . "\n";
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if ($f->isFile()) { $count++; } }
echo "    files written: $count\n";
echo "\nDone.\n";
