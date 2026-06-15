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
    $map = ['done' => '#3F7D5B', 'working' => '#C2613F', 'queued' => '#6B6862',
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
<style>
  :root{--bg:#0c0a08;--panel:#141009;--card:#1a140c;--ink:#f0e6d3;--muted:#7a6a52;--line:#2e2218;--clay:#ff6b1a;--clay-deep:#c44d0d;--ok:#c8a020;--err:#e05c5c;--warn:#f5c842;}
  body{background-image:radial-gradient(ellipse at 0% 100%, rgba(255,107,26,0.06) 0%, transparent 60%);}
  .brand{color:#ff6b1a !important;font-weight:800 !important;letter-spacing:-.5px;}
  nav a{color:#8a8070;}
  nav a:hover{background:#1a140c;color:#f0e6d3;}
  nav a.active{background:rgba(255,107,26,0.1);color:#ff6b1a;border:1px solid rgba(255,107,26,0.2);font-weight:600;}
  .msize{color:#f5c842 !important;}
  .btn-primary{background:#ff6b1a;color:#fff;} .btn-primary:hover{background:#c44d0d;}
  .btn-primary:disabled{background:#2e1a0a;color:#5a4a32;cursor:not-allowed;}
  .btn-ghost{color:#8a8070;border-color:#2e2218;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(245,200,66,0.08);color:#f5c842;}
  select{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .card.sel{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.2);}
  .pick{accent-color:#ff6b1a;}
  .banner{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .notice.ok{background:#1a1200;color:#f5c842;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .badge.paid{background:#3d2000;color:#f5c842;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2e2218;}
  th{background:#141009;}
  .pill.fetch{background:#1a1200;color:#f5c842;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .track{background:#2e2218;} .fill{background:#ff6b1a;}
  .rowfill{background:#ff6b1a;} .rowfill.green{background:#c8a020;}
  .overall .live .dot{background:#ff6b1a;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2e2218;color:#f0e6d3;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#f5c842;box-shadow:0 0 0 2px rgba(245,200,66,0.15);}
  .folder-hdr{border-color:#2e2218;color:#7a6a52;}
  .bar{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .file-counter{background:#2e2218;color:#f0e6d3;}
  .srcBtn{color:#7a6a52;}
  .navlabel{color:#5a4a32;}
  code{background:#1a140c;}
  .notice{background:#1a140c;}
  .step a{color:#ff6b1a;}
  .act button{background:#1a140c;border-color:#2e2218;color:#7a6a52;}
  .tag{background:#ff6b1a;}

  body{background-image:radial-gradient(circle,rgba(57,168,92,.06) 1px,transparent 1px);background-size:24px 24px;}
  .brand{color:#ff6b1a !important;font-family:ui-monospace,monospace !important;letter-spacing:-.5px;}
  nav a:hover{background:#1a140c;color:#e8ede9;}
  nav a.active{background:rgba(255,107,26,.1);color:#ff6b1a;border:1px solid rgba(57,168,92,.2);font-weight:500;}
  nav a:not(.active){color:#c8d4c9;}
  .msize{color:#f5a623 !important;}
  .btn-primary{background:#39a85c;color:#0a1a0e;} .btn-primary:hover{background:#2a7d44;}
  .btn-primary:disabled{background:#1c3023;color:#6b8070;cursor:not-allowed;}
  .btn-ghost{color:#c8d4c9;border-color:#2a3028;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(255,107,26,.1);color:#ff6b1a;}
  select{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .card.sel{border-color:#d4820a;box-shadow:0 0 0 2px rgba(255,107,26,.2);}
  .pick{accent-color:#d4820a;}
  .banner{background:#1a1500;color:#f5a623;border-color:#3d3000;}
  .notice.ok,.notice{background:#0d1f12;color:#ff6b1a;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .notice.warn{background:#1a1200;color:#d4820a;}
  .badge.paid{background:#3d2600;color:#f5a623;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2a3028;}
  th{background:#161a17;}
  .pill.fetch{background:#0d1f12;color:#ff6b1a;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .track{background:#2a3028;} .fill{background:#39a85c;}
  .rowfill.green{background:#39a85c;}
  .overall .live .dot{background:#39a85c;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2a3028;color:#e8ede9;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#d4820a;box-shadow:0 0 0 2px rgba(212,130,10,.2);}
  .folder-hdr{border-color:#2a3028;color:#6b8070;}
  .bar{background:#1a1500;color:#f5a623;border-color:#3d3000;}

  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 20px;letter-spacing:-0.3px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#1a140c;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;letter-spacing:-0.4px;margin-bottom:18px;}
  .stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:12px 18px;min-width:96px;}
  .stat .n{font-size:22px;font-weight:600;} .stat .l{font-size:12px;color:var(--muted);text-transform:capitalize;}
  .toolbar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;}
  button{font:inherit;cursor:pointer;border:none;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:500;}
  .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--line);} .btn-ghost:hover{border-color:var(--clay);color:var(--clay-deep);}
  .notice{background:#E8F1EC;color:#3F7D5B;padding:10px 14px;border-radius:9px;font-size:14px;margin-bottom:16px;}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;}
  th,td{text-align:left;padding:11px 14px;font-size:13px;border-bottom:1px solid var(--line);}
  th{background:var(--panel);font-weight:600;color:var(--muted);}
  tr:last-child td{border-bottom:none;}
  .tag{display:inline-block;padding:2px 9px;border-radius:20px;color:#fff;font-size:11px;font-weight:600;text-transform:capitalize;}
  .err{color:#B23B3B;font-size:12px;} .muted{color:var(--muted);}
  /* live progress */
  .overall{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:20px;}
  .overall .top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:9px;gap:12px;flex-wrap:wrap;}
  .overall .label{font-size:14px;font-weight:600;}
  .overall .live{font-size:13px;color:var(--clay-deep);}
  .overall .live .dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--clay);margin-right:6px;animation:pulse 1.2s ease-in-out infinite;}
  @keyframes pulse{0%,100%{opacity:.35;}50%{opacity:1;}}
  .track{height:9px;border-radius:6px;background:var(--line);overflow:hidden;}
  .fill{height:100%;background:linear-gradient(90deg,var(--clay),var(--clay-deep));width:0;transition:width .4s ease;}
  .rowbar{height:6px;border-radius:4px;background:var(--line);overflow:hidden;width:120px;margin-top:4px;}
  .rowfill{height:100%;background:var(--clay);width:0;transition:width .25s ease;}
  .rowfill.green{background:#3F7D5B;}
  .pacefill{transition:width 1s linear;}
  .rowbar.indet{position:relative;}
  .rowbar.indet .rowfill{width:40%;transition:none;position:absolute;animation:indet 1.1s ease-in-out infinite;}
  @keyframes indet{0%{left:-40%;}100%{left:100%;}}
  .rowprog{font-size:11px;color:var(--muted);white-space:nowrap;}
  .file-counter{display:inline-block;font-size:11px;font-weight:600;color:var(--fg);background:var(--line);border-radius:3px;padding:0 4px;margin-right:3px;}
  .act{display:inline-flex;gap:6px;}
  .act button{padding:5px 9px;font-size:12px;border:1px solid var(--line);background:var(--card);color:var(--muted);border-radius:7px;}
  .act button:hover{border-color:var(--clay);color:var(--clay-deep);}
  .act button.rm:hover{border-color:#B23B3B;color:#B23B3B;}
</style>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php" class="active">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="settings.php">Settings</a>
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
      <thead><tr><th>Model</th><th>Model ID</th><th>Creator</th><th>Type</th><th>Status</th><th>Progress</th><th>Attempts</th><th>Actions</th></tr></thead>
      <tbody id="qbody">
        <?php if ($rows === []): ?>
          <tr><td colspan="8" class="muted">Nothing queued yet. Select models on Browse and hit Download.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr data-job="<?= (int) $r['id'] ?>">
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
    const BADGE = { done:'#3F7D5B', working:'#C2613F', queued:'#6B6862', failed:'#B23B3B', skipped:'#C9912F', error:'#B23B3B' };
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
          body.innerHTML = '<tr><td colspan="8" class="muted">Nothing queued yet. Select models on Browse and hit Download.</td></tr>';
        } else {
          body.innerHTML = data.jobs.map(j =>
            '<tr data-job="' + j.id + '">' +
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
</body>
</html>
