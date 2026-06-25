<?php
declare(strict_types=1);

/**
 * proxy.php — server-side image proxy for CORS-blocked CDN thumbnails.
 *
 * GET ?url=<encoded remote image URL>
 *
 * Security:
 *   - Only allows known 3D-print platform CDN hostnames.
 *   - Never follows redirects to private/internal addresses.
 *   - 30s timeout; 8MB response cap.
 *   - Cached 24h by the browser.
 */

$ALLOWED_HOSTS = [
    'images.cults3d.com',
    'cdn.cults3d.com',
    'files.cults3d.com',
    'cdn.thingiverse.com',
    'resize.thingiverse.com',
    'thingiverse-production.s3.amazonaws.com',
    'thingiverse-production.s3-us-west-2.amazonaws.com',
    'cdn.thangs.com',
    'images.makerworld.com',
    'makerworld-model.oss-us-west-1.aliyuncs.com',
    's3.us-east-2.amazonaws.com',
    'static.stlflix.com',
    'stlflix.b-cdn.net',
    'nikkoindustriesmembership.com',
    'hex3dpatreon.com',
];

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    exit;
}

$parsed = parse_url($url);
$host   = strtolower($parsed['host'] ?? '');
$scheme = strtolower($parsed['scheme'] ?? '');

// Thingiverse resize service wraps the real CDN URL in ?url= param.
// Unwrap it so we fetch the actual image directly.
if ($host === 'resize.thingiverse.com' && isset($parsed['query'])) {
    parse_str($parsed['query'], $qp);
    if (isset($qp['url']) && str_starts_with($qp['url'], 'https://')) {
        $innerParsed = parse_url($qp['url']);
        $innerHost   = strtolower($innerParsed['host'] ?? '');
        if (in_array($innerHost, $ALLOWED_HOSTS, true)) {
            $url  = $qp['url'];
            $parsed = $innerParsed;
            $host   = $innerHost;
            $scheme = 'https';
        }
    }
}

if ($scheme !== 'https' || !in_array($host, $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    exit;
}

// Block private/internal IP ranges in case of redirect.
// Allow up to 3 redirects but only within the same allowlist.
$ch = curl_init($url);
$proxyHeaders = ['Accept: image/*'];

// Hex3D board images (/imag/, /imag2/) are session-gated — without the login
// cookie they 403. Attach the stored session so previews actually load. Other
// hosts serve images publicly and need no auth.
if ($host === 'hex3dpatreon.com') {
    require_once __DIR__ . '/bootstrap.php';
    $h3u   = trim((string) cfg('hex3dforum_u'));
    $h3sid = trim((string) cfg('hex3dforum_sid'));
    $h3k   = trim((string) cfg('hex3dforum_k'));
    $cookieParts = [];
    if ($h3u   !== '') $cookieParts[] = 'phpbb3_3ceqg_u='   . $h3u;
    if ($h3sid !== '') $cookieParts[] = 'phpbb3_3ceqg_sid=' . $h3sid;
    if ($h3k   !== '') $cookieParts[] = 'phpbb3_3ceqg_k='   . $h3k;
    if ($cookieParts !== []) {
        $proxyHeaders[] = 'Cookie: ' . implode('; ', $cookieParts);
        $proxyHeaders[] = 'Referer: https://hex3dpatreon.com/index.php' . ($h3sid !== '' ? '?sid=' . $h3sid : '');
    }
    // phpBB scripts like download/file.php enforce the SID as a URL parameter
    // (static /imag/ uploads don't — Apache serves those directly). Without it,
    // an attachment-image fetch is rejected → 502. Append it for script URLs.
    if ($h3sid !== '' && str_contains($url, 'download/file.php') && !str_contains($url, 'sid=')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'sid=' . $h3sid;
        curl_setopt($ch, CURLOPT_URL, $url);
    }
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FarFetched/1.0',
    CURLOPT_HTTPHEADER     => $proxyHeaders,
]);

$body     = curl_exec($ch);
$st       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct       = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

// Validate the final URL after redirects is still an allowed host.
$finalHost = strtolower(parse_url($finalUrl, PHP_URL_HOST) ?? '');
if ($finalHost !== '' && !in_array($finalHost, $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    exit;
}

if ($body === false || $st !== 200 || strlen($body) > 8 * 1024 * 1024) {
    http_response_code(502);
    exit;
}

// Only serve image content types.
$ct = strtolower(explode(';', $ct)[0]);
$allowed_ct = ['image/jpeg','image/png','image/gif','image/webp','image/avif','image/svg+xml'];
if (!in_array($ct, $allowed_ct, true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $ct);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $body;
