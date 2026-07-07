<?php
declare(strict_types=1);

/**
 * auth.php — single-user session lock for FarFetched.
 *
 * Storage: one row in the `auth` table of the private SQLite db. We store only a
 * bcrypt password hash (no username — this is a single-user self-hosted tool).
 *
 * States:
 *   - No hash present  -> auth DISABLED; app is open. Settings offers setup.
 *   - Hash present      -> auth ENABLED; pages call require_auth() and redirect
 *                          to login.php until $_SESSION['ff_authed'] is set.
 *
 * Forgot password: there is no email reset (offline tool). The owner clears the
 * hash via a terminal command (see login.php), which disables auth and lets them
 * log in freely and set a new password.
 *
 * Requires bootstrap.php (db(), session helpers) to be loaded first.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/bootstrap.php';
}

/** Ensure the auth table exists (idempotent). */
function auth_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS auth (
            id            INTEGER PRIMARY KEY CHECK (id = 1),
            password_hash TEXT NOT NULL,
            created_at    TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at    TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );
    $done = true;
}

/** True if a password has been set (auth is active). */
function auth_is_enabled(): bool
{
    auth_init();
    $h = db()->query('SELECT password_hash FROM auth WHERE id = 1')->fetchColumn();
    return is_string($h) && $h !== '';
}

/** Begin the session if not already started. */
function auth_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // PHP's default session dir (/tmp) may be read-only (e.g. a hardened or
    // tmpfs-less container). Store sessions in the app's private dir, which is
    // always writable, so session_start() can't fail and emit header-breaking
    // warnings. Must be set BEFORE session_start().
    if (!headers_sent()) {
        $dir = PRIVATE_DIR . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_maxlifetime', '86400');
        }
    }
    session_start();
}

/** True if the current visitor is authenticated (or auth is disabled). */
function auth_check(): bool
{
    if (!auth_is_enabled()) {
        return true; // open app — nothing to check
    }
    auth_session();
    return !empty($_SESSION['ff_authed']);
}

/**
 * Page guard. Call at the very top of every page (after bootstrap). If auth is
 * enabled and the visitor isn't logged in, redirect to the login screen and
 * stop. No-op when auth is disabled or already authed.
 */
function require_auth(): void
{
    if (auth_check()) {
        return;
    }
    // Remember where they were headed (same-origin path only).
    auth_session();
    $to = $_SERVER['REQUEST_URI'] ?? 'index.php';
    if (!is_string($to) || $to === '' || strpos($to, '://') !== false) {
        $to = 'index.php';
    }
    $_SESSION['ff_after_login'] = $to;
    header('Location: login.php');
    exit;
}

/** Set (or replace) the password. Returns true on success. */
function auth_set_password(string $plain): bool
{
    auth_init();
    $plain = trim($plain);
    if (strlen($plain) < 6) {
        return false; // enforce a minimum
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    if ($hash === false) {
        return false;
    }
    // Upsert the single row.
    $db = db();
    $db->prepare(
        'INSERT INTO auth (id, password_hash) VALUES (1, :h)
         ON CONFLICT(id) DO UPDATE SET password_hash = :h2, updated_at = datetime(\'now\')'
    )->execute([':h' => $hash, ':h2' => $hash]);
    return true;
}

/** Verify a password attempt against the stored hash. */
function auth_verify(string $plain): bool
{
    auth_init();
    $hash = db()->query('SELECT password_hash FROM auth WHERE id = 1')->fetchColumn();
    if (!is_string($hash) || $hash === '') {
        return false;
    }
    return password_verify($plain, $hash);
}

/** Mark the current session authenticated (after a verified login or first set). */
function auth_login_session(): void
{
    auth_session();
    session_regenerate_id(true); // prevent fixation
    $_SESSION['ff_authed'] = true;
}

/** Log out: drop the auth flag for this session. */
function auth_logout(): void
{
    auth_session();
    unset($_SESSION['ff_authed']);
}

/** Remove the password entirely (disables auth). Used by setup/disable flows. */
function auth_clear_password(): void
{
    auth_init();
    db()->exec('DELETE FROM auth WHERE id = 1');
}
