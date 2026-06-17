<?php
declare(strict_types=1);

/**
 * jobs.php — download queue status.
 * Read-only view of download_jobs + a "retry failed" action.
 * Auto-refreshes so you can watch the paced worker drain it.
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

// Retry-failed action (CSRF-guarded).
$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $notice = 'Session expired — reload.';
    } elseif (($_POST['action'] ?? '') === 'retry_failed') {
        $n = $pdo->exec("UPDATE download_jobs SET status='queued', attempts=0, last_error='' WHERE status='failed'");
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
$total = array_sum($counts);

$rows = $pdo->query(
    "SELECT * FROM download_jobs ORDER BY
       CASE status WHEN 'working' THEN 0 WHEN 'queued' THEN 1 WHEN 'failed' THEN 2
                   WHEN 'skipped' THEN 3 ELSE 4 END, updated_at DESC
     LIMIT 500"
)->fetchAll();

$csrf = csrf_token();
$badge = static function (string $s): string {
    $map = ['done' => '#3F7D5B', 'working' => '#ff6b1a', 'queued' => '#6B6862',
            'failed' => '#B23B3B', 'skipped' => '#C9912F'];
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

</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php" class="active">Queue</a>
      <a href="viewer.php">3D Viewer</a>
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
      <?php foreach (['queued','working','done','failed','skipped'] as $s): ?>
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
            <td><?= e($r['name'] !== '' ? $r['name'] : $r['model_id']) ?></td>
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
  </main>

  <script>
  (function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const BADGE = { done:'#3F7D5B', working:'#ff6b1a', queued:'#6B6862', failed:'#B23B3B', skipped:'#C9912F', error:'#B23B3B' };
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
      ['queued','working','done','failed','skipped'].forEach(s => {
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
    let lastQueueEmpty = false;

    function startPolling() {
      if (pollTimer) return;
      pollTimer = setInterval(poll, 1500);
    }
    function stopPolling() {
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    async function poll() {
      try {
        const r = await fetch('jobs_status.php', { cache: 'no-store' });
        const data = await r.json();
        const hasJobs = (data.counts.by && (data.counts.by.queued || data.counts.by.working || data.counts.by.failed));
        render(data);
        if (!hasJobs) {
          stopPolling();
          lastQueueEmpty = true;
        } else {
          lastQueueEmpty = false;
          startPolling();
        }
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
        const j = await r.json();
        if (!j.ok) alert(j.msg || 'Action failed.');
      } catch (e) {
        alert('Action failed — reload and try again.');
      }
      lastSig = '';   // force a full re-render on the next poll
      poll();
    });

    poll();
    startPolling();
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

</body>
</html>
