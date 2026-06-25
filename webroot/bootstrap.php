<?php
declare(strict_types=1);

// ---- Render safety net ----------------------------------------------------
// A partial deploy (some files updated, others stale) can fatal mid-render and
// leave a blank page that's hard to diagnose. Convert that into a visible,
// logged, non-leaky message for any page that includes bootstrap.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('[FarFetched] fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<div style="margin:24px;padding:16px 18px;border:1px solid #C2613F;border-radius:10px;'
       . 'background:#F6E7E7;color:#7A2E2E;font:14px ui-sans-serif,system-ui,sans-serif;line-height:1.5;">'
       . '<strong>FarFetched hit a server error rendering this page.</strong><br>'
       . 'This is almost always a partial deploy &mdash; some files updated while others stayed stale. '
       . 'Re-deploy the <em>complete</em> <code>webroot/</code> as one set and rebuild the container, then hard-refresh. '
       . 'The specific error has been written to the PHP/container log.</div>';
});

/**
 * bootstrap.php — shared foundation for every entry point.
 *
 * Single source of truth for:
 *   - filesystem paths (private dir, db, config stores)
 *   - the SQLite PDO handle (auto-creates schema on first run)
 *   - config readers (token, download dir)
 *   - small shared helpers (e(), csrf)
 *
 * Included by: index.php, settings.php, enqueue.php, jobs.php, worker.php
 * No output, no side effects beyond ensuring the private dir + db exist.
 */

// ---- Paths ----------------------------------------------------------------
// PRIVATE_DIR sits OUTSIDE the web root (one level up from this file's dir).
// On Unraid this whole project might live under /mnt/user/appdata/... — the
// relative layout holds regardless of absolute location.
define('APP_ROOT',    __DIR__);
define('PRIVATE_DIR', dirname(__DIR__) . '/private');
define('DB_PATH',     PRIVATE_DIR . '/fetcher.db');
define('TOKEN_STORE', PRIVATE_DIR . '/printables_token.php');
define('REFRESH_STORE', PRIVATE_DIR . '/printables_refresh.php');
define('REFRESH_LOCK', PRIVATE_DIR . '/refresh.lock');
define('WORKER_STATUS', PRIVATE_DIR . '/worker_status.json');
define('PATH_STORE',  PRIVATE_DIR . '/download_dir.php');
define('CONFIG_STORE', PRIVATE_DIR . '/config.php');
define('THUMBS_DIR',  PRIVATE_DIR . '/thumbs');

// Default download dir. In Docker this is set to /downloads (a mounted volume)
// via the FETCHER_DOWNLOAD_DIR env var; outside Docker it falls back to the
// Unraid share path. The Settings UI can still override it at runtime.
//
// MODELS_ROOT is the parent that holds per-source subfolders
// (printables/, stlflix/, etc.). FarFetched saves into the
// 'printables' subfolder so every source lives side by side.
//
// IMPORTANT: the container's cron daemon runs jobs in a scrubbed environment.
// The entrypoint writes /etc/fetcher.env and the crontab sources it, but a
// bare "VAR=value" line (no `export`) only sets a shell variable — it is NOT
// inherited by the php child, so getenv() returns false under cron. We resolve
// the root defensively: process env first, then the entrypoint env file, then
// the conventional Docker mount point, and only then the legacy host path.
if (!function_exists('ff_resolve_models_root')) {
    function ff_resolve_models_root(): string
    {
        // 1) Process environment (works for web requests + `docker exec`).
        $env = getenv('FETCHER_DOWNLOAD_DIR');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }
        // 2) Parse the entrypoint-written env file directly. Cron sources this
        //    but may not export it, so getenv() can miss it. Handle both
        //    `export FETCHER_DOWNLOAD_DIR=...` and bare `FETCHER_DOWNLOAD_DIR=...`.
        foreach (['/etc/fetcher.env'] as $envFile) {
            if (is_readable($envFile)) {
                $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    if (preg_match('/^\s*(?:export\s+)?FETCHER_DOWNLOAD_DIR\s*=\s*(.+?)\s*$/', $line, $m)) {
                        $val = trim($m[1], " \t\"'");
                        if ($val !== '') {
                            return rtrim($val, '/');
                        }
                    }
                }
            }
        }
        // 3) Conventional Docker mount point — correct for the standard image.
        if (is_dir('/downloads')) {
            return '/downloads';
        }
        // 4) Legacy bare-metal Unraid host path (last resort).
        return '/mnt/user/Downloads/models';
    }
}
define('MODELS_ROOT', ff_resolve_models_root());
define('DEFAULT_DOWNLOAD_DIR',       rtrim(MODELS_ROOT, '/') . '/printables');
define('MAKERWORLD_DOWNLOAD_DIR',    rtrim(MODELS_ROOT, '/') . '/makerworld');
define('THINGIVERSE_DOWNLOAD_DIR',   rtrim(MODELS_ROOT, '/') . '/thingiverse');
define('CULTS3D_DOWNLOAD_DIR',       rtrim(MODELS_ROOT, '/') . '/cults3d');
define('STLFLIX_DOWNLOAD_DIR',       rtrim(MODELS_ROOT, '/') . '/stlflix');
define('CREALITY_DOWNLOAD_DIR',      rtrim(MODELS_ROOT, '/') . '/creality');
define('NIKKO_DOWNLOAD_DIR',         rtrim(MODELS_ROOT, '/') . '/nikko');
define('HEX3DFORUM_DOWNLOAD_DIR',    rtrim(MODELS_ROOT, '/') . '/hex3dforum');

// Seconds between file downloads: now runtime-configurable via the Settings UI.
// Resolution order is defaults <- env <- stored config (UI wins). The constant
// is defined further down, once the config layer (cfg) is available.

// ---- Ensure private dir exists (0700) -------------------------------------
if (!is_dir(PRIVATE_DIR)) {
    @mkdir(PRIVATE_DIR, 0700, true);
}

// ---- Generic PHP-return config store --------------------------------------
function store_read(string $file): string
{
    if (!is_file($file)) {
        return '';
    }
    $v = @include $file;
    return is_string($v) ? $v : '';
}

function store_write(string $file, string $value): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $payload = "<?php return " . var_export($value, true) . ";\n";
    if (file_put_contents($file, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod($file, 0600);
    return true;
}

// ---- Multi-source library helpers (LOCAL filesystem only) -----------------
/**
 * List source folders directly under MODELS_ROOT (printables/, stlflix/, ...).
 * Each becomes a tile on the homepage. Creating a new subfolder is all it
 * takes to add a source — no code change.
 *
 * @return array<int,array{slug:string,path:string,count:int}>
 */
function list_sources(): array
{
    $root = MODELS_ROOT;
    $out = [];
    if (is_dir($root)) {
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $out[] = ['slug' => $name, 'path' => $path, 'count' => count_models($path)];
        }
        usort($out, static fn($a, $b) => strcasecmp($a['slug'], $b['slug']));
    } else {
        @mkdir($root, 0777, true);
    }

    // Append registered custom folders (indexed in place, outside MODELS_ROOT).
    // Slug is custom:<id> so it never encodes a filesystem path; the real path
    // is resolved from config by source_path(). Missing/unreadable folders are
    // still listed (count 0) so the user can see and remove a broken entry.
    foreach (custom_folders() as $cf) {
        $p = $cf['path'];
        $out[] = [
            'slug'   => 'custom:' . $cf['id'],
            'path'   => $p,
            'count'  => is_dir($p) ? count_models($p) : 0,
            'label'  => $cf['label'],
            'custom' => true,
        ];
    }
    return $out;
}

/** Registered custom folders from config, normalized. @return array<int,array{id:string,label:string,path:string}> */
function custom_folders(): array
{
    $raw = cfg('custom_folders');
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $e) {
        if (!is_array($e)) continue;
        $id   = (string) ($e['id'] ?? '');
        $path = (string) ($e['path'] ?? '');
        if ($id === '' || $path === '') continue;
        $out[] = ['id' => $id, 'label' => (string) ($e['label'] ?? basename($path)), 'path' => $path];
    }
    return $out;
}

/** Resolve a custom:<id> slug to its registered absolute path, or null. */
function custom_folder_path(string $slug): ?string
{
    if (!str_starts_with($slug, 'custom:')) return null;
    $id = substr($slug, 7);
    foreach (custom_folders() as $cf) {
        if ($cf['id'] === $id) {
            return is_dir($cf['path']) ? $cf['path'] : null;
        }
    }
    return null;
}

/**
 * Find an existing preview image in a model folder. Prefers conventionally
 * named files (thumb/preview/cover/render) at the top level, then falls back
 * to the first image found anywhere in the folder. Returns an absolute path
 * or null. Used for custom-folder thumbnails (no STL rendering).
 */
function lib_find_preview_image(string $dir): ?string
{
    $exts = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    // 1. Preferred names at the top level.
    foreach (['thumb', 'preview', 'cover', 'render', 'image'] as $stem) {
        foreach ($exts as $ext) {
            foreach ([$stem . '.' . $ext, ucfirst($stem) . '.' . $ext] as $cand) {
                $p = $dir . '/' . $cand;
                if (is_file($p)) return $p;
            }
        }
    }
    // 2. First image anywhere (shallow-first via sorted recursive walk).
    try {
        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            if (in_array(strtolower($f->getExtension()), $exts, true)) {
                $found[] = $f->getPathname();
            }
        }
        if ($found !== []) {
            // Prefer the shallowest path (fewest separators), then alphabetical.
            usort($found, static function ($a, $b) {
                $da = substr_count($a, '/'); $db = substr_count($b, '/');
                return $da <=> $db ?: strcasecmp($a, $b);
            });
            return $found[0];
        }
    } catch (\Throwable $e) {
        return null;
    }
    return null;
}

/** Count immediate model entries in a source folder (subfolders + loose zips). */
function count_models(string $sourcePath): int
{
    $n = 0;
    foreach (scandir($sourcePath) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $p = $sourcePath . '/' . $name;
        if (is_dir($p) || preg_match('/\.zip$/i', $name)) {
            $n++;
        }
    }
    return $n;
}

/** Validate a source slug and return its absolute path, or null if invalid. */
function source_path(string $slug): ?string
{
    // Custom folders carry a custom:<id> slug; resolve via config (the real
    // path lives outside MODELS_ROOT and is never built from the slug).
    if (str_starts_with($slug, 'custom:')) {
        return custom_folder_path($slug);
    }
    // No separators / traversal — slug must be a single safe segment.
    if ($slug === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
        return null;
    }
    $path = MODELS_ROOT . '/' . $slug;
    return is_dir($path) ? $path : null;
}

/**
 * List models inside a source folder. Each immediate subfolder is a model;
 * each loose .zip is a pending import. Returns display rows.
 *
 * @return array<int,array{name:string,kind:string,files:int,size:int}>
 */
function list_models(string $sourcePath): array
{
    $rows = [];
    foreach (scandir($sourcePath) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $p = $sourcePath . '/' . $name;
        if (is_dir($p)) {
            [$files, $size] = dir_stats($p);
            $rows[] = ['name' => $name, 'kind' => 'folder', 'files' => $files, 'size' => $size];
        } elseif (preg_match('/\.zip$/i', $name)) {
            $rows[] = ['name' => $name, 'kind' => 'zip', 'files' => 0, 'size' => @filesize($p) ?: 0];
        }
    }
    usort($rows, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $rows;
}

/** Recursively count printable files + total bytes in a model folder. */
function dir_stats(string $dir): array
{
    $files = 0;
    $size = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files++;
            $size += $f->getSize();
        }
    }
    return [$files, $size];
}

function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (int) $n : round($n, 1)) . ' ' . $u[$i];
}

/** Turn a folder name into a readable title: strip trailing hash, de-underscore. */
function clean_model_name(string $folder): string
{
    // Drop a trailing _<hex/id> suffix (e.g. _a90d523f88, _68575fd8a2).
    $name = preg_replace('/_[0-9a-f]{6,}$/i', '', $folder) ?? $folder;
    $name = str_replace(['_', '+'], ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

/** Distinct file extensions present in a model folder (uppercased, for badges). */
function model_file_types(string $dir): array
{
    $types = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $ext = strtoupper($f->getExtension());
            if ($ext !== '') {
                $types[$ext] = true;
            }
        }
    }
    ksort($types);
    return array_keys($types);
}

/**
 * Classify a model folder's "customize / pose" potential. Returns:
 *   [
 *     'mode'      => 'parametric' | 'variants' | 'none',
 *     'script'    => relative path to the .scad/.py script (parametric only),
 *     'engine'    => 'openscad' | 'cadquery' | null,
 *     'variants'  => [ ['file'=>rel, 'label'=>..., 'group'=>...], ... ],  (variants only)
 *     'reason'    => short human note,
 *   ]
 *
 * - parametric: a single .scad (OpenSCAD) or parametric .py (CadQuery/build123d)
 *   that can be re-rendered with different variable values.
 * - variants: no script, but the folder ships SEVERAL mesh files that look like
 *   pose/option variants of one design (e.g. skeleton_standing.stl,
 *   skeleton_sitting.stl) — the user picks one rather than re-rendering.
 * - none: a single static mesh, nothing to customize.
 */
function model_customization(string $dir): array
{
    $none = ['mode' => 'none', 'script' => null, 'engine' => null, 'variants' => [], 'reason' => 'Static model — no parameters or variants.'];
    if (!is_dir($dir)) {
        return $none;
    }

    $scad = null;
    $pyParametric = null;
    $meshes = []; // [relpath => filename]

    $meshExt = ['stl' => 1, '3mf' => 1, 'obj' => 1];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $path = $f->getPathname();
        if (strpos($path, '.farfetched') !== false) {
            continue;
        }
        $ext = strtolower($f->getExtension());
        $rel = ltrim(substr($path, strlen($dir)), '/\\');

        if ($ext === 'scad' && $scad === null) {
            $scad = $rel;
        } elseif ($ext === 'py' && $pyParametric === null) {
            // Only treat as parametric if it imports a known CAD kernel.
            $head = @file_get_contents($path, false, null, 0, 4096) ?: '';
            if (preg_match('/\b(cadquery|build123d)\b/i', $head)) {
                $pyParametric = $rel;
            }
        } elseif (isset($meshExt[$ext])) {
            $meshes[$rel] = $f->getBasename();
        }
    }

    // 1) Parametric script wins (it can regenerate any geometry).
    if ($scad !== null) {
        return ['mode' => 'parametric', 'script' => $scad, 'engine' => 'openscad',
                'variants' => [], 'reason' => 'OpenSCAD script — adjustable parameters.'];
    }
    if ($pyParametric !== null) {
        return ['mode' => 'parametric', 'script' => $pyParametric, 'engine' => 'cadquery',
                'variants' => [], 'reason' => 'CadQuery script — adjustable parameters.'];
    }

    // 2) Variant set: 2+ meshes that share a common stem and differ by suffix.
    if (count($meshes) >= 2) {
        $variants = ff_detect_variants($meshes);
        if (count($variants) >= 2) {
            return ['mode' => 'variants', 'script' => null, 'engine' => null,
                    'variants' => $variants, 'reason' => 'Multiple variants/poses included.'];
        }
    }

    return $none;
}

/**
 * Given [relpath => basename] mesh files, decide whether they form a variant
 * set and return labelled variants. Heuristic: strip extension, treat the part
 * after the last separator (-, _, space) as the variant label when the stems
 * share a common prefix; otherwise each file is its own variant.
 *
 * @param array<string,string> $meshes
 * @return array<int,array{file:string,label:string}>
 */
function ff_detect_variants(array $meshes): array
{
    $out = [];
    foreach ($meshes as $rel => $base) {
        $stem = preg_replace('/\.(stl|3mf|obj)$/i', '', $base);
        // Human-friendly label: replace separators with spaces, trim.
        $label = trim(preg_replace('/[_\-]+/', ' ', (string) $stem));
        if ($label === '') {
            $label = $base;
        }
        $out[] = ['file' => $rel, 'label' => $label];
    }
    // Sort by label for stable display.
    usort($out, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $out;
}

/** Quick boolean: is this model customizable/poseable in any way? */
function model_is_customizable(string $dir): bool
{
    return model_customization($dir)['mode'] !== 'none';
}

/* ===========================================================================
 * OPENSCAD — headless parametric rendering for #2 (parametric posing).
 * =========================================================================== */

/** Locate the openscad binary (config override first, then PATH). Cached. */
function openscad_bin(): ?string
{
    static $cached = false;
    static $val = null;
    if ($cached) {
        return $val;
    }
    $cached = true;
    $cfg = function_exists('cfg') ? (string) cfg('openscad_path') : '';
    if ($cfg !== '' && @is_executable($cfg)) {
        return $val = $cfg;
    }
    $found = trim((string) @shell_exec('command -v openscad 2>/dev/null'));
    if ($found !== '' && @is_executable($found)) {
        return $val = $found;
    }
    foreach (['/usr/bin/openscad', '/usr/local/bin/openscad'] as $c) {
        if (@is_executable($c)) {
            return $val = $c;
        }
    }
    return $val = null;
}

/** Is xvfb-run available (needed for headless GL on OpenSCAD 2021)? */
function openscad_has_xvfb(): bool
{
    static $v = null;
    if ($v === null) {
        $v = trim((string) @shell_exec('command -v xvfb-run 2>/dev/null')) !== '';
    }
    return $v;
}

/** True if parametric rendering is available on this host. */
function openscad_available(): bool
{
    return openscad_bin() !== null;
}

/**
 * Parse OpenSCAD Customizer parameters from a .scad file. Reads top-level
 * variable assignments and their preceding `// [..]` annotation, returning
 * control descriptors for the UI.
 *
 * Supports: sliders `// [min:max]` / `// [min:step:max]`, dropdowns
 * `// [a, b, c]` / `// [1:Low, 2:High]`, plus bare number/bool/string vars.
 * Stops at the first `module`/`function` (those are body, not parameters).
 *
 * @return array<int,array{name:string,type:string,default:mixed,min?:float,max?:float,step?:float,options?:array}>
 */
function scad_parse_params(string $scadPath): array
{
    $src = @file_get_contents($scadPath);
    if ($src === false) {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];
    $params = [];
    $pendingHint = null;

    foreach ($lines as $line) {
        $trim = trim($line);

        // Stop scanning once the geometry body begins.
        if (preg_match('/^\s*(module|function)\s+/', $trim)) {
            break;
        }

        // Capture an annotation comment for the next assignment.
        if (preg_match('/^\/\/\s*(\[.*\])\s*$/', $trim, $am)) {
            $pendingHint = $am[1];
            continue;
        }

        // Variable assignment:  name = value;   (optionally with trailing // [..])
        if (preg_match('/^([A-Za-z_]\w*)\s*=\s*([^;]+);(?:\s*\/\/\s*(\[.*\]))?/', $trim, $vm)) {
            $name = $vm[1];
            $rawVal = trim($vm[2]);
            $hint = $vm[3] ?? $pendingHint;
            $pendingHint = null;

            $param = ['name' => $name];

            // Default value typing.
            if ($rawVal === 'true' || $rawVal === 'false') {
                $param['type'] = 'bool';
                $param['default'] = $rawVal === 'true';
            } elseif (preg_match('/^"(.*)"$/s', $rawVal, $sm)) {
                $param['type'] = 'string';
                $param['default'] = $sm[1];
            } elseif (is_numeric($rawVal)) {
                $param['type'] = 'number';
                $param['default'] = $rawVal + 0;
            } else {
                // Unsupported (vectors, expressions) — skip from UI.
                continue;
            }

            // Apply annotation hints.
            if ($hint !== null) {
                $inner = trim($hint, '[] ');
                if (strpos($inner, ',') !== false) {
                    // Dropdown list.
                    $opts = [];
                    foreach (explode(',', $inner) as $opt) {
                        $opt = trim($opt);
                        if ($opt === '') { continue; }
                        if (strpos($opt, ':') !== false) {
                            [$val, $label] = array_map('trim', explode(':', $opt, 2));
                            $opts[] = ['value' => $val, 'label' => $label];
                        } else {
                            $opts[] = ['value' => $opt, 'label' => $opt];
                        }
                    }
                    if ($opts) {
                        $param['type'] = 'select';
                        $param['options'] = $opts;
                    }
                } elseif (preg_match('/^([\-0-9.]+):([\-0-9.]+)(?::([\-0-9.]+))?$/', $inner, $rm)) {
                    // Slider range  min:max  or  min:step:max
                    $param['type'] = 'number';
                    if (isset($rm[3]) && $rm[3] !== '') {
                        $param['min'] = (float) $rm[1];
                        $param['step'] = (float) $rm[2];
                        $param['max'] = (float) $rm[3];
                    } else {
                        $param['min'] = (float) $rm[1];
                        $param['max'] = (float) $rm[2];
                        $param['step'] = 1;
                    }
                }
            }

            $params[$name] = $param;
        }
    }

    return array_values($params);
}

/**
 * Render a .scad to an output mesh with parameter overrides.
 * Returns [ok, path|error].
 *
 * @param array<string,mixed> $values  name => value overrides
 */
function scad_render(string $scadPath, array $values, string $outPath): array
{
    $bin = openscad_bin();
    if ($bin === null) {
        return ['ok' => false, 'error' => 'OpenSCAD is not installed on the server.'];
    }
    if (!is_file($scadPath)) {
        return ['ok' => false, 'error' => 'Script not found.'];
    }

    $args = [];
    foreach ($values as $k => $v) {
        if (!preg_match('/^[A-Za-z_]\w*$/', (string) $k)) {
            continue; // reject unsafe variable names
        }
        if (is_bool($v)) {
            $val = $v ? 'true' : 'false';
        } elseif (is_numeric($v)) {
            $val = (string) ($v + 0);
        } else {
            // string value — quote and escape
            $val = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"';
        }
        $args[] = '-D ' . escapeshellarg($k . '=' . $val);
    }

    $cmd = escapeshellarg($bin)
        . ' -o ' . escapeshellarg($outPath) . ' '
        . implode(' ', $args) . ' '
        . escapeshellarg($scadPath) . ' 2>&1';

    if (openscad_has_xvfb()) {
        $cmd = 'xvfb-run -a ' . $cmd;
    }

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    if ($code !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
        return ['ok' => false, 'error' => 'Render failed: ' . trim(implode("\n", array_slice($out, -4)))];
    }
    return ['ok' => true, 'path' => $outPath];
}


/* ===========================================================================
 * PROJECTS — user workspaces for posing/customizing, kept separate from the
 * pristine source models. A project copies a source model into its own working
 * directory under PRIVATE_DIR/projects/<id>/, records pose/param state in the
 * db, and exports finished results to the 'poses' source so they appear in the
 * library. Source models are never modified.
 * =========================================================================== */

/** Absolute path to the projects working root (inside the private dir). */
function projects_root(): string
{
    $p = PRIVATE_DIR . '/projects';
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
    return $p;
}

/** The 'poses' export source folder under MODELS_ROOT (library destination). */
function poses_dir(): string
{
    return rtrim(MODELS_ROOT, '/') . '/poses';
}

/** True if the poses export folder is writable (or can be created). */
function poses_writable(): bool
{
    $d = poses_dir();
    if (is_dir($d)) {
        return is_writable($d);
    }
    // Not yet created — can we create it (i.e. is the parent writable)?
    return is_writable(MODELS_ROOT) || @mkdir($d, 0775, true);
}

/** Ensure the projects table exists (idempotent). */
function projects_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            src_slug    TEXT,
            src_folder  TEXT,
            mode        TEXT NOT NULL DEFAULT \'variants\',
            work_dir    TEXT NOT NULL,
            state       TEXT NOT NULL DEFAULT \'{}\',
            created_at  TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at  TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );
    $done = true;
}

/** List all projects (newest first). */
function projects_list(): array
{
    projects_init();
    return db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Fetch one project by id, or null. */
function project_get(int $id): ?array
{
    projects_init();
    $st = db()->prepare('SELECT * FROM projects WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create a project by copying a source model's working files into the project's
 * own directory. Returns [ok, id|error].
 */
function project_create(string $name, string $srcSlug, string $srcFolder): array
{
    projects_init();
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Project name required.'];
    }
    $base = source_path($srcSlug);
    if ($base === null) {
        return ['ok' => false, 'error' => 'Invalid source.'];
    }
    $srcDir = realpath($base . '/' . $srcFolder);
    $baseReal = realpath($base);
    if ($srcDir === false || $baseReal === false || !is_dir($srcDir)
        || strncmp($srcDir, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
        return ['ok' => false, 'error' => 'Source model not found.'];
    }

    $cust = model_customization($srcDir);
    $mode = $cust['mode'] === 'none' ? 'variants' : $cust['mode'];

    // Create the project row first to get an id for the work dir.
    $db = db();
    $db->prepare('INSERT INTO projects (name, src_slug, src_folder, mode, work_dir) VALUES (:n,:s,:f,:m,:w)')
       ->execute([':n' => $name, ':s' => $srcSlug, ':f' => $srcFolder, ':m' => $mode, ':w' => '']);
    $id = (int) $db->lastInsertId();

    $work = projects_root() . '/' . $id;
    if (!is_dir($work) && !@mkdir($work, 0775, true) && !is_dir($work)) {
        $db->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $id]);
        return ['ok' => false, 'error' => 'Could not create project workspace (private dir not writable).'];
    }

    // Copy working files (meshes + scripts) into the project — sources stay pristine.
    $copied = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $path = $f->getPathname();
        if (strpos($path, '.farfetched') !== false) {
            continue;
        }
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['stl', '3mf', 'obj', 'scad', 'py'], true)) {
            continue;
        }
        $rel = ltrim(substr($path, strlen($srcDir)), '/\\');
        $dest = $work . '/' . $rel;
        @mkdir(dirname($dest), 0775, true);
        if (@copy($path, $dest)) {
            $copied++;
        }
    }

    $db->prepare('UPDATE projects SET work_dir = :w WHERE id = :id')->execute([':w' => $work, ':id' => $id]);
    return ['ok' => true, 'id' => $id, 'copied' => $copied, 'mode' => $mode];
}

/** Update a project's saved pose/param state (JSON). */
function project_set_state(int $id, array $state): void
{
    projects_init();
    db()->prepare('UPDATE projects SET state = :s, updated_at = datetime(\'now\') WHERE id = :id')
        ->execute([':s' => json_encode($state), ':id' => $id]);
}

/** Delete a project and its working directory. */
function project_delete(int $id): void
{
    projects_init();
    $p = project_get($id);
    if ($p && !empty($p['work_dir']) && is_dir($p['work_dir'])
        && strncmp($p['work_dir'], projects_root() . '/', strlen(projects_root()) + 1) === 0) {
        ff_rrmdir($p['work_dir']);
    }
    db()->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $id]);
}

/** Recursively remove a directory (used for project cleanup). */
function ff_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * Export a project file into the 'poses' library source. $srcRel is a file
 * relative to the project work dir. Returns [ok, folder|error].
 */
function project_export(int $id, string $srcRel, string $exportName): array
{
    $p = project_get($id);
    if (!$p) {
        return ['ok' => false, 'error' => 'Project not found.'];
    }
    if (!poses_writable()) {
        return ['ok' => false, 'error' => 'The /models/poses folder is not writable. Fix folder permissions (give the web user write access), then try again.'];
    }
    $work = (string) $p['work_dir'];
    $srcFile = realpath($work . '/' . $srcRel);
    if ($srcFile === false || !is_file($srcFile)
        || strncmp($srcFile, $work . DIRECTORY_SEPARATOR, strlen($work) + 1) !== 0) {
        return ['ok' => false, 'error' => 'Export source file not found.'];
    }

    $poses = poses_dir();
    if (!is_dir($poses)) {
        @mkdir($poses, 0775, true);
    }

    // Sanitise the export folder name.
    $exportName = trim($exportName);
    $exportName = preg_replace('/[^A-Za-z0-9 _.\-]+/', '', $exportName) ?: 'pose';
    $folder = $poses . '/' . $exportName;
    // Avoid clobbering: append a counter if needed.
    $final = $folder;
    $n = 2;
    while (is_dir($final)) {
        $final = $folder . ' (' . $n . ')';
        $n++;
    }
    if (!@mkdir($final, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create the export folder in /models/poses.'];
    }

    $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
    $destName = $exportName . '.' . $ext;
    if (!@copy($srcFile, $final . '/' . $destName)) {
        return ['ok' => false, 'error' => 'Could not write the exported file.'];
    }

    return ['ok' => true, 'folder' => basename($final), 'file' => $destName];
}


/** First PDF found in a model folder, or null. */
function find_pdf(string $dir): ?string
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') {
            return $f->getPathname();
        }
    }
    return null;
}

/**
 * Return a cached thumbnail PNG path for a model. Strategy:
 *   1. Extract embedded images from the PDF (pdfimages) and pick the LARGEST
 *      by pixel area — that's the hero render, not the text/logo.
 *   2. Fallback: rasterize page 1 (pdftoppm) if no embedded images found.
 * Renders once, then reuses the cache. Returns null if no PDF.
 * LOCAL ONLY — reads the user's own PDF.
 *
 * @return string|null absolute path to a PNG under THUMBS_DIR
 */
function model_thumb(string $source, string $modelFolder, string $modelPath): ?string
{
    $cacheDir = THUMBS_DIR . '/' . $source;
    $cache = $cacheDir . '/' . $modelFolder . '.png';
    if (is_file($cache)) {
        return $cache;
    }

    $pdf = find_pdf($modelPath);
    if ($pdf === null) {
        return null;
    }
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0777, true)) {
        return null;
    }

    // --- Strategy 1: largest embedded image ---
    $work = sys_get_temp_dir() . '/thumb_' . md5($source . $modelFolder);
    @mkdir($work, 0700, true);
    $prefix = $work . '/img';
    // -png converts to png where possible; -j keeps jpegs; we scan both.
    @exec('pdfimages -png ' . escapeshellarg($pdf) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

    $best = null;
    $bestArea = 0;
    foreach (glob($work . '/img*') ?: [] as $img) {
        $info = @getimagesize($img);
        if ($info === false) {
            continue;
        }
        $area = $info[0] * $info[1];
        // Ignore tiny assets (logos, icons); require a reasonably large image.
        if ($area > $bestArea && $info[0] >= 200 && $info[1] >= 200) {
            $bestArea = $area;
            $best = $img;
        }
    }

    if ($best !== null) {
        // Normalize to PNG at the cache path (re-encode via pdftoppm? no—copy/convert).
        // getimagesize told us it's a valid image; convert to png if not already.
        $ext = strtolower(pathinfo($best, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            @copy($best, $cache);
        } else {
            // Re-encode jpeg/ppm to png via GD if available, else just copy bytes.
            if (function_exists('imagecreatefromstring')) {
                $im = @imagecreatefromstring((string) file_get_contents($best));
                if ($im !== false) {
                    @imagepng($im, $cache);
                    imagedestroy($im);
                }
            }
            if (!is_file($cache)) {
                @copy($best, $cache); // last resort: serve as-is
            }
        }
    }

    // --- Strategy 2 fallback: rasterize page 1 ---
    if (!is_file($cache)) {
        $rp = $cacheDir . '/' . $modelFolder;
        @exec('pdftoppm -png -singlefile -f 1 -scale-to 400 '
            . escapeshellarg($pdf) . ' ' . escapeshellarg($rp) . ' 2>/dev/null');
    }

    // cleanup temp
    array_map('unlink', glob($work . '/*') ?: []);
    @rmdir($work);

    return is_file($cache) ? $cache : null;
}

/**
 * Safely extract a ZIP into $targetDir, guarding against zip-slip (entries
 * escaping the target via absolute paths or ..). Returns true on success.
 * Used by the worker for pack downloads. LOCAL filesystem only.
 */
/**
 * Organize engine — resumable, chunk-based, pausable.
 *
 * State lives in <root>/_processing/organize.json so both the AJAX driver and
 * an optional background worker share progress and the pause flag. The work is
 * naturally resumable: once a loose file is moved into its folder (or a zip is
 * extracted + moved to _processed/), it's no longer a pending top-level item,
 * so re-scanning simply skips it.
 *
 * Functions:
 *   organize_state_path($root)         → state file path
 *   organize_read_state($root)         → array|null
 *   organize_write_state($root,$st)    → bool
 *   organize_pending_items($root)      → list of pending top-level names
 *   organize_one_item($root,$name,&$s) → process a single item (mutates summary)
 *   organize_start($root)              → seed state (total, counters), status=running
 *   organize_chunk($root,$n)           → process up to $n pending items, update state
 *   organize_set_status($root,$status) → pause/resume/cancel
 *
 * Back-compat: organize_custom_folder($root) runs the whole thing synchronously
 * (used by the non-chunked path / CLI), built on the same primitives.
 */

function organize_state_path(string $root): string
{
    return rtrim($root, '/') . '/_processing/organize.json';
}

function organize_read_state(string $root): ?array
{
    $p = organize_state_path($root);
    if (!is_file($p)) return null;
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') return null;
    $st = json_decode($raw, true);
    return is_array($st) ? $st : null;
}

function organize_write_state(string $root, array $st): bool
{
    $dir = dirname(organize_state_path($root));
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) return false;
    $st['updated'] = time();
    return @file_put_contents(organize_state_path($root), json_encode($st), LOCK_EX) !== false;
}

/** Top-level items still needing processing (loose model files + zips). */
function organize_pending_items(string $root): array
{
    $modelExt = ['stl','3mf','obj','step','stp','scad','gcode','gco','ply','amf','off','dae','fbx','glb','gltf'];
    $pending = [];
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if ($name[0] === '.') continue;
        if ($name === '_processed' || $name === '_processing') continue;
        $abs = $root . '/' . $name;
        if (!is_file($abs)) continue;                  // dirs are already models
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip' || in_array($ext, $modelExt, true)) {
            $pending[] = $name;
        }
    }
    sort($pending);
    return $pending;
}

/** Process a single top-level item. Mutates $summary counters/errors. */
function organize_one_item(string $root, string $name, array &$summary): void
{
    $modelExt = ['stl','3mf','obj','step','stp','scad','gcode','gco','ply','amf','off','dae','fbx','glb','gltf'];
    $abs = $root . '/' . $name;
    if (!is_file($abs)) { return; }                    // vanished or already moved

    $uniqueDir = static function (string $base) use ($root): string {
        $base = trim($base) !== '' ? $base : 'model';
        $base = preg_replace('/[\/\\\\:*?"<>|]+/', '_', $base);
        $cand = $base; $n = 2;
        while (is_dir($root . '/' . $cand) || is_file($root . '/' . $cand)) {
            $cand = $base . ' (' . $n . ')'; $n++;
        }
        return $root . '/' . $cand;
    };

    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $stem = pathinfo($name, PATHINFO_FILENAME);

    if ($ext === 'zip') {
        $wrapDir = zip_single_root_dir($abs);
        if ($wrapDir !== null) {
            $target = $root;
            if (is_dir($root . '/' . $wrapDir)) { $target = $uniqueDir($stem); }
            if (extract_zip_safe($abs, $target)) {
                $summary['zips']++;
                custom_move_to_processed($root, $abs, $summary);
            } else {
                $summary['errors'][] = 'Failed to extract: ' . $name;
            }
        } else {
            $target = $uniqueDir($stem);
            if (extract_zip_safe($abs, $target)) {
                $summary['zips']++;
                custom_move_to_processed($root, $abs, $summary);
            } else {
                @rmdir($target);
                $summary['errors'][] = 'Failed to extract: ' . $name;
            }
        }
        return;
    }

    if (in_array($ext, $modelExt, true)) {
        $target = $uniqueDir($stem);
        if (!@mkdir($target, 0777, true) && !is_dir($target)) {
            $summary['errors'][] = 'Could not create folder for: ' . $name;
            return;
        }
        if (@rename($abs, $target . '/' . $name)) {
            $summary['moved']++;
        } elseif (@copy($abs, $target . '/' . $name) && @unlink($abs)) {
            $summary['moved']++;
        } else {
            $summary['errors'][] = 'Could not move: ' . $name;
            @rmdir($target);
        }
        return;
    }

    $summary['skipped']++;
}

/** Seed the state file for a fresh run (or restart). Returns state or error. */
function organize_start(string $root): array
{
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return ['ok' => false, 'error' => 'Folder not found: ' . $root];
    }
    if (!is_writable($realRoot)) {
        return ['ok' => false, 'error' => 'Folder is not writable by the app: ' . $realRoot];
    }
    $pending = organize_pending_items($realRoot);
    $st = [
        'status'  => 'running',
        'total'   => count($pending),
        'done'    => 0,
        'moved'   => 0,
        'zips'    => 0,
        'skipped' => 0,
        'errors'  => [],
        'current' => null,
        'started' => time(),
    ];
    organize_write_state($realRoot, $st);
    return ['ok' => true, 'state' => $st];
}

/** Process up to $n pending items. Honors a paused state (no-op while paused). */
function organize_chunk(string $root, int $n = 8): array
{
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return ['ok' => false, 'error' => 'Folder not found.'];
    }
    $st = organize_read_state($realRoot);
    if ($st === null) {
        $seed = organize_start($realRoot);
        if (!$seed['ok']) return $seed;
        $st = $seed['state'];
    }
    if (($st['status'] ?? '') === 'paused') {
        return ['ok' => true, 'state' => $st, 'paused' => true];
    }
    if (($st['status'] ?? '') === 'done') {
        return ['ok' => true, 'state' => $st, 'done' => true];
    }

    $pending = organize_pending_items($realRoot);
    if ($pending === []) {
        $st['status']  = 'done';
        $st['current'] = null;
        organize_write_state($realRoot, $st);
        return ['ok' => true, 'state' => $st, 'done' => true];
    }

    $summary = ['moved' => $st['moved'], 'zips' => $st['zips'], 'skipped' => $st['skipped'], 'errors' => $st['errors']];
    $processed = 0;
    foreach ($pending as $name) {
        // Re-check pause between items so a Pause click lands within one chunk.
        $live = organize_read_state($realRoot);
        if ($live !== null && ($live['status'] ?? '') === 'paused') {
            $st['status'] = 'paused';
            break;
        }
        $st['current'] = $name;
        organize_write_state($realRoot, $st);     // surface "current" to the UI
        organize_one_item($realRoot, $name, $summary);
        $processed++;
        $st['done']    = (int) $st['done'] + 1;
        $st['moved']   = $summary['moved'];
        $st['zips']    = $summary['zips'];
        $st['skipped'] = $summary['skipped'];
        $st['errors']  = array_slice($summary['errors'], 0, 50);
        if ($processed >= $n) break;
    }

    // If nothing remains after this chunk, mark done.
    if (organize_pending_items($realRoot) === [] && ($st['status'] ?? '') !== 'paused') {
        $st['status'] = 'done';
        $st['current'] = null;
    }
    organize_write_state($realRoot, $st);
    return ['ok' => true, 'state' => $st, 'done' => ($st['status'] === 'done')];
}

/** Set status to 'paused' or 'running' (resume). */
function organize_set_status(string $root, string $status): array
{
    $realRoot = realpath($root);
    if ($realRoot === false) return ['ok' => false, 'error' => 'Folder not found.'];
    $st = organize_read_state($realRoot);
    if ($st === null) return ['ok' => false, 'error' => 'No organize in progress.'];
    if (!in_array($status, ['paused', 'running'], true)) {
        return ['ok' => false, 'error' => 'Bad status.'];
    }
    $st['status'] = $status;
    organize_write_state($realRoot, $st);
    return ['ok' => true, 'state' => $st];
}

/**
 * Synchronous whole-folder organize (back-compat / CLI). Built on the same
 * per-item primitive. Returns ['moved','zips','skipped','errors'].
 */
function organize_custom_folder(string $root): array
{
    $summary = ['moved' => 0, 'zips' => 0, 'skipped' => 0, 'errors' => []];
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        $summary['errors'][] = 'Folder not found: ' . $root;
        return $summary;
    }
    if (!is_writable($realRoot)) {
        $summary['errors'][] = 'Folder is not writable by the app: ' . $realRoot;
        return $summary;
    }
    // Count already-organized subfolders as skipped, for an informative summary.
    foreach (scandir($realRoot) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') continue;
        if ($name === '_processed' || $name === '_processing') continue;
        if (is_dir($realRoot . '/' . $name)) $summary['skipped']++;
    }
    foreach (organize_pending_items($realRoot) as $name) {
        organize_one_item($realRoot, $name, $summary);
    }
    return $summary;
}

/** Move a processed zip into <root>/_processed/, keeping the file. */
function custom_move_to_processed(string $root, string $zipPath, array &$summary): void
{
    $bin = $root . '/_processed';
    if (!is_dir($bin) && !@mkdir($bin, 0777, true) && !is_dir($bin)) {
        $summary['errors'][] = 'Could not create _processed for: ' . basename($zipPath);
        return;
    }
    $dest = $bin . '/' . basename($zipPath);
    // Avoid clobbering an existing processed zip of the same name.
    if (is_file($dest)) {
        $dest = $bin . '/' . pathinfo($zipPath, PATHINFO_FILENAME)
              . '-' . substr(md5((string) microtime()), 0, 6) . '.zip';
    }
    if (!@rename($zipPath, $dest)) {
        if (@copy($zipPath, $dest)) { @unlink($zipPath); }
    }
}

/**
 * If a zip's entries all live under a single top-level folder, return that
 * folder name; otherwise null (the zip is "flat" or has multiple roots).
 */
function zip_single_root_dir(string $zipPath): ?string
{
    if (!is_file($zipPath)) return null;
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) return null;
    $root = null;
    $multiple = false;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $entry = $za->getNameIndex($i);
        if ($entry === false || $entry === '') continue;
        $norm = str_replace('\\', '/', $entry);
        if ($norm[0] === '/' || $norm === '' ) continue;
        $top = explode('/', $norm)[0];
        if ($top === '' ) continue;
        if ($root === null) {
            $root = $top;
        } elseif ($root !== $top) {
            $multiple = true;
            break;
        }
    }
    $za->close();
    if ($multiple || $root === null) return null;
    // Only treat as "wrapped" if there's actually a folder (entries beyond just
    // the bare top name) — a single loose file isn't a wrapping dir.
    return $root;
}

function extract_zip_safe(string $zipPath, string $targetDir): bool
{
    if (!is_file($zipPath)) {
        return false;
    }
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return false;
    }
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        return false;
    }

    $realTarget = realpath($targetDir);
    if ($realTarget === false) { $za->close(); return false; }

    $extracted = 0;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $entry = $za->getNameIndex($i);
        if ($entry === false || $entry === '') {
            continue;
        }
        // Normalize separators and reject genuinely unsafe entries (absolute
        // paths or directory traversal) — but allow ordinary subfolders, which
        // many packs (e.g. Gridfinity sets) legitimately use.
        $norm = str_replace('\\', '/', $entry);
        if ($norm[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $norm)) {
            continue; // skip just this unsafe entry, keep extracting the rest
        }
        // Skip bulky video files some authors bundle into packs — they're not
        // needed for printing and just bloat the library.
        if (preg_match('/\.(mp4|mkv|mov|avi|webm|wmv|flv|m4v|mpg|mpeg|3gp|m2ts|ts)$/i', $norm)) {
            continue;
        }
        // Directory entry — ensure it exists.
        if (substr($norm, -1) === '/') {
            @mkdir($realTarget . '/' . $norm, 0777, true);
            continue;
        }
        // Confirm the resolved destination stays inside the target dir.
        $destPath = $realTarget . '/' . $norm;
        $destDir  = dirname($destPath);
        if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
        $stream = $za->getStream($entry);
        if ($stream === false) { continue; }
        $out = @fopen($destPath, 'wb');
        if ($out === false) { fclose($stream); continue; }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        $extracted++;
    }
    $za->close();
    return $extracted > 0;
}

// ---- Config accessors -----------------------------------------------------
/** The token's self-declared type: 'access', 'refresh', or null if unknown. */
function jwt_token_type(string $jwt): ?string
{
    $claims = jwt_claims($jwt);
    if ($claims === null || !isset($claims['type'])) {
        return null;
    }
    $t = strtolower((string) $claims['type']);
    return in_array($t, ['access', 'refresh'], true) ? $t : null;
}

function get_token(): string
{
    return store_read(TOKEN_STORE);
}

/** App-managed access token: written by the refresh routine, never pasted. */
function set_token(string $value): bool
{
    return store_write(TOKEN_STORE, $value);
}

/**
 * Read the worker's live status JSON (download progress / pacing countdown).
 * Returns null if missing, unreadable, or stale (worker not currently active).
 */
function read_worker_status(): ?array
{
    if (!is_file(WORKER_STATUS)) {
        return null;
    }
    $raw = @file_get_contents(WORKER_STATUS);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    // Stale guard: if the worker hasn't written in >12s it isn't actively
    // running (between cron invocations), so report nothing live.
    if (!isset($data['updated']) || (microtime(true) - (float) $data['updated']) > 12.0) {
        return null;
    }
    return $data;
}

/** The paste-once refresh token (long-lived, rotates on every refresh). */
function get_refresh_token(): string
{
    return store_read(REFRESH_STORE);
}

function set_refresh_token(string $value): bool
{
    return store_write(REFRESH_STORE, $value);
}

function get_download_dir(): string
{
    $v = store_read(PATH_STORE);
    return $v !== '' ? $v : DEFAULT_DOWNLOAD_DIR;
}

// ---- Runtime config (UI-editable worker knobs) ----------------------------
/**
 * Resolution order: hardcoded defaults  <-  env vars  <-  stored config file.
 * The Settings UI writes the stored file, so UI changes win and take effect on
 * the worker's next cron tick — no container rebuild, no compose edit.
 *
 * @return array{download_delay:int,max_attempts:int,batch_cap:int,paused:bool}
 */
function cfg_defaults(): array
{
    return [
        'download_delay' => (int) (getenv('FETCHER_DOWNLOAD_DELAY') ?: 120),
        'max_attempts'   => (int) (getenv('FETCHER_MAX_ATTEMPTS') ?: 3),
        'batch_cap'      => (int) (getenv('FETCHER_BATCH_CAP') ?: 2000),
        'paused'         => false,
        'keep_zip'       => true,
        'overwrite'      => false,
        'prefer_pack'    => false,
        'makerworld_token'           => (string) (getenv('FETCHER_MAKERWORLD_TOKEN') ?: ''),
        'makerworld_refresh_token'   => '',
        'makerworld_download_dir'    => '',
        'makerworld_delay'           => (int) (getenv('FETCHER_MAKERWORLD_DELAY') ?: 45),
        'thingiverse_token'          => (string) (getenv('FETCHER_THINGIVERSE_TOKEN') ?: ''),
        'thingiverse_client_id'      => '',
        'thingiverse_client_sec'     => '',
        'thingiverse_download_dir'   => '',
        'thingiverse_delay'          => 60,
        'cults3d_username'           => (string) (getenv('FETCHER_CULTS3D_USERNAME') ?: ''),
        'cults3d_token'              => (string) (getenv('FETCHER_CULTS3D_TOKEN') ?: ''),
        'cults3d_session'            => '',
        'cults3d_cf_clearance'       => '',
        'cults3d_user_agent'         => '',
        'curl_impersonate_bin'       => '',
        'cults3d_browser'            => 'chrome',
        'cults3d_download_dir'       => '',
        'cults3d_delay'              => 60,
        'stlflix_token'              => (string) (getenv('FETCHER_STLFLIX_TOKEN') ?: ''),
        'stlflix_download_dir'       => '',
        'stlflix_delay'              => 60,
        'creality_token'             => (string) (getenv('FETCHER_CREALITY_TOKEN') ?: ''),
        'creality_user_id'           => (string) (getenv('FETCHER_CREALITY_USER_ID') ?: ''),
        'creality_cf_clearance'      => '',
        'creality_duid'              => '',
        'creality_download_dir'      => '',
        'creality_delay'             => 60,
        'nikko_phpsessid'            => (string) (getenv('FETCHER_NIKKO_PHPSESSID') ?: ''),
        'nikko_wp_logged_in'         => (string) (getenv('FETCHER_NIKKO_WP_LOGGED_IN') ?: ''),
        'nikko_download_dir'         => '',
        'nikko_delay'                => 60,
        'hex3dforum_cookie'          => (string) (getenv('FETCHER_HEX3DFORUM_COOKIE') ?: ''),
        'hex3dforum_u'               => (string) (getenv('FETCHER_HEX3DFORUM_U') ?: ''),
        'hex3dforum_sid'             => (string) (getenv('FETCHER_HEX3DFORUM_SID') ?: ''),
        'hex3dforum_k'               => (string) (getenv('FETCHER_HEX3DFORUM_K') ?: ''),
        'hex3dforum_forum_ids'       => '',
        'hex3dforum_download_dir'    => '',
        'hex3dforum_delay'           => 60,
        // Custom local folders the user registers to surface in My Library.
        // Each entry: ['id' => unique, 'label' => display name, 'path' => abs path].
        // Indexed in place (never copied); removing an entry never touches files.
        'custom_folders'             => [],
    ];
}

function cfg_all(): array
{
    $defaults = cfg_defaults();
    if (!is_file(CONFIG_STORE)) {
        return $defaults;
    }
    $stored = @include CONFIG_STORE;
    if (!is_array($stored)) {
        return $defaults;
    }
    return array_merge($defaults, $stored);
}

/** Typed single-key read. */
function cfg(string $key)
{
    $all = cfg_all();
    return $all[$key] ?? (cfg_defaults()[$key] ?? null);
}

/**
 * Persist a validated subset of config. Clamps to safe ranges so a fat-finger
 * in the UI can never set a 0-second delay (hammering the API) or absurd caps.
 *
 * @param array<string,mixed> $patch
 */
function cfg_save(array $patch): bool
{
    $current = cfg_all();

    if (isset($patch['download_delay'])) {
        $current['download_delay'] = max(30, min(3600, (int) $patch['download_delay']));
    }
    if (isset($patch['max_attempts'])) {
        $current['max_attempts'] = max(1, min(10, (int) $patch['max_attempts']));
    }
    if (isset($patch['batch_cap'])) {
        $current['batch_cap'] = max(1, min(10000, (int) $patch['batch_cap']));
    }
    if (isset($patch['paused'])) {
        $current['paused'] = (bool) $patch['paused'];
    }
    if (array_key_exists('keep_zip', $patch)) {
        $current['keep_zip'] = (bool) $patch['keep_zip'];
    }
    if (array_key_exists('overwrite', $patch)) {
        $current['overwrite'] = (bool) $patch['overwrite'];
    }
    if (array_key_exists('prefer_pack', $patch)) {
        $current['prefer_pack'] = (bool) $patch['prefer_pack'];
    }
    if (array_key_exists('makerworld_token', $patch)) {
        // Opaque cookie value; trim only. Empty string clears it.
        $current['makerworld_token'] = trim((string) $patch['makerworld_token']);
    }
    if (array_key_exists('makerworld_refresh_token', $patch)) {
        $current['makerworld_refresh_token'] = trim((string) $patch['makerworld_refresh_token']);
    }
    if (array_key_exists('makerworld_download_dir', $patch)) {
        $current['makerworld_download_dir'] = trim((string) $patch['makerworld_download_dir']);
    }
    if (isset($patch['makerworld_delay'])) {
        $current['makerworld_delay'] = max(30, min(3600, (int) $patch['makerworld_delay']));
    }
    if (array_key_exists('thingiverse_token', $patch)) {
        $current['thingiverse_token'] = trim((string) $patch['thingiverse_token']);
    }
    if (array_key_exists('thingiverse_client_id', $patch)) {
        $current['thingiverse_client_id'] = trim((string) $patch['thingiverse_client_id']);
    }
    if (array_key_exists('thingiverse_client_sec', $patch)) {
        $current['thingiverse_client_sec'] = trim((string) $patch['thingiverse_client_sec']);
    }
    if (isset($patch['thingiverse_delay'])) {
        $current['thingiverse_delay'] = max(30, min(3600, (int) $patch['thingiverse_delay']));
    }
    if (array_key_exists('cults3d_username', $patch)) {
        $current['cults3d_username'] = trim((string) $patch['cults3d_username']);
    }
    if (array_key_exists('cults3d_token', $patch)) {
        $current['cults3d_token'] = trim((string) $patch['cults3d_token']);
    }
    if (array_key_exists('cults3d_session', $patch)) {
        $current['cults3d_session'] = trim((string) $patch['cults3d_session']);
    }
    if (array_key_exists('cults3d_cf_clearance', $patch)) {
        $current['cults3d_cf_clearance'] = trim((string) $patch['cults3d_cf_clearance']);
    }
    if (array_key_exists('cults3d_user_agent', $patch)) {
        $current['cults3d_user_agent'] = trim((string) $patch['cults3d_user_agent']);
    }
    if (array_key_exists('cults3d_browser', $patch)) {
        $b = strtolower(trim((string) $patch['cults3d_browser']));
        $current['cults3d_browser'] = in_array($b, ['chrome','firefox','edge','safari'], true) ? $b : 'chrome';
    }
    if (array_key_exists('cults3d_download_dir', $patch)) {
        $current['cults3d_download_dir'] = trim((string) $patch['cults3d_download_dir']);
    }
    if (isset($patch['cults3d_delay'])) {
        $current['cults3d_delay'] = max(30, min(3600, (int) $patch['cults3d_delay']));
    }
    if (array_key_exists('stlflix_token', $patch)) {
        $current['stlflix_token'] = trim((string) $patch['stlflix_token']);
    }
    if (array_key_exists('stlflix_download_dir', $patch)) {
        $current['stlflix_download_dir'] = trim((string) $patch['stlflix_download_dir']);
    }
    if (isset($patch['stlflix_delay'])) {
        $current['stlflix_delay'] = max(30, min(3600, (int) $patch['stlflix_delay']));
    }
    if (array_key_exists('creality_token', $patch)) {
        $current['creality_token'] = trim((string) $patch['creality_token']);
    }
    if (array_key_exists('creality_user_id', $patch)) {
        $current['creality_user_id'] = trim((string) $patch['creality_user_id']);
    }
    if (array_key_exists('creality_cf_clearance', $patch)) {
        $current['creality_cf_clearance'] = trim((string) $patch['creality_cf_clearance']);
    }
    if (array_key_exists('creality_duid', $patch)) {
        $current['creality_duid'] = trim((string) $patch['creality_duid']);
    }
    if (array_key_exists('creality_download_dir', $patch)) {
        $current['creality_download_dir'] = trim((string) $patch['creality_download_dir']);
    }
    if (isset($patch['creality_delay'])) {
        $current['creality_delay'] = max(30, min(3600, (int) $patch['creality_delay']));
    }
    if (array_key_exists('nikko_phpsessid', $patch)) {
        $current['nikko_phpsessid'] = trim((string) $patch['nikko_phpsessid']);
    }
    if (array_key_exists('nikko_wp_logged_in', $patch)) {
        $current['nikko_wp_logged_in'] = trim((string) $patch['nikko_wp_logged_in']);
    }
    if (array_key_exists('nikko_download_dir', $patch)) {
        $current['nikko_download_dir'] = trim((string) $patch['nikko_download_dir']);
    }
    if (isset($patch['nikko_delay'])) {
        $current['nikko_delay'] = max(30, min(3600, (int) $patch['nikko_delay']));
    }
    if (array_key_exists('hex3dforum_cookie', $patch)) {
        $current['hex3dforum_cookie'] = trim((string) $patch['hex3dforum_cookie']);
    }
    foreach (['hex3dforum_u', 'hex3dforum_sid', 'hex3dforum_k'] as $hkey) {
        if (array_key_exists($hkey, $patch)) {
            $current[$hkey] = trim((string) $patch[$hkey]);
        }
    }
    // (helper hex3dforum_configured() lives near the other source helpers)
    if (array_key_exists('hex3dforum_forum_ids', $patch)) {
        // Stored as a comma-separated string of forum IDs; normalize input
        // that may arrive as newline/space separated from a textarea.
        $ids = preg_split('/[\s,]+/', trim((string) $patch['hex3dforum_forum_ids']), -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_values(array_unique(array_filter($ids, static fn($v) => ctype_digit($v))));
        $current['hex3dforum_forum_ids'] = implode(',', $ids);
    }
    if (array_key_exists('hex3dforum_download_dir', $patch)) {
        $current['hex3dforum_download_dir'] = trim((string) $patch['hex3dforum_download_dir']);
    }
    if (isset($patch['hex3dforum_delay'])) {
        $current['hex3dforum_delay'] = max(30, min(3600, (int) $patch['hex3dforum_delay']));
    }
    if (array_key_exists('custom_folders', $patch) && is_array($patch['custom_folders'])) {
        // Sanitize each entry: keep a stable id, a display label, and an
        // absolute path. Paths are stored verbatim (validated at use-time);
        // ids are opaque so source slugs never encode a filesystem path.
        $clean = [];
        foreach ($patch['custom_folders'] as $entry) {
            if (!is_array($entry)) continue;
            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') continue;
            $id    = preg_replace('/[^A-Za-z0-9]/', '', (string) ($entry['id'] ?? '')) ?: substr(md5($path . microtime()), 0, 12);
            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') $label = basename(rtrim($path, '/'));
            $clean[] = ['id' => $id, 'label' => $label, 'path' => $path];
        }
        $current['custom_folders'] = $clean;
    }

    $payload = "<?php return " . var_export($current, true) . ";\n";
    if (file_put_contents(CONFIG_STORE, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod(CONFIG_STORE, 0600);

    // CONFIG_STORE is a PHP file loaded via include, so OPcache keeps a compiled
    // copy. Without invalidating it, the next cfg_all() include in THIS request
    // (e.g. when settings.php re-renders the form) returns the OLD values — the
    // "save doesn't take until I refresh" bug. Invalidate so re-reads are fresh.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONFIG_STORE, true);
    }
    // Belt-and-suspenders: clear the stat cache too, so file_exists/include see
    // the new mtime on filesystems that cache stat results (e.g. FUSE mounts).
    clearstatcache(true, CONFIG_STORE);

    return true;
}

// Backward-compatible constant: existing code (worker.php) reads this directly.
// Now sourced from the config layer instead of a fixed env value.
define('DOWNLOAD_DELAY_SECONDS',       (int) cfg('download_delay'));
define('MAKERWORLD_DELAY_SECONDS',     (int) cfg('makerworld_delay'));
define('THINGIVERSE_DELAY_SECONDS',    (int) cfg('thingiverse_delay'));
define('CULTS3D_DELAY_SECONDS',        (int) cfg('cults3d_delay'));
define('STLFLIX_DELAY_SECONDS',        (int) cfg('stlflix_delay'));
define('CREALITY_DELAY_SECONDS',       (int) cfg('creality_delay'));
define('NIKKO_DELAY_SECONDS',          (int) cfg('nikko_delay'));
define('HEX3DFORUM_DELAY_SECONDS',     (int) cfg('hex3dforum_delay'));

function get_makerworld_dir(): string
{
    $v = trim((string) cfg('makerworld_download_dir'));
    return $v !== '' ? $v : MAKERWORLD_DOWNLOAD_DIR;
}

function get_thingiverse_dir(): string
{
    $v = trim((string) cfg('thingiverse_download_dir'));
    return $v !== '' ? $v : THINGIVERSE_DOWNLOAD_DIR;
}

function get_cults3d_dir(): string
{
    $v = trim((string) cfg('cults3d_download_dir'));
    return $v !== '' ? $v : CULTS3D_DOWNLOAD_DIR;
}

function get_stlflix_dir(): string
{
    $v = trim((string) cfg('stlflix_download_dir'));
    return $v !== '' ? $v : STLFLIX_DOWNLOAD_DIR;
}

function get_creality_dir(): string
{
    $v = trim((string) cfg('creality_download_dir'));
    return $v !== '' ? $v : CREALITY_DOWNLOAD_DIR;
}

function get_nikko_dir(): string
{
    $v = trim((string) cfg('nikko_download_dir'));
    return $v !== '' ? $v : NIKKO_DOWNLOAD_DIR;
}

function get_hex3dforum_dir(): string
{
    $v = trim((string) cfg('hex3dforum_download_dir'));
    return $v !== '' ? $v : HEX3DFORUM_DOWNLOAD_DIR;
}

/**
 * True when Hex3D Forum has a usable session configured — either the three
 * split fields (user id + sid, k optional) or the legacy combined cookie.
 * sid is the part that actually matters for content access.
 */
/**
 * Sanitize a pasted Hex3D cookie value. Users frequently paste more than the
 * bare value — the whole "name=value" pair, a trailing semicolon, surrounding
 * quotes, or even several cookies at once copied from DevTools. This extracts
 * just the value for the requested field ('u', 'sid', or 'k').
 *
 *   "phpbb3_3ceqg_sid=abc123"        → "abc123"
 *   "abc123; phpbb3_3ceqg_u=21504"   → "abc123" (for sid), "21504" (for u)
 *   '"abc123"'                        → "abc123"
 *   "  abc123  "                      → "abc123"
 */
function hex3dforum_clean_cookie_value(string $raw, string $field): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    // If they pasted a whole cookie blob, try to find this field's named cookie
    // first (phpbb3_<board>_<field>=value), regardless of which box it landed in.
    if (preg_match('/phpbb3_[a-z0-9]+_' . preg_quote($field, '/') . '=([^;\s"\']+)/i', $raw, $m)) {
        return trim($m[1]);
    }

    // Otherwise: strip a leading "name=" (any name), surrounding quotes, and any
    // trailing "; othercookie=..." that got dragged along.
    $val = $raw;
    // Drop everything after the first semicolon (one cookie per field).
    if (($semi = strpos($val, ';')) !== false) {
        $val = substr($val, 0, $semi);
    }
    // Strip a leading "name=" if present.
    if (preg_match('/^\s*[A-Za-z0-9_]+\s*=\s*(.*)$/s', $val, $m)) {
        $val = $m[1];
    }
    // Strip surrounding quotes and whitespace.
    $val = trim($val);
    $val = trim($val, "\"'");
    return trim($val);
}

function hex3dforum_configured(): bool
{
    if (trim((string) cfg('hex3dforum_sid')) !== '') return true;
    return trim((string) cfg('hex3dforum_cookie')) !== '';
}

/**
 * Validate the FORMAT of pasted Hex3D credential values and return a list of
 * human-readable warnings (empty list = all look fine). This is a light sanity
 * check only — the live validate() is the real test. Formats observed on
 * hex3dpatreon.com's phpBB install:
 *   _u   : numeric user id
 *   _sid : 32 hex chars
 *   _k   : 16-char alphanumeric login key (this board's format — NOT the 32-hex
 *          phpBB default; verified against a live cookie capture)
 * We only warn on things that are clearly wrong (e.g. obvious whitespace, a
 * pasted cookie name, or a non-numeric user id), and deliberately do NOT police
 * the exact length/charset of _k or _sid, since those vary by phpBB build and a
 * false "looks wrong" warning is worse than none.
 */
function hex3dforum_format_warnings(string $u, string $sid, string $k): array
{
    $warn = [];
    if ($u !== '' && !ctype_digit($u)) {
        $warn[] = 'User ID should be just a number (the phpbb3_…_u cookie value), e.g. 21504 — got "' . $u . '".';
    }
    // Catch a leftover cookie-name prefix or stray "=" that the sanitizer should
    // have stripped — a strong signal something was pasted whole by mistake.
    foreach (['Session ID' => $sid, 'Login Key' => $k] as $label => $val) {
        if ($val === '') continue;
        if (str_contains($val, '=') || stripos($val, 'phpbb') !== false) {
            $warn[] = $label . ' still contains a cookie name or "=" — paste just the value, not the whole "name=value" pair.';
        } elseif (preg_match('/\s/', $val)) {
            $warn[] = $label . ' contains a space — copy just the cookie value with no surrounding text.';
        }
    }
    return $warn;
}

/** @return string[] configured forum IDs, in the order pasted. */
function hex3dforum_ids(): array
{
    $raw = trim((string) cfg('hex3dforum_forum_ids'));
    if ($raw === '') return [];
    return array_values(array_filter(explode(',', $raw), static fn($v) => $v !== ''));
}

/** True when Creality Cloud credentials (token + user id) are configured. */
function creality_ready(): bool
{
    return trim((string) cfg('creality_token')) !== ''
        && trim((string) cfg('creality_user_id')) !== '';
}

// ---- Database (SQLite via PDO) --------------------------------------------
/**
 * Returns a shared PDO handle, creating the schema on first connect.
 * SQLite chosen deliberately: single-user local tool, no DB server to run,
 * one portable file under the private dir.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $fresh = !is_file(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Wait up to 30s for a held lock at the driver level before giving up.
        PDO::ATTR_TIMEOUT            => 30,
    ]);

    // WAL = better concurrency between the web UI (enqueue) and the worker —
    // but WAL relies on shared-memory/mmap that FUSE and network filesystems
    // (Unraid user-shares, NFS, SMB) often don't implement correctly, which
    // surfaces as spurious "database is locked" errors. So we request WAL, then
    // verify it actually took; if not, fall back to the portable DELETE journal
    // (plain POSIX file locks) which works on every filesystem.
    $pdo->exec('PRAGMA foreign_keys = ON;');
    @$pdo->exec('PRAGMA journal_mode = WAL;');
    $mode = '';
    try {
        $mode = (string) $pdo->query('PRAGMA journal_mode;')->fetchColumn();
    } catch (\Throwable $e) {
        $mode = '';
    }
    if (strtolower($mode) !== 'wal') {
        // WAL didn't stick (likely a FUSE/network mount). DELETE journaling is
        // slower under concurrency but reliable everywhere.
        @$pdo->exec('PRAGMA journal_mode = DELETE;');
    }
    // busy_timeout: how long SQLite retries a locked DB before throwing.
    // Generous (30s) because the worker can hold write intent across a paced
    // download, and on some bind-mounted/FUSE volumes advisory flock between
    // the cron worker and a manual run isn't fully reliable — the timeout is
    // the real guard against "database is locked".
    $pdo->exec('PRAGMA busy_timeout = 30000;');
    // NORMAL is safe under WAL and reduces fsync contention between writers.
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    if ($fresh) {
        @chmod(DB_PATH, 0600);
    }

    init_schema($pdo);
    return $pdo;
}

/**
 * Run a write (prepared statement) with retry-on-lock backoff. Use for any
 * UPDATE/INSERT/DELETE that could collide with another writer. Returns the
 * statement on success; rethrows if it's still locked after several tries.
 *
 * @param array<string,mixed>|array<int,mixed> $params
 */
function db_exec_retry(string $sql, array $params = [], int $tries = 5): \PDOStatement
{
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            $locked = stripos($e->getMessage(), 'locked') !== false
                   || stripos($e->getMessage(), 'busy') !== false;
            if (!$locked || $attempt >= $tries) {
                throw $e;
            }
            if (function_exists('logln')) {
                logln('  DB locked, retrying write (' . $attempt . '/' . $tries . ')…');
            }
            usleep(500000 * $attempt); // 0.5s, 1s, 1.5s… backoff
        }
    }
}

/**
 * Idempotent schema creation. Mirrors schema.sql (kept as documentation).
 * status flow: queued -> working -> done | failed | skipped
 */
function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS download_jobs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            source       TEXT    NOT NULL DEFAULT 'printables',
            model_id     TEXT    NOT NULL,
            slug         TEXT    NOT NULL DEFAULT '',
            name         TEXT    NOT NULL DEFAULT '',
            creator      TEXT    NOT NULL DEFAULT '',
            file_type    TEXT    NOT NULL DEFAULT 'STL',
            status       TEXT    NOT NULL DEFAULT 'queued',
            attempts     INTEGER NOT NULL DEFAULT 0,
            last_error   TEXT    NOT NULL DEFAULT '',
            saved_path   TEXT    NOT NULL DEFAULT '',
            created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(source, model_id, file_type)
        );
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status ON download_jobs(status);');
    // Composite index for the live-queue snapshot query (jobs_status.php),
    // which filters/sorts by status then updated_at on every poll.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status_updated ON download_jobs(status, updated_at);');

    // Favorites: starred models, server-side so they persist across devices.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS favorites (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            source       TEXT    NOT NULL,
            model_id     TEXT    NOT NULL,
            slug         TEXT    NOT NULL DEFAULT '',
            name         TEXT    NOT NULL DEFAULT '',
            creator      TEXT    NOT NULL DEFAULT '',
            thumb        TEXT    NOT NULL DEFAULT '',
            price        INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(source, model_id)
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fav_source ON favorites(source);');

    // Print tracker — a manual print journal (count + notes per model).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS prints (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            source      TEXT    NOT NULL,
            folder      TEXT    NOT NULL,
            print_count INTEGER NOT NULL DEFAULT 0,
            notes       TEXT    NOT NULL DEFAULT '',
            last_printed TEXT   NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(source, folder)
        );
    SQL);

    // Collections — user-defined buckets, with a join table to models.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS collections (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(name)
        );
    SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS collection_items (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            source        TEXT    NOT NULL,
            folder        TEXT    NOT NULL,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(collection_id, source, folder),
            FOREIGN KEY(collection_id) REFERENCES collections(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_colitems_col ON collection_items(collection_id);');

    // My Printers — which printers the user owns + bed size for the fit checker.
    // No UNIQUE(name): people own multiples of the same model, each with its own
    // nickname ("A1 mini — garage").
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS printers (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            nickname   TEXT    NOT NULL DEFAULT '',
            brand      TEXT    NOT NULL DEFAULT '',
            bed_x      INTEGER NOT NULL DEFAULT 0,
            bed_y      INTEGER NOT NULL DEFAULT 0,
            bed_z      INTEGER NOT NULL DEFAULT 0,
            image      TEXT    NOT NULL DEFAULT '',
            enabled    INTEGER NOT NULL DEFAULT 1,
            is_custom  INTEGER NOT NULL DEFAULT 0,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    SQL);

    // Migration: add `nickname` to pre-existing printers installs.
    $pcols = $pdo->query("PRAGMA table_info(printers)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if ($pcols && !in_array('nickname', $pcols, true)) {
        $pdo->exec("ALTER TABLE printers ADD COLUMN nickname TEXT NOT NULL DEFAULT ''");
    }

    // Migration: add `source` to pre-existing installs (CREATE TABLE won't alter).
    $cols = $pdo->query("PRAGMA table_info(download_jobs)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('source', $cols, true)) {
        $pdo->exec("ALTER TABLE download_jobs ADD COLUMN source TEXT NOT NULL DEFAULT 'printables'");
        // New uniqueness key spanning source (old table-level UNIQUE stays harmless).
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_jobs_src_unique ON download_jobs(source, model_id, file_type)");
    }

    // Hex3D Forum local index — populated by the background crawler
    // (hex3d_crawl.php). Browse/search read from here instead of hitting the
    // forum live, since the board is slow, session-gated, and paginated only
    // via admin-ajax-style page walks. One row per topic (= one model).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS hex3d_topics (
            forum_id       TEXT    NOT NULL,
            topic_id       TEXT    NOT NULL,
            forum_name     TEXT    NOT NULL DEFAULT '',
            title          TEXT    NOT NULL DEFAULT '',
            thumb          TEXT    NOT NULL DEFAULT '',
            attachment_ids TEXT    NOT NULL DEFAULT '[]',
            detail_done    INTEGER NOT NULL DEFAULT 0,
            first_seen     TEXT    NOT NULL DEFAULT (datetime('now')),
            indexed_at     TEXT    NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (forum_id, topic_id)
        );
    SQL);
    // detail_done flags whether the per-topic page has been fetched yet (thumb
    // + attachment IDs filled). The crawler inserts the title/id cheaply during
    // the forum-list pass, then fills details incrementally — so a half-finished
    // crawl still leaves a usable, growing index.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hex3d_forum ON hex3d_topics(forum_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hex3d_detail ON hex3d_topics(detail_done);');

    // Single-row crawl state for status display + resumability.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS hex3d_crawl_state (
            id             INTEGER PRIMARY KEY CHECK (id = 1),
            status         TEXT    NOT NULL DEFAULT 'idle',
            started_at     TEXT    NOT NULL DEFAULT '',
            finished_at    TEXT    NOT NULL DEFAULT '',
            topics_seen    INTEGER NOT NULL DEFAULT 0,
            details_done   INTEGER NOT NULL DEFAULT 0,
            last_error     TEXT    NOT NULL DEFAULT '',
            updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    SQL);
    $pdo->exec("INSERT OR IGNORE INTO hex3d_crawl_state (id, status) VALUES (1, 'idle')");
}

// ---- View helpers ---------------------------------------------------------
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---- Favorites ------------------------------------------------------------

/** Add (or update) a favorite. Returns true on success. */
function favorite_add(array $m): bool
{
    $source = strtolower(trim((string) ($m['source'] ?? '')));
    $id     = trim((string) ($m['id'] ?? $m['model_id'] ?? ''));
    if ($source === '' || $id === '') return false;

    $stmt = db()->prepare(
        'INSERT INTO favorites (source, model_id, slug, name, creator, thumb, price)
         VALUES (:source, :model_id, :slug, :name, :creator, :thumb, :price)
         ON CONFLICT(source, model_id) DO UPDATE SET
            slug=excluded.slug, name=excluded.name, creator=excluded.creator,
            thumb=excluded.thumb, price=excluded.price'
    );
    return $stmt->execute([
        ':source'   => $source,
        ':model_id' => $id,
        ':slug'     => (string) ($m['slug'] ?? ''),
        ':name'     => (string) ($m['name'] ?? ''),
        ':creator'  => (string) ($m['creator'] ?? ''),
        ':thumb'    => (string) ($m['thumb'] ?? ''),
        ':price'    => (int) ($m['price'] ?? 0),
    ]);
}

/** Remove a favorite by source + model id. */
function favorite_remove(string $source, string $modelId): bool
{
    $stmt = db()->prepare('DELETE FROM favorites WHERE source = :s AND model_id = :m');
    return $stmt->execute([':s' => strtolower(trim($source)), ':m' => trim($modelId)]);
}

/** Is a given model favorited? */
function favorite_exists(string $source, string $modelId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM favorites WHERE source = :s AND model_id = :m LIMIT 1');
    $stmt->execute([':s' => strtolower(trim($source)), ':m' => trim($modelId)]);
    return (bool) $stmt->fetchColumn();
}

/** All favorites, newest first. @return array<int,array<string,mixed>> */
function favorites_all(): array
{
    $rows = db()->query('SELECT * FROM favorites ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** Set of "source:model_id" keys for quick membership checks in the grid. */
function favorites_key_set(): array
{
    $rows = db()->query('SELECT source, model_id FROM favorites')->fetchAll(PDO::FETCH_ASSOC);
    $set = [];
    foreach ($rows as $r) { $set[$r['source'] . ':' . $r['model_id']] = true; }
    return $set;
}

/**
 * Rebuild the public model URL on the source site for a favorite.
 * Returns '' when we can't construct one.
 */
function favorite_source_url(string $source, string $modelId, string $slug = ''): string
{
    $source = strtolower($source);
    switch ($source) {
        case 'printables':
            // Printables URLs are /model/<id>-<slug>; id alone redirects fine.
            return 'https://www.printables.com/model/' . rawurlencode($modelId);
        case 'makerworld':
            return 'https://makerworld.com/en/models/' . rawurlencode($modelId);
        case 'thingiverse':
            return 'https://www.thingiverse.com/thing:' . rawurlencode($modelId);
        case 'cults3d':
            return $slug !== ''
                ? 'https://cults3d.com/en/3d-model/various/' . rawurlencode($slug)
                : 'https://cults3d.com/en/3d-model/various/' . rawurlencode($modelId);
        case 'stlflix':
            return 'https://www.stlflix.com/model/' . rawurlencode($modelId);
        case 'creality':
            // Creality detail pages key off the group id via profileId.
            return 'https://www.crealitycloud.com/model-detail/' . rawurlencode($slug !== '' ? $slug : $modelId);
        case 'nikko':
            return 'https://nikkoindustriesmembership.com/product/' . rawurlencode($slug !== '' ? $slug : $modelId) . '/';
        case 'hex3dforum':
            // slug carries "{forumId}-{topicId}"; rebuild the topic URL from it.
            if ($slug !== '' && str_contains($slug, '-')) {
                [$fid, $tid] = explode('-', $slug, 2);
                return 'https://www.hex3dpatreon.com/viewtopic.php?f=' . rawurlencode($fid) . '&t=' . rawurlencode($tid);
            }
            return 'https://www.hex3dpatreon.com/viewtopic.php?t=' . rawurlencode($modelId);
        default:
            return '';
    }
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
}

// ---- Event log (errors/warnings the user can see) -------------------------
// A curated, human-readable log of the things that actually matter — auth
// failures, refused links, skips — so a silent stall becomes a visible line.
// Separate from worker.log (which is raw stdout). Rotates at ~512 KB.
define('ERROR_LOG', PRIVATE_DIR . '/farfetched.log');

function ff_log(string $level, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] '
        . strtoupper($level) . ' '
        . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    if (is_file(ERROR_LOG) && (int) @filesize(ERROR_LOG) > 512 * 1024) {
        @rename(ERROR_LOG, ERROR_LOG . '.1'); // single-generation rotation
    }
    @file_put_contents(ERROR_LOG, $line, FILE_APPEND | LOCK_EX);
}

/** Last $lines log entries, oldest-first. */
function ff_log_tail(int $lines = 50): array
{
    if (!is_file(ERROR_LOG)) {
        return [];
    }
    $all = @file(ERROR_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice($all, -max(1, $lines));
}

// ---- Worker activity feed ("chef's pass") --------------------------------
// The Queue page's activity strip mirrors the worker's raw stdout verbatim —
// the same lines you'd see from `docker exec … worker.php`. Cron redirects that
// stdout to private/worker.log, so we simply tail it. No separate writer needed.
define('WORKER_LOG', PRIVATE_DIR . '/worker.log');

/** Last $lines of the raw worker stdout log, oldest-first. */
function worker_feed_tail(int $lines = 200): array
{
    if (!is_file(WORKER_LOG)) {
        return [];
    }
    // Read only the tail of the file so a large log doesn't cost a full read.
    $all = ff_tail_file(WORKER_LOG, $lines);
    return $all;
}

/**
 * Efficiently read approximately the last $lines lines of a (possibly large)
 * file by seeking from the end in chunks. Returns lines oldest-first.
 */
function ff_tail_file(string $path, int $lines): array
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        return [];
    }
    $buffer = '';
    $chunk  = 8192;
    $pos    = fstat($f)['size'] ?? 0;
    $found  = 0;
    while ($pos > 0 && $found <= $lines) {
        $read = (int) min($chunk, $pos);
        $pos -= $read;
        fseek($f, $pos, SEEK_SET);
        $buffer = fread($f, $read) . $buffer;
        $found  = substr_count($buffer, "\n");
    }
    fclose($f);
    $rows = preg_split('/\r?\n/', rtrim($buffer, "\r\n")) ?: [];
    return array_slice($rows, -max(1, $lines));
}

// ---- Token expiry (read the JWT's own exp claim) --------------------------
/**
 * Decode a JWT payload (the middle segment) without verifying the signature —
 * we only need the public claims (exp), not to trust them for auth.
 * Returns null if the token isn't a well-formed JWT.
 */
function jwt_claims(string $jwt): ?array
{
    $parts = explode('.', trim($jwt));
    if (count($parts) < 2) {
        return null;
    }
    $payload = strtr($parts[1], '-_', '+/');
    if ($pad = strlen($payload) % 4) {
        $payload .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode($payload, true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Status of the currently stored token, derived from its own exp claim.
 * state: none | unknown | valid | expiring (<5 min) | expired
 *
 * @return array{state:string,exp:?int,seconds:?int}
 */
function token_status(): array
{
    return token_status_for(get_token());
}

/** Status of an arbitrary access-token JWT string. */
function token_status_for(string $tok): array
{
    if ($tok === '') {
        return ['state' => 'none', 'exp' => null, 'seconds' => null];
    }
    $claims = jwt_claims($tok);
    $exp = isset($claims['exp']) ? (int) $claims['exp'] : null;
    if ($exp === null) {
        return ['state' => 'unknown', 'exp' => null, 'seconds' => null];
    }
    $secs = $exp - time();
    $state = $secs <= 0 ? 'expired' : ($secs <= 300 ? 'expiring' : 'valid');
    return ['state' => $state, 'exp' => $exp, 'seconds' => $secs];
}

/**
 * Status of the stored refresh token (the paste-once secret). Same shape as
 * token_status; 'expiring' threshold is 1 day since it's a 30-day token.
 *
 * @return array{state:string,exp:?int,seconds:?int}
 */
function refresh_token_status(): array
{
    $rt = get_refresh_token();
    if ($rt === '') {
        return ['state' => 'none', 'exp' => null, 'seconds' => null];
    }
    $claims = jwt_claims($rt);
    $exp = isset($claims['exp']) ? (int) $claims['exp'] : null;
    if ($exp === null) {
        return ['state' => 'unknown', 'exp' => null, 'seconds' => null];
    }
    $secs = $exp - time();
    $state = $secs <= 0 ? 'expired' : ($secs <= 86400 ? 'expiring' : 'valid');
    return ['state' => $state, 'exp' => $exp, 'seconds' => $secs];
}

/** Human "42 min" / "3 h 5 min" / "12 s" from a seconds count. */
function human_duration(int $secs): string
{
    $secs = abs($secs);
    if ($secs < 60) {
        return $secs . ' s';
    }
    if ($secs < 3600) {
        return intdiv($secs, 60) . ' min';
    }
    return intdiv($secs, 3600) . ' h ' . intdiv($secs % 3600, 60) . ' min';
}
