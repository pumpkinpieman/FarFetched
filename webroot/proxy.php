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
    'cdn.thangs.com',
    'images.makerworld.com',
    'makerworld-model.oss-us-west-1.aliyuncs.com',
    's3.us-east-2.amazonaws.com',
    'static.stlflix.com',
    'stlflix.b-cdn.net',
];

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    exit;
}

$parsed = parse_url($url);
$host   = strtolower($parsed['host'] ?? '');
$scheme = strtolower($parsed['scheme'] ?? '');

if ($scheme !== 'https' || !in_array($host, $ALLOWED_HOSTS, true)) {
    http_response_code(403);
    exit;
}

// Block private/internal IP ranges in case of redirect.
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,   // no redirects — prevents SSRF
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FarFetched/1.0',
    CURLOPT_HTTPHEADER     => ['Accept: image/*'],
]);

$body = curl_exec($ch);
$st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

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
