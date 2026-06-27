<?php
declare(strict_types=1);

/**
 * settings.php — unified configuration with tabbed layout.
 * Tabs: Sources (per-source auth + download dirs) | Worker | Activity
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();
require_once __DIR__ . '/PrintablesService.php';
require_once __DIR__ . '/Cults3DService.php';
require_once __DIR__ . '/STLFlixService.php';
require_once __DIR__ . '/CrealityCloudService.php';
require_once __DIR__ . '/NikkoService.php';
require_once __DIR__ . '/Hex3DForumService.php';
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

    // ---- Source thumbnails (per-source toggle) ------------------------------
    elseif ($action === 'save_source_thumbs') {
        // Checkboxes only POST when checked, so build the full map explicitly.
        $known = ['printables','makerworld','thingiverse','cults3d','stlflix','creality','nikko','hex3dforum'];
        $sel = $_POST['source_thumbs'] ?? [];
        $map = [];
        foreach ($known as $s) {
            $map[$s] = is_array($sel) && !empty($sel[$s]);
        }
        cfg_save(['source_thumbs' => $map]);
        $on = array_keys(array_filter($map));
        $notice = ['type' => 'ok', 'text' => $on === []
            ? 'Source thumbnails disabled for all sources (using generated renders).'
            : 'Source thumbnails enabled for: ' . implode(', ', $on) . '. Use “Backfill now” to fetch images for existing models.'];
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
        $ua      = trim((string) ($_POST['cults_user_agent'] ?? ''));
        $browser = strtolower(trim((string) ($_POST['cults_browser'] ?? 'chrome')));
        cfg_save(['cults3d_session' => $session, 'cults3d_cf_clearance' => $cf, 'cults3d_user_agent' => $ua, 'cults3d_browser' => $browser]);
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

    // ---- Nikko Industries -----------------------------------------------------
    elseif ($action === 'save_nikko_cookie') {
        $sess  = trim((string) ($_POST['nikko_phpsessid'] ?? ''));
        $login = trim((string) ($_POST['nikko_wp_logged_in'] ?? ''));
        if ($sess === '' && $login === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste at least your PHPSESSID value first.'];
        } else {
            cfg_save(['nikko_phpsessid' => $sess, 'nikko_wp_logged_in' => $login]);
            $n = new NikkoService($sess, $login);
            $notice = $n->validate()
                ? ['type' => 'ok', 'text' => 'Nikko Industries connected successfully.']
                : ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $n->lastError];
        }
    }
    elseif ($action === 'clear_nikko_cookie') {
        cfg_save(['nikko_phpsessid' => '', 'nikko_wp_logged_in' => '']);
        $notice = ['type' => 'ok', 'text' => 'Nikko Industries session cookies cleared.'];
    }
    elseif ($action === 'save_nikko_dir') {
        $r = apply_source_dir((string) ($_POST['nikko_dir'] ?? ''), 'nikko');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_nikko_delay') {
        cfg_save(['nikko_delay' => (int) ($_POST['nikko_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'Nikko Industries pacing saved.'];
    }

    // ---- Hex3D Forum -----------------------------------------------------
    elseif ($action === 'save_hex3dforum_cookie') {
        $rawU   = (string) ($_POST['hex3dforum_u'] ?? '');
        $rawSid = (string) ($_POST['hex3dforum_sid'] ?? '');
        $rawK   = (string) ($_POST['hex3dforum_k'] ?? '');
        // Forgiving paste: strip cookie-name prefixes, quotes, stray semicolons,
        // or a whole cookie blob down to the bare value for each field.
        $u   = hex3dforum_clean_cookie_value($rawU,   'u');
        $sid = hex3dforum_clean_cookie_value($rawSid, 'sid');
        $k   = hex3dforum_clean_cookie_value($rawK,   'k');
        $wasCleaned = ($u !== trim($rawU)) || ($sid !== trim($rawSid)) || ($k !== trim($rawK));
        if ($sid === '' && $u === '') {
            $notice = ['type' => 'err', 'text' => 'Nothing to save — paste at least your User ID and SID first.'];
        } else {
            cfg_save(['hex3dforum_u' => $u, 'hex3dforum_sid' => $sid, 'hex3dforum_k' => $k]);
            // Format check first — catches the common "wrong cookie" mistake with
            // a specific message before the live validation's generic error.
            $fmtWarn = hex3dforum_format_warnings($u, $sid, $k);
            // Construct with no arg so it reads the three fields we just saved.
            $h = new Hex3DForumService();
            if ($h->validate()) {
                $txt = 'Hex3D Forum connected successfully.'
                    . ($wasCleaned ? ' (Tidied up the pasted values for you.)' : '');
                // Even on success, surface a format note if something looked odd
                // (e.g. a non-standard but working SID) — informational only.
                $notice = ['type' => 'ok', 'text' => $txt];
            } else {
                // Validation failed — lead with the most likely cause. If a field
                // looks malformed, that's almost certainly why; show it first.
                if ($fmtWarn !== []) {
                    $notice = ['type' => 'err', 'text' => 'Couldn\'t connect — and one or more values look wrong: '
                        . implode(' ', $fmtWarn)];
                } else {
                    $notice = ['type' => 'err', 'text' => 'Saved, but validation failed: ' . $h->lastError
                        . ' — this almost always means the SID has already expired. Grab a FRESH sid value from your browser cookies and paste it again right away (the SID rotates quickly).'];
                }
            }
        }
    }
    elseif ($action === 'clear_hex3dforum_cookie') {
        cfg_save(['hex3dforum_u' => '', 'hex3dforum_sid' => '', 'hex3dforum_k' => '', 'hex3dforum_cookie' => '']);
        $notice = ['type' => 'ok', 'text' => 'Hex3D Forum session cleared.'];
    }
    elseif ($action === 'save_hex3dforum_dir') {
        $r = apply_source_dir((string) ($_POST['hex3dforum_dir'] ?? ''), 'hex3dforum');
        $notice = ['type' => $r['ok'] ? 'ok' : 'err', 'text' => $r['msg']];
    }
    elseif ($action === 'save_hex3dforum_delay') {
        cfg_save(['hex3dforum_delay' => (int) ($_POST['hex3dforum_delay'] ?? 60)]);
        $notice = ['type' => 'ok', 'text' => 'Hex3D Forum pacing saved.'];
    }
    elseif ($action === 'hex3dforum_crawl') {
        // Launch the crawler detached so the request returns immediately — the
        // run can take hours. Output goes to a log the user can tail.
        if (!hex3dforum_configured()) {
            $notice = ['type' => 'err', 'text' => 'Set your Hex3D Forum session cookie first.'];
        } else {
            $script = escapeshellarg(__DIR__ . '/hex3d_crawl.php');
            $log    = escapeshellarg(sys_get_temp_dir() . '/hex3d_crawl.log');
            // nohup + & so it survives the request ending; stdout/err to the log.
            @exec('nohup php ' . $script . ' > ' . $log . ' 2>&1 &');
            $notice = ['type' => 'ok', 'text' => 'Crawl started in the background. Status updates below as it progresses; large first crawls take a long time.'];
        }
    }
    elseif ($action === 'hex3dforum_crawl_restart') {
        // Full kill → clear lock → reset state → verify session → relaunch.
        // Refuses to relaunch into a dead session (the recurring "session dead"
        // surprise) and tells the user to re-paste the SID instead.
        if (!hex3dforum_configured()) {
            $notice = ['type' => 'err', 'text' => 'Set your Hex3D Forum session cookie first.'];
        } else {
            // 1. Kill any running crawler.
            @exec('pkill -f hex3d_crawl.php 2>/dev/null');
            // 2. Clear the lockfile.
            $lock = sys_get_temp_dir() . '/hex3d_crawl.lock';
            if (is_file($lock)) @unlink($lock);
            // 3. Reset crawl state to idle, clear last error.
            try { db()->exec("UPDATE hex3d_crawl_state SET status = 'idle', last_error = '' WHERE id = 1"); } catch (\Throwable $e) {}
            // 4. Verify the session is actually alive before relaunching.
            $alive = false;
            $probeErr = '';
            $svcFile = __DIR__ . '/Hex3DForumService.php';
            if (is_file($svcFile)) {
                require_once $svcFile;
                try {
                    $probe = new Hex3DForumService();
                    $alive = $probe->discoverForums() !== [];
                    if (!$alive) $probeErr = (string) ($probe->lastError ?? '');
                } catch (\Throwable $e) { $probeErr = $e->getMessage(); }
            }
            if (!$alive) {
                $notice = ['type' => 'err', 'text' => 'Crawler stopped and state reset — but the session looks dead (no forums discoverable)'
                    . ($probeErr !== '' ? ': ' . $probeErr : '') . '. Re-paste a fresh SID above (Save & Connect), then click Kill & restart again.'];
            } else {
                // 5. Relaunch detached.
                $script = escapeshellarg(__DIR__ . '/hex3d_crawl.php');
                $log    = escapeshellarg(sys_get_temp_dir() . '/hex3d_crawl.log');
                @exec('nohup php ' . $script . ' > ' . $log . ' 2>&1 &');
                $notice = ['type' => 'ok', 'text' => 'Session verified alive — crawler killed, state reset, and a fresh crawl launched. Watch progress below.'];
            }
        }
    }

    elseif ($action === 'add_custom_folder') {
        $path  = trim((string) ($_POST['custom_path'] ?? ''));
        $label = trim((string) ($_POST['custom_label'] ?? ''));
        if ($path === '') {
            $notice = ['type' => 'err', 'text' => 'Enter a folder path.'];
        } elseif (!is_dir($path)) {
            $notice = ['type' => 'err', 'text' => 'Not reachable from the container: ' . $path . ' — use the container path (what the app sees inside Docker), not the host path. If this lives outside a mounted folder, add a bind mount (e.g. host dir → /custom) in the Docker template first. Tip: the Browse button only shows folders the container can actually see.'];
        } elseif (!is_readable($path)) {
            $notice = ['type' => 'err', 'text' => 'That folder exists but is not readable by the app.'];
        } else {
            $folders = custom_folders();
            // Reject duplicates by resolved path.
            $already = false;
            foreach ($folders as $f) { if (rtrim($f['path'], '/') === rtrim($path, '/')) { $already = true; break; } }
            if ($already) {
                $notice = ['type' => 'err', 'text' => 'That folder is already registered.'];
            } else {
                $folders[] = [
                    'id'    => substr(md5($path . microtime()), 0, 12),
                    'label' => $label !== '' ? $label : basename(rtrim($path, '/')),
                    'path'  => $path,
                ];
                cfg_save(['custom_folders' => $folders]);
                $notice = ['type' => 'ok', 'text' => 'Folder registered. Its models now appear in My Library.'];
            }
        }
        $tab = 'custom';
    }
    elseif ($action === 'remove_custom_folder') {
        $id = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['custom_id'] ?? ''));
        $folders = array_values(array_filter(custom_folders(), static fn($f) => $f['id'] !== $id));
        cfg_save(['custom_folders' => $folders]);
        $notice = ['type' => 'ok', 'text' => 'Folder removed from the list. The files on disk were not touched.'];
        $tab = 'custom';
    }

    elseif ($action === 'organize_custom_folder') {
        $id = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['custom_id'] ?? ''));
        $target = null;
        foreach (custom_folders() as $f) { if ($f['id'] === $id) { $target = $f; break; } }
        if ($target === null) {
            $notice = ['type' => 'err', 'text' => 'Folder not found.'];
        } elseif (!is_dir($target['path'])) {
            $notice = ['type' => 'err', 'text' => 'Folder is not reachable: ' . $target['path']];
        } else {
            $r = organize_custom_folder($target['path']);
            $parts = [];
            if ($r['moved'] > 0) $parts[] = $r['moved'] . ' file' . ($r['moved'] === 1 ? '' : 's') . ' foldered';
            if ($r['zips']  > 0) $parts[] = $r['zips'] . ' zip' . ($r['zips'] === 1 ? '' : 's') . ' extracted';
            if ($r['skipped'] > 0) $parts[] = $r['skipped'] . ' left as-is';
            $msg = 'Organized "' . $target['label'] . '": ' . ($parts ? implode(', ', $parts) : 'nothing to do') . '.';
            if ($r['errors'] !== []) {
                $msg .= ' Issues: ' . implode('; ', array_slice($r['errors'], 0, 5));
                if (count($r['errors']) > 5) $msg .= ' (+' . (count($r['errors']) - 5) . ' more)';
            }
            $notice = ['type' => $r['errors'] === [] ? 'ok' : 'err', 'text' => $msg];
        }
        $tab = 'custom';
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
                'nikko'       => (string) cfg('nikko_phpsessid') !== '',
                'hex3dforum'  => hex3dforum_configured(),
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
$authEnabled = auth_is_enabled();

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
$cultsUa    = (string) cfg('cults3d_user_agent');
$cultsBrowser = (string) cfg('cults3d_browser') ?: 'chrome';
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

// Nikko Industries
$nikkoPhpSessId  = (string) cfg('nikko_phpsessid');
$nikkoWpLoggedIn = (string) cfg('nikko_wp_logged_in');
$nikkoDir    = get_nikko_dir();
$nikkoWrite  = is_dir($nikkoDir) && is_writable($nikkoDir);
$nikkoDelay  = (int) cfg('nikko_delay');
$nikkoReady  = $nikkoPhpSessId !== '';

// Hex3D Forum
$hex3dforumU       = (string) cfg('hex3dforum_u');
$hex3dforumSid     = (string) cfg('hex3dforum_sid');
$hex3dforumK       = (string) cfg('hex3dforum_k');
$hex3dforumDir     = get_hex3dforum_dir();
$hex3dforumWrite   = is_dir($hex3dforumDir) && is_writable($hex3dforumDir);
$hex3dforumDelay   = (int) cfg('hex3dforum_delay');
$hex3dforumReady   = $hex3dforumSid !== '' || $hex3dforumU !== '';


// Active tab
$tab = (string) ($_GET['tab'] ?? $_POST['_tab'] ?? 'sources');
if (!in_array($tab, ['sources', 'worker', 'activity', 'security', 'donate', 'custom', 'octoprint'], true)) $tab = 'sources';

// Printers (with their OctoPrint settings) for the OctoPrint tab.
$octoPrinters = db()->query(
    'SELECT id, name, nickname, octoprint_url, octoprint_api_key, octoprint_enabled
       FROM printers ORDER BY enabled DESC, name'
)->fetchAll(PDO::FETCH_ASSOC);
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
  <div class="navlabel">Tool</div>
  <nav>
    <a href="index.php">Browse Models</a>
    <a href="jobs.php">Queue</a>
    <a href="viewer.php">3D Viewer</a>
    <a href="library.php">My Library</a>
      <a href="customize.php">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
    <a href="collections_view.php">Collections</a>
    <a href="favorites.php">Favorites</a>
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
    <button class="tab-btn <?= $tab==='custom'?'active':'' ?>" onclick="switchTab('custom')">Custom</button>
    <button class="tab-btn <?= $tab==='worker'?'active':'' ?>" onclick="switchTab('worker')">Worker</button>
    <button class="tab-btn <?= $tab==='octoprint'?'active':'' ?>" onclick="switchTab('octoprint')">OctoPrint</button>
    <button class="tab-btn <?= $tab==='activity'?'active':'' ?>" onclick="switchTab('activity')">Activity</button>
    <button class="tab-btn <?= $tab==='security'?'active':'' ?>" onclick="switchTab('security')">Security</button>
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
      <button type="button" class="src-btn <?= $nikkoReady ? 'connected' : '' ?>" onclick="openModal('src-nikko')">Nikko Industries</button>
      <button type="button" class="src-btn <?= $hex3dforumReady ? 'connected' : '' ?>" onclick="openModal('src-hex3dforum')">Hex3D Forum</button>
    </div>


    
  </div>

  <!-- ===================== CUSTOM TAB ===================== -->
  <div class="tab-content <?= $tab==='custom'?'active':'' ?>" id="tab-custom">
    <div class="panel">
      <h2>Custom Local Folders</h2>
      <p class="hint">Register folders that already live on disk (or any mount the app can reach). Each <strong>subfolder</strong> is treated as one model; its files are the model's parts. If a folder contains a preview image (<code>thumb.png</code>, <code>preview.jpg</code>, or any image), it's used as the thumbnail. Models appear blended into <strong>My Library</strong>. Folders are indexed in place — nothing is copied, and removing an entry never touches your files.</p>
      <p class="hint" style="opacity:.85;">Got a folder full of <strong>loose</strong> <code>.stl</code>/<code>.3mf</code> files and <code>.zip</code>s instead of per-model subfolders? Register it, then click <strong>Organize</strong> — it moves each loose file into its own folder and extracts zips (originals go to <code>_processed/</code>) so they show as proper models. <em>Organize modifies the folder on disk; existing subfolders are left alone.</em></p>

      <?php $cf = custom_folders(); ?>
      <?php if ($cf === []): ?>
        <p class="hint" style="opacity:.7;">No folders registered yet. Add one below.</p>
      <?php else: ?>
        <table class="cf-table" style="width:100%;border-collapse:collapse;margin:12px 0;">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid var(--border,#2a2a2a);">
              <th style="padding:8px 6px;">Label</th>
              <th style="padding:8px 6px;">Path</th>
              <th style="padding:8px 6px;">Status</th>
              <th style="padding:8px 6px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cf as $f):
              $exists = is_dir($f['path']);
              $readable = $exists && is_readable($f['path']);
              $count = $exists ? count_models($f['path']) : 0;
            ?>
              <tr style="border-bottom:1px solid var(--border,#222);">
                <td style="padding:8px 6px;"><?= e($f['label']) ?></td>
                <td style="padding:8px 6px;"><code style="font-size:12px;"><?= e($f['path']) ?></code></td>
                <td style="padding:8px 6px;" id="cf-status-<?= e($f['id']) ?>">
                  <?php if (!$exists): ?>
                    <span style="color:#e07a5f;">● not found</span>
                  <?php elseif (!$readable): ?>
                    <span style="color:#e0b15f;">● not readable</span>
                  <?php else: ?>
                    <span style="color:#7fb069;">● <?= (int) $count ?> model<?= $count === 1 ? '' : 's' ?></span>
                  <?php endif; ?>
                </td>
                <td style="padding:8px 6px;text-align:right;white-space:nowrap;">
                  <?php if ($exists && $readable): ?>
                  <button type="button" class="btn-ghost btn-sm cf-organize-btn"
                          data-id="<?= e($f['id']) ?>"
                          data-label="<?= e($f['label']) ?>"
                          data-status-cell="cf-status-<?= e($f['id']) ?>"
                          title="Move loose files into per-model folders and extract zips">Organize</button>
                  <?php endif; ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Remove this folder from the list? Your files are not deleted.');">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="_tab" value="custom">
                    <input type="hidden" name="custom_id" value="<?= e($f['id']) ?>">
                    <button class="btn-ghost btn-sm" name="action" value="remove_custom_folder">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <form method="post" class="cf-add" style="margin-top:16px;">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="_tab" value="custom">
        <label for="custom_path">Folder path</label>
        <div class="row" style="gap:8px;align-items:stretch;">
          <input type="text" id="custom_path" name="custom_path" placeholder="/custom/my-models" autocomplete="off" style="flex:1;">
          <button type="button" class="btn-ghost btn-sm" id="cf-browse-btn" style="white-space:nowrap;">Browse…</button>
        </div>
        <p class="hint">Pick a folder with <strong>Browse</strong>, or type a <strong>container path</strong> (what the app sees inside Docker, not the host path). Custom folders live under <code>/custom</code> — add a bind mount (host dir → <code>/custom</code>) in the FarFetched Docker template if you haven't yet, then restart the container. Host paths like <code>/mnt/user/…</code> won't work unless they're mounted.</p>

        <!-- Folder picker panel (hidden until Browse is clicked) -->
        <div id="cf-picker" style="display:none;border:1px solid var(--border,#2a2a2a);border-radius:8px;margin:8px 0;background:rgba(0,0,0,.2);">
          <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid var(--border,#222);">
            <button type="button" class="btn-ghost btn-sm" id="cf-up">↑ Up</button>
            <code id="cf-cwd" style="font-size:12px;opacity:.85;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">/custom</code>
            <button type="button" class="btn-primary btn-sm" id="cf-pick">Use this folder</button>
          </div>
          <div id="cf-list" style="max-height:260px;overflow:auto;padding:4px;"></div>
          <div id="cf-msg" class="hint" style="padding:8px 10px;display:none;"></div>
        </div>

        <label for="custom_label">Label <span style="opacity:.6;">(optional)</span></label>
        <input type="text" id="custom_label" name="custom_label" placeholder="Defaults to the folder's own name" autocomplete="off">
        <div class="row" style="margin-top:12px;">
          <button class="btn-primary btn-sm" name="action" value="add_custom_folder">Add Folder</button>
        </div>
      </form>

      <script>
      (function () {
        const btn   = document.getElementById('cf-browse-btn');
        const panel = document.getElementById('cf-picker');
        const list  = document.getElementById('cf-list');
        const cwdEl = document.getElementById('cf-cwd');
        const msgEl = document.getElementById('cf-msg');
        const upBtn = document.getElementById('cf-up');
        const pick  = document.getElementById('cf-pick');
        const pathInput = document.getElementById('custom_path');
        if (!btn) return;

        let cwd = null;      // current absolute dir
        let parent = null;   // parent dir or null at root

        function esc(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

        async function load(path) {
          list.innerHTML = '<div class="hint" style="padding:10px;">Loading…</div>';
          msgEl.style.display = 'none';
          let data;
          try {
            const url = 'browse_dirs.php' + (path ? ('?path=' + encodeURIComponent(path)) : '');
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            data = await res.json();
          } catch (e) {
            list.innerHTML = '';
            msgEl.textContent = 'Could not reach the folder browser.';
            msgEl.style.display = 'block';
            return;
          }
          if (!data.ok) {
            list.innerHTML = '';
            msgEl.textContent = data.message || ('Browser error: ' + (data.error || 'unknown'));
            msgEl.style.display = 'block';
            cwd = data.root || '/custom';
            cwdEl.textContent = cwd;
            parent = null;
            upBtn.disabled = true;
            return;
          }
          cwd = data.path;
          parent = data.parent;
          cwdEl.textContent = cwd;
          upBtn.disabled = !parent;

          if (!data.dirs.length) {
            list.innerHTML = '<div class="hint" style="padding:10px;">No subfolders here. Click <strong>Use this folder</strong> above to pick it, or go up.</div>';
            return;
          }
          list.innerHTML = '';
          for (const d of data.dirs) {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;';
            row.onmouseenter = () => row.style.background = 'rgba(255,255,255,.05)';
            row.onmouseleave = () => row.style.background = 'transparent';
            const count = d.models > 0 ? (' <span style="opacity:.6;">· ' + d.models + ' model' + (d.models===1?'':'s') + '</span>') : '';
            // Folder name (click to open/drill in)
            const nameEl = document.createElement('span');
            nameEl.style.cssText = 'flex:1;cursor:pointer;display:flex;align-items:center;gap:8px;';
            nameEl.innerHTML = '<span style="opacity:.7;">📁</span><span>' + esc(d.name) + count + '</span>';
            nameEl.title = 'Open ' + d.name;
            nameEl.onclick = () => load(d.path);
            // Per-row Select button (pick THIS folder without drilling in)
            const selBtn = document.createElement('button');
            selBtn.type = 'button';
            selBtn.className = 'btn-ghost btn-sm';
            selBtn.textContent = 'Select';
            selBtn.style.cssText = 'white-space:nowrap;';
            selBtn.onclick = (ev) => { ev.stopPropagation(); choose(d.path); };
            const openHint = document.createElement('span');
            openHint.style.cssText = 'opacity:.35;font-size:12px;cursor:pointer;';
            openHint.textContent = 'open ›';
            openHint.onclick = () => load(d.path);
            row.appendChild(nameEl);
            row.appendChild(selBtn);
            row.appendChild(openHint);
            list.appendChild(row);
          }
        }

        // Commit a chosen path: fill the field, confirm visually, close picker.
        function choose(path) {
          pathInput.value = path;
          panel.style.display = 'none';
          // Brief highlight so it's obvious the field was filled.
          pathInput.style.transition = 'box-shadow .15s, border-color .15s';
          pathInput.style.boxShadow = '0 0 0 2px var(--accent, #e0a458)';
          pathInput.focus();
          setTimeout(() => { pathInput.style.boxShadow = ''; }, 900);
          // Default the label to the folder's own name if empty.
          const labelEl = document.getElementById('custom_label');
          if (labelEl && labelEl.value.trim() === '') {
            const base = path.replace(/\/+$/, '').split('/').pop();
            if (base) labelEl.value = base.charAt(0).toUpperCase() + base.slice(1);
          }
        }

        btn.addEventListener('click', () => {
          const show = panel.style.display === 'none';
          panel.style.display = show ? 'block' : 'none';
          if (show && cwd === null) load(null); // first open → root
        });
        upBtn.addEventListener('click', () => { if (parent) load(parent); });
        pick.addEventListener('click', () => { if (cwd) choose(cwd); });
      })();

      // Organize: chunked, pausable, with a live progress bar. Each chunk is an
      // AJAX call; pausing flips server state and the loop stops requesting.
      (function () {
        const CSRF = <?= json_encode($csrf) ?>;
        const CHUNK = 8;
        const state = {}; // id -> { running, paused }

        function post(action, id, extra) {
          const body = new URLSearchParams(Object.assign({ action, custom_id: id, csrf: CSRF }, extra || {}));
          return fetch('organize_run.php', { method: 'POST', body }).then(r => r.json());
        }

        function render(cell, st, ctrl) {
          if (!cell) return;
          const total = (st && st.total) || 0;
          const done  = (st && st.done) || 0;
          const pct   = total > 0 ? Math.round((done / total) * 100) : 0;
          const status = (st && st.status) || 'running';
          const cur = (st && st.current) ? (' · ' + st.current) : '';
          let label;
          if (status === 'done')        label = '✓ done — ' + done + ' processed';
          else if (status === 'paused') label = '⏸ paused — ' + done + '/' + total;
          else                          label = '⟳ ' + done + '/' + total + ' (' + pct + '%)' + cur;
          cell.innerHTML =
            '<div style="display:flex;flex-direction:column;gap:4px;min-width:160px;">' +
              '<div style="font-size:12px;color:' + (status==='done'?'#7fb069':(status==='paused'?'#e0b15f':'#cfd8c5')) + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + label + '</div>' +
              '<div style="height:6px;border-radius:3px;background:rgba(255,255,255,.08);overflow:hidden;">' +
                '<div style="height:100%;width:' + pct + '%;background:' + (status==='done'?'#7fb069':'#e0a458') + ';transition:width .2s;"></div>' +
              '</div>' +
            '</div>';
        }

        async function runLoop(id, cell, btn) {
          const s = state[id];
          while (s.running && !s.paused) {
            let res;
            try { res = await post('next', id, { chunk: CHUNK }); }
            catch (e) { cell.innerHTML = '<span style="color:#e07a5f;">network error — retry Organize</span>'; s.running = false; resetBtn(btn); return; }
            if (!res.ok) {
              cell.innerHTML = '<span style="color:#e07a5f;">' + (res.error || 'error') + (res.path ? (': ' + res.path) : '') + '</span>';
              s.running = false; resetBtn(btn); return;
            }
            render(cell, res.state, btn);
            if (res.done || (res.state && res.state.status === 'done')) {
              s.running = false; finishBtn(btn);
              // Surface any errors compactly under the bar.
              const errs = (res.state && res.state.errors) || [];
              if (errs.length) {
                cell.innerHTML += '<div class="hint" style="color:#e0b15f;font-size:11px;margin-top:4px;">' +
                  errs.slice(0,3).map(e => e.replace(/[<>&]/g, '')).join('; ') + (errs.length>3?(' (+'+(errs.length-3)+' more)'):'') + '</div>';
              }
              return;
            }
            if (res.paused || (res.state && res.state.status === 'paused')) {
              s.running = false; s.paused = true; pauseBtn(btn); return;
            }
          }
        }

        function makeCtrl(btn) {
          // Replace the single Organize button with Pause/Resume while running.
          return btn;
        }
        function resetBtn(btn) { btn.disabled = false; btn.textContent = 'Organize'; btn.dataset.mode = ''; }
        function finishBtn(btn) { btn.disabled = false; btn.textContent = 'Organize again'; btn.dataset.mode = ''; }
        function pauseBtn(btn)  { btn.disabled = false; btn.textContent = 'Resume'; btn.dataset.mode = 'paused'; }
        function runningBtn(btn){ btn.disabled = false; btn.textContent = 'Pause'; btn.dataset.mode = 'running'; }

        document.querySelectorAll('.cf-organize-btn').forEach(function (btn) {
          const id   = btn.dataset.id;
          const cell = document.getElementById(btn.dataset.statusCell);
          state[id] = { running: false, paused: false };

          btn.addEventListener('click', async function () {
            const mode = btn.dataset.mode || '';

            // Pause an in-flight run.
            if (mode === 'running') {
              await post('pause', id);
              state[id].paused = true; state[id].running = false;
              pauseBtn(btn);
              return;
            }
            // Resume a paused run.
            if (mode === 'paused') {
              await post('resume', id);
              state[id].paused = false; state[id].running = true;
              runningBtn(btn);
              runLoop(id, cell, btn);
              return;
            }

            // Fresh start — confirm first (it modifies the folder).
            const label = btn.dataset.label || 'this folder';
            const ok = confirm('Organize "' + label + '"?\n\n'
              + 'This MODIFIES the folder on disk:\n'
              + '• loose files → moved into per-name folders\n'
              + '• zips → extracted (originals moved to _processed/)\n\n'
              + 'Existing subfolders are left alone. You can Pause anytime. Continue?');
            if (!ok) return;

            const seed = await post('start', id);
            if (!seed.ok) { cell.innerHTML = '<span style="color:#e07a5f;">' + (seed.error || 'could not start') + '</span>'; return; }
            render(cell, seed.state, btn);
            state[id].running = true; state[id].paused = false;
            runningBtn(btn);
            runLoop(id, cell, btn);
          });
        });
      })();
      </script>
    </div>
  </div>
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
        <h2>Thumbnails</h2>
        <?php
          $stCfg = cfg('source_thumbs'); if (!is_array($stCfg)) $stCfg = [];
          $thumbSources = [
            'printables'  => 'Printables',
            'makerworld'  => 'MakerWorld',
            'thingiverse' => 'Thingiverse',
            'cults3d'     => 'Cults3D',
            'stlflix'     => 'STLFlix',
            'creality'    => 'Creality',
            'nikko'       => 'Nikko Industries',
            'hex3dforum'  => 'Hex3D Forum',
          ];
        ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="_tab" value="worker">
          <p class="hint" style="margin-top:0;">By default, My Library shows a thumbnail rendered from the model's 3D file. Turn a source on below to use that <strong>site's own cover image</strong> instead — it's downloaded and saved alongside the model. If a source image isn't available for a model, the generated render is used as a fallback.</p>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 24px;margin:10px 0;">
            <?php foreach ($thumbSources as $slug => $label): ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13.5px;font-weight:500;color:var(--ink);">
                <input type="checkbox" name="source_thumbs[<?= e($slug) ?>]" value="1" <?= !empty($stCfg[$slug]) ? 'checked' : '' ?> style="width:auto;">
                <?= e($label) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="row" style="margin-top:6px;">
            <button class="btn-primary btn-sm" name="action" value="save_source_thumbs">Save thumbnail sources</button>
          </div>
        </form>
        <div class="row" style="margin-top:12px;">
          <button class="btn-ghost btn-sm" type="button" id="backfillThumbsBtn">📥 Backfill now (fetch source images for existing models)</button>
          <span id="backfillThumbsStatus" class="hint" style="margin-left:8px;"></span>
        </div>
        <p class="hint">Backfill downloads the source cover image for models you already have (only for sources enabled above, and only where a cover URL was captured at download time). New downloads are picked up automatically. Re-runnable any time; it skips models that already have a saved image.</p>

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

  <!-- ===================== OCTOPRINT TAB ===================== -->
  <div class="tab-content <?= $tab==='octoprint'?'active':'' ?>" id="tab-octoprint">
    <div class="panel">
      <h2>OctoPrint</h2>
      <p class="hint">Connect each printer to its own OctoPrint instance. Set the OctoPrint URL (e.g. <code>http://octopi.local</code> or <code>http://192.168.1.50</code>) and an API key from OctoPrint → Settings → API. Once connected, you can upload downloaded files to the printer and control prints. Note: OctoPrint prints <strong>gcode</strong> — STL/3MF files upload as models but must be sliced before printing.</p>

      <?php if ($octoPrinters === []): ?>
      <div class="status"><span class="dot off"></span>No printers yet. Add a printer first in <a href="printers.php">My Printers</a>.</div>
      <?php else: ?>
      <div class="op-list">
        <?php foreach ($octoPrinters as $op): ?>
        <div class="op-card" data-printer-id="<?= (int) $op['id'] ?>">
          <div class="op-head">
            <strong><?= e($op['nickname'] ?: $op['name']) ?></strong>
            <span class="op-status" id="op-status-<?= (int) $op['id'] ?>">
              <span class="dot <?= $op['octoprint_enabled'] ? 'on' : 'off' ?>"></span>
              <?= $op['octoprint_enabled'] ? 'Enabled' : 'Disabled' ?>
            </span>
          </div>
          <div class="op-fields">
            <label>OctoPrint URL
              <input type="text" class="op-url" value="<?= e($op['octoprint_url']) ?>" placeholder="http://octopi.local">
            </label>
            <label>API Key
              <input type="password" class="op-key" value="<?= e($op['octoprint_api_key']) ?>" placeholder="OctoPrint API key" autocomplete="off">
            </label>
            <label class="op-enable">
              <input type="checkbox" class="op-enabled" <?= $op['octoprint_enabled'] ? 'checked' : '' ?>> Enable OctoPrint for this printer
            </label>
          </div>
          <div class="op-actions">
            <button type="button" class="btn-primary btn-sm op-save">Save</button>
            <button type="button" class="btn-sm op-test">Test connection</button>
            <button type="button" class="btn-sm op-refresh">Refresh status</button>
            <span class="op-msg" id="op-msg-<?= (int) $op['id'] ?>"></span>
          </div>
          <div class="op-live" id="op-live-<?= (int) $op['id'] ?>" hidden></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
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

  <!-- ===================== SECURITY TAB ===================== -->
  <div class="tab-content <?= $tab==='security'?'active':'' ?>" id="tab-security">
    <div class="panel" style="max-width:560px;">
      <h2>Security</h2>

      <?php if (!$authEnabled): ?>
        <p class="hint" style="margin-bottom:16px;">
          This instance is currently <strong>open</strong> — anyone who can reach it has full access.
          Set a password to lock it behind a sign-in screen.
        </p>
        <div id="secSetup">
          <label for="secNew">Create a password</label>
          <input type="password" class="short" id="secNew" autocomplete="new-password" placeholder="At least 6 characters" style="max-width:280px;">
          <label for="secNew2" style="margin-top:10px;">Confirm password</label>
          <input type="password" class="short" id="secNew2" autocomplete="new-password" style="max-width:280px;">
          <div class="row" style="margin-top:16px;">
            <button class="btn-primary btn-sm" id="secSetBtn" type="button">Enable lock</button>
          </div>
          <p class="sec-msg hint" id="secSetupMsg"></p>
        </div>
      <?php else: ?>
        <p class="hint" style="margin-bottom:16px;">
          This instance is <strong>locked</strong> 🔒 — a password is required to sign in.
        </p>

        <div id="secChange" style="margin-bottom:26px;">
          <h3 style="font-size:14px;margin:0 0 10px;">Change password</h3>
          <label for="secCur">Current password</label>
          <input type="password" class="short" id="secCur" autocomplete="current-password" style="max-width:280px;">
          <label for="secChNew" style="margin-top:10px;">New password</label>
          <input type="password" class="short" id="secChNew" autocomplete="new-password" placeholder="At least 6 characters" style="max-width:280px;">
          <div class="row" style="margin-top:16px;">
            <button class="btn-primary btn-sm" id="secChangeBtn" type="button">Update password</button>
          </div>
          <p class="sec-msg hint" id="secChangeMsg"></p>
        </div>

        <div style="border-top:1px solid var(--line);padding-top:20px;">
          <h3 style="font-size:14px;margin:0 0 6px;">Session</h3>
          <div class="row" style="gap:10px;">
            <button class="btn-sm" id="secLogoutBtn" type="button">Sign out</button>
            <button class="btn-sm" id="secDisableBtn" type="button" style="color:var(--err);border-color:var(--err);">Remove lock…</button>
          </div>
          <div id="secDisableWrap" hidden style="margin-top:14px;">
            <label for="secDisCur">Confirm current password to remove the lock</label>
            <input type="password" class="short" id="secDisCur" autocomplete="current-password" style="max-width:280px;">
            <div class="row" style="margin-top:12px;">
              <button class="btn-sm" id="secDisableConfirm" type="button" style="color:var(--err);border-color:var(--err);">Disable lock</button>
            </div>
          </div>
          <p class="sec-msg hint" id="secSessionMsg"></p>
        </div>
      <?php endif; ?>
    </div>
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

// ===== Security panel =====
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  function post(payload) {
    return fetch('auth_action.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)),
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Request failed' }; }); });
  }
  function msg(el, text, ok) {
    if (!el) return;
    el.textContent = text;
    el.style.color = ok ? 'var(--ok)' : 'var(--err)';
  }
  function byId(id) { return document.getElementById(id); }

  // First-time setup
  var setBtn = byId('secSetBtn');
  if (setBtn) setBtn.addEventListener('click', function () {
    var a = byId('secNew').value, b = byId('secNew2').value;
    var m = byId('secSetupMsg');
    if (a.length < 6) return msg(m, 'Password must be at least 6 characters.', false);
    if (a !== b) return msg(m, 'Passwords do not match.', false);
    setBtn.disabled = true;
    post({ action: 'set', password: a }).then(function (d) {
      if (d.ok) { msg(m, 'Lock enabled. Reloading…', true); setTimeout(function () { location.href = 'settings.php?tab=security'; }, 700); }
      else { msg(m, d.error || 'Failed.', false); setBtn.disabled = false; }
    });
  });

  // Change password
  var chBtn = byId('secChangeBtn');
  if (chBtn) chBtn.addEventListener('click', function () {
    var cur = byId('secCur').value, nw = byId('secChNew').value;
    var m = byId('secChangeMsg');
    if (nw.length < 6) return msg(m, 'New password must be at least 6 characters.', false);
    chBtn.disabled = true;
    post({ action: 'change', current: cur, password: nw }).then(function (d) {
      if (d.ok) { msg(m, 'Password updated.', true); byId('secCur').value = ''; byId('secChNew').value = ''; }
      else msg(m, d.error || 'Failed.', false);
      chBtn.disabled = false;
    });
  });

  // Sign out
  var outBtn = byId('secLogoutBtn');
  if (outBtn) outBtn.addEventListener('click', function () {
    post({ action: 'logout' }).then(function (d) {
      if (d.ok) location.href = 'login.php';
    });
  });

  // Remove lock (reveal confirm)
  var disBtn = byId('secDisableBtn');
  if (disBtn) disBtn.addEventListener('click', function () {
    var w = byId('secDisableWrap'); w.hidden = !w.hidden;
  });
  var disConfirm = byId('secDisableConfirm');
  if (disConfirm) disConfirm.addEventListener('click', function () {
    var cur = byId('secDisCur').value;
    var m = byId('secSessionMsg');
    disConfirm.disabled = true;
    post({ action: 'disable', current: cur }).then(function (d) {
      if (d.ok) { msg(m, 'Lock removed. Reloading…', true); setTimeout(function () { location.href = 'settings.php?tab=security'; }, 700); }
      else { msg(m, d.error || 'Failed.', false); disConfirm.disabled = false; }
    });
  });
})();
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
  'src-nikko': 'nikko',
  'src-hex3dforum': 'hex3dforum',
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
          // Reload after the notice so the modal's field values and "(set)"
          // indicators re-render from the now-saved server state. Without this,
          // the DOM keeps the old PHP-rendered values and a saved credential can
          // look like it didn't stick when the modal is reopened.
          setTimeout(() => { window.location.reload(); }, 900);
        }
      })
      .catch(() => {
        showModalNotice(overlay, 'err', 'Network error — try again.');
        if (submitter) submitter.disabled = false;
      });
  });
});

/* ===================== OctoPrint tab ===================== */
(function () {
  const CSRF = <?= json_encode($csrf) ?>;
  const fmtTime = s => {
    if (s == null) return '–';
    s = Math.round(s); const h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
    return h ? (h + 'h ' + m + 'm') : (m + 'm');
  };

  async function opPost(payload) {
    const res = await fetch('octoprint_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)),
    });
    return res.json();
  }

  function msg(id, text, kind) {
    const el = document.getElementById('op-msg-' + id);
    if (!el) return;
    el.textContent = text;
    el.className = 'op-msg ' + (kind || '');
  }

  function renderLive(id, s) {
    const live = document.getElementById('op-live-' + id);
    if (!live) return;
    if (!s || !s.ok) { live.hidden = true; return; }
    const t = s.temps || {};
    const tool = t.tool0 || {}, bed = t.bed || {};
    const job = s.job || null;
    const flags = s.flags || {};
    let html = '<div class="op-live-row"><span class="op-state">' + (s.state || 'Unknown') + '</span>';
    if (tool.actual != null) html += '<span>Nozzle: ' + tool.actual + '°' + (tool.target ? ' / ' + tool.target + '°' : '') + '</span>';
    if (bed.actual != null)  html += '<span>Bed: ' + bed.actual + '°' + (bed.target ? ' / ' + bed.target + '°' : '') + '</span>';
    html += '</div>';
    if (job && job.file) {
      html += '<div class="op-live-row"><span>Job: ' + job.file + '</span>';
      if (job.completion != null) html += '<span>' + job.completion + '%</span>';
      if (job.printTimeLeft != null) html += '<span>ETA: ' + fmtTime(job.printTimeLeft) + '</span>';
      html += '</div>';
    }
    // Control buttons based on state.
    html += '<div class="op-controls">';
    if (flags.printing) {
      html += '<button type="button" class="btn-sm op-ctl" data-cmd="pause">Pause</button>';
      html += '<button type="button" class="btn-sm op-ctl" data-cmd="cancel">Cancel</button>';
    } else if (flags.paused) {
      html += '<button type="button" class="btn-sm op-ctl" data-cmd="resume">Resume</button>';
      html += '<button type="button" class="btn-sm op-ctl" data-cmd="cancel">Cancel</button>';
    } else if (flags.operational) {
      html += '<button type="button" class="btn-sm op-ctl" data-cmd="start">Start</button>';
    }
    html += '</div>';
    live.innerHTML = html;
    live.hidden = false;

    live.querySelectorAll('.op-ctl').forEach(b => {
      b.addEventListener('click', async () => {
        b.disabled = true;
        const r = await opPost({ action: 'control', id, cmd: b.dataset.cmd });
        msg(id, r.ok ? ('Sent: ' + b.dataset.cmd) : ('Failed: ' + (r.error||'')), r.ok ? 'ok' : 'err');
        setTimeout(() => refreshStatus(id), 1200);
      });
    });
  }

  async function refreshStatus(id) {
    msg(id, 'Checking…', '');
    try {
      const r = await opPost({ action: 'status', id });
      if (r.ok) { msg(id, r.online ? 'Online' : 'Reachable (printer offline)', 'ok'); renderLive(id, r); }
      else { msg(id, r.error || 'Failed', 'err'); }
    } catch (e) { msg(id, 'Network error', 'err'); }
  }

  document.querySelectorAll('.op-card').forEach(card => {
    const id = parseInt(card.dataset.printerId, 10);
    const url = card.querySelector('.op-url');
    const key = card.querySelector('.op-key');
    const en  = card.querySelector('.op-enabled');

    card.querySelector('.op-save').addEventListener('click', async () => {
      const r = await opPost({ action: 'save', id, url: url.value.trim(), api_key: key.value.trim(), enabled: en.checked ? 1 : 0 });
      msg(id, r.ok ? 'Saved.' : ('Error: ' + (r.error||'')), r.ok ? 'ok' : 'err');
    });
    card.querySelector('.op-test').addEventListener('click', async () => {
      msg(id, 'Testing…', '');
      const r = await opPost({ action: 'test', id });
      msg(id, r.ok ? ('Connected — OctoPrint ' + (r.version||'')) : ('Failed: ' + (r.error||'')), r.ok ? 'ok' : 'err');
    });
    card.querySelector('.op-refresh').addEventListener('click', () => refreshStatus(id));
  });
})();
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
  (function () {
    var bf = document.getElementById('backfillThumbsBtn');
    if (!bf) return;
    bf.addEventListener('click', function () {
      var st = document.getElementById('backfillThumbsStatus');
      bf.disabled = true;
      if (st) st.textContent = 'Fetching source images… (looking up older models may take a minute)';
      var body = new URLSearchParams();
      body.set('csrf', <?= json_encode($csrf) ?>);
      fetch('source_thumbs_backfill.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) {
          if (st) st.textContent = 'Error: ' + ((d && d.error) || 'unknown');
        } else if (d.note) {
          if (st) st.textContent = d.note;
        } else {
          if (st) st.textContent = 'Done — saved ' + d.saved +
            (d.resolved ? (' (' + d.resolved + ' looked up from source)') : '') +
            ', skipped ' + d.skipped +
            (d.failed ? (', failed ' + d.failed) : '') + ' (scanned ' + d.scanned + ').';
        }
      }).catch(function (err) {
        if (st) st.textContent = 'Request failed: ' + err;
      }).finally(function () { bf.disabled = false; });
    });
  })();

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal-btn');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) return;
    // New masked-text fields (type=text + CSS masking) avoid Chromium's password
    // manager clearing pasted values on submit. Toggle the CSS mask, not the type.
    if (input.classList.contains('masked-field')) {
      var masked = input.getAttribute('data-mask') === '1';
      input.setAttribute('data-mask', masked ? '0' : '1');
      btn.textContent = masked ? '🙈' : '👁';
      return;
    }
    // Legacy password fields elsewhere — toggle the type.
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
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="pb_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="pb_delay" name="download_delay" inputmode="numeric" value="<?= (int) $conf['download_delay'] ?>">
              <button class="btn-primary btn-sm" name="action" value="save_worker_settings">Save</button>
            </div>
            <p class="hint">⚠️ Keep at 120s minimum — going lower risks bot detection and account bans.</p>
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
            <input type="text" id="tv_client_id" name="tv_client_id" value="<?= e($tvClientId) ?>" placeholder="your-app-client-id" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="tv_client_id" aria-label="Show/hide value">👁</button>
            <label for="tv_api_key" style="margin-top:10px;">App Token / API Key</label>
            <input type="text" id="tv_api_key" name="tv_api_key" value="<?= e($tvApiKey) ?>" placeholder="paste your Thingiverse app token" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="tv_api_key" aria-label="Show/hide value">👁</button>
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
            <input type="text" id="cults_api_key" name="cults_api_key" value="<?= e($cultsTok) ?>" placeholder="paste your Cults3D API key" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="cults_api_key" aria-label="Show/hide value">👁</button>
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
            <input type="text" id="cults_session" name="cults_session" value="<?= e($cultsSess) ?>" placeholder="paste your _session_id cookie value" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="cults_session" aria-label="Show/hide value">👁</button>
            <label for="cults_cf_clearance" style="margin-top:10px;"><code>cf_clearance</code> cookie (optional, helps avoid Cloudflare blocks)</label>
            <input type="text" id="cults_cf_clearance" name="cults_cf_clearance" value="<?= e($cultsCf) ?>" placeholder="paste your cf_clearance cookie value (optional)" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="cults_cf_clearance" aria-label="Show/hide value">👁</button>
            <label for="cults_browser" style="margin-top:10px;">Your browser <span style="color:var(--muted);font-weight:400;">(must match where you copied the cookies)</span></label>
            <select id="cults_browser" name="cults_browser" class="short" style="width:auto;min-width:160px;">
              <?php foreach (['chrome'=>'Chrome','firefox'=>'Firefox','edge'=>'Edge','safari'=>'Safari'] as $bval=>$blabel): ?>
                <option value="<?= e($bval) ?>" <?= $cultsBrowser === $bval ? 'selected' : '' ?>><?= e($blabel) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="cults_user_agent" style="margin-top:10px;">Browser User-Agent <?php if ($cultsUa !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="cults_user_agent" name="cults_user_agent" value="<?= e($cultsUa) ?>" placeholder="paste your browser's User-Agent (must match the cf_clearance browser)">
            <p class="hint"><code>cf_clearance</code> is tied to the exact browser that created it. Paste the matching User-Agent (DevTools → Network → any request → Request Headers → <code>user-agent</code>), or the same browser's value from <code>navigator.userAgent</code> in the console. Leave blank to use the built-in default.</p>
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
            <input type="text" id="creality_token" name="creality_token" value="<?= e($crealityTok) ?>" placeholder="64-char token from the Cookie or request header" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="creality_token" aria-label="Show/hide value">👁</button>
            <label for="creality_user_id">User ID (model_user_id)</label>
            <input type="text" id="creality_user_id" name="creality_user_id" value="<?= e($crealityUid) ?>" placeholder="your numeric model_user_id" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="creality_user_id" aria-label="Show/hide value">👁</button>
            <label for="creality_cf_clearance">cf_clearance cookie (optional, helps avoid 403)</label>
            <input type="text" id="creality_cf_clearance" name="creality_cf_clearance" value="<?= e($crealityCf) ?>" placeholder="cf_clearance cookie value" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="creality_cf_clearance" aria-label="Show/hide value">👁</button>
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

<div class="modal-overlay" id="modal-src-nikko" onclick="if(event.target===this)closeModal('src-nikko')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-nikko')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">Nikko Industries</span>
          <span class="status" style="margin:0;"><span class="dot <?= $nikkoReady?'on':'off' ?>"></span><?= $nikkoReady?'Connected':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="nikko_phpsessid">PHPSESSID <?php if ($nikkoPhpSessId !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="nikko_phpsessid" name="nikko_phpsessid" value="<?= e($nikkoPhpSessId) ?>" placeholder="e.g. fjhsbieipge8kv60abvm1dq..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="nikko_phpsessid" aria-label="Show/hide value">👁</button>
            <label for="nikko_wp_logged_in" style="margin-top:14px;">wordpress_logged_in <?php if ($nikkoWpLoggedIn !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="nikko_wp_logged_in" name="nikko_wp_logged_in" value="<?= e($nikkoWpLoggedIn) ?>" placeholder="paste the FULL pair: wordpress_logged_in_xxxxx=value" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="nikko_wp_logged_in" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_nikko_cookie">Save &amp; Connect</button>
              <?php if ($nikkoReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_nikko_cookie" onclick="return confirm('Clear Nikko Industries session cookies?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint">nikkoindustriesmembership.com has no API — this site is accessed entirely via browser session. Log in with an active membership, open DevTools → Application/Storage → Cookies, and copy the <code>PHPSESSID</code> value into the first field. For the second field, copy the cookie named <code>wordpress_logged_in_</code> followed by a long random suffix — <strong>paste the whole row</strong> (name and value, joined with <code>=</code>) since WordPress checks that exact name, not just the value. Membership is flat-fee unlimited downloads across the whole library, not per-model — any active member can fetch any product. Re-paste when downloads start failing; there's no fixed expiry, it depends on server session settings.</p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="nikko_dir">Download folder</label>
            <input type="text" id="nikko_dir" name="nikko_dir" value="<?= e($nikkoDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_nikko_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $nikkoWrite?'on':'off' ?>"></span><?= $nikkoWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(NIKKO_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="nikko_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="nikko_delay" name="nikko_delay" inputmode="numeric" value="<?= e((string)$nikkoDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_nikko_delay">Save</button>
            </div>
          </form>
          <p class="hint">Catalog pages are scraped HTML, not an API — keep a reasonable delay so the site isn't hammered.</p>
        </div>
  </div>
</div>

<div class="modal-overlay" id="modal-src-hex3dforum" onclick="if(event.target===this)closeModal('src-hex3dforum')">
  <div class="modal modal-src">
    <button class="modal-close" onclick="closeModal('src-hex3dforum')" aria-label="Close">&times;</button>

        <div class="src-head">
          <span class="src-name">Hex3D Forum</span>
          <span class="status" style="margin:0;"><span class="dot <?= $hex3dforumReady?'on':'off' ?>"></span><?= $hex3dforumReady?'Connected':'Not connected' ?></span>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="hex3dforum_u">User ID <?php if ($hex3dforumU !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="hex3dforum_u" name="hex3dforum_u" value="<?= e($hex3dforumU) ?>" placeholder="the phpbb3_..._u cookie value (a number)">
            <label for="hex3dforum_sid" style="margin-top:12px;">Session ID (SID) <?php if ($hex3dforumSid !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="hex3dforum_sid" name="hex3dforum_sid" value="<?= e($hex3dforumSid) ?>" placeholder="the phpbb3_..._sid cookie value" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="hex3dforum_sid" aria-label="Show/hide value">👁</button>
            <label for="hex3dforum_k" style="margin-top:12px;">Login Key <span style="color:var(--muted);font-weight:400;">(optional, from "Remember me")</span> <?php if ($hex3dforumK !== ''): ?><span style="color:var(--ok);font-weight:600;">(set)</span><?php endif; ?></label>
            <input type="text" id="hex3dforum_k" name="hex3dforum_k" value="<?= e($hex3dforumK) ?>" placeholder="the phpbb3_..._k cookie value" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-mask="1" class="masked-field"><button type="button" class="reveal-btn" data-target="hex3dforum_k" aria-label="Show/hide value">👁</button>
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_hex3dforum_cookie">Save &amp; Connect</button>
              <?php if ($hex3dforumReady): ?>
              <button class="btn-ghost btn-sm" name="action" value="clear_hex3dforum_cookie" onclick="return confirm('Clear Hex3D Forum session?')">Clear</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint"><strong>hex3dpatreon.com</strong> is a phpBB forum with no API, so FarFetched uses your browser session. Setup:</p>
          <ol class="hint" style="margin:4px 0 8px 18px;padding:0;line-height:1.6;">
            <li>Log in to hex3dpatreon.com <strong>with "Remember me" checked</strong> (this creates the durable <code>_k</code> login-key cookie — without it the session dies almost immediately).</li>
            <li>Open DevTools (F12) → <strong>Application</strong> (Chrome) or <strong>Storage</strong> (Firefox) → <strong>Cookies</strong> → hex3dpatreon.com.</li>
            <li>Copy these three values into the boxes above: <code>phpbb3_…_u</code> → User ID, <code>phpbb3_…_sid</code> → Session ID, <code>phpbb3_…_k</code> → Login Key.</li>
            <li>Click <strong>Save &amp; Connect</strong> right away.</li>
          </ol>
          <p class="hint">You can paste just the value, or the whole <code>name=value</code> — FarFetched strips the cookie name for you. <strong>If you get "session expired or lacks access":</strong> the SID rotated between copying and saving. Grab a <em>fresh</em> <code>_sid</code> value and save again immediately — the SID changes frequently, the User ID and Login Key rarely do, so usually only the SID needs re-pasting. Make sure your account has active forum access, too.</p>
        </div>
        <div class="src-body">
          <p class="hint" style="margin-top:0;">Forums are discovered automatically — once your session cookie is set, the Browse page lists every forum your account can see. <strong>Heads up:</strong> hex3dpatreon.com permits a replayed session to load the forum <em>index</em> but rejects it on actual forum/topic pages unless the session ID rides along in the URL — FarFetched handles that automatically by reading the SID out of your session cookie. Browsing reads a locally-built index (below), so keep your cookie fresh enough for the crawler to run.</p>
        </div>
        <div class="src-body">
          <?php
            $hex3dState = db()->query('SELECT * FROM hex3d_crawl_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $hex3dSeen  = (int) ($hex3dState['topics_seen'] ?? 0);
            $hex3dDone  = (int) ($hex3dState['details_done'] ?? 0);
            $hex3dStatus = (string) ($hex3dState['status'] ?? 'idle');
            $hex3dErr    = (string) ($hex3dState['last_error'] ?? '');
          ?>
          <label>Local index</label>
          <p class="hint" style="margin-top:4px;">
            Status: <strong><?= e(ucfirst($hex3dStatus)) ?></strong> ·
            <strong><?= $hex3dSeen ?></strong> topics indexed ·
            <strong><?= $hex3dDone ?></strong> with thumbnails
            <?php if ($hex3dState['finished_at'] ?? ''): ?> · last finished <?= e($hex3dState['finished_at']) ?><?php endif; ?>
            <?php if ($hex3dErr !== ''): ?><br><span style="color:var(--err);"><?= e($hex3dErr) ?></span><?php endif; ?>
          </p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="hex3dforum_crawl"<?= $hex3dStatus === 'running' ? ' disabled' : '' ?>>
                <?= $hex3dSeen > 0 ? 'Update index (crawl now)' : 'Build index (crawl now)' ?>
              </button>
              <button class="btn-ghost btn-sm" name="action" value="hex3dforum_crawl_restart"
                      onclick="return confirm('Kill any running crawl, reset its state, verify the session, and start a fresh crawl?');"
                      title="Stop a stuck/running crawl, clear the lock, reset state, check the session is alive, and relaunch">
                Kill &amp; restart crawl
              </button>
            </div>
          </form>
          <p class="hint">The crawler walks every accessible forum and records each model (title, thumbnail, attachments) into a local index that Browse/Search read instantly. It's <strong>incremental</strong> (re-runs only fetch new topics) and <strong>resumable</strong> (a dropped session picks up next run). The first full crawl is large and paced slowly to be polite to the board — it may span several runs. For unattended nightly updates, add a cron entry on your server:</p>
          <pre style="background:var(--panel-2,#0d0d0d);padding:10px;border-radius:6px;overflow-x:auto;font-size:12px;">0 4 * * * docker exec FarFetched php /var/www/html/webroot/hex3d_crawl.php >> /tmp/hex3d_crawl.log 2>&1</pre>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="hex3dforum_dir">Download folder</label>
            <input type="text" id="hex3dforum_dir" name="hex3dforum_dir" value="<?= e($hex3dforumDir) ?>">
            <div class="row">
              <button class="btn-primary btn-sm" name="action" value="save_hex3dforum_dir">Save &amp; Create</button>
              <span class="status" style="margin:0;"><span class="dot <?= $hex3dforumWrite?'on':'off' ?>"></span><?= $hex3dforumWrite?'Writable':'Not found / not writable' ?></span>
            </div>
          </form>
          <p class="hint">Default: <code><?= e(HEX3DFORUM_DOWNLOAD_DIR) ?></code></p>
        </div>
        <div class="src-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="_tab" value="sources">
            <label for="hex3dforum_delay">Delay between downloads (seconds)</label>
            <div class="row">
              <input type="text" class="short" id="hex3dforum_delay" name="hex3dforum_delay" inputmode="numeric" value="<?= e((string)$hex3dforumDelay) ?>">
              <button class="btn-primary btn-sm" name="action" value="save_hex3dforum_delay">Save</button>
            </div>
          </form>
          <p class="hint">Forum and topic pages are scraped HTML — keep a reasonable delay so the board isn't hammered.</p>
        </div>
  </div>
</div>
  <script src="js/theme.js"></script>
</body>
</html>
