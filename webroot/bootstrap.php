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
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
        return [];
    }
    $out = [];
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
    return $out;
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
function extract_zip_safe(string $zipPath, string $targetDir): bool
{
    if (!is_file($zipPath)) {
        return false;
    }
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true)) {
        return false;
    }
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        return false;
    }
    for ($i = 0; $i < $za->numFiles; $i++) {
        $entry = $za->getNameIndex($i);
        if ($entry === false || $entry === '' || $entry[0] === '/' || strpos($entry, '..') !== false) {
            $za->close();
            return false; // unsafe path — refuse the whole archive
        }
    }
    $ok = $za->extractTo($targetDir);
    $za->close();
    return $ok;
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

    $payload = "<?php return " . var_export($current, true) . ";\n";
    if (file_put_contents(CONFIG_STORE, $payload, LOCK_EX) === false) {
        return false;
    }
    @chmod(CONFIG_STORE, 0600);
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

    // WAL = better concurrency between the web UI (enqueue) and the worker.
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
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

    // Migration: add `source` to pre-existing installs (CREATE TABLE won't alter).
    $cols = $pdo->query("PRAGMA table_info(download_jobs)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('source', $cols, true)) {
        $pdo->exec("ALTER TABLE download_jobs ADD COLUMN source TEXT NOT NULL DEFAULT 'printables'");
        // New uniqueness key spanning source (old table-level UNIQUE stays harmless).
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_jobs_src_unique ON download_jobs(source, model_id, file_type)");
    }
}

// ---- View helpers ---------------------------------------------------------
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
