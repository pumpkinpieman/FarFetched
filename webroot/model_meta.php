<?php
declare(strict_types=1);

/**
 * model_meta.php — extract print stats from a model's 3MF, if present.
 *
 * GET ?src=<slug>&model=<folder>
 *   -> JSON { ok, printTime?, filamentWeight?, filamentLength?, source3mf? }
 *
 * Slicer 3MFs (Bambu/Creality/Prusa/Orca) embed print time and filament usage
 * in Metadata/slice_info.config (or project_settings) inside the 3MF zip. We
 * read the smallest 3MF in the folder, pull those values, and return them.
 * Returns ok:false (not an error) when there's no 3MF or no embedded stats —
 * the modal just hides the badge in that case.
 *
 * Read-only; same exposure as the model listing.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: private, max-age=300');

function mm_out(array $p): void { echo json_encode($p); exit; }

/** Parse "3h 40m 12s" / "1d 2h" / "40m" → seconds. */
function mm_parse_hms(string $s): int
{
    $sec = 0;
    if (preg_match('/(\d+)\s*d/i', $s, $m)) $sec += (int) $m[1] * 86400;
    if (preg_match('/(\d+)\s*h/i', $s, $m)) $sec += (int) $m[1] * 3600;
    if (preg_match('/(\d+)\s*m/i', $s, $m)) $sec += (int) $m[1] * 60;
    if (preg_match('/(\d+)\s*s/i', $s, $m)) $sec += (int) $m[1];
    return $sec;
}

$src   = (string) ($_GET['src'] ?? '');
$model = (string) ($_GET['model'] ?? '');

$srcPath = source_path($src);
if ($srcPath === null) mm_out(['ok' => false]);

if ($model === '' || !preg_match('/^[A-Za-z0-9._ +&,\'()!-]+$/', $model)) {
    mm_out(['ok' => false]);
}

$modelReal = realpath($srcPath . '/' . $model);
$srcReal   = realpath($srcPath);
if ($modelReal === false || $srcReal === false || !is_dir($modelReal)
    || strncmp($modelReal, $srcReal . DIRECTORY_SEPARATOR, strlen($srcReal) + 1) !== 0) {
    mm_out(['ok' => false]);
}

// Find the smallest .3mf in the folder (cheapest to open).
$smallest = null; $smallestSize = PHP_INT_MAX;
try {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modelReal, FilesystemIterator::SKIP_DOTS)
    ) as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === '3mf') {
            $sz = $f->getSize();
            if ($sz > 0 && $sz < $smallestSize) { $smallest = $f->getPathname(); $smallestSize = $sz; }
        }
    }
} catch (\Throwable $e) {
    mm_out(['ok' => false]);
}
if ($smallest === null) mm_out(['ok' => false]); // no 3MF → no stats

// Open the 3MF (zip) and read slicer metadata.
$zip = new ZipArchive();
if ($zip->open($smallest) !== true) mm_out(['ok' => false]);

// Candidate metadata files, in priority order.
$candidates = [
    'Metadata/slice_info.config',
    'Metadata/Slic3r_PE.config',
    'Metadata/Prusa_Slicer.config',
    'Metadata/project_settings.config',
];

$printSeconds = 0;
$filamentG    = 0.0;
$filamentMM   = 0.0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false) continue;

    $isCandidate = false;
    foreach ($candidates as $c) {
        if (strcasecmp($name, $c) === 0) { $isCandidate = true; break; }
    }
    // slice_info.config is XML; project_settings is JSON-ish; also scan any
    // Metadata/*.config as a fallback.
    if (!$isCandidate && !preg_match('#^Metadata/.*\.config$#i', $name)) continue;

    $data = $zip->getFromIndex($i);
    if ($data === false || $data === '') continue;

    // slice_info.config: <metadata key="..." value="..."/> entries.
    if (preg_match_all('/<metadata\s+key="([^"]+)"\s+value="([^"]*)"/i', $data, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $row) {
            $k = strtolower($row[1]); $v = $row[2];
            if ($printSeconds === 0 && (strpos($k, 'prediction') !== false || strpos($k, 'time') !== false)) {
                $printSeconds = (int) round((float) $v);
            }
            if ($filamentG == 0.0 && (strpos($k, 'weight') !== false || strpos($k, 'filament') !== false && strpos($k, 'used') !== false && strpos($k, 'g') !== false)) {
                $filamentG = (float) $v;
            }
        }
    }
    // Generic key = value lines (Prusa/Orca .config).
    if ($printSeconds === 0 && preg_match('/estimated[ _]printing[ _]time[^\n=]*=\s*([0-9hms :]+)/i', $data, $t)) {
        $printSeconds = mm_parse_hms($t[1]);
    }
    if ($filamentG == 0.0 && preg_match('/filament[ _]used[ _]\[g\]\s*=\s*([0-9.]+)/i', $data, $g)) {
        $filamentG = (float) $g[1];
    }
    if ($filamentMM == 0.0 && preg_match('/filament[ _]used[ _]\[mm\]\s*=\s*([0-9.]+)/i', $data, $l)) {
        $filamentMM = (float) $l[1];
    }
}
$zip->close();

if ($printSeconds === 0 && $filamentG == 0.0) {
    mm_out(['ok' => false]); // 3MF had no usable stats
}

mm_out([
    'ok'             => true,
    'printSeconds'   => $printSeconds,
    'filamentGrams'  => round($filamentG, 1),
    'filamentMeters' => $filamentMM > 0 ? round($filamentMM / 1000, 2) : 0,
]);
