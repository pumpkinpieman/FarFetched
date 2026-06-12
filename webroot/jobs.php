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
<?php $hasActive = ($counts['queued'] ?? 0) + ($counts['working'] ?? 0) > 0; ?>
<?php if ($hasActive): ?><meta http-equiv="refresh" content="15"><?php endif; ?>
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 20px;letter-spacing:-0.3px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
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
</style>
</head>
<body>
  <aside>
    <div class="brand">◆ FarFetched</div>
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

    <div class="stats">
      <?php foreach (['queued','working','done','failed','skipped'] as $s): ?>
        <div class="stat"><div class="n"><?= (int)($counts[$s] ?? 0) ?></div><div class="l"><?= $s ?></div></div>
      <?php endforeach; ?>
      <div class="stat"><div class="n"><?= $total ?></div><div class="l">total</div></div>
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
      <span class="muted" style="font-size:12px;">
        <?= $hasActive ? 'Auto-refreshing every 15s while jobs are active.' : 'Idle.' ?>
      </span>
    </div>

    <table>
      <thead><tr><th>Model</th><th>Model ID</th><th>Creator</th><th>Type</th><th>Status</th><th>Attempts</th></tr></thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="6" class="muted">Nothing queued yet. Select models on Browse and hit Download.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name'] !== '' ? $r['name'] : $r['model_id']) ?></td>
            <td class="muted"><?= e($r['model_id']) ?></td>
            <td class="muted"><?= e($r['creator']) ?></td>
            <td><?= e($r['file_type']) ?></td>
            <td><span class="tag" style="background:<?= $badge($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td><?= (int) $r['attempts'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </main>
</body>
</html>
