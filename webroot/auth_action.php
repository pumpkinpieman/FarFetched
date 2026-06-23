<?php
declare(strict_types=1);

/**
 * auth_action.php — mutating auth operations from the Settings page.
 *
 * POST (JSON), all CSRF-guarded:
 *   { action:'set',    password, csrf }                 first-time setup
 *   { action:'change', current, password, csrf }        change existing password
 *   { action:'disable',current, csrf }                  remove the lock
 *   { action:'logout', csrf }                           end this session
 *
 * 'set' is only allowed when no password exists yet. 'change'/'disable' require
 * the current password. After 'set' or 'change' the session is marked authed so
 * the user isn't immediately bounced to the login screen.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

auth_session();
header('Content-Type: application/json');

function aa_out(array $p): void { echo json_encode($p); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    aa_out(['ok' => false, 'error' => 'POST required']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    http_response_code(403);
    aa_out(['ok' => false, 'error' => 'csrf']);
}

$action = (string) ($in['action'] ?? '');

switch ($action) {
    case 'set':
        if (auth_is_enabled()) {
            aa_out(['ok' => false, 'error' => 'A password is already set. Use change instead.']);
        }
        $pw = (string) ($in['password'] ?? '');
        if (!auth_set_password($pw)) {
            aa_out(['ok' => false, 'error' => 'Password must be at least 6 characters.']);
        }
        auth_login_session(); // keep them logged in right after setup
        aa_out(['ok' => true, 'enabled' => true]);
        // no break needed (exit above)

    case 'change':
        if (!auth_is_enabled()) {
            aa_out(['ok' => false, 'error' => 'No password is set yet.']);
        }
        if (!auth_verify((string) ($in['current'] ?? ''))) {
            usleep(400000);
            aa_out(['ok' => false, 'error' => 'Current password is incorrect.']);
        }
        if (!auth_set_password((string) ($in['password'] ?? ''))) {
            aa_out(['ok' => false, 'error' => 'New password must be at least 6 characters.']);
        }
        auth_login_session();
        aa_out(['ok' => true, 'enabled' => true]);

    case 'disable':
        if (!auth_is_enabled()) {
            aa_out(['ok' => true, 'enabled' => false]);
        }
        if (!auth_verify((string) ($in['current'] ?? ''))) {
            usleep(400000);
            aa_out(['ok' => false, 'error' => 'Current password is incorrect.']);
        }
        auth_clear_password();
        auth_logout();
        aa_out(['ok' => true, 'enabled' => false]);

    case 'logout':
        auth_logout();
        aa_out(['ok' => true, 'loggedOut' => true]);

    default:
        aa_out(['ok' => false, 'error' => 'Unknown action']);
}
