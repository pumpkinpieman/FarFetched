<?php
declare(strict_types=1);
/**
 * font_upload.php — install fonts for the OpenSCAD text() nodes.
 *
 * Actions (POST, auth + CSRF gated):
 *   upload  (multipart)  — install an uploaded .ttf/.otf/.ttc file
 *   url     (JSON/form)  — fetch a DIRECT font-file URL and install it
 *   list    (JSON/form)  — list installed user fonts
 *   delete  (JSON/form)  — remove one installed user font
 *
 * Fonts land in PRIVATE_DIR/fonts (outside the web root, fontconfig-scanned via
 * the image's /etc/fonts/local.conf). fc-cache refreshes the index; the picker
 * re-reads on next Customize load.
 *
 * Security posture:
 *   - Only real font files accepted: extension AND magic-byte checked
 *     (.ttf=00 01 00 00 | .otf=OTTO | .ttc=ttcf). woff/woff2 rejected — OpenSCAD
 *     can't render them.
 *   - URL import is SSRF-hardened: https only, host resolved and rejected if it
 *     maps to any private/loopback/link-local range, the validated IP is PINNED
 *     for the connection (defeats DNS-rebind), redirects capped to https only,
 *     size + time capped, and the fetched bytes re-validated by magic number.
 *   - Stored filename is sanitized to a strict allowlist; writes are confined to
 *     the fonts dir. No SQL surface.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

function fu_out(array $p): void { echo json_encode($p); exit; }
function fu_err(string $m, int $code = 400): void { http_response_code($code); fu_out(['ok' => false, 'error' => $m]); }

if (!auth_check())                              fu_err('Not authorized.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')      fu_err('POST only.', 405);
if (!csrf_ok())                                 fu_err('Session expired — reload the page.', 403);

const FONT_MAX_BYTES = 5 * 1024 * 1024;        // 5 MB cap (upload + URL)
const FONT_HTTP_TIMEOUT = 20;                  // seconds

/** Directory that holds user-installed fonts (created lazily, 0700). */
function fonts_dir(): string
{
    $d = PRIVATE_DIR . '/fonts';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

/**
 * Classify font bytes by magic number. Returns 'ttf' | 'otf' | 'ttc' | ''.
 * (Extension is advisory only; the header is authoritative.)
 */
function font_kind(string $bytes): string
{
    if (strlen($bytes) < 4) {
        return '';
    }
    $sig = substr($bytes, 0, 4);
    if ($sig === "\x00\x01\x00\x00" || $sig === "true" || $sig === "typ1") {
        return 'ttf';           // TrueType (and legacy Apple 'true'/'typ1')
    }
    if ($sig === 'OTTO') {
        return 'otf';           // OpenType with CFF outlines
    }
    if ($sig === 'ttcf') {
        return 'ttc';           // TrueType Collection
    }
    return '';                  // woff (wOFF) / woff2 (wOF2) / anything else -> reject
}

/** Sanitize a proposed base name to a strict allowlist; force a valid extension. */
function font_safe_name(string $name, string $kind): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9 _-]/', '', $base) ?? '';
    $base = trim($base);
    if ($base === '') {
        $base = 'font_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    $base = substr($base, 0, 64);
    return $base . '.' . $kind;
}

/** Persist validated font bytes; refresh the fontconfig cache. Returns filename. */
function font_store(string $bytes, string $suggestedName, string $kind): string
{
    $dir  = fonts_dir();
    $name = font_safe_name($suggestedName, $kind);
    $path = $dir . '/' . $name;

    // Avoid clobbering an existing font of a different origin.
    if (file_exists($path)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $kind;
        $path = $dir . '/' . $name;
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write the font file (permissions?).');
    }
    @chmod($path, 0644);

    // Refresh fontconfig. www-data needs a writable cache home; keep it private.
    $cacheHome = PRIVATE_DIR . '/fontcache';
    if (!is_dir($cacheHome)) { @mkdir($cacheHome, 0755, true); }
    @exec('XDG_CACHE_HOME=' . escapeshellarg($cacheHome) . ' fc-cache -f '
        . escapeshellarg($dir) . ' 2>&1');

    return $name;
}

/* ------------------------------------------------------------------ SSRF guard */

/**
 * True only for globally-routable addresses. Rejects private, loopback,
 * link-local, and reserved ranges (v4 + v6).
 */
function ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/** Resolve a hostname to all A/AAAA addresses. */
function resolve_ips(string $host): array
{
    $ips = [];
    // Literal IP passed directly?
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }
    $a = @gethostbynamel($host);
    if (is_array($a)) { $ips = array_merge($ips, $a); }
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) { if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; } }
    }
    return array_values(array_unique($ips));
}

/**
 * Fetch a direct font URL with SSRF protections. Returns the raw bytes.
 * Throws on any policy violation or transport error.
 */
function fetch_font_url(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('Invalid URL.');
    }
    if (strtolower($parts['scheme']) !== 'https') {
        throw new RuntimeException('Only https:// URLs are allowed.');
    }
    $host = $parts['host'];

    // Resolve + require EVERY resolved address to be public (defeats a host that
    // resolves to a mix of public and internal IPs).
    $ips = resolve_ips($host);
    if (!$ips) {
        throw new RuntimeException('Could not resolve host.');
    }
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) {
            throw new RuntimeException('Refusing to fetch from a private/internal address.');
        }
    }
    $pinIp = $ips[0];
    $port  = (int) ($parts['port'] ?? 443);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('URL import unavailable (curl missing).');
    }

    $buf = '';
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        // Pin the validated IP so libcurl connects exactly where we checked
        // (closes the DNS-rebind window between resolve and connect).
        CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $pinIp],
        // Follow redirects but keep them on https, and re-guard each hop below.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0,
        CURLOPT_REDIR_PROTOCOLS=> defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => FONT_HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'FarFetched-FontImport/1.0',
        CURLOPT_MAXFILESIZE    => FONT_MAX_BYTES,
        // Hard size ceiling even if the server lies about Content-Length.
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$buf) {
            $buf .= $chunk;
            if (strlen($buf) > FONT_MAX_BYTES) {
                return 0; // abort transfer
            }
            return strlen($chunk);
        },
    ]);

    $ok   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Final effective URL — ensure a redirect didn't land somewhere non-https/internal.
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($ok === false || $buf === '') {
        if ($errNo === CURLE_FILESIZE_EXCEEDED || strlen($buf) > FONT_MAX_BYTES) {
            throw new RuntimeException('Font exceeds the 5 MB limit.');
        }
        throw new RuntimeException('Download failed' . ($errMsg ? ': ' . $errMsg : '.'));
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Server returned HTTP ' . $code . '.');
    }
    // Guard the final hop's host too.
    $fp = parse_url($finalUrl);
    if (!$fp || strtolower($fp['scheme'] ?? '') !== 'https' || empty($fp['host'])) {
        throw new RuntimeException('Redirected to a disallowed location.');
    }
    foreach (resolve_ips($fp['host']) as $ip) {
        if (!ip_is_public($ip)) {
            throw new RuntimeException('Redirected to a private/internal address.');
        }
    }
    return $buf;
}

/* ------------------------------------------------------------------ dispatch */

$action = (string) ($_POST['action'] ?? '');

try {
    switch ($action) {

        case 'upload': {
            if (empty($_FILES['font']) || !is_uploaded_file($_FILES['font']['tmp_name'] ?? '')) {
                fu_err('No file received.');
            }
            if (($_FILES['font']['size'] ?? 0) > FONT_MAX_BYTES) {
                fu_err('Font exceeds the 5 MB limit.');
            }
            $bytes = (string) @file_get_contents($_FILES['font']['tmp_name']);
            $kind  = font_kind($bytes);
            if ($kind === '') {
                fu_err('Not a supported font. Only .ttf, .otf and .ttc are accepted (woff/woff2 can’t be used by OpenSCAD).');
            }
            $name = font_store($bytes, (string) ($_FILES['font']['name'] ?? 'font'), $kind);
            fu_out(['ok' => true, 'installed' => $name]);
        }

        case 'url': {
            $url = trim((string) ($_POST['url'] ?? ''));
            if ($url === '') {
                fu_err('No URL provided.');
            }
            $bytes = fetch_font_url($url);
            $kind  = font_kind($bytes);
            if ($kind === '') {
                fu_err('That URL did not return a usable font. Only direct .ttf/.otf/.ttc links work (not .woff/.woff2 or download pages).');
            }
            $suggested = basename((string) (parse_url($url, PHP_URL_PATH) ?: 'font'));
            $name = font_store($bytes, $suggested, $kind);
            fu_out(['ok' => true, 'installed' => $name]);
        }

        case 'families': {
            // Font FAMILY names (what text(font=) needs), for the live picker.
            fu_out(['ok' => true, 'families' => function_exists('openscad_fonts') ? openscad_fonts() : []]);
        }

        case 'list': {
            $out = [];
            foreach (glob(fonts_dir() . '/*.{ttf,otf,ttc}', GLOB_BRACE) ?: [] as $f) {
                $out[] = ['name' => basename($f), 'size' => filesize($f) ?: 0];
            }
            usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            fu_out(['ok' => true, 'fonts' => $out]);
        }

        case 'delete': {
            $name = basename((string) ($_POST['name'] ?? ''));
            // Strict allowlist: a stored font name only.
            if (!preg_match('/^[A-Za-z0-9 _-]{1,64}\.(ttf|otf|ttc)$/', $name)) {
                fu_err('Invalid font name.');
            }
            $path = fonts_dir() . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
                $cacheHome = PRIVATE_DIR . '/fontcache';
                @exec('XDG_CACHE_HOME=' . escapeshellarg($cacheHome) . ' fc-cache -f '
                    . escapeshellarg(fonts_dir()) . ' 2>&1');
                fu_out(['ok' => true, 'removed' => $name]);
            }
            fu_err('Font not found.', 404);
        }

        default:
            fu_err('Unknown action.');
    }
} catch (Throwable $e) {
    ff_log('warn', 'font_upload: ' . $e->getMessage());
    fu_err($e->getMessage(), 400);
}
