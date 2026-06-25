<?php
/**
 * browse_dirs.php — server-side directory browser for the Custom Folders picker.
 *
 * Lists immediate subdirectories of a requested path, strictly jailed under
 * CUSTOM_BROWSE_ROOT (/custom). The container only sees what's bind-mounted
 * there, so this can never wander into system or app directories.
 *
 *   GET ?path=/custom/SomeFolder   → { ok, root, path, parent, dirs:[{name,path,models}] }
 *
 * Security model:
 *   - Auth required (same guard as every other page).
 *   - The resolved realpath() MUST remain within CUSTOM_BROWSE_ROOT, else 403.
 *     This defeats ../ traversal and symlink escapes (realpath resolves both).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); exit; }

header('Content-Type: application/json');

// The browse jail. Anything the user is allowed to pick lives under here.
// Add the matching bind mount in the Unraid template: host dir => /custom.
if (!defined('CUSTOM_BROWSE_ROOT')) {
    define('CUSTOM_BROWSE_ROOT', '/custom');
}

$root = CUSTOM_BROWSE_ROOT;

// If the mount doesn't exist yet, say so cleanly rather than erroring.
if (!is_dir($root)) {
    echo json_encode([
        'ok'        => false,
        'error'     => 'not_mounted',
        'root'      => $root,
        'message'   => 'The /custom folder is not mounted yet. Add a bind mount (host dir => /custom) in the FarFetched Docker template, then restart the container.',
    ]);
    exit;
}

$rootReal = realpath($root);
if ($rootReal === false) {
    echo json_encode(['ok' => false, 'error' => 'root_unresolved', 'root' => $root]);
    exit;
}

// Requested path defaults to the root.
$req = (string) ($_GET['path'] ?? $rootReal);
if ($req === '') { $req = $rootReal; }

$real = realpath($req);

// Containment: the resolved path must exist, be a directory, and sit inside the
// jail (or be the jail itself). realpath() has already collapsed any ../ and
// resolved symlinks, so a prefix check here is sound.
$within = $real !== false
    && is_dir($real)
    && ($real === $rootReal || strncmp($real, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) === 0);

if (!$within) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'out_of_bounds', 'root' => $rootReal]);
    exit;
}

// Parent (null if we're at the jail root, so the UI can't navigate above it).
$parent = null;
if ($real !== $rootReal) {
    $p = dirname($real);
    if ($p === $rootReal || strncmp($p, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) === 0) {
        $parent = $p;
    } else {
        $parent = $rootReal;
    }
}

// List immediate subdirectories, alphabetical, with a cheap model (subfolder)
// count so the user can see which folders actually contain models.
$dirs = [];
foreach (scandir($real) ?: [] as $name) {
    if ($name === '.' || $name === '..') { continue; }
    if ($name[0] === '.') { continue; } // hide dotfolders (.farfetched, etc.)
    $abs = $real . '/' . $name;
    if (!is_dir($abs)) { continue; }
    // count_models() lives in bootstrap.php; it counts model subfolders.
    $count = function_exists('count_models') ? count_models($abs) : 0;
    $dirs[] = ['name' => $name, 'path' => $abs, 'models' => $count];
}
usort($dirs, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

echo json_encode([
    'ok'     => true,
    'root'   => $rootReal,
    'path'   => $real,
    'parent' => $parent,
    'dirs'   => $dirs,
]);
