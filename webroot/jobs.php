<?php
declare(strict_types=1);

/**
 * jobs.php — download queue status.
 * Read-only view of download_jobs + a "retry failed" action.
 * Auto-refreshes so you can watch the paced worker drain it.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

// Retry-failed action (CSRF-guarded).
$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $notice = 'Session expired — reload.';
    } elseif (($_POST['action'] ?? '') === 'retry_failed') {
        $n = $pdo->exec("UPDATE download_jobs SET status='queued', attempts=0, last_error='' WHERE status IN ('failed','error')");
        $notice = ($n ?: 0) . ' failed job(s) re-queued.';
    } elseif (($_POST['action'] ?? '') === 'clear_done') {
        $n = $pdo->exec("DELETE FROM download_jobs WHERE status='done'");
        $notice = ($n ?: 0) . ' completed job(s) cleared.';
    }
}

$counts = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM download_jobs GROUP BY status") as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
// 'error' is a failed download under a different name — count it under Failed.
$counts['failed'] = ($counts['failed'] ?? 0) + ($counts['error'] ?? 0);
unset($counts['error']);
$total = array_sum($counts);

$rows = $pdo->query(
    "SELECT * FROM download_jobs ORDER BY
       CASE status WHEN 'working' THEN 0 WHEN 'queued' THEN 1 WHEN 'failed' THEN 2
                   WHEN 'skipped' THEN 3 WHEN 'paywalled' THEN 4 WHEN 'no_files' THEN 5 ELSE 6 END, updated_at DESC
     LIMIT 500"
)->fetchAll();

$csrf = csrf_token();
$badge = static function (string $s): string {
    $map = ['done' => '#3F7D5B', 'working' => '#ff6b1a', 'queued' => '#6B6862',
            'failed' => '#B23B3B', 'skipped' => '#C9912F', 'paywalled' => '#9B59B6', 'no_files' => '#7F8C8D'];
    return $map[$s] ?? '#6B6862';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queue · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_jobs.css">
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
  }
}
</script>

</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php" class="active">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php">My Library</a>
      <a href="customize.php">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
      <a href="filament.php">My Filament</a>
      <a href="collections_view.php">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
		<button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
		<span id="theme-toggle-icon">🌙</span> Change Appearance
		</button>
    </nav>
  </aside>
  <main>
    <h1>Download Queue</h1>
    <?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>

    <?php $doneCount = (int)($counts['done'] ?? 0); $pct = $total > 0 ? (int)floor($doneCount / $total * 100) : 0; ?>
    <div class="overall">
      <div class="top">
        <span class="label"><span id="ov-done"><?= $doneCount ?></span> of <span id="ov-total"><?= $total ?></span> done</span>
        <span class="live" id="ov-live"></span>
      </div>
      <div class="track"><div class="fill" id="ov-fill" style="width:<?= $pct ?>%"></div></div>
    </div>

    <div class="stats">
      <?php foreach (['queued','working','done','failed','skipped','paywalled','no_files'] as $s): ?>
        <div class="stat"><div class="n" id="stat-<?= $s ?>"><?= (int)($counts[$s] ?? 0) ?></div><div class="l"><?= ucfirst($s) ?></div></div>
      <?php endforeach; ?>
      <div class="stat"><div class="n" id="stat-total"><?= $total ?></div><div class="l">Total</div></div>
    </div>

    <div class="toolbar">
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn-ghost" name="action" value="retry_failed">Retry failed</button>
      </form>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn-ghost" name="action" value="clear_done">Clear completed</button>
      </form>
      <span class="muted" style="font-size:12px;">Live — updates automatically.</span>
    </div>

    <table>
      <thead><tr><th>Src</th><th>Model</th><th>Model ID</th><th>Creator</th><th>Type</th><th>Status</th><th>Progress</th><th>Attempts</th><th>Actions</th></tr></thead>
      <tbody id="qbody">
        <?php if ($rows === []): ?>
          <tr><td colspan="9" class="muted">Nothing queued yet. Select models on Browse and hit Download.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr data-job="<?= (int) $r['id'] ?>">
            <?php
              $srcSlug = strtolower((string)($r['source'] ?? ''));
              $srcLabels = ['printables'=>'PT','makerworld'=>'MW','thingiverse'=>'TV','cults3d'=>'C3D','stlflix'=>'SF','creality'=>'CR'];
              $srcLabel = $srcLabels[$srcSlug] ?? ($srcSlug !== '' ? strtoupper(substr($srcSlug,0,3)) : '');
            ?>
            <td><?php if ($srcLabel !== ''): ?><span class="src-badge <?= e($srcSlug) ?>"><?= e($srcLabel) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?php
              $srcUrl = source_model_url($srcSlug, (string) $r['model_id'], (string) ($r['slug'] ?? ''));
              $label = e($r['name'] !== '' ? $r['name'] : $r['model_id']);
              if ($srcUrl !== '') {
                  echo '<a href="' . e($srcUrl) . '" target="_blank" rel="noopener" title="View on source site" style="color:inherit;text-decoration:none;border-bottom:1px dotted var(--line);">' . $label . ' ↗</a>';
              } else {
                  echo $label;
              }
            ?></td>
            <td class="muted"><?= e($r['model_id']) ?></td>
            <td class="muted"><?= e($r['creator']) ?></td>
            <td><?= e($r['file_type']) ?></td>
            <td><span class="tag" style="background:<?= $badge($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td class="rowprog"></td>
            <td><?= (int) $r['attempts'] ?></td>
            <td class="act">
              <button data-act="restart" data-id="<?= (int) $r['id'] ?>">↻ Restart</button>
              <button class="rm" data-act="delete" data-id="<?= (int) $r['id'] ?>">✕ Remove</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <!-- Chef's pass: passive, read-only worker activity feed. -->
    <section id="chefpass" style="margin-top:22px;border:1px solid var(--line);border-radius:12px;background:var(--card);overflow:hidden;">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);background:var(--panel);">
        <strong style="font-size:13px;letter-spacing:.02em;">Worker activity</strong>
        <span id="cp-summary" style="font-size:12px;color:var(--muted);"></span>
      </div>
      <pre id="cp-log" style="margin:0;padding:12px 16px;max-height:220px;overflow-y:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.55;color:var(--ink);white-space:pre-wrap;word-break:break-word;">Waiting for worker activity…</pre>
    </section>

    <!-- Bulk CSV import. -->
    <section id="csvimport" style="margin-top:22px;border:1px solid var(--line);border-radius:12px;background:var(--card);overflow:hidden;">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);background:var(--panel);">
        <strong style="font-size:13px;letter-spacing:.02em;">Bulk import from CSV</strong>
        <a href="csv_import.php?template=1" style="font-size:12px;color:var(--accent,#ff6b1a);text-decoration:none;">⬇ Download template</a>
      </div>
      <div style="padding:14px 16px;">
        <p style="margin:0 0 10px;font-size:13px;color:var(--muted);">
          Columns: <code>Model URL</code> (required), <code>Source Thumbnail</code> (y/n),
          <code>Collection</code> (name of an existing collection), <code>Favorites</code> (y/n).
          One model per row. Supported sources: Printables, MakerWorld, Thingiverse, Cults3D, STLFlix, Creality.
        </p>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="file" id="csvFile" accept=".csv,text/csv" style="font-size:13px;">
          <button type="button" id="csvImportBtn" class="btn-primary" style="padding:7px 14px;">Import CSV</button>
          <span id="csvStatus" style="font-size:13px;color:var(--muted);"></span>
        </div>
        <pre id="csvErrors" style="display:none;margin:12px 0 0;padding:10px 12px;background:var(--panel);border-radius:8px;font-size:12px;line-height:1.5;color:var(--ink);white-space:pre-wrap;max-height:180px;overflow-y:auto;"></pre>
      </div>
    </section>
  </main>

  <script>
  (function () {
    const CSRF_IMPORT = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
    const fileEl = document.getElementById('csvFile');
    const btn = document.getElementById('csvImportBtn');
    const status = document.getElementById('csvStatus');
    const errBox = document.getElementById('csvErrors');
    if (btn) btn.addEventListener('click', async () => {
      if (!fileEl || !fileEl.files || !fileEl.files[0]) { status.textContent = 'Choose a CSV file first.'; return; }
      btn.disabled = true; status.textContent = 'Importing…'; errBox.style.display = 'none';
      const fd = new FormData();
      fd.append('csrf', CSRF_IMPORT);
      fd.append('file', fileEl.files[0]);
      try {
        const res = await fetch('csv_import.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.ok) {
          status.textContent = `Queued ${d.queued}, skipped ${d.skipped}, favorites ${d.favorites}` +
            (d.errors && d.errors.length ? `, ${d.errors.length} issue(s)` : '') + '.';
          if (d.errors && d.errors.length) { errBox.textContent = d.errors.join('\n'); errBox.style.display = 'block'; }
          fileEl.value = '';
        } else {
          status.textContent = 'Failed: ' + (d.error || 'unknown error');
        }
      } catch (e) {
        status.textContent = 'Network error.';
      } finally { btn.disabled = false; }
    });
  })();
  </script>

  <script>
  (function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const BADGE = { done:'#3F7D5B', working:'#ff6b1a', queued:'#6B6862', failed:'#B23B3B', skipped:'#C9912F', paywalled:'#9B59B6', no_files:'#7F8C8D', error:'#B23B3B' };
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const fmtBytes = b => { if (!b) return ''; const u=['B','KB','MB','GB']; let i=0,n=b; while(n>=1024&&i<u.length-1){n/=1024;i++;} return n.toFixed(n<10&&i>0?1:0)+' '+u[i]; };
    const fmtClock = s => { s=Math.max(0,s|0); const m=(s/60)|0, ss=s%60; return m+':'+String(ss).padStart(2,'0'); };

    const body = document.getElementById('qbody');
    const ovDone = document.getElementById('ov-done'), ovTotal = document.getElementById('ov-total');
    const ovFill = document.getElementById('ov-fill'), ovLive = document.getElementById('ov-live');

    let lastSig = '';            // skip DOM work when nothing changed
    let countdown = null;        // {remaining, file} for local pace ticking
    let active = null;
    let jobsById = {};           // id -> job (so progress updates know row status)
    let nextQueuedId = null;     // the job the worker will pick up next
    let hasWorking = false;      // is any row actively being processed

    function rowProgressHTML(job) {
      if (!job || !active) return '';
      const isWorkingRow = (job.status === 'working' && active.job_id === job.id);

      if (active.phase === 'downloading' && isWorkingRow) {
        const sized = active.total ? (fmtBytes(active.bytes) + ' / ' + fmtBytes(active.total)) : fmtBytes(active.bytes);
        const fileCounter = (active.file_num && active.file_total)
          ? '<span class="file-counter">' + active.file_num + '/' + active.file_total + '</span> '
          : '';
        if (active.percent === null || !active.total) {
          return '<div class="rowbar indet"><div class="rowfill green"></div></div>'
               + '<span class="rowprog">' + fileCounter + 'downloading…' + (sized ? ' ' + esc(sized) : '') + '</span>';
        }
        return greenBar(active.percent) + '<span class="rowprog">' + fileCounter + active.percent + '% · ' + esc(sized) + '</span>';
      }

      if (active.phase === 'waiting') {
        // The pacing wait, rendered as a green bar that fills as it counts down.
        const pct = pacePct(active.remaining, active.delay);
        if (isWorkingRow) {
          return greenBar(pct, true) + '<span class="rowprog">next file in <span class="cd">' + fmtClock(active.remaining) + '</span></span>';
        }
        if (!hasWorking && job.status === 'queued' && job.id === nextQueuedId) {
          return greenBar(pct, true) + '<span class="rowprog">starts in <span class="cd">' + fmtClock(active.remaining) + '</span></span>';
        }
      }
      return '';
    }

    // A green fill bar. `pacing` tags it so the local 1s ticker can keep it moving.
    function greenBar(pct, pacing) {
      return '<div class="rowbar"><div class="rowfill green' + (pacing ? ' pacefill' : '') +
             '" style="width:' + Math.max(0, Math.min(100, pct)) + '%"></div></div>';
    }
    function pacePct(remaining, delay) {
      return (delay > 0) ? (1 - remaining / delay) * 100 : 0;
    }

    function render(data) {
      active = data.active && data.active.phase && data.active.phase !== 'idle' ? data.active : null;
      jobsById = {};
      data.jobs.forEach(j => { jobsById[j.id] = j; });
      // Worker claims oldest queued first; find that next-up job for the countdown.
      hasWorking = data.jobs.some(j => j.status === 'working');
      nextQueuedId = null;
      let minId = Infinity;
      data.jobs.forEach(j => { if (j.status === 'queued' && j.id < minId) { minId = j.id; nextQueuedId = j.id; } });

      // Overall bar
      const total = data.counts.total, done = data.counts.done;
      ovDone.textContent = done; ovTotal.textContent = total;
      ovFill.style.width = (total > 0 ? Math.floor(done / total * 100) : 0) + '%';
      // Update stat cards
      const by = data.counts.by || {};
      ['queued','working','done','failed','skipped','paywalled','no_files'].forEach(s => {
        const el = document.getElementById('stat-' + s);
        if (el) el.textContent = by[s] ?? 0;
      });
      const elTotal = document.getElementById('stat-total');
      if (elTotal) elTotal.textContent = total;

      // Live status line
      if (active && active.phase === 'downloading') {
        const sized = active.total ? (' · ' + fmtBytes(active.bytes) + ' / ' + fmtBytes(active.total)) : '';
        ovLive.innerHTML = '<span class="dot"></span>Downloading ' + esc(active.file) +
          (active.percent !== null ? ' — ' + active.percent + '%' : '') + sized;
      } else if (active && active.phase === 'waiting') {
        ovLive.innerHTML = '<span class="dot"></span>Pacing — next download in <span class="cd">' + fmtClock(active.remaining) + '</span>';
      } else {
        ovLive.textContent = (data.counts.by && (data.counts.by.queued || data.counts.by.working)) ? 'Worker idle — runs on the next cron tick.' : 'Idle.';
      }

      // Rows (re-render only when the row set/statuses change; progress handled separately)
      const sig = JSON.stringify(data.jobs);
      if (sig !== lastSig) {
        lastSig = sig;
        if (!data.jobs.length) {
          body.innerHTML = '<tr><td colspan="9" class="muted">Nothing queued yet. Select models on Browse and hit Download.</td></tr>';
        } else {
          body.innerHTML = data.jobs.map(j =>
            '<tr data-job="' + j.id + '">' +
            (function(s){ const m={'printables':'PT','makerworld':'MW','thingiverse':'TV','cults3d':'C3D','stlflix':'SF','creality':'CR'}; const lbl = m[s] || (s ? s.substring(0,3).toUpperCase() : ''); return '<td>' + (lbl ? '<span class="src-badge '+s+'">'+lbl+'</span>' : '<span class="muted">—</span>') + '</td>'; })(j.source||'') +
            '<td>' + esc(j.name || j.model_id) + '</td>' +
            '<td class="muted">' + esc(j.model_id) + '</td>' +
            '<td class="muted">' + esc(j.creator) + '</td>' +
            '<td>' + esc(j.file_type) + '</td>' +
            '<td><span class="tag" style="background:' + (BADGE[j.status] || '#6B6862') + '">' + esc(j.status) + '</span></td>' +
            '<td class="progcell">' + rowProgressHTML(j) + '</td>' +
            '<td>' + j.attempts + '</td>' +
            '<td class="act">' +
              '<button data-act="restart" data-id="' + j.id + '">↻ Restart</button>' +
              '<button class="rm" data-act="delete" data-id="' + j.id + '">✕ Remove</button>' +
            '</td>' +
            '</tr>'
          ).join('');
        }
      } else {
        // same rows — just refresh the active row's progress cell
        document.querySelectorAll('#qbody tr').forEach(tr => {
          const id = parseInt(tr.getAttribute('data-job'), 10);
          const cell = tr.querySelector('.progcell');
          if (cell) cell.innerHTML = rowProgressHTML(jobsById[id]);
        });
      }

      countdown = (active && active.phase === 'waiting') ? { remaining: active.remaining, delay: active.delay || 0 } : null;

      renderChefPass(data);
    }

    function renderChefPass(data) {
      const log = document.getElementById('cp-log');
      const sum = document.getElementById('cp-summary');
      if (log) {
        const lines = Array.isArray(data.feed) ? data.feed : [];
        if (lines.length) {
          const stick = (log.scrollTop + log.clientHeight >= log.scrollHeight - 24); // autoscroll only if near bottom
          log.textContent = lines.join('\n');
          if (stick) log.scrollTop = log.scrollHeight;
        }
      }
      if (sum) {
        const by = data.counts.by || {};
        const q = by.queued || 0, w = by.working || 0;
        const parts = [];
        parts.push(q + ' queued' + (w ? ' · ' + w + ' working' : ''));
        if (data.eta_seconds > 0) parts.push('~' + fmtClock(data.eta_seconds) + ' to drain');
        if (data.pace) parts.push('pace ' + data.pace.makerworld + 's MW / ' + data.pace.printables + 's PB');
        parts.push('transfer time excluded');
        sum.textContent = parts.join('  ·  ');
      }
    }

    let pollTimer = null;
    let pollRate = 0;          // current interval in ms (0 = none)
    const RATE_ACTIVE = 1500;  // queue has working/queued/failed jobs
    const RATE_IDLE   = 8000;  // queue drained — still poll slowly so jobs

                               // enqueued from another tab/device are picked up

    function setPolling(rate) {
      if (rate === pollRate && pollTimer) return;  // already at this rate
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
      pollRate = rate;
      if (rate > 0) pollTimer = setInterval(poll, rate);
    }
    function startPolling() { setPolling(RATE_ACTIVE); }
    function stopPolling()  { setPolling(0); }

    async function poll() {
      try {
        const r = await fetch('jobs_status.php', { cache: 'no-store' });
        const data = await r.json();
        const hasJobs = (data.counts.by && (data.counts.by.queued || data.counts.by.working || data.counts.by.failed));
        render(data);
        // Stay subscribed either way; just throttle when idle so a job added
        // elsewhere is still reflected here within a few seconds.
        setPolling(hasJobs ? RATE_ACTIVE : RATE_IDLE);
      } catch (e) { /* transient; next tick retries */ }
    }

    // Local 1s tick: keep the countdown text AND the green pacing bar moving
    // smoothly between server polls.
    setInterval(() => {
      if (!countdown) return;
      countdown.remaining = Math.max(0, countdown.remaining - 1);
      const txt = fmtClock(countdown.remaining);
      const w = (countdown.delay > 0 ? (1 - countdown.remaining / countdown.delay) * 100 : 0);
      document.querySelectorAll('.cd').forEach(el => el.textContent = txt);
      document.querySelectorAll('.pacefill').forEach(el => el.style.width = Math.max(0, Math.min(100, w)) + '%');
    }, 1000);

    // Per-row actions (event-delegated so it survives live re-renders).
    body.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('button[data-act]');
      if (!btn) return;
      const act = btn.getAttribute('data-act');
      const id = btn.getAttribute('data-id');
      if (act === 'delete' && !confirm('Remove this job from the queue?')) return;
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('action', act);
        fd.append('id', id);
        fd.append('csrf', CSRF);
        const r = await fetch('job_action.php', { method: 'POST', body: fd });
        // A genuinely expired session (403) can't be fixed by the stale in-page
        // token — reload once to mint a fresh one instead of dead-ending.
        if (r.status === 403) { location.reload(); return; }
        const j = await r.json();
        if (!j.ok) {
          if (/session expired/i.test(j.msg || '')) { location.reload(); return; }
          alert(j.msg || 'Action failed.');
        }
      } catch (e) {
        alert('Action failed — reload and try again.');
      }
      lastSig = '';   // force a full re-render on the next poll
      poll();
    });

    poll();
    startPolling();

    // Pause polling while the tab is in the background; resume + refresh on return.
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        stopPolling();
      } else {
        poll();
      }
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

<script type="module">
  // Background thumbnail trickle. While the queue page is open, this quietly
  // generates thumbnails for any downloaded model that doesn't have one yet —
  // a few at a time, with the WebGL context torn down between passes. This is
  // what makes thumbnails appear shortly after a download finalizes, and it
  // backfills anything missed by headless downloads. Deliberately gentle: it
  // never processes a large burst, so it can't crash the tab the way a full
  // manual batch can.
  import { createViewer } from './js/viewer-core.js';

  const CSRF = <?= json_encode($csrf) ?>;
  const PASS_SIZE = 8;        // models generated per pass
  const PASS_INTERVAL = 30000; // ms between passes
  let passRunning = false;

  const fileUrl = (src, model, rel) =>
    'model_file.php?src=' + encodeURIComponent(src) +
    '&model=' + encodeURIComponent(model) +
    '&file=' + encodeURIComponent(rel);

  async function saveThumb(src, model, png) {
    const res = await fetch('save_thumb.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, src, model, png }),
    });
    const j = await res.json().catch(() => ({}));
    return res.ok && j.ok;
  }

  function loadWithTimeout(v, url, ext, ms = 20000) {
    return Promise.race([
      v.loadFile(url, ext),
      new Promise((_, rej) => setTimeout(() => rej(new Error('Timed out')), ms)),
    ]);
  }

  async function runPass() {
    if (passRunning || document.hidden) return;
    passRunning = true;
    try {
      const res = await fetch('thumb_pending.php?limit=' + PASS_SIZE, { cache: 'no-store' });
      const pending = await res.json();
      if (!Array.isArray(pending) || pending.length === 0) return;

      // One context for the whole pass; torn down at the end.
      const host = document.createElement('div');
      host.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:512px;height:512px;';
      document.body.appendChild(host);
      const v = createViewer(host, { background: 0x16140f, showGrid: false });

      try {
        for (const m of pending) {
          if (document.hidden) break; // pause when tab not visible
          const ext = m.firstfile.split('.').pop().toLowerCase();
          try {
            await loadWithTimeout(v, fileUrl(m.src, m.folder, m.firstfile), ext);
            await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
            const png = v.capturePNG(512);
            await saveThumb(m.src, m.folder, png);
          } catch (_) { /* skip corrupt/empty; manual batch surfaces these */ }
          await new Promise(r => setTimeout(r, 0));
        }
      } finally {
        v.dispose();
        host.remove();
      }
    } catch (_) {
      /* network/parse hiccup — try again next interval */
    } finally {
      passRunning = false;
    }
  }

  // Kick off shortly after load, then on a gentle interval. Also run when the
  // tab regains focus (a download may have finished while it was hidden).
  setTimeout(runPass, 4000);
  setInterval(runPass, PASS_INTERVAL);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) runPass(); });
</script>

  <script src="js/theme.js"></script>
</body>
</html>
