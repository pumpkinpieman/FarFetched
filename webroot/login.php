<?php
declare(strict_types=1);

/**
 * login.php — session unlock screen.
 *
 * GET  : show the login form (or a "set your password" form on very first run
 *        if somehow reached while auth is disabled).
 * POST : verify the password; on success, mark the session authed and bounce
 *        back to wherever the user was headed.
 *
 * Forgot password: no email reset (offline tool). We show the exact terminal
 * command that clears the stored hash, after which auth is disabled and the
 * owner can log in freely and set a new password in Settings.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

auth_session();

// If auth isn't even enabled, there's nothing to log into.
if (!auth_is_enabled()) {
    header('Location: index.php');
    exit;
}
// Already authed? Go home.
if (!empty($_SESSION['ff_authed'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF.
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expired — please try again.';
    } else {
        $pw = (string) ($_POST['password'] ?? '');
        if (auth_verify($pw)) {
            auth_login_session();
            $to = $_SESSION['ff_after_login'] ?? 'index.php';
            unset($_SESSION['ff_after_login']);
            // Same-origin safety.
            if (!is_string($to) || $to === '' || strpos($to, '://') !== false) {
                $to = 'index.php';
            }
            header('Location: ' . $to);
            exit;
        }
        $error = 'Incorrect password.';
        // Tiny delay to blunt brute-forcing.
        usleep(400000);
    }
}

$csrf = csrf_token();
// Container path to the db, for the forgot-password command.
$dbContainerPath = '/var/www/html/private/fetcher.db';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · FarFetched</title>
<link rel="stylesheet" href="css/styles.css?v=20260622a">
<script>
  // Apply saved theme immediately (no flash).
  (function(){ var t = localStorage.getItem('theme'); if (t && t !== 'dark') document.documentElement.setAttribute('data-theme', t); })();
</script>
<style>
  /* Login is a standalone page (no sidebar) — override the app's flex body so
     the card centers in the full viewport. */
  body{display:block !important;}
  .login-wrap{width:100%;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;}
  .login-card{width:100%;max-width:380px;background:var(--card);border:1px solid var(--line);
              border-radius:16px;padding:32px;box-shadow:0 20px 60px -20px rgba(0,0,0,.5);}
  .login-brand{display:flex;align-items:center;gap:10px;font-family:ui-serif,Georgia,serif;
               font-size:22px;font-weight:600;color:var(--clay);margin-bottom:4px;}
  .login-brand img{height:1.1em;width:auto;}
  .login-sub{font-size:13px;color:var(--muted);margin-bottom:24px;}
  .login-label{display:block;font-size:12px;color:var(--muted);margin-bottom:7px;letter-spacing:.3px;}
  .login-input{width:100%;box-sizing:border-box;padding:11px 14px;border:1px solid var(--line);
               background:var(--panel);color:var(--ink);border-radius:10px;font-size:14px;}
  .login-input:focus{outline:none;border-color:var(--clay);}
  .login-btn{width:100%;margin-top:16px;padding:12px;background:var(--clay);color:#fff;border:none;
             border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s;}
  .login-btn:hover{background:var(--clay-deep);}
  .login-err{margin-top:14px;padding:10px 12px;background:rgba(224,92,92,.12);
             border:1px solid rgba(224,92,92,.4);color:var(--err);border-radius:9px;font-size:13px;}
  .login-forgot{margin-top:20px;text-align:center;}
  .login-forgot button{background:none;border:none;color:var(--muted);font-size:12.5px;cursor:pointer;
                       text-decoration:underline;font-family:inherit;}
  .login-forgot button:hover{color:var(--clay);}
  .forgot-panel{margin-top:18px;padding:16px;background:var(--panel);border:1px solid var(--line);
                border-radius:11px;font-size:13px;color:var(--ink);line-height:1.55;}
  .forgot-panel ol{margin:10px 0 0;padding-left:20px;}
  .forgot-panel li{margin-bottom:8px;}
  .forgot-cmd{display:flex;align-items:flex-start;gap:8px;margin:8px 0;padding:11px 13px;
              background:#11100e;color:#d8e0c8;border-radius:8px;
              font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;
              border:1px solid #2a2a26;}
  .forgot-cmd-text{flex:1;white-space:pre-wrap;word-break:break-all;line-height:1.5;}
  .forgot-copy{flex:0 0 auto;background:rgba(255,255,255,.12);border:none;color:#cfcabf;
               font-size:10px;padding:4px 9px;border-radius:5px;cursor:pointer;
               font-family:inherit;align-self:flex-start;}
  .forgot-copy:hover{background:rgba(255,255,255,.24);}
</style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <img src="logo.svg" alt=""> FarFetched
      </div>
      <div class="login-sub">This instance is locked. Enter your password to continue.</div>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="login-label" for="pw">Password</label>
        <input class="login-input" type="password" name="password" id="pw" autofocus required>
        <button class="login-btn" type="submit">Sign in</button>
        <?php if ($error !== ''): ?>
          <div class="login-err"><?= e($error) ?></div>
        <?php endif; ?>
      </form>

      <div class="login-forgot">
        <button type="button" id="forgotToggle">Forgot password?</button>
      </div>

      <div class="forgot-panel" id="forgotPanel" hidden>
        No email reset here — FarFetched is a local tool. To regain access, run this
        on the server hosting FarFetched (the host shell), then reload this page:
        <span class="forgot-cmd"><span class="forgot-cmd-text">docker exec FarFetched sqlite3 <?= e($dbContainerPath) ?> "DELETE FROM auth;"</span><button class="forgot-copy" id="forgotCopy" type="button">copy</button></span>
        <ol>
          <li>The lock is removed and the app becomes open again.</li>
          <li>Reload this page — you'll go straight in.</li>
          <li>Open <strong>Settings → Security</strong> and set a new password.</li>
        </ol>
        <div style="margin-top:10px;color:var(--muted);font-size:12px;">
          Adjust the container name if yours isn't <code>FarFetched</code>. This clears only the
          password — your models, settings, and data are untouched.
        </div>
      </div>
    </div>
  </div>

<script>
  document.getElementById('forgotToggle').addEventListener('click', function () {
    var p = document.getElementById('forgotPanel');
    p.hidden = !p.hidden;
  });
  var copyBtn = document.getElementById('forgotCopy');
  if (copyBtn) copyBtn.addEventListener('click', function () {
    var cmd = 'docker exec FarFetched sqlite3 <?= e($dbContainerPath) ?> "DELETE FROM auth;"';
    navigator.clipboard.writeText(cmd).then(function () {
      copyBtn.textContent = 'copied';
      setTimeout(function () { copyBtn.textContent = 'copy'; }, 1500);
    });
  });
</script>
</body>
</html>
