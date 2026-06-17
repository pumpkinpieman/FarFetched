<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Cults3DService.php';

$slug = $argv[1] ?? 'free-pioneer007-3293';

echo "=== curl-impersonate test ===\n";

// Locate the binary the same way the service does (by selected browser).
$browser = strtolower((string) cfg('cults3d_browser')) ?: 'chrome';
echo "selected browser: $browser\n";
$byBrowser = [
    'chrome'  => ['curl_chrome116', 'curl_chrome110', 'curl_chrome104', 'curl_chrome100'],
    'edge'    => ['curl_edge101', 'curl_edge99', 'curl_chrome116'],
    'firefox' => ['curl_ff117', 'curl_ff109', 'curl_ff102', 'curl_ff100'],
    'safari'  => ['curl_safari17_0', 'curl_safari15_5', 'curl_safari15_3'],
];
$candidates = array_merge(
    [(string) cfg('curl_impersonate_bin')],
    array_map(fn($n) => '/usr/local/bin/' . $n, $byBrowser[$browser] ?? $byBrowser['chrome']),
    ['/usr/local/bin/curl-impersonate-chrome', '/usr/local/bin/curl-impersonate']
);
$bin = '';
foreach ($candidates as $c) {
    if ($c !== '' && is_executable($c)) { $bin = $c; break; }
}
echo "binary: " . ($bin !== '' ? $bin : 'NOT FOUND') . "\n";
if ($bin === '') {
    echo "Install curl-impersonate (see Dockerfile.curl-impersonate.snippet) and rebuild.\n";
    exit(1);
}

$sess = (string) cfg('cults3d_session');
$cf   = (string) cfg('cults3d_cf_clearance');
$cookie = '_session_id=' . $sess;
if ($cf !== '') $cookie .= '; cf_clearance=' . $cf;

$url = "https://cults3d.com/en/3d-model/_/$slug";
echo "GET $url (via impersonated TLS)\n\n";

$cmd = implode(' ', array_map('escapeshellarg', [
    $bin, '-sS', '-i', '--max-time', '60',
    '-H', 'Cookie: ' . $cookie,
    $url,
]));
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);

$parts = preg_split("/\r?\n\r?\n/", (string) $out, 2);
$head = $parts[0] ?? '';
$body = $parts[1] ?? '';

if (preg_match('#^HTTP/\S+\s+(\d+)#', $head, $m)) echo "HTTP " . $m[1] . "\n";
if ($err) echo "stderr: " . trim($err) . "\n";

if (stripos($body, 'Just a moment') !== false || stripos($body, 'challenge-platform') !== false) {
    echo ">>> STILL a Cloudflare challenge — cf_clearance/UA mismatch, or this slug is gated harder.\n";
} elseif (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $cm)) {
    echo ">>> SUCCESS — csrf-token FOUND: " . substr($cm[1], 0, 24) . "...\n";
    echo ">>> curl-impersonate cleared Cloudflare. Downloads should work.\n";
} else {
    echo ">>> No challenge, but no csrf-token either (session may be invalid).\n";
}
echo "body length: " . strlen($body) . "\n";
echo "Done.\n";
