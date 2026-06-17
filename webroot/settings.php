<?php
declare(strict_types=1);

/**
 * settings.php — unified configuration with tabbed layout.
 * Tabs: Sources (per-source auth + download dirs) | Worker | Activity
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';
require_once __DIR__ . '/Cults3DService.php';
require_once __DIR__ . '/STLFlixService.php';
require_once __DIR__ . '/CrealityCloudService.php';
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
    if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path))
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
        $clientId  = trim((string) ($_POST['tv_client_id']  ?? ''));
        $clientSec = trim((string) ($_POST['tv_client_sec'] ?? ''));
        $apiKey    = trim((string) ($_POST['tv_api_key']    ?? ''));
        if ($clientId === '' && $apiKey === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste your Thingiverse API key.'];
        } else {
            cfg_save([
                'thingiverse_token'      => $apiKey,
                'thingiverse_client_id'  => $clientId,
                'thingiverse_client_sec' => $clientSec,
            ]);
            $notice = ['type' => 'ok', 'text' => 'Thingiverse credentials saved.'];
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

    // ---- Cults3D ------------------------------------------------------------
    elseif ($action === 'save_cults_token') {
        $username = trim((string) ($_POST['cults_username'] ?? ''));
        $apiKey   = trim((string) ($_POST['cults_api_key'] ?? ''));
        if ($username === '' || $apiKey === '') {
            $notice = ['type' => 'err', 'text' => 'Both username and API key are required.'];
        } else {
            cfg_save(['cults3d_username' => $username, 'cults3d_token' => $apiKey]);
            // Quick validation
            $c = new Cults3DService($username, $apiKey);
            $r = $c->gqlPublic('{ categories { slug } }');
            $notice = ($r !== null && !empty($r['categories']))
                ? ['type' => 'ok', 'text' => 'Cults3D connected successfully.']
                : ['type' => 'err', 'text' => 'Saved but validation failed: ' . $c->lastError];
        }
    }
    elseif ($action === 'clear_cults_token') {
        cfg_save(['cults3d_username' => '', 'cults3d_token' => '']);
        $notice = ['type' => 'ok', 'text' => 'Cults3D credentials cleared.'];
    }
    elseif ($action === 'save_cults_session') {
        $session = trim((string) ($_POST['cults_session'] ?? ''));
        $cf      = trim((string) ($_POST['cults_cf_clearance'] ?? ''));
        cfg_save(['cults3d_session' => $session, 'cults3d_cf_clearance' => $cf]);
        $notice = $session !== ''
            ? ['type' => 'ok', 'text' => 'Cults3D download session saved. Free models can now be downloaded.']
            : ['type' => 'ok', 'text' => 'Cults3D download session cleared.'];
    }
    elseif ($action === 'clear_cults_session') {
        cfg_save(['cults3d_session' => '', 'cults3d_cf_clearance' => '']);
        $notice = ['type' => 'ok', 'text' => 'Cults3D download session cleared.'];
    }
    elseif ($action === 'save_cults_dir') {
        $r = apply_source_dir((string) ($_POST['cults_dir'] ?? ''), 'cults3d');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_cults_delay') {
        cfg_save(['cults3d_delay' => (int) ($_POST['cults_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'Cults3D pacing saved.'];
    }

    // ---- STLFlix ------------------------------------------------------------
    elseif ($action === 'save_stlflix_token') {
        $tok = trim((string) ($_POST['stlflix_token'] ?? ''));
        $tok = preg_replace('/^Bearer\s+/i', '', $tok) ?? $tok;
        $tok = preg_replace('/^jwt=/', '', $tok) ?? $tok;
        if ($tok === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save - paste your STLFlix jwt first.'];
        } else {
            cfg_save(['stlflix_token' => $tok]);
            $svc = new STLFlixService($tok);
            $notice = $svc->validate()
                ? ['type' => 'ok', 'text' => 'STLFlix connected successfully.']
                : ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $svc->lastError];
        }
    }
    elseif ($action === 'clear_stlflix_token') {
        cfg_save(['stlflix_token' => '']);
        $notice = ['type' => 'ok', 'text' => 'STLFlix token cleared.'];
    }
    elseif ($action === 'save_stlflix_dir') {
        $r = apply_source_dir((string) ($_POST['stlflix_dir'] ?? ''), 'stlflix');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_stlflix_delay') {
        cfg_save(['stlflix_delay' => (int) ($_POST['stlflix_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'STLFlix pacing saved.'];
    }

    // ---- Creality Cloud -----------------------------------------------------
    elseif ($action === 'save_creality_token') {
        $tok = trim((string) ($_POST['creality_token'] ?? ''));
        $uid = trim((string) ($_POST['creality_user_id'] ?? ''));
        $cf  = trim((string) ($_POST['creality_cf_clearance'] ?? ''));
        if ($tok === '' || $uid === '') {
            $notice = ['type' => 'err', 'text' => 'Both token and user ID are required.'];
        } else {
            cfg_save([
                'creality_token'        => $tok,
                'creality_user_id'      => $uid,
                'creality_cf_clearance' => $cf,
            ]);
            // Validate against a cheap authenticated endpoint.
            $c = new CrealityCloudService($tok, $uid, $cf);
            $notice = $c->validate()
                ? ['type' => 'ok', 'text' => 'Creality Cloud connected successfully.']
                : ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $c->lastError];
        }
    }
    elseif ($action === 'clear_creality_token') {
        cfg_save(['creality_token' => '', 'creality_user_id' => '', 'creality_cf_clearance' => '']);
        $notice = ['type' => 'ok', 'text' => 'Creality Cloud credentials cleared.'];
    }
    elseif ($action === 'save_creality_dir') {
        $r = apply_source_dir((string) ($_POST['creality_dir'] ?? ''), 'creality');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_creality_delay') {
        cfg_save(['creality_delay' => (int) ($_POST['creality_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'Creality Cloud pacing saved.'];
    }

    // ---- Worker -------------------------------------------------------------
    elseif ($action === 'save_config') {
        $ok = cfg_save([
            'download_delay' => (int) ($_POST['download_delay'] ?? 120),
            'max_attempts'   => (int) ($_POST['max_attempts'] ?? 3),
            'batch_cap'      => (int) ($_POST['batch_cap'] ?? 2000),
            'keep_zip'       => isset($_POST['keep_zip']),
            'overwrite'      => isset($_POST['overwrite']),
            'prefer_pack'    => isset($_POST['prefer_pack']),
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

    // AJAX requests (from the source modals) get a JSON reply instead of a full
    // page re-render. The action handlers above have already run and set
    // $notice; we just report the result plus the current per-source connection
    // state so the buttons can update without a reload.
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
           || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
    if ($isAjax) {
        header('Content-Type: application/json');
        $n = $notice ?? ['type' => 'ok', 'text' => 'Saved.'];
        echo json_encode([
            'ok'     => ($n['type'] ?? 'ok') !== 'err',
            'type'   => $n['type'] ?? 'ok',
            'text'   => $n['text'] ?? '',
            'status' => [
                'printables'  => get_token() !== '' || get_refresh_token() !== '',
                'makerworld'  => (string) cfg('makerworld_token') !== '',
                'thingiverse' => (string) cfg('thingiverse_token') !== '',
                'cults3d'     => (string) cfg('cults3d_username') !== '' && (string) cfg('cults3d_token') !== '',
                'creality'    => creality_ready(),
                'stlflix'     => (string) cfg('stlflix_token') !== '',
            ],
        ]);
        exit;
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

$cultsUser  = (string) cfg('cults3d_username');
$cultsTok   = (string) cfg('cults3d_token');
$cultsSess  = (string) cfg('cults3d_session');
$cultsCf    = (string) cfg('cults3d_cf_clearance');
$cultsDir   = get_cults3d_dir();
$cultsWrite = is_dir($cultsDir) && is_writable($cultsDir);
$cultsDelay = (int) cfg('cults3d_delay');
$cultsReady = $cultsUser !== '' && $cultsTok !== '';

$stlflixTok   = (string) cfg('stlflix_token');
$stlflixDir   = get_stlflix_dir();
$stlflixWrite = is_dir($stlflixDir) && is_writable($stlflixDir);
$stlflixDelay = (int) cfg('stlflix_delay');
$stlflixReady = $stlflixTok !== '';

// Creality Cloud
$crealityTok   = (string) cfg('creality_token');
$crealityUid   = (string) cfg('creality_user_id');
$crealityCf    = (string) cfg('creality_cf_clearance');
$crealityDir   = get_creality_dir();
$crealityWrite = is_dir($crealityDir) && is_writable($crealityDir);
$crealityDelay = (int) cfg('creality_delay');
$crealityReady = creality_ready();


// Active tab
$tab = (string) ($_GET['tab'] ?? $_POST['_tab'] ?? 'sources');
if (!in_array($tab, ['sources', 'worker', 'activity', 'donate'], true)) $tab = 'sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_settings.css">

</head>
<body>
<aside>
  <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
  <nav>
    <a href="index.php">Browse Models</a>
    <a href="jobs.php">Queue</a>
    <a href="viewer.php">3D Viewer</a>
    <a href="settings.php" class="active">Settings</a>
		<button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
		<span id="theme-toggle-icon">🌙</span> Change Appearance
		</button>
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
    <button class="tab-btn <?= $tab==='donate'?'active':'' ?>" onclick="switchTab('donate')">Donate</button>
  </div>

  <!-- ===================== SOURCES TAB ===================== -->
  <div class="tab-content <?= $tab==='sources'?'active':'' ?>" id="tab-sources">
    <div class="src-btn-row">
      <button type="button" class="src-btn <?= ($hasRefresh || $hasTok) ? 'connected' : '' ?>" onclick="openModal('src-printables')">Printables</button>
      <button type="button" class="src-btn <?= ($mwTok !== '') ? 'connected' : '' ?>" onclick="openModal('src-mw')">MakerWorld</button>
      <button type="button" class="src-btn <?= ((string) cfg('thingiverse_token') !== '') ? 'connected' : '' ?>" onclick="openModal('src-thingiverse')">Thingiverse</button>
      <button type="button" class="src-btn <?= $cultsReady ? 'connected' : '' ?>" onclick="openModal('src-cults')">Cults3D</button>
      <button type="button" class="src-btn <?= $stlflixReady ? 'connected' : '' ?>" onclick="openModal('src-stlflix')">STLFlix</button>
      <button type="button" class="src-btn <?= creality_ready() ? 'connected' : '' ?>" onclick="openModal('src-creality')">Creality</button>
    </div>


    
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

          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13.5px;font-weight:500;color:var(--ink);margin-top:14px;">
            <input type="checkbox" name="prefer_pack" value="1" <?= cfg('prefer_pack')===true?'checked':'' ?> style="width:auto;">
            Prefer whole-model ZIP when available
          </label>
          <p class="hint">When a source offers a whole-model pack, download it as a single ZIP and extract it rather than fetching files one at a time — faster, with fewer paced steps, but you get every file in the model. Mainly affects Printables (which otherwise downloads each requested file individually). Sources that already pack whole models (MakerWorld, Thingiverse) or only serve per-file zips (Cults3D, STLFlix) are unchanged.</p>

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

  <div class="tab-content <?= $tab==='donate'?'active':'' ?>" id="tab-donate">
    <div class="donate-wrap">
      <div class="donate-card">
        <img src="https://www.btcbdesign.com/logo.png" alt="BTCB Design" width="200" height="200" class="donate-logo">
        <h2 class="donate-title">Do you like this project?</h2>
        <p class="donate-text">FarFetched is free and open source. If it's saved you time, consider buying me a ko-fi to keep the builds coming.</p>
        <a href="https://ko-fi.com/bloodthirstycheeseburger90415" target="_blank" rel="noopener noreferrer" class="donate-btn">
          ☕ Buy me a ko-fi
        </a>
        <p class="donate-handle">ko-fi.com/bloodthirstycheeseburger90415</p>
      </div>
    </div>
  </div>

</main>

<!-- More info modals -->
<div class="modal-overlay" id="modal-tv" onclick="if(event.target===this)closeModal('tv')">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('tv')" aria-label="Close">&times;</button>
    <h3>Why does Thingiverse require this?</h3>
    <p>Printables and MakerWorld provide simple cookie-based tokens you can copy directly from your browser — no app registration needed.</p>
    <p><strong>Thingiverse uses OAuth2</strong>, which means they don't expose a simple persistent token in your browser cookies. Instead, access is granted through an app registration tied to your account.</p>
    <p>This is a one-time setup per user. Your credentials are stored only on your own FarFetched instance — they are never shared with anyone.</p>
    <p><strong>Steps:</strong></p>
    <div class="oauth-steps" style="margin-top:8px;">
      <div class="step"><span class="step-n">1</span>Go to <a href="https://www.thingiverse.com/apps/create" target="_blank">thingiverse.com/apps/create</a></div>
      <div class="step"><span class="step-n">2</span>Fill in any Name and Client Key (slug). For Homepage URL and Redirect URI, use <code>http://localhost</code> — these aren't used by FarFetched</div>
      <div class="step"><span class="step-n">3</span>Save the app — Thingiverse will generate an <strong>App Token</strong></div>
      <div class="step"><span class="step-n">4</span>Copy that token and paste it into the field on this page</div>
    </div>
    <p style="margin-top:14px;font-size:12px;">The App Token is long-lived and tied to your Thingiverse account. It stays valid until you revoke the app.</p>
  </div>
</div>

<script>
function switchTab(t) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.querySelector('#tab-' + t).classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => {
    if (b.textContent.trim().toLowerCase().startsWith(t)) b.classList.add('active');
  });
  history.replaceState(null, '', '?tab=' + t);
}
function openModal(id) {
  document.getElementById('modal-' + id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById('modal-' + id).classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => {
      m.classList.remove('open');
      document.body.style.overflow = '';
    });
  }
});

// ---- AJAX submit for source modals -----------------------------------------
// Maps the modal id suffix to the source key returned in the JSON status.
const SRC_STATUS_KEY = {
  'src-printables': 'printables',
  'src-mw': 'makerworld',
  'src-thingiverse': 'thingiverse',
  'src-cults': 'cults3d',
  'src-stlflix': 'stlflix',
  'src-creality': 'creality',
};
// Map each source button (by onclick target) so we can flip its state.
function srcButtonFor(modalId) {
  return document.querySelector('.src-btn[onclick*="' + modalId + '"]');
}
function showModalNotice(modalEl, type, text) {
  let n = modalEl.querySelector('.modal-notice');
  if (!n) {
    n = document.createElement('div');
    n.className = 'modal-notice';
    modalEl.querySelector('.modal-src').prepend(n);
  }
  n.className = 'modal-notice notice ' + (type === 'err' ? 'err' : 'ok');
  n.textContent = text;
  n.style.display = 'block';
}
document.querySelectorAll('.modal-src form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const overlay = form.closest('.modal-overlay');
    const modalId = overlay ? overlay.id.replace('modal-', '') : '';
    // Which submit button was used (carries name="action" value)
    const submitter = e.submitter || form.querySelector('button[name="action"]');
    const fd = new FormData(form);
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    fd.append('ajax', '1');
    if (submitter) { submitter.disabled = true; }
    fetch('settings.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
      .then(r => r.json())
      .then(data => {
        showModalNotice(overlay, data.type, data.text || 'Saved.');
        // Update the source button connected state from returned status
        const key = SRC_STATUS_KEY[modalId];
        if (data.status && key in data.status) {
          const btn = srcButtonFor(modalId);
          if (btn) btn.classList.toggle('connected', !!data.status[key]);
        }
        if (submitter) submitter.disabled = false;
        // Close after save/clear; keep open for validate so the result is visible.
        const act = (submitter && submitter.value) || '';
        const isValidate = act.indexOf('validate') !== -1;
        if (data.ok && !isValidate) {
          setTimeout(() => closeModal(modalId), 900);
        }
      })
      .catch(() => {
        showModalNotice(overlay, 'err', 'Network error — try again.');
        if (submitter) submitter.disabled = false;
      });
  });
});
</script>
<script>
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');

  // Check for saved user preference, otherwise default to dark
  const currentTheme = localStorage.getItem('theme') || 'dark';

  if (currentTheme === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
    if (toggleIcon) toggleIcon.textContent = '☀️';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', () => {
    let theme = 'dark';
    if (document.documentElement.getAttribute('data-theme') !== 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      toggleIcon.textContent = '☀️';
      theme = 'light';
    } else {
      document.documentElement.removeAttribute('data-theme');
      toggleIcon.textContent = '🌙';
    }
    localStorage.setItem('theme', theme);
  });
</script>

<script>
  // Show/hide toggles for masked credential fields.
  // Delegated so it works even though the buttons live in modals rendered
  // later in the document than this script.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal-btn');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) return;
    if (input.type === 'password') {
      input.type = 'text';
      btn.textContent = '🙈';
    } else {
      input.type = 'password';
      btn.textContent = '👁';
    }
  });
</script>
<div class="modal-overlay" id="modal-src-printables" onclick="if(event.target===this)closeModal('src-printables')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-printables')" aria-label="Close">&times;</button>

        <div class="src-head">
          <?php
            $pbDot = ($hasRefresh || $hasTok) ? 'on' : 'off';
            $pbTxt = $hasRefresh ? 'Auto-renewing (refresh token)' : ($hasTok ? 'Access-token mode' : 'Not connected');
          ?>
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
      

      <!-- MakerWorld -->
  </div>
</div>

<div class="modal-overlay" id="modal-src-mw" onclick="if(event.target===this)closeModal('src-mw')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-mw')" aria-label="Close">&times;</button>

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
      

      <!-- Thingiverse -->
      <?php
        $tvClientId = (string) cfg('thingiverse_client_id');
        $tvApiKey   = (string) cfg('thingiverse_token');
        $tvReady    = $tvApiKey !== '';
      ?>
  </div>
</div>

<div class="modal-overlay" id="modal-src-thingiverse" onclick="if(event.target===this)closeModal('src-thingiverse')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-thingiverse')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">Thingiverse</span>
          <span style="display:flex;align-items:center;gap:10px;">
            <button type="button" class="btn-ghost btn-sm" onclick="openModal('tv')">More info</button>
            <span class="status" style="margin:0;"><span class="dot <?= $tvReady?'on':'off' ?>"></span><?= $tvReady?'Connected':'Not connected' ?></span>
          </span>
        </div>
        <div class="src-body">
          <div class="oauth-steps">
            <div class="step"><span class="step-n">1</span>Go to <a href="https://www.thingiverse.com/apps/create" target="_blank">thingiverse.com/apps/create</a></div>
            <div class="step"><span class="step-n">2</span>Fill in Name, Client Key (slug), Homepage URL, Redirect URI — any values work</div>
            <div class="step"><span class="step-n">3</span>Save → copy the generated <strong>App Token</strong></div>
            <div class="step"><span class="step-n">4</span>Paste it below</div>
          </div>
          <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="tv_client_id">Client ID <span style="font-weight:400;text-transform:none;">(optional — for OAuth downloads)</span></label>
            <input type="password" id="tv_client_id" name="tv_client_id" value="<?= e($tvClientId) ?>" placeholder="your-app-client-id"><button type="button" class="reveal-btn" data-target="tv_client_id" aria-label="Show/hide value">👁</button>
            <label for="tv_api_key" style="margin-top:10px;">App Token / API Key</label>
            <input type="password" id="tv_api_key" name="tv_api_key" value="<?= e($tvApiKey) ?>" placeholder="paste your Thingiverse app token"><button type="button" class="reveal-btn" data-target="tv_api_key" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_tv_token">Save &amp; Connect</button>
              <?php if ($tvReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_tv_token" onclick="return confirm('Clear Thingiverse credentials?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
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
      

      <!-- Cults3D -->
  </div>
</div>

<div class="modal-overlay" id="modal-src-cults" onclick="if(event.target===this)closeModal('src-cults')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-cults')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">Cults3D</span>
          <span class="status" style="margin:0;"><span class="dot <?= $cultsReady?'on':'off' ?>"></span><?= $cultsReady?'Connected':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="cults_username">Cults3D Username</label>
            <input type="text" id="cults_username" name="cults_username" value="<?= e($cultsUser) ?>" placeholder="your-cults3d-username">
            <label for="cults_api_key" style="margin-top:10px;">API Key</label>
            <input type="password" id="cults_api_key" name="cults_api_key" value="<?= e($cultsTok) ?>" placeholder="paste your Cults3D API key"><button type="button" class="reveal-btn" data-target="cults_api_key" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_cults_token">Save &amp; Connect</button>
              <?php if ($cultsReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_cults_token" onclick="return confirm('Clear Cults3D credentials?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">cults3d.com → Account → Settings → API → Generate key. Enter your username and the generated key.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="cults_session">Download session — <code>_session_id</code> cookie <?php if ($cultsSess !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="password" id="cults_session" name="cults_session" value="<?= e($cultsSess) ?>" placeholder="paste your _session_id cookie value"><button type="button" class="reveal-btn" data-target="cults_session" aria-label="Show/hide value">👁</button>
            <label for="cults_cf_clearance" style="margin-top:10px;"><code>cf_clearance</code> cookie (optional, helps avoid Cloudflare blocks)</label>
            <input type="password" id="cults_cf_clearance" name="cults_cf_clearance" value="<?= e($cultsCf) ?>" placeholder="paste your cf_clearance cookie value (optional)"><button type="button" class="reveal-btn" data-target="cults_cf_clearance" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_cults_session">Save Session</button>
              <?php if ($cultsSess !== ''): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_cults_session" onclick="return confirm('Clear Cults3D download session?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">The public Cults3D API can't download files, so free-model downloads use your browser session. In a logged-in cults3d.com tab: DevTools → Storage/Application → Cookies → copy the <code>_session_id</code> value (and optionally <code>cf_clearance</code>). These expire periodically — re-paste when downloads start failing. Paid models still require purchasing on the site.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="cults_dir">Download folder</label>
            <input type="text" id="cults_dir" name="cults_dir" value="<?= e($cultsDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_cults_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $cultsWrite?'on':'off' ?>"></span><?= $cultsWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(CULTS3D_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="cults_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="cults_delay" name="cults_delay" inputmode="numeric" value="<?= e((string)$cultsDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_cults_delay">Save</button>
            </div>
          </form>
          <p class="hint">Cults3D rate limit: ~500 requests/day. Keep ≥ 60s to stay well within limits.</p>
        </div>
      

      <!-- STLFlix -->
  </div>
</div>

<div class="modal-overlay" id="modal-src-stlflix" onclick="if(event.target===this)closeModal('src-stlflix')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-stlflix')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">STLFlix</span>
          <span class="status" style="margin:0;"><span class="dot <?= $stlflixReady?'on':'off' ?>"></span><?= $stlflixReady?'Connected':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="stlflix_token"><?= $stlflixReady?'Replace jwt':'Paste jwt' ?></label>
            <textarea id="stlflix_token" name="stlflix_token" placeholder="paste the jwt value from platform.stlflix.com local storage"></textarea>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_stlflix_token">Save &amp; Connect</button>
              <?php if ($stlflixReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_stlflix_token" onclick="return confirm('Clear STLFlix token?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">platform.stlflix.com → DevTools → Application → Local Storage → copy <code>jwt</code>. Re-paste when STLFlix asks you to log in again.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="stlflix_dir">Download folder</label>
            <input type="text" id="stlflix_dir" name="stlflix_dir" value="<?= e($stlflixDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_stlflix_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $stlflixWrite?'on':'off' ?>"></span><?= $stlflixWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(STLFLIX_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="stlflix_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="stlflix_delay" name="stlflix_delay" inputmode="numeric" value="<?= e((string)$stlflixDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_stlflix_delay">Save</button>
            </div>
          </form>
        </div>
      
  </div>
</div>
<div class="modal-overlay" id="modal-src-creality" onclick="if(event.target===this)closeModal('src-creality')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-creality')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">Creality Cloud</span>
          <span class="status" style="margin:0;"><span class="dot <?= $crealityReady?'on':'off' ?>"></span><?= $crealityReady?'Connected':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="creality_token">Token (__CXY_TOKEN_ / model_token)</label>
            <input type="password" id="creality_token" name="creality_token" value="<?= e($crealityTok) ?>" placeholder="64-char token from the Cookie or request header"><button type="button" class="reveal-btn" data-target="creality_token" aria-label="Show/hide value">👁</button>
            <label for="creality_user_id">User ID (model_user_id)</label>
            <input type="password" id="creality_user_id" name="creality_user_id" value="<?= e($crealityUid) ?>" placeholder="your numeric model_user_id"><button type="button" class="reveal-btn" data-target="creality_user_id" aria-label="Show/hide value">👁</button>
            <label for="creality_cf_clearance">cf_clearance cookie (optional, helps avoid 403)</label>
            <input type="password" id="creality_cf_clearance" name="creality_cf_clearance" value="<?= e($crealityCf) ?>" placeholder="cf_clearance cookie value"><button type="button" class="reveal-btn" data-target="creality_cf_clearance" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_creality_token">Save &amp; Connect</button>
              <?php if ($crealityReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_creality_token" onclick="return confirm('Clear Creality credentials?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">crealitycloud.com → log in → DevTools → Network → click any <code>/api/cxy/</code> request → copy the <code>__CXY_TOKEN_</code> header (same as the <code>model_token</code> cookie) and <code>model_user_id</code>. Re-paste when downloads start failing.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="creality_dir">Download folder</label>
            <input type="text" id="creality_dir" name="creality_dir" value="<?= e($crealityDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_creality_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $crealityWrite?'on':'off' ?>"></span><?= $crealityWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(CREALITY_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="creality_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="creality_delay" name="creality_delay" inputmode="numeric" value="<?= e((string)$crealityDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_creality_delay">Save</button>
            </div>
          </form>
        </div>
  </div>
</div>
</body>
</html>
