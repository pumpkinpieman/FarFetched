<?php
declare(strict_types=1);

/**
 * settings.php — unified configuration with tabbed layout.
 * Tabs: Sources (per-source auth + download dirs) | Worker | Activity
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';
csrf_token();

// ---- Helpers ----------------------------------------------------------------
function validate_printables(): array
{
    $svc = new PrintablesService();
    if (!$svc->ensureFreshToken()) {
        return ['ok' => false, 'msg' => $svc->lastError !== '' ? $svc->lastError : 'No refresh token stored.'];
    }
    $ch = curl_init('https://api.printables.com/graphql/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . get_token(),
            'User-Agent: Mozilla/5.0 FarFetched/1.0',
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => '{ me { id publicUsername } }']),
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $user = json_decode((string) $body, true)['data']['me'] ?? null;
    $ts   = token_status();
    $rs   = refresh_token_status();
    if ($status === 200 && !empty($user['id'])) {
        $msg = 'Authenticated as ' . ($user['publicUsername'] ?? $user['id'])
            . ' — access valid ' . human_duration((int) ($ts['seconds'] ?? 0));
        if ($rs['state'] === 'valid' || $rs['state'] === 'expiring') {
            $msg .= ', refresh valid ' . human_duration((int) ($rs['seconds'] ?? 0)) . ' (auto-renews)';
        }
        return ['ok' => true, 'msg' => $msg . '.'];
    }
    return ['ok' => false, 'msg' => 'Token rejected (HTTP ' . $status . ').'];
}

function apply_source_dir(string $raw, string $configKey): array
{
    $path = trim($raw);
    if ($path === '')           return ['ok' => false, 'msg' => 'Path is empty.'];
    if ($path[0] !== '/')       return ['ok' => false, 'msg' => 'Use an absolute path starting with "/".'];
    if (strpos($path, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $path))
                                return ['ok' => false, 'msg' => 'Path contains illegal segments.'];
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path))
                                return ['ok' => false, 'msg' => 'Could not create directory — check parent permissions.'];
    if (!is_writable($path))    return ['ok' => false, 'msg' => 'Directory exists but is not writable.'];
    $real = realpath($path) ?: $path;
    if ($configKey === 'printables') {
        if (!store_write(PATH_STORE, $real)) return ['ok' => false, 'msg' => 'Validated but could not save setting.'];
    } else {
        cfg_save([$configKey . '_download_dir' => $real]);
    }
    return ['ok' => true, 'msg' => 'Download location set: ' . $real];
}

// ---- Actions ----------------------------------------------------------------
$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_ok()) {
        $notice = ['type' => 'err', 'text' => 'Session expired — reload and retry.'];
    }

    // ---- Printables ---------------------------------------------------------
    elseif ($action === 'save_printables_token') {
        $refresh = preg_replace('/^Bearer\s+/i', '', trim((string) ($_POST['printables_refresh'] ?? ''))) ?? '';
        $refresh = preg_replace('/^auth\.refresh_token=/', '', $refresh) ?? $refresh;
        $access  = preg_replace('/^Bearer\s+/i', '', trim((string) ($_POST['printables_access'] ?? ''))) ?? '';
        $access  = preg_replace('/^auth\.access_token=/', '', $access) ?? $access;

        if ($refresh === '' && $access === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste at least one token.'];
        } else {
            $saved = false;
            if ($refresh !== '') {
                if (jwt_claims($refresh) === null) {
                    $notice = ['type' => 'err', 'text' => 'Refresh token does not look like a JWT.'];
                } else {
                    set_refresh_token($refresh);
                    $saved = true;
                }
            }
            if ($access !== '') {
                if (jwt_claims($access) === null) {
                    $notice = ['type' => 'err', 'text' => 'Access token does not look like a JWT.'];
                } else {
                    $ts = token_status_for($access);
                    if ($ts['state'] === 'expired') {
                        $notice = ['type' => 'err', 'text' => 'Access token is already expired — grab a fresh one.'];
                    } else {
                        set_token($access);
                        $saved = true;
                    }
                }
            }
            if ($saved && !isset($notice)) {
                $v  = validate_printables();
                $rs = refresh_token_status();
                $refreshNote = ($rs['state'] === 'valid' || $rs['state'] === 'expiring')
                    ? ' Refresh token valid ' . human_duration((int) ($rs['seconds'] ?? 0)) . ' — auto-renews.'
                    : '';
                $notice = $v['ok']
                    ? ['type' => 'ok', 'text' => 'Printables connected. ' . $v['msg'] . $refreshNote]
                    : ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $v['msg']];
            }
        }
    }
    elseif ($action === 'validate_printables_token') {
        $v = validate_printables();
        $notice = ['type' => $v['ok'] ? 'ok' : 'err', 'text' => $v['msg']];
    }
    elseif ($action === 'clear_printables_token') {
        foreach ([TOKEN_STORE, REFRESH_STORE] as $f) { if (is_file($f)) @unlink($f); }
        $notice = ['type' => 'ok', 'text' => 'Printables tokens cleared.'];
    }
    elseif ($action === 'save_printables_dir') {
        $r = apply_source_dir((string) ($_POST['printables_dir'] ?? ''), 'printables');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }

    // ---- MakerWorld ---------------------------------------------------------
    elseif ($action === 'save_mw_token') {
        $tok = trim((string) ($_POST['mw_token'] ?? ''));
        $tok = preg_replace('/^token=/', '', $tok) ?? $tok;
        if ($tok === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste your MakerWorld token first.'];
        } else {
            $ok = cfg_save(['makerworld_token' => $tok]);
            $notice = $ok
                ? ['type' => 'ok', 'text' => 'MakerWorld token saved.']
                : ['type' => 'err', 'text' => 'Could not write config.'];
        }
    }
    elseif ($action === 'clear_mw_token') {
        cfg_save(['makerworld_token' => '']);
        $notice = ['type' => 'ok', 'text' => 'MakerWorld token cleared.'];
    }
    elseif ($action === 'save_mw_dir') {
        $r = apply_source_dir((string) ($_POST['mw_dir'] ?? ''), 'makerworld');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_mw_delay') {
        $d = (int) ($_POST['mw_delay'] ?? 45);
        cfg_save(['makerworld_delay' => $d]);
        $eff  = (int) cfg('makerworld_delay');
        $warn = $eff < 45 ? ' ⚠ Below 45s risks MakerWorld anti-bot blocks.' : '';
        $notice = ['type' => $eff < 45 ? 'err' : 'ok', 'text' => 'MakerWorld pacing set to ' . $eff . 's.' . $warn];
    }

    // ---- Thingiverse --------------------------------------------------------
    elseif ($action === 'save_tv_token') {
        $tok = preg_replace('/^Bearer\s+/i', '', trim((string) ($_POST['tv_token'] ?? ''))) ?? '';
        if ($tok === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste your Thingiverse token first.'];
        } else {
            $ok = cfg_save(['thingiverse_token' => $tok]);
            $notice = $ok
                ? ['type' => 'ok', 'text' => 'Thingiverse token saved.']
                : ['type' => 'err', 'text' => 'Could not write config.'];
        }
    }
    elseif ($action === 'clear_tv_token') {
        cfg_save(['thingiverse_token' => '']);
        $notice = ['type' => 'ok', 'text' => 'Thingiverse token cleared.'];
    }
    elseif ($action === 'save_tv_dir') {
        $r = apply_source_dir((string) ($_POST['tv_dir'] ?? ''), 'thingiverse');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_tv_delay') {
        cfg_save(['thingiverse_delay' => (int) ($_POST['tv_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'Thingiverse pacing saved.'];
    }

    // ---- MyMiniFactory ------------------------------------------------------
    elseif ($action === 'save_mmf_token') {
        $sessId     = trim((string) ($_POST['mmf_token'] ?? ''));
        $rememberMe = trim((string) ($_POST['mmf_remember_me'] ?? ''));
        // Strip "PHPSESSID=" or "REMEMBERME=" prefixes if pasted as cookie pairs
        $sessId     = preg_replace('/^PHPSESSID=/', '', $sessId) ?? $sessId;
        $rememberMe = preg_replace('/^REMEMBERME=/', '', $rememberMe) ?? $rememberMe;
        if ($sessId === '' && $rememberMe === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste at least one cookie value.'];
        } else {
            $ok = cfg_save([
                'myminifactory_token'       => $sessId,
                'myminifactory_remember_me' => $rememberMe,
            ]);
            $notice = $ok
                ? ['type' => 'ok', 'text' => 'MyMiniFactory cookies saved.' . ($rememberMe !== '' ? ' REMEMBERME will keep you logged in for ~30 days.' : ' Only PHPSESSID stored — paste REMEMBERME too for persistence.')]
                : ['type' => 'err', 'text' => 'Could not write config.'];
        }
    }
    elseif ($action === 'clear_mmf_token') {
        cfg_save(['myminifactory_token' => '', 'myminifactory_remember_me' => '']);
        $notice = ['type' => 'ok', 'text' => 'MyMiniFactory cookies cleared.'];
    }
    elseif ($action === 'save_mmf_dir') {
        $r = apply_source_dir((string) ($_POST['mmf_dir'] ?? ''), 'myminifactory');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_mmf_delay') {
        cfg_save(['myminifactory_delay' => (int) ($_POST['mmf_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'MyMiniFactory pacing saved.'];
    }

    // ---- Worker -------------------------------------------------------------
    elseif ($action === 'save_config') {
        $ok = cfg_save([
            'download_delay' => (int) ($_POST['download_delay'] ?? 120),
            'max_attempts'   => (int) ($_POST['max_attempts'] ?? 3),
            'batch_cap'      => (int) ($_POST['batch_cap'] ?? 2000),
            'keep_zip'       => isset($_POST['keep_zip']),
            'overwrite'      => isset($_POST['overwrite']),
        ]);
        $notice = $ok
            ? ['type' => 'ok', 'text' => 'Worker settings saved.']
            : ['type' => 'err', 'text' => 'Could not write config.'];
    }
    elseif ($action === 'toggle_pause') {
        $now = cfg('paused') === true;
        cfg_save(['paused' => !$now]);
        $notice = ['type' => 'ok', 'text' => $now ? 'Downloads resumed.' : 'Downloads paused (queue preserved).'];
    }
}

// ---- State ------------------------------------------------------------------
$token      = get_token();
$hasTok     = $token !== '';
$rtok       = get_refresh_token();
$hasRefresh = $rtok !== '';
$tstat      = token_status();
$rstat      = refresh_token_status();
$conf       = cfg_all();
$isPaused   = $conf['paused'] === true;
$logRows    = ff_log_tail(40);
$csrf       = csrf_token();

// Per-source state
$pbDir   = store_read(PATH_STORE) ?: DEFAULT_DOWNLOAD_DIR;
$pbWrite = is_dir($pbDir) && is_writable($pbDir);

$mwTok   = (string) cfg('makerworld_token');
$mwDir   = get_makerworld_dir();
$mwWrite = is_dir($mwDir) && is_writable($mwDir);
$mwDelay = (int) cfg('makerworld_delay');

$tvTok   = (string) cfg('thingiverse_token');
$tvDir   = get_thingiverse_dir();
$tvWrite = is_dir($tvDir) && is_writable($tvDir);
$tvDelay = (int) cfg('thingiverse_delay');

$mmfTok   = (string) cfg('myminifactory_token');
$mmfDir   = get_myminifactory_dir();
$mmfWrite = is_dir($mmfDir) && is_writable($mmfDir);
$mmfDelay = (int) cfg('myminifactory_delay');

// Active tab
$tab = (string) ($_GET['tab'] ?? $_POST['_tab'] ?? 'sources');
if (!in_array($tab, ['sources', 'worker', 'activity'], true)) $tab = 'sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings · FarFetched</title>
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;--ok:#3F7D5B;--err:#B23B3B;--warn:#C9912F;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:220px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 20px;letter-spacing:-0.3px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 36px;max-width:900px;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;letter-spacing:-0.4px;margin-bottom:4px;}
  .sub{color:var(--muted);font-size:14px;margin-bottom:24px;}

  /* Tabs */
  .tabs{display:flex;gap:4px;margin-bottom:28px;border-bottom:1px solid var(--line);padding-bottom:0;}
  .tab-btn{background:none;border:none;border-bottom:2px solid transparent;padding:10px 18px;font:inherit;font-size:14px;font-weight:500;color:var(--muted);cursor:pointer;margin-bottom:-1px;border-radius:8px 8px 0 0;}
  .tab-btn:hover{color:var(--ink);background:var(--panel);}
  .tab-btn.active{color:var(--clay-deep);border-bottom-color:var(--clay);background:none;}
  .tab-content{display:none;} .tab-content.active{display:block;}

  /* Source cards */
  .src-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:28px;}
  @media(max-width:760px){.src-grid{grid-template-columns:1fr;}}
  .src-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;}
  .src-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 14px;border-bottom:1px solid var(--line);}
  .src-name{font-size:15px;font-weight:600;}
  .src-body{padding:16px 18px;}
  .src-body+.src-body{border-top:1px solid var(--line);}

  /* Status */
  .status{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:12px;}
  .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
  .dot.on{background:var(--ok);} .dot.off{background:#C9C4B6;} .dot.warn{background:var(--warn);}

  /* Forms */
  label{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
  textarea,input[type=text]{width:100%;border:1px solid var(--line);border-radius:8px;padding:9px 12px;font:13px ui-monospace,Menlo,monospace;color:var(--ink);background:var(--bg);}
  textarea{min-height:72px;resize:vertical;}
  textarea:focus,input:focus{outline:none;border-color:var(--clay);}
  input[type=text].short{width:100px;}
  .row{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center;}
  button{font:inherit;cursor:pointer;border:none;border-radius:8px;padding:8px 16px;font-size:13.5px;font-weight:500;}
  .btn-primary{background:var(--clay);color:#fff;} .btn-primary:hover{background:var(--clay-deep);}
  .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--line);} .btn-ghost:hover{border-color:var(--clay);color:var(--clay-deep);}
  .btn-sm{padding:6px 12px;font-size:12.5px;}
  .notice{padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:20px;}
  .notice.ok{background:#E8F1EC;color:var(--ok);} .notice.err{background:#F6E7E7;color:var(--err);}
  .hint{font-size:12px;color:var(--muted);line-height:1.55;margin-top:10px;}
  code{background:var(--panel);padding:1px 5px;border-radius:4px;font-size:11.5px;}
  hr{border:none;border-top:1px solid var(--line);margin:14px 0;}

  /* Worker panel */
  .worker-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
  @media(max-width:680px){.worker-grid{grid-template-columns:1fr;}}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;}
  .panel h2{font-size:14px;font-weight:600;margin-bottom:14px;}
  .panel label{margin-top:12px;}
  .panel label:first-of-type{margin-top:0;}

  pre.log{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px;margin:0;max-height:300px;overflow:auto;font-size:11.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word;}
</style>
</head>
<body>
<aside>
  <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
  <nav>
    <a href="index.php">Browse Models</a>
    <a href="jobs.php">Queue</a>
    <a href="viewer.php">3D Viewer</a>
    <a href="settings.php" class="active">Settings</a>
  </nav>
</aside>

<main>
  <h1>Settings</h1>
  <p class="sub">Sources, download locations, and worker tuning.</p>

  <?php if ($notice): ?>
  <div class="notice <?= $notice['type'] === 'ok' ? 'ok' : 'err' ?>"><?= e($notice['text']) ?></div>
  <?php endif; ?>

  <!-- Tab buttons -->
  <div class="tabs">
    <button class="tab-btn <?= $tab==='sources'?'active':'' ?>" onclick="switchTab('sources')">Sources</button>
    <button class="tab-btn <?= $tab==='worker'?'active':'' ?>" onclick="switchTab('worker')">Worker</button>
    <button class="tab-btn <?= $tab==='activity'?'active':'' ?>" onclick="switchTab('activity')">Activity</button>
  </div>

  <!-- ===================== SOURCES TAB ===================== -->
  <div class="tab-content <?= $tab==='sources'?'active':'' ?>" id="tab-sources">
    <div class="src-grid">

      <!-- Printables -->
      <?php
        $pbDot = ($hasRefresh || $hasTok) ? 'on' : 'off';
        $pbTxt = $hasRefresh ? 'Auto-renewing (refresh token)' : ($hasTok ? 'Access-token mode' : 'Not connected');
      ?>
      <div class="src-card">
        <div class="src-head">
          <span class="src-name">Printables</span>
          <span class="status" style="margin:0;flex-direction:column;align-items:flex-end;gap:3px;">
            <span><span class="dot <?= $pbDot ?>"></span> <?= e($pbTxt) ?></span>
            <?php if ($hasRefresh): $rs = refresh_token_status(); ?>
            <span style="font-size:11.5px;color:var(--muted);">Refresh: <?= human_duration((int)($rs['seconds']??0)) ?> left</span>
            <?php endif; ?>
          </span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="printables_refresh">Refresh token <span style="font-weight:400;text-transform:none;">(auto-renews for ~2 months)</span></label>
            <textarea id="printables_refresh" name="printables_refresh" placeholder="eyJ… paste auth.refresh_token cookie value"></textarea>
            <label for="printables_access" style="margin-top:10px;">Access token <span style="font-weight:400;text-transform:none;">(optional — valid ~2h, auto-minted from refresh)</span></label>
            <textarea id="printables_access" name="printables_access" placeholder="eyJ… paste auth.access_token cookie value"></textarea>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_printables_token">Save &amp; Connect</button>
              <?php if ($hasRefresh||$hasTok): ?>
              <button class="btn-ghost btn-sm" name="action" value="validate_printables_token">Validate</button>
              <button class="btn-ghost btn-sm" name="action" value="clear_printables_token" onclick="return confirm('Clear Printables tokens?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">printables.com → DevTools → Application → Cookies. Paste <code>auth.refresh_token</code> — it auto-renews every ~2 months. Optionally also paste <code>auth.access_token</code> so it works immediately without waiting for the first refresh. The app detects which is which automatically.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="pb_dir">Download folder</label>
            <input type="text" id="pb_dir" name="printables_dir" value="<?= e($pbDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_printables_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $pbWrite?'on':'off' ?>"></span><?= $pbWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
        </div>
      </div>

      <!-- MakerWorld -->
      <div class="src-card">
        <div class="src-head">
          <span class="src-name">MakerWorld</span>
          <span class="status" style="margin:0;"><span class="dot <?= $mwTok!==''?'on':'off' ?>"></span><?= $mwTok!==''?'Token stored':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mw_token"><?= $mwTok!==''?'Replace token':'Paste token' ?></label>
            <textarea id="mw_token" name="mw_token" placeholder="AQA… paste the `token` cookie value from makerworld.com"></textarea>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_mw_token">Save &amp; Connect</button>
              <?php if ($mwTok !== ''): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_mw_token" onclick="return confirm('Clear MakerWorld token?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">makerworld.com → DevTools → Application → Cookies → copy <code>token</code> value. Won't auto-renew — re-paste when downloads start failing auth.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mw_dir">Download folder</label>
            <input type="text" id="mw_dir" name="mw_dir" value="<?= e($mwDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_mw_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $mwWrite?'on':'off' ?>"></span><?= $mwWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(MAKERWORLD_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mw_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="mw_delay" name="mw_delay" inputmode="numeric" value="<?= e((string)$mwDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_mw_delay">Save</button>
            </div>
            <p class="hint">Keep ≥ 45s — MakerWorld's anti-bot check triggers below that.</p>
          </form>
        </div>
      </div>

      <!-- Thingiverse -->
      <div class="src-card">
        <div class="src-head">
          <span class="src-name">Thingiverse</span>
          <span class="status" style="margin:0;"><span class="dot <?= $tvTok!==''?'on':'off' ?>"></span><?= $tvTok!==''?'Token stored':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="tv_token"><?= $tvTok!==''?'Replace token':'Paste token' ?></label>
            <textarea id="tv_token" name="tv_token" placeholder="paste Bearer token from api.thingiverse.com request headers"></textarea>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_tv_token">Save &amp; Connect</button>
              <?php if ($tvTok !== ''): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_tv_token" onclick="return confirm('Clear Thingiverse token?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">thingiverse.com → DevTools → Network → any <code>api.thingiverse.com</code> request → copy the <code>Authorization</code> header value (without "Bearer ").</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="tv_dir">Download folder</label>
            <input type="text" id="tv_dir" name="tv_dir" value="<?= e($tvDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_tv_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $tvWrite?'on':'off' ?>"></span><?= $tvWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(THINGIVERSE_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="tv_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="tv_delay" name="tv_delay" inputmode="numeric" value="<?= e((string)$tvDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_tv_delay">Save</button>
            </div>
          </form>
        </div>
      </div>

      <!-- MyMiniFactory -->
      <?php $mmfHasRemember = (string)cfg('myminifactory_remember_me') !== ''; ?>
      <div class="src-card">
        <div class="src-head">
          <span class="src-name">MyMiniFactory</span>
          <span class="status" style="margin:0;"><span class="dot <?= $mmfTok!==''?'on':'off' ?>"></span><?= $mmfTok!==''?'Cookies stored':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mmf_sessid">PHPSESSID <span style="font-weight:400;text-transform:none;">(session cookie)</span></label>
            <textarea id="mmf_sessid" name="mmf_token" placeholder="cc0335178b7afb2d777ce265a6b705e0"></textarea>
            <label for="mmf_remember" style="margin-top:10px;">REMEMBERME <span style="font-weight:400;text-transform:none;">(persistent ~30 days)</span></label>
            <textarea id="mmf_remember" name="mmf_remember_me" placeholder="MyMini.UserBundle.Entity.User…"></textarea>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_mmf_token">Save &amp; Connect</button>
              <?php if ($mmfTok !== ''): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_mmf_token" onclick="return confirm('Clear MyMiniFactory cookies?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">myminifactory.com → log in (check "Remember me") → DevTools → Application → Cookies → copy <code>PHPSESSID</code> and <code>REMEMBERME</code> values. REMEMBERME lasts ~30 days and re-establishes your session automatically.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mmf_dir">Download folder</label>
            <input type="text" id="mmf_dir" name="mmf_dir" value="<?= e($mmfDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_mmf_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $mmfWrite?'on':'off' ?>"></span><?= $mmfWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(MYMINIFACTORY_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="mmf_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="mmf_delay" name="mmf_delay" inputmode="numeric" value="<?= e((string)$mmfDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_mmf_delay">Save</button>
            </div>
          </form>
        </div>
      </div>

    </div><!-- /.src-grid -->
  </div>

  <!-- ===================== WORKER TAB ===================== -->
  <div class="tab-content <?= $tab==='worker'?'active':'' ?>" id="tab-worker">
    <div class="worker-grid">
      <div class="panel">
        <h2>Pacing &amp; Limits</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="_tab" value="worker">
          <label for="download_delay">Printables delay (seconds)</label>
          <input type="text" class="short" id="download_delay" name="download_delay" value="<?= (int) $conf['download_delay'] ?>">
          <p class="hint">Min 30s. Default 120. Higher = gentler on Printables.</p>

          <label for="max_attempts">Max retries per model</label>
          <input type="text" class="short" id="max_attempts" name="max_attempts" value="<?= (int) $conf['max_attempts'] ?>">
          <p class="hint">Jobs retry up to this many times before being marked failed. 1–10.</p>

          <label for="batch_cap">Max per "Download Selected"</label>
          <input type="text" class="short" id="batch_cap" name="batch_cap" value="<?= (int) $conf['batch_cap'] ?>">
          <p class="hint">Safety cap on a single submit. 1–10000.</p>

          <div class="row" style="margin-top:16px;">
            <button class="btn-primary btn-sm" name="action" value="save_config">Save Worker Settings</button>
          </div>
        </form>
      </div>

      <div class="panel">
        <h2>Behavior</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="_tab" value="worker">

          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13.5px;font-weight:500;color:var(--ink);">
            <input type="checkbox" name="keep_zip" value="1" <?= cfg('keep_zip')===true?'checked':'' ?> style="width:auto;">
            Keep .zip files after extracting
          </label>
          <p class="hint">When a model downloads as a ZIP it's extracted into the model folder. Keep checked to retain the original .zip too.</p>

          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13.5px;font-weight:500;color:var(--ink);margin-top:14px;">
            <input type="checkbox" name="overwrite" value="1" <?= cfg('overwrite')===true?'checked':'' ?> style="width:auto;">
            Force re-download (overwrite existing)
          </label>
          <p class="hint">By default a file already on disk is skipped. Enable to overwrite — useful if a download was corrupted or the model was updated.</p>

          <div class="row" style="margin-top:16px;">
            <button class="btn-primary btn-sm" name="action" value="save_config">Save</button>
          </div>
        </form>

        <hr>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="_tab" value="worker">
          <div class="status">
            <span class="dot <?= $isPaused?'warn':'on' ?>"></span>
            <?= $isPaused ? 'Worker paused — queue preserved' : 'Worker active' ?>
          </div>
          <button class="<?= $isPaused?'btn-primary':'btn-ghost' ?> btn-sm" name="action" value="toggle_pause">
            <?= $isPaused ? 'Resume downloads' : 'Pause downloads' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ===================== ACTIVITY TAB ===================== -->
  <div class="tab-content <?= $tab==='activity'?'active':'' ?>" id="tab-activity">
    <?php if ($logRows === []): ?>
    <div class="status"><span class="dot on"></span>No errors or warnings logged yet.</div>
    <?php else: ?>
    <div class="status"><span class="dot warn"></span>Last <?= count($logRows) ?> event(s) — newest at the bottom.</div>
    <pre class="log"><?php foreach ($logRows as $row) { echo e($row) . "\n"; } ?></pre>
    <p class="hint" style="margin-top:10px;">Auth failures, skipped files, and errors land here. Full log at <code>private/farfetched.log</code>.</p>
    <?php endif; ?>
  </div>

</main>

<script>
function switchTab(t) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.querySelector('#tab-' + t).classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => { if (b.textContent.trim().toLowerCase().startsWith(t)) b.classList.add('active'); });
  history.replaceState(null, '', '?tab=' + t);
}
</script>
</body>
</html>
