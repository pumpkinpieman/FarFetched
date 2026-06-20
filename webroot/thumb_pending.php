<?php
declare(strict_types=1);

/**
 * thumb_pending.php — list models that still need a thumbnail.
 *
 * GET (optional ?limit=N&src=<slug>):
 *   -> JSON [ { src, folder, firstfile }, ... ]
 *
 * Scans the download folders for model dirs that contain a renderable
 * (.stl/.3mf) file but have NO cached .farfetched/thumb.png yet. The queue
 * page polls this and renders pending thumbnails one at a time in the browser,
 * so thumbnails appear shortly after each download finalizes (and any missed
 * by headless downloads get filled in next time the app is open).
 *
 * Read-only; no auth needed (same exposure as the model listing).
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

const TP_VIEW_EXT = ['stl', '3mf'];
// Files larger than this are skipped for AUTO/batch generation — parsing a
// 40MB+ mesh in a throwaway context is what exhausts browser memory and crashes
// the tab (and can blow the load timeout, getting a perfectly good model flagged
// "corrupt"). Such models can still be done manually from the modal.
const TP_MAX_AUTO_BYTES = 18 * 1024 * 1024; // 18 MB

$limit = max(1, min(200, (int) ($_GET['limit'] ?? 30)));
$onlySrc = (string) ($_GET['src'] ?? '');

/**
 * Smallest renderable file (rel path) in a model dir that is also under the
 * auto-size ceiling, or null if none qualify. Smallest-first keeps memory low;
 * the ceiling guarantees we never hand the batch a monster mesh.
 */
function tp_smallest_viewable(string $dir): ?string
{
    try {
        $cands = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if (!$f->isFile()) continue;
            $sz = $f->getSize();
            if ($sz <= 0 || $sz > TP_MAX_AUTO_BYTES) continue;
            if (!in_array(strtolower($f->getExtension()), TP_VIEW_EXT, true)) continue;
            $rel = str_replace('\\', '/', ltrim(substr($f->getPathname(), strlen($dir)), '/\\'));
            $cands[] = ['rel' => $rel, 'sz' => $sz];
        }
        if ($cands === []) return null;
        usort($cands, static fn($a, $b) => $a['sz'] <=> $b['sz']);
        return $cands[0]['rel'];
    } catch (\Throwable $e) {
        return null;
    }
}

// Which sources to scan.
if ($onlySrc !== '') {
    $sp = source_path($onlySrc);
    $sources = $sp !== null ? [['slug' => $onlySrc, 'path' => $sp]] : [];
} else {
    $sources = array_map(
        static fn($s) => ['slug' => $s['slug'], 'path' => $s['path']],
        list_sources()
    );
}

$pending = [];
foreach ($sources as $s) {
    if (count($pending) >= $limit) break;
    foreach (list_models($s['path']) as $m) {
        if (count($pending) >= $limit) break;
        if ($m['kind'] !== 'folder') continue;
        $abs = $s['path'] . '/' . $m['name'];

        // Already has a thumbnail? skip.
        if (is_file($abs . '/.farfetched/thumb.png')) continue;

        $first = tp_smallest_viewable($abs);
        if ($first === null) continue; // nothing renderable under the size ceiling

        $pending[] = [
            'src'       => $s['slug'],
            'folder'    => $m['name'],
            'firstfile' => $first,
        ];
    }
}

echo json_encode($pending);
