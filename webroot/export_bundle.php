<?php
declare(strict_types=1);

/**
 * export_bundle.php — download a model as a self-contained zip:
 *   - all model files
 *   - thumbnail.png (if one exists)
 *   - metadata.json (print stats from MakerWorld/Creality/3MF when available)
 *   - index.html (offline info page)
 *
 * GET ?src=&model=<folder>
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/MakerWorldService.php';
require_once __DIR__ . '/CrealityCloudService.php';

// Resume the session so $_SESSION['csrf'] is available for the token check.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


$src = (string) ($_GET['src'] ?? '');
$model = (string) ($_GET['model'] ?? '');

$srcPath = source_path($src);
if ($srcPath === null || $model === '' || !preg_match('/^[A-Za-z0-9._ +&,\'()!-]+$/', $model)) {
    http_response_code(400); exit('Bad request');
}
$modelReal = realpath($srcPath . '/' . $model);
$srcReal = realpath($srcPath);
if ($modelReal === false || $srcReal === false || !is_dir($modelReal)
    || strncmp($modelReal, $srcReal . DIRECTORY_SEPARATOR, strlen($srcReal) + 1) !== 0) {
    http_response_code(404); exit('Not found');
}

// Gather print metadata (reuse the same logic as the badge).
$meta = ['source' => $src, 'model' => clean_model_name($model), 'folder' => $model];
$stats = null;
try {
    if ($src === 'makerworld' && preg_match('/^(\d+)\s*-/', $model, $m) && (string) cfg('makerworld_token') !== '') {
        $s = (new MakerWorldService())->getPrintStats($m[1]);
        if (!empty($s['ok'])) $stats = $s;
    } elseif ($src === 'creality' && creality_ready()) {
        $gid = preg_match('/^([A-Za-z0-9]+)\s*-/', $model, $m) ? $m[1] : $model;
        $s = (new CrealityCloudService())->getPrintStats($gid);
        if (!empty($s['ok'])) $stats = $s;
    }
} catch (\Throwable $e) { /* omit gracefully */ }

if ($stats !== null) {
    $meta['print'] = [
        'time_seconds'    => $stats['printSeconds'] ?? 0,
        'filament_grams'  => $stats['weightG'] ?? 0,
        'filament_meters' => $stats['lenM'] ?? 0,
        'plates'          => $stats['plates'] ?? 0,
        'colors'          => $stats['colors'] ?? 0,
        'printer'         => $stats['printer'] ?? '',
    ];
}

// Build the zip in a temp file.
$tmp = tempnam(sys_get_temp_dir(), 'ff_bundle_');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);

$base = clean_model_name($model);

// Model files (skip our own .farfetched dir except the thumb, handled below).
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modelReal, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $abs = $f->getPathname();
    $rel = ltrim(substr($abs, strlen($modelReal)), '/\\');
    if (strpos($rel, '.farfetched') === 0) continue; // skip internal dir
    $zip->addFile($abs, $base . '/files/' . $rel);
}

// Thumbnail.
$thumb = $modelReal . '/.farfetched/thumb.png';
$hasThumb = is_file($thumb);
if ($hasThumb) $zip->addFile($thumb, $base . '/thumbnail.png');

// metadata.json
$zip->addFromString($base . '/metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// index.html — offline info page.
$pt = '';
if (isset($meta['print'])) {
    $p = $meta['print'];
    $h = intdiv($p['time_seconds'], 3600); $mn = (int) round(($p['time_seconds'] % 3600) / 60);
    $chips = [];
    if ($p['time_seconds'] > 0) $chips[] = '⏱ ' . ($h > 0 ? "{$h}h {$mn}m" : "{$mn}m");
    if ($p['filament_grams'] > 0) $chips[] = '🧵 ' . $p['filament_grams'] . ' g';
    if ($p['filament_meters'] > 0) $chips[] = '📏 ' . $p['filament_meters'] . ' m';
    if ($p['plates'] > 0) $chips[] = '🍽 ' . $p['plates'] . ' plate(s)';
    if ($p['colors'] > 1) $chips[] = '🎨 ' . $p['colors'] . ' colors';
    if ($p['printer'] !== '') $chips[] = '🖨 ' . $p['printer'];
    $pt = '<p class="chips">' . implode(' &nbsp; ', array_map('htmlspecialchars', $chips)) . '</p>';
}
$thumbTag = $hasThumb ? '<img src="thumbnail.png" alt="">' : '';
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($base) . '</title>'
    . '<style>body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 20px;color:#222}'
    . 'img{max-width:100%;border-radius:12px}.chips{font-size:15px;color:#444}'
    . '.src{text-transform:uppercase;letter-spacing:.5px;color:#888;font-size:12px}'
    . 'a{color:#c0622e}</style></head><body>'
    . '<p class="src">' . htmlspecialchars($src) . '</p><h1>' . htmlspecialchars($base) . '</h1>'
    . $thumbTag . $pt
    . '<p>Files are in the <code>files/</code> folder. Print details in <code>metadata.json</code>.</p>'
    . '<p class="src">Exported from FarFetched</p></body></html>';
$zip->addFromString($base . '/index.html', $html);

$zip->close();

// Stream it.
$dlName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) . '_bundle.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
