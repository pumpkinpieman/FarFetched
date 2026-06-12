<?php
declare(strict_types=1);

/**
 * settings.php — configuration.
 *   Panel 1: Printables session token (paste-once, stored out of web root).
 *   Panel 2: Download location (validated + auto-created + writability check).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';
csrf_token(); // ensures session + token

// ---- Account validation (mints via refresh, then confirms identity) -------
function validate_account(): array
{
    $svc = new PrintablesService();
    if (!$svc->ensureFreshToken()) {
        return ['ok' => false, 'msg' => $svc->lastError !== '' ? $svc->lastError : 'No refresh token stored.'];
    }
    // Confirm identity with a small `me` query using the freshly-minted token.
    $ch = curl_init('https://api.printables.com/graphql/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . get_token(),
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/116 Safari/537.36',
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => '{ me { id publicUsername } }']),
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $user = json_decode((string) $body, true)['data']['me'] ?? null;
    $ts   = token_status();
    if ($status === 200 && !empty($user['id'])) {
        return ['ok' => true, 'msg' => 'Authenticated as ' . ($user['publicUsername'] ?? $user['id'])
            . ' — access valid ' . human_duration((int) ($ts['seconds'] ?? 0)) . '.'];
    }
    if ($status === 401 || $status === 403) {
        return ['ok' => false, 'msg' => 'Refreshed, but the access token was rejected (HTTP ' . $status . ').'];
    }
    return ['ok' => false, 'msg' => 'Refreshed; identity check returned HTTP ' . $status . '.'];
}

// ---- Download-dir validation + creation -----------------------------------
function apply_download_dir(string $raw): array
{
    $path = trim($raw);
    if ($path === '') {
        return ['ok' => false, 'msg' => 'Path is empty.'];
    }
    if ($path[0] !== '/') {
        return ['ok' => false, 'msg' => 'Use an absolute path (must start with "/").'];
    }
    if (strpos($path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        return ['ok' => false, 'msg' => 'Path contains illegal segments.'];
    }
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        return ['ok' => false, 'msg' => 'Could not create directory. Check parent permissions.'];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'msg' => 'Directory exists but is not writable by the web user.'];
    }
    $real = realpath($path) ?: $path;
    if (!store_write(PATH_STORE, $real)) {
        return ['ok' => false, 'msg' => 'Validated, but could not save the setting.'];
    }
    return ['ok' => true, 'msg' => 'Download location set: ' . $real];
}

// ---- Actions --------------------------------------------------------------
$notice = null; $validation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_ok()) {
        $notice = ['type' => 'err', 'text' => 'Session expired — reload and retry.'];
    } elseif ($action === 'save_refresh') {
        $tok = preg_replace('/^Bearer\s+/i', '', trim((string) ($_POST['refresh_token'] ?? ''))) ?? '';
        $tok = preg_replace('/^auth\.(refresh|access)_token=/', '', $tok) ?? $tok; // tolerate cookie-pair paste
        if ($tok === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste a token first.'];
        } elseif (jwt_claims($tok) === null) {
            $notice = ['type' => 'err', 'text' => 'That does not look like a token (not a JWT).'];
        } else {
            // Auto-detect what they pasted. A refresh token self-renews; an
            // access token is the short-lived fallback for accounts that don't
            // expose a refresh token.
            $type = jwt_token_type($tok);
            if ($type === 'refresh') {
                if (!set_refresh_token($tok)) {
                    $notice = ['type' => 'err', 'text' => 'Could not write the token store — check permissions.'];
                } else {
                    if (is_file(TOKEN_STORE)) { @unlink(TOKEN_STORE); } // drop any stale access token
                    $v = validate_account(); // mints a fresh access token to prove it works
                    $notice = $v['ok']
                        ? ['type' => 'ok', 'text' => 'Connected (auto-renewing). ' . $v['msg']]
                        : ['type' => 'err', 'text' => 'Saved, but the first refresh failed: ' . $v['msg']];
                }
            } else {
                // type === 'access' (or untyped): store directly as a one-shot
                // access token. No refresh token, so it won't self-renew.
                $ts = token_status_for($tok);
                if ($ts['state'] === 'expired') {
                    $notice = ['type' => 'err', 'text' => 'That access token is already expired — grab a fresh one from the cookie jar and paste it right away.'];
                } elseif (!set_token($tok)) {
                    $notice = ['type' => 'err', 'text' => 'Could not write the token store — check permissions.'];
                } else {
                    if (is_file(REFRESH_STORE)) { @unlink(REFRESH_STORE); } // access-only mode
                    $v = validate_account();
                    $life = human_duration((int) ($ts['seconds'] ?? 0));
                    $notice = $v['ok']
                        ? ['type' => 'ok', 'text' => 'Connected in access-token mode — valid ' . $life . '. This token can\'t self-renew; re-paste a fresh one when it expires. (Tip: paste an auth.refresh_token instead and you\'ll only re-paste ~monthly.)']
                        : ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $v['msg']];
                }
            }
        }
    } elseif ($action === 'validate_token') {
        $validation = validate_account();
    } elseif ($action === 'clear_token') {
        foreach ([TOKEN_STORE, REFRESH_STORE] as $f) {
            if (is_file($f)) { @unlink($f); }
        }
        $notice = ['type' => 'ok', 'text' => 'Tokens cleared.'];
    } elseif ($action === 'save_dir') {
        $r = apply_download_dir((string) ($_POST['dir'] ?? ''));
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    } elseif ($action === 'save_config') {
        $ok = cfg_save([
            'download_delay' => (int) ($_POST['download_delay'] ?? 120),
            'max_attempts'   => (int) ($_POST['max_attempts'] ?? 3),
            'batch_cap'      => (int) ($_POST['batch_cap'] ?? 2000),
            'keep_zip'       => isset($_POST['keep_zip']),
            'overwrite'      => isset($_POST['overwrite']),
        ]);
        $notice = $ok
            ? ['type' => 'ok', 'text' => 'Worker settings saved — applied on the next worker run.']
            : ['type' => 'err', 'text' => 'Could not write config (check private/ permissions).'];
    } elseif ($action === 'toggle_pause') {
        $now = cfg('paused') === true;
        cfg_save(['paused' => !$now]);
        $notice = ['type' => 'ok', 'text' => $now ? 'Downloads resumed.' : 'Downloads paused (queue preserved).'];
    }
}

// ---- State ----------------------------------------------------------------
$token  = get_token();
$hasTok = $token !== '';
$masked = $hasTok ? substr($token, 0, 6) . str_repeat('•', 14) . substr($token, -4) : '';
$tstat  = token_status();           // access token: ['state','exp','seconds']
$rtok   = get_refresh_token();
$hasRefresh = $rtok !== '';
$rstat  = refresh_token_status();   // refresh token status
$logRows = ff_log_tail(40);         // recent event-log lines, oldest-first
$dirStored = store_read(PATH_STORE);
$dirIsSet  = $dirStored !== '';
$dirShown  = $dirIsSet ? $dirStored : DEFAULT_DOWNLOAD_DIR;
$dirWrite  = $dirIsSet && is_dir($dirStored) && is_writable($dirStored);
$conf      = cfg_all();
$isPaused  = $conf['paused'] === true;
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings · FarFetched</title>
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;--ok:#3F7D5B;--err:#B23B3B;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 20px;letter-spacing:-0.3px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;max-width:720px;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;letter-spacing:-0.4px;margin-bottom:6px;}
  h2{font-size:15px;font-weight:600;margin-bottom:14px;}
  .sub{color:var(--muted);font-size:14px;margin-bottom:24px;line-height:1.5;}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:18px;}
  .status{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;margin-bottom:16px;}
  .dot{width:10px;height:10px;border-radius:50%;} .dot.on{background:var(--ok);} .dot.off{background:#C9C4B6;} .dot.warn{background:#C9912F;}
  label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;}
  textarea,input[type=text]{width:100%;border:1px solid var(--line);border-radius:9px;padding:11px 13px;font:13px ui-monospace,Menlo,monospace;color:var(--ink);background:var(--bg);}
  textarea{min-height:90px;resize:vertical;} textarea:focus,input:focus{outline:none;border-color:var(--clay);}
  .masked{font:13px ui-monospace,Menlo,monospace;color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:9px;padding:11px 13px;word-break:break-all;}
  .row{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;}
  button{font:inherit;cursor:pointer;border:none;border-radius:9px;padding:10px 18px;font-size:14px;font-weight:500;}
  .btn-primary{background:var(--clay);color:#fff;} .btn-primary:hover{background:var(--clay-deep);}
  .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--line);} .btn-ghost:hover{border-color:var(--clay);color:var(--clay-deep);}
  .notice{padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:18px;} .notice.ok{background:#E8F1EC;color:var(--ok);} .notice.err{background:#F6E7E7;color:var(--err);}
  .hint{font-size:12.5px;color:var(--muted);line-height:1.55;margin-top:12px;} code{background:var(--panel);padding:1px 5px;border-radius:4px;font-size:12px;}
  hr{border:none;border-top:1px solid var(--line);margin:18px 0;}
</style>
</head>
<body>
  <aside>
    <div class="brand">◆ FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="settings.php" class="active">Settings</a>
    </nav>
  </aside>
  <main>
    <h1>Settings</h1>
    <p class="sub">Configure Printables access and where downloaded files land.</p>

    <?php if ($notice): ?><div class="notice <?= $notice['type']==='ok'?'ok':'err' ?>"><?= e($notice['text']) ?></div><?php endif; ?>
    <?php if ($validation): ?><div class="notice <?= $validation['ok']?'ok':'err' ?>"><?= e($validation['msg']) ?></div><?php endif; ?>

    <div class="panel">
      <h2>Printables Authentication</h2>
      <?php
        $accessOnly = !$hasRefresh && $hasTok;
        $statusDot  = ($hasRefresh || $accessOnly) ? 'on' : 'off';
        $statusTxt  = $hasRefresh
          ? 'Refresh token stored — session self-renews'
          : ($accessOnly ? 'Access-token mode — works until it expires, then re-paste' : 'Not connected — paste a token below');
      ?>
      <div class="status"><span class="dot <?= $statusDot ?>"></span><?= e($statusTxt) ?></div>
      <?php if ($hasRefresh):
        $rmap = [
          'valid'    => ['on',   'Refresh token valid — ' . human_duration((int) $rstat['seconds']) . ' left'],
          'expiring' => ['warn', 'Refresh token expires in ' . human_duration((int) $rstat['seconds']) . ' — open the app to renew'],
          'expired'  => ['off',  'Refresh token EXPIRED — paste a fresh one'],
          'unknown'  => ['warn', 'Refresh token stored (expiry unreadable)'],
        ][$rstat['state']] ?? ['warn', '—'];
        $amap = [
          'valid'    => ['on',   'Access token active — ' . human_duration((int) $tstat['seconds']) . ' left'],
          'expiring' => ['warn', 'Access token expiring — auto-renews next use'],
          'expired'  => ['warn', 'Access token expired — auto-renews next use'],
          'none'     => ['warn', 'No access token yet — minted on first use'],
          'unknown'  => ['warn', 'Access token state unknown'],
        ][$tstat['state']] ?? ['warn', '—'];
      ?>
        <div class="status" style="font-size:13px;color:var(--muted);margin-top:-8px;"><span class="dot <?= $rmap[0] ?>"></span><?= e($rmap[1]) ?></div>
        <div class="status" style="font-size:13px;color:var(--muted);margin-top:-10px;"><span class="dot <?= $amap[0] ?>"></span><?= e($amap[1]) ?></div>
      <?php elseif ($accessOnly):
        $amap = [
          'valid'    => ['on',   'Access token valid — ' . human_duration((int) $tstat['seconds']) . ' left'],
          'expiring' => ['warn', 'Access token expiring in ' . human_duration((int) $tstat['seconds']) . ' — re-paste soon'],
          'expired'  => ['off',  'Access token EXPIRED — paste a fresh one'],
          'unknown'  => ['warn', 'Access token stored (expiry unreadable)'],
        ][$tstat['state']] ?? ['warn', '—'];
      ?>
        <div class="status" style="font-size:13px;color:var(--muted);margin-top:-8px;"><span class="dot <?= $amap[0] ?>"></span><?= e($amap[1]) ?></div>
      <?php endif; ?>
      <?php if ($hasRefresh || $accessOnly): ?>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn-primary" name="action" value="validate_token">Validate now</button>
          <button class="btn-ghost" name="action" value="clear_token" onclick="return confirm('Remove the stored tokens?');">Clear</button>
        </form>
        <hr>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="refresh_token"><?= ($hasRefresh || $accessOnly)?'Replace token':'Paste token' ?></label>
        <textarea id="refresh_token" name="refresh_token" placeholder="eyJ… (auth.refresh_token — or auth.access_token if your account has no refresh token)"></textarea>
        <div class="row"><button class="btn-primary" name="action" value="save_refresh">Save &amp; Connect</button></div>
        <p class="hint">printables.com → DevTools → <strong>Application/Storage</strong> → Cookies → <code>printables.com</code>. Best: copy <code>auth.refresh_token</code> (self-renews, re-paste ~monthly). If your account doesn't have one (some SSO logins), copy <code>auth.access_token</code> instead — it works for about 2 hours, then paste a fresh one. The app auto-detects which you pasted.</p>
      </form>
    </div>

    <div class="panel">
      <h2>Recent activity</h2>
      <?php if ($logRows === []): ?>
        <div class="status"><span class="dot on"></span>No errors or warnings logged.</div>
      <?php else: ?>
        <div class="status"><span class="dot warn"></span>Last <?= count($logRows) ?> event(s) — newest at the bottom.</div>
        <pre style="background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px;margin:0;max-height:260px;overflow:auto;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;"><?php
          foreach ($logRows as $row) { echo e($row) . "\n"; }
        ?></pre>
        <p class="hint">Auth failures, refused links, and skips land here. The full log lives at <code>private/farfetched.log</code>.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Download Location</h2>
      <div class="status">
        <span class="dot <?= $dirIsSet?($dirWrite?'on':'warn'):'off' ?>"></span>
        <?php if (!$dirIsSet): ?>Not set (default suggested below)
        <?php elseif ($dirWrite): ?>Ready &mdash; <?= e($dirStored) ?>
        <?php else: ?>Set, but not writable &mdash; <?= e($dirStored) ?><?php endif; ?>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="dir">Absolute path on this server</label>
        <input type="text" id="dir" name="dir" value="<?= e($dirShown) ?>">
        <div class="row"><button class="btn-primary" name="action" value="save_dir">Save &amp; Create</button></div>
        <p class="hint">Created automatically if missing (recursive). On Unraid, <code>/mnt/user/…</code> is a share path — make sure the PHP/web user can write there. Saving verifies writability before accepting.</p>
      </form>
    </div>

    <div class="panel">
      <h2>Worker &amp; Pacing</h2>
      <div class="status">
        <span class="dot <?= $isPaused ? 'warn' : 'on' ?>"></span>
        <?= $isPaused ? 'Paused — worker skips processing until resumed' : 'Active — worker processes the queue on each run' ?>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <label for="download_delay">Delay between files (seconds)</label>
        <input type="text" id="download_delay" name="download_delay" value="<?= (int) $conf['download_delay'] ?>" style="width:120px">
        <p class="hint">The real throttle. Minimum 30s (clamped). Higher = gentler on Printables. Default 120.</p>

        <label for="max_attempts" style="margin-top:14px;">Max retry attempts per model</label>
        <input type="text" id="max_attempts" name="max_attempts" value="<?= (int) $conf['max_attempts'] ?>" style="width:120px">
        <p class="hint">Failed jobs are re-queued until this many tries, then marked failed. 1–10.</p>

        <label for="batch_cap" style="margin-top:14px;">Max models per “Download Selected”</label>
        <input type="text" id="batch_cap" name="batch_cap" value="<?= (int) $conf['batch_cap'] ?>" style="width:120px">
        <p class="hint">Safety cap on a single submit. 1–10000.</p>

        <label style="margin-top:14px;display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="keep_zip" value="1" <?= cfg('keep_zip') === true ? 'checked' : '' ?> style="width:auto;">
          Keep .zip files after extracting (whole-model downloads)
        </label>
        <p class="hint">When a model is downloaded as a ZIP, it's extracted into the model folder. Leave checked to keep the original .zip too; uncheck to delete it after extraction.</p>

        <label style="margin-top:14px;display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="overwrite" value="1" <?= cfg('overwrite') === true ? 'checked' : '' ?> style="width:auto;">
          Force re-download (overwrite existing files)
        </label>
        <p class="hint">By default, a file already on disk is skipped — re-running a job won't re-download it. Turn this on to overwrite existing files instead. Useful if a download was corrupted or the source model was updated. Leave off to save bandwidth and respect pacing.</p>

        <div class="row"><button class="btn-primary" name="action" value="save_config">Save Worker Settings</button></div>
      </form>

      <hr>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="<?= $isPaused ? 'btn-primary' : 'btn-ghost' ?>" name="action" value="toggle_pause">
          <?= $isPaused ? 'Resume downloads' : 'Pause downloads' ?>
        </button>
        <p class="hint">Pausing keeps the queue intact; the worker simply skips runs until you resume. Changes apply on the worker’s next cron tick.</p>
      </form>
    </div>
  </main>
</body>
</html>
