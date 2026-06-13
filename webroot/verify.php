<?php
declare(strict_types=1);

/**
 * verify.php — API seam probe (LOCAL DIAGNOSTIC).
 *
 * Runs each reverse-engineered call with its own cURL and dumps the RAW JSON
 * response, so you can compare actual field names against what
 * PrintablesService.php assumes. This file deliberately does NOT use the
 * service — it's the ground truth you check the service against.
 *
 * Covers: [token] me · [S1/S2] morePrints search · [S4] print() files ·
 *         [S5] getDownloadLink mutation (shows link, does NOT download).
 *
 * Delete or block this file once the seams are confirmed.
 */

require_once __DIR__ . '/bootstrap.php';

$token = get_token();
$probe = $_GET['probe'] ?? '';
$result = null;
$sentQuery = null;

function api_raw(string $token, array $payload): array
{
    $ch = curl_init('https://api.printables.com/graphql/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/116 Safari/537.36',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => $body === false ? '' : (string) $body, 'curl_err' => $err];
}

function pretty(string $json): string
{
    $d = json_decode($json, true);
    return $d === null ? $json : json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if ($token !== '' && $probe !== '') {
    if ($probe === 'token') {
        $payload = ['query' => '{ me { id publicUsername } }'];
    } elseif ($probe === 'search') {
        $catid = trim((string) ($_GET['catid'] ?? '')); // optional numeric categoryId
        $payload = [
            'query' => 'query ModelList($limit:Int!,$cursor:String,$categoryId:ID,$ordering:String){
                morePrints(limit:$limit,cursor:$cursor,categoryId:$categoryId,ordering:$ordering){
                  cursor
                  items { id slug name user { publicUsername } image { filePath } }
                }
            }',
            'variables' => [
                'limit' => 3, 'cursor' => null,
                'categoryId' => $catid !== '' ? $catid : null,
                'ordering' => 'trending',
            ],
        ];
    } elseif ($probe === 'files') {
        $model = trim((string) ($_GET['model'] ?? ''));
        $payload = [
            'query' => 'query F($id:ID!){ print(id:$id){ id name stls { id name fileSize } gcodes { id name } } }',
            'variables' => ['id' => $model],
        ];
    } elseif ($probe === 'introspect') {
        // Ask the API for every field on PrintType — no more guessing names.
        $payload = [
            'query' => 'query Introspect { __type(name: "PrintType") { fields { name type { name kind ofType { name kind } } } } }',
            'variables' => [],
        ];
    } elseif ($probe === 'pack') {
        // Hunt for the model "download all as ZIP" URL field on the print object.
        // Tries several likely names; the API returns null for ones that exist
        // but are empty, and errors only for names that don't exist at all.
        $model = trim((string) ($_GET['model'] ?? ''));
        $payload = [
            'query' => 'query P($id:ID!){
                print(id:$id){
                  id
                  name
                  filesType
                  downloadPackUrl
                  filesPackUrl
                  packUrl
                  zipUrl
                  packs { id name fileType filePath fileSize }
                }
            }',
            'variables' => ['id' => $model],
        ];
    } elseif ($probe === 'link') {
        $file  = trim((string) ($_GET['file'] ?? ''));
        $model = trim((string) ($_GET['model'] ?? ''));
        $type  = strtolower(trim((string) ($_GET['type'] ?? 'stl')));
        $payload = [
            'query' => 'mutation L($id:ID!,$modelId:ID!,$fileType:DownloadFileTypeEnum!,$source:DownloadSourceEnum!){
                getDownloadLink(id:$id,printId:$modelId,fileType:$fileType,source:$source){
                  ok output { link ttl } errors { field messages }
                }
            }',
            'variables' => ['id' => $file, 'modelId' => $model, 'fileType' => $type, 'source' => 'model_detail'],
        ];
    }

    if (isset($payload)) {
        $sentQuery = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = api_raw($token, $payload);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify Seams · FarFetched</title>
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;--ok:#3F7D5B;--err:#B23B3B;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 20px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;max-width:860px;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;margin-bottom:6px;}
  .sub{color:var(--muted);font-size:14px;margin-bottom:22px;line-height:1.5;}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
  h2{font-size:14px;font-weight:600;margin-bottom:4px;} .seam{font-size:12px;color:var(--muted);margin-bottom:12px;}
  label{font-size:12px;font-weight:600;display:block;margin:8px 0 4px;}
  input{border:1px solid var(--line);border-radius:8px;padding:8px 11px;font:13px ui-monospace,monospace;background:var(--bg);width:280px;max-width:100%;}
  input:focus{outline:none;border-color:var(--clay);}
  button{font:inherit;cursor:pointer;border:none;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:500;background:var(--clay);color:#fff;margin-top:10px;}
  button:hover{background:var(--clay-deep);}
  .inline{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
  pre{background:#1f1e1c;color:#e8e5da;border-radius:10px;padding:16px;overflow:auto;font:12px ui-monospace,monospace;line-height:1.5;max-height:460px;}
  .meta{font-size:13px;margin-bottom:8px;} .ok{color:var(--ok);font-weight:600;} .err{color:var(--err);font-weight:600;}
  .warn{background:#FBF1D9;color:#8A6D1F;border:1px solid #ECD9A6;padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:18px;}
  code{background:var(--panel);padding:1px 5px;border-radius:4px;font-size:12px;}
</style>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="settings.php">Settings</a>
      <a href="verify.php" class="active">Verify Seams</a>
    </nav>
  </aside>
  <main>
    <h1>Verify API Seams</h1>
    <p class="sub">Runs each reverse-engineered call and shows the <strong>raw JSON</strong>. Compare the real field names here against what <code>PrintablesService.php</code> assumes, then adjust the service if they differ.</p>

    <?php if ($token === ''): ?>
      <div class="warn">No token stored. Add one in <a href="settings.php">Settings</a> first.</div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
      <div class="panel">
        <div class="meta">
          HTTP <strong><?= (int) $result['status'] ?></strong>
          <?php if ($result['status'] === 200 && empty(json_decode($result['body'], true)['errors'])): ?>
            <span class="ok">— looks OK</span>
          <?php else: ?>
            <span class="err">— check errors below</span>
          <?php endif; ?>
          <?php if ($result['curl_err'] !== ''): ?><span class="err"> · cURL: <?= e($result['curl_err']) ?></span><?php endif; ?>
        </div>
        <?php if ($sentQuery): ?><label>Sent</label><pre><?= e($sentQuery) ?></pre><?php endif; ?>
        <label>Response</label>
        <pre><?= e(pretty($result['body'])) ?></pre>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Token check</h2><div class="seam">[token] — confirms the <code>me</code> field name</div>
      <a href="?probe=token"><button type="button">Run token probe</button></a>
    </div>

    <div class="panel">
      <h2>Category search</h2><div class="seam">[S1/S2] — confirms <code>morePrints</code> fields + a numeric categoryId</div>
      <form method="get" class="inline">
        <input type="hidden" name="probe" value="search">
        <div><label>categoryId (blank = all)</label><input name="catid" placeholder="e.g. 4"></div>
        <button>Run search probe</button>
      </form>
    </div>

    <div class="panel">
      <h2>Model files</h2><div class="seam">[S4] — confirms <code>print()</code> + file field/enum names</div>
      <form method="get" class="inline">
        <input type="hidden" name="probe" value="files">
        <div><label>model id</label><input name="model" placeholder="numeric id from a model URL"></div>
        <button>Run files probe</button>
      </form>
    </div>

    <div class="panel">
      <h2>Model ZIP pack</h2><div class="seam">[ZIP] — finds the "download all as ZIP" URL field on the model</div>
      <form method="get" class="inline">
        <input type="hidden" name="probe" value="pack">
        <div><label>model id</label><input name="model" placeholder="numeric id (e.g. 1744585)"></div>
        <button>Run pack probe</button>
      </form>
    </div>

    <div class="panel">
      <h2>List PrintType fields</h2><div class="seam">[INTROSPECT] — dumps every real field name on a model (find the ZIP one here)</div>
      <form method="get" class="inline">
        <input type="hidden" name="probe" value="introspect">
        <button>List all fields</button>
      </form>
    </div>

    <div class="panel">
      <h2>Download link</h2><div class="seam">[S5] — confirms the mutation + enum values (no file is downloaded)</div>
      <form method="get" class="inline">
        <input type="hidden" name="probe" value="link">
        <div><label>file id</label><input name="file"></div>
        <div><label>model id</label><input name="model"></div>
        <div><label>type</label><input name="type" value="STL" style="width:90px"></div>
        <button>Run link probe</button>
      </form>
    </div>
  </main>
</body>
</html>
