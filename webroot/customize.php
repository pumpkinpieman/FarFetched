<?php
declare(strict_types=1);

/**
 * customize.php — "Customize & Pose" workshop, organised around PROJECTS.
 *
 * Flow:  Create project (import a source model -> copied into a private
 *        workspace, sources stay pristine) -> pose/customize -> Save to Library
 *        (exports STL/3MF into the 'poses' source, appearing in My Library).
 *
 * Variant engine works today (pick a pose mesh, preview, export). Parametric
 * (.scad) rendering lights up once OpenSCAD is installed; it degrades to a
 * notice until then.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();

projects_init();

// --- All models, browsable in the New Project picker. Detection becomes a
// --- helpful HINT (parametric / poses / plain), never a filter — the user
// --- chooses what to import, like the Library page. ---
$allModels = [];
foreach (list_sources() as $s) {
    if ($s['slug'] === 'poses') {
        continue; // don't re-import our own exports
    }
    $base = source_path($s['slug']);
    if ($base === null) {
        continue;
    }
    foreach (list_models($base) as $m) {
        if (($m['kind'] ?? 'folder') !== 'folder') {
            continue;
        }
        $dir = $base . '/' . $m['name'];

        // Only list models that contain at least one editable/importable file
        // type. (CAD formats are included for forward-compat even though live
        // preview/edit support varies.)
        $allowed = ['OBJ', 'STEP', 'STP', 'FCSTD', 'SCAD', 'XLS', 'SLDPRT', 'F3D'];
        $types = model_file_types($dir);
        if (array_intersect($allowed, $types) === []) {
            continue;
        }

        $c = model_customization($dir);
        $allModels[] = [
            'src'    => $s['slug'],
            'folder' => $m['name'],
            'title'  => clean_model_name($m['name']),
            'mode'   => $c['mode'], // variants | parametric | none (hint only)
        ];
    }
}
usort($allModels, static fn($a, $b) => strcasecmp($a['title'], $b['title']));

// Distinct source slugs for the filter pills.
$srcSlugs = [];
foreach ($allModels as $m) {
    $srcSlugs[$m['src']] = true;
}
$srcSlugs = array_keys($srcSlugs);
sort($srcSlugs);

$projects     = projects_list();
$designState  = [];
foreach ($projects as $pp) {
    $ss = json_decode((string) ($pp['state'] ?? '{}'), true) ?: [];
    if (!empty($ss['designMode'])) {
        $designState[(int) $pp['id']] = ['designMode' => $ss['designMode'], 'nodes' => $ss['nodes'] ?? []];
    }
}
$posesOk      = poses_writable();
$csrf         = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customize · FarFetched</title>
<link rel="stylesheet" href="css/styles.css?v=20260622a">
<link rel="stylesheet" href="css/styles_library.css">
<script>(function(){var t=localStorage.getItem('theme');if(t&&t!=='dark')document.documentElement.setAttribute('data-theme',t);})();</script>
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
  }
}
</script>
<script type="application/json" id="cz-csrf"><?= json_encode($csrf) ?></script>
<script type="application/json" id="cz-design-state"><?= json_encode($designState, JSON_UNESCAPED_SLASHES) ?></script>
<style>
  .cz-eyebrow{font-size:11px;letter-spacing:3px;text-transform:uppercase;color:var(--clay);font-weight:600;margin-bottom:6px;}
  .cz-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0 8px;}
  .cz-layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:24px;align-items:start;margin-top:18px;}
  .cz-layout > *{min-width:0;width:100%;}
  #czEditor, #czEditorEmpty{width:100%;}
  @media (max-width:880px){ .cz-layout{grid-template-columns:1fr;} }
  /* Projects rail */
  .cz-projects{display:flex;flex-direction:column;gap:8px;}
  .cz-proj{display:flex;flex-direction:column;gap:3px;padding:12px 14px;border:1px solid var(--line);
           border-radius:11px;background:var(--card);cursor:pointer;transition:border-color .15s,transform .15s;position:relative;}
  .cz-proj:hover{border-color:var(--clay);transform:translateY(-1px);}
  .cz-proj.active{border-color:var(--clay);box-shadow:0 0 0 1px var(--clay) inset;}
  .cz-proj-name{font-size:13.5px;font-weight:500;color:var(--ink);}
  .cz-proj-meta{font-size:11px;color:var(--muted);}
  .cz-proj-del{position:absolute;top:9px;right:9px;background:none;border:none;color:var(--muted);
               cursor:pointer;font-size:13px;opacity:0;transition:opacity .12s;}
  .cz-proj:hover .cz-proj-del{opacity:1;}
  .cz-proj-del:hover{color:var(--err);}
  .cz-new{padding:12px 14px;border:1px dashed var(--line);border-radius:11px;background:transparent;
          color:var(--clay);cursor:pointer;font-size:13px;font-weight:500;text-align:center;transition:all .15s;}
  .cz-new:hover{border-color:var(--clay);background:var(--card);}
  /* Editor */
  .cz-stage{position:relative;width:100%;height:62vh;min-height:460px;max-height:720px;
            background:radial-gradient(ellipse at 50% 40%, #232220 0%, #161513 100%);
            border:1px solid var(--line);border-radius:14px;overflow:hidden;
            box-shadow:inset 0 0 60px rgba(0,0,0,.4);}
  .cz-stage canvas{max-width:100%;max-height:100%;display:block;}
  /* Floating toolbar */
  .cz-toolbar-float{position:absolute;top:12px;left:12px;display:flex;align-items:center;gap:3px;
                    background:rgba(20,19,17,.82);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.08);
                    border-radius:11px;padding:4px;box-shadow:0 6px 24px -6px rgba(0,0,0,.6);z-index:5;}
  .cz-tool{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
           width:34px;height:34px;border:none;background:transparent;color:#cfcabf;border-radius:8px;
           cursor:pointer;transition:all .12s;}
  .cz-tool:hover{background:rgba(255,255,255,.1);color:#fff;}
  .cz-tool.active{background:var(--clay);color:#fff;}
  .cz-tool-ico{font-size:16px;line-height:1;}
  .cz-tool-key{position:absolute;bottom:1px;right:3px;font-size:8px;opacity:.5;font-family:ui-monospace,monospace;}
  .cz-tool-sep{width:1px;height:22px;background:rgba(255,255,255,.12);margin:0 3px;}
  /* Axis indicator (bottom-left) */
  .cz-axis-ind{position:absolute;bottom:12px;left:12px;width:54px;height:54px;z-index:5;opacity:.8;pointer-events:none;}
  /* Help chip */
  .cz-help-chip{position:absolute;bottom:12px;right:12px;width:28px;height:28px;border-radius:50%;
                background:rgba(20,19,17,.82);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.1);
                color:#cfcabf;font-size:14px;cursor:pointer;z-index:5;transition:all .12s;}
  .cz-help-chip:hover{background:var(--clay);color:#fff;}
  .cz-help-pop{position:absolute;bottom:48px;right:12px;background:rgba(20,19,17,.95);backdrop-filter:blur(10px);
               border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:14px 16px;z-index:6;
               box-shadow:0 12px 40px -8px rgba(0,0,0,.7);min-width:200px;}
  .cz-help-title{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--clay);margin-bottom:10px;}
  .cz-help-grid{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;align-items:center;}
  .cz-help-grid kbd{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);border-radius:5px;
                    padding:2px 7px;font-size:11px;font-family:ui-monospace,monospace;color:#fff;text-align:center;}
  .cz-help-grid span{font-size:12px;color:#cfcabf;}
  .cz-stage-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#c9c6c0;font-size:13px;text-align:center;padding:20px;pointer-events:none;}
  .cz-editor-empty{text-align:center;padding:80px 20px;color:var(--muted);}
  .cz-poses{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
  .cz-pose{padding:8px 14px;border-radius:9px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-size:13px;cursor:pointer;transition:all .14s;}
  .cz-pose:hover{border-color:var(--clay);}
  .cz-pose.active{background:var(--clay);border-color:var(--clay);color:#fff;font-weight:500;}
  .cz-export{margin-top:18px;padding:16px;border:1px solid var(--line);border-radius:12px;background:var(--card);}
  .cz-export h3{margin:0 0 12px;font-size:14px;}
  .cz-export-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
  .cz-export-row .lib-btn{flex:0 0 auto;width:auto;}
  .cz-fmt{display:flex;gap:6px;}
  .cz-fmt label{display:flex;align-items:center;gap:5px;font-size:13px;color:var(--ink);cursor:pointer;}
  .cz-warn{margin-top:10px;padding:10px 12px;background:rgba(245,200,66,.12);border:1px solid rgba(245,200,66,.4);
           color:var(--warn);border-radius:9px;font-size:12.5px;line-height:1.5;}
  .cz-input{padding:9px 12px;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:9px;font-size:13px;}
  .cz-param-note{margin-top:14px;padding:14px;border:1px dashed var(--line);border-radius:11px;background:var(--panel);font-size:13px;color:var(--muted);line-height:1.5;}
  #czParamControls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px;}
  @media (max-width:560px){#czParamControls{grid-template-columns:1fr;}}
  .cz-pctl{display:flex;align-items:center;gap:10px;margin-bottom:7px;min-width:0;}
  .cz-pctl label{flex:0 0 140px;font-size:12.5px;color:var(--ink);font-family:ui-monospace,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cz-pctl input[type=range]{flex:1;min-width:0;}
  .cz-pctl input[type=text],.cz-pctl input[type=number]{flex:1;min-width:0;width:auto;padding:6px 10px;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;font-size:13px;}
  .cz-pctl select{flex:1;min-width:0;width:auto;padding:6px 10px;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;font-size:13px;}
  .cz-pval{flex:0 0 40px;text-align:right;font-family:ui-monospace,monospace;font-size:12px;color:var(--muted);}
  .cz-arrange-tools{display:flex;flex-wrap:nowrap;gap:8px;}
  .cz-arrange-tools .lib-btn{flex:0 0 auto;width:auto;white-space:nowrap;}
  /* Contextual part toolbar (follows the selected object) */
  .cz-ctx-tools{position:absolute;z-index:7;display:flex;gap:3px;padding:4px;
                background:rgba(20,19,17,.9);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.12);
                border-radius:10px;box-shadow:0 8px 28px -6px rgba(0,0,0,.7);
                transform:translate(-50%,0);transition:opacity .12s;}
  .cz-ctx-tools[hidden]{display:none;}
  .cz-ctx-btn{width:32px;height:32px;border:none;background:transparent;color:#cfcabf;border-radius:7px;
              cursor:pointer;font-size:15px;transition:all .12s;display:flex;align-items:center;justify-content:center;}
  .cz-ctx-btn:hover{background:rgba(255,255,255,.12);color:#fff;}
  .cz-ctx-btn.active{background:var(--clay);color:#fff;}
  .cz-ctx-close{color:#8a8580;}
  .cz-ctx-close:hover{background:rgba(224,92,92,.25);color:#fff;}
  /* Create modal */
  .cz-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px;}
  .cz-modal[hidden]{display:none;}
  .cz-modal-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:24px;width:100%;max-width:460px;max-height:84vh;overflow-y:auto;}
  .cz-modal-card h2{margin:0 0 4px;font-size:18px;}
  .cz-modal-sub{font-size:12.5px;color:var(--muted);margin-bottom:16px;}
  .cz-src-list{display:flex;flex-direction:column;gap:6px;max-height:300px;overflow-y:auto;margin:12px 0;}
  .cz-src{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 12px;border:1px solid var(--line);border-radius:9px;cursor:pointer;font-size:13px;color:var(--ink);}
  .cz-src:hover{border-color:var(--clay);}
  .cz-src.active{border-color:var(--clay);background:var(--panel);}
  .cz-src-tag{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 6px;border-radius:5px;}
  .cz-src-tag.variants{background:rgba(46,158,91,.18);color:#3fb574;}
  .cz-src-tag.parametric{background:rgba(255,107,26,.18);color:var(--clay);}
  .cz-src-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
  .cz-srcpill{padding:5px 12px;border-radius:999px;border:1px solid var(--line);background:var(--card);
              color:var(--muted);font-size:12px;cursor:pointer;transition:all .14s;}
  .cz-srcpill:hover{border-color:var(--clay);color:var(--ink);}
  .cz-srcpill.active{background:var(--clay);border-color:var(--clay);color:#fff;}
  .cz-src-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cz-src-right{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;}
  .cz-src-from{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.3px;}
</style>
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
      <a href="customize.php" class="active">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
      <a href="collections_view.php">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost"><span id="theme-toggle-icon">🎨</span> Change Appearance</button>
    </nav>
  </aside>

  <main>
    <div class="cz-eyebrow">Workshop</div>
    <h1>Customize &amp; Pose</h1>
    <div class="sub">Create a project from a model, pose or customize it, then save the result to your library.</div>

    <?php if (!$posesOk): ?>
      <div class="cz-warn" style="max-width:680px;">
        ⚠ The <code>/models/poses</code> export folder isn't writable yet, so saving to your library will fail.
        Give the web server user write access (the same fix as thumbnails), then exports will work.
      </div>
    <?php endif; ?>

    <div class="cz-layout">
      <!-- Projects rail -->
      <div>
        <div class="cz-projects" id="czProjects">
          <button class="cz-new" id="czNewBtn">+ New project</button>
          <?php foreach ($projects as $p): ?>
            <?php $pstate = json_decode((string) ($p['state'] ?? '{}'), true) ?: []; $pDesign = (string) ($pstate['designMode'] ?? ''); ?>
            <div class="cz-proj" data-id="<?= (int) $p['id'] ?>"
                 data-mode="<?= e($p['mode']) ?>" data-name="<?= e($p['name']) ?>" data-design="<?= e($pDesign) ?>">
              <button class="cz-proj-del" data-id="<?= (int) $p['id'] ?>" title="Delete project">🗑</button>
              <div class="cz-proj-name"><?= e($p['name']) ?></div>
              <div class="cz-proj-meta">
                <?php if ($pDesign !== ''): ?>
                  ✏️ Design · <?= e(ucfirst($pDesign)) ?>
                <?php else: ?>
                  <?= $p['mode'] === 'parametric' ? '⚙ Parametric' : '🤸 Poses' ?>
                  · from <?= e($p['src_slug'] ?? '?') ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($projects === []): ?>
            <p style="font-size:12.5px;color:var(--muted);padding:8px 4px;">No projects yet. Create one to start posing.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Editor -->
      <div>
        <div id="czEditorEmpty" class="cz-editor-empty">
          <p style="font-size:38px;opacity:.4;margin:0 0 12px;">🤸</p>
          <p>Select a project, or create one to begin.</p>
        </div>

        <div id="czEditor" hidden>
          <div class="cz-stage">
            <div id="czCanvas" style="width:100%;height:100%;max-width:100%;"></div>
            <div class="cz-stage-hint" id="czHint">Loading…</div>

            <!-- Contextual part toolbar (follows selection) -->
            <div class="cz-ctx-tools" id="czCtxTools" hidden>
              <button class="cz-ctx-btn" id="czCtxMove" title="Move (G)">↔</button>
              <button class="cz-ctx-btn" id="czCtxRot" title="Rotate (R)">⟳</button>
              <button class="cz-ctx-btn" id="czCtxReset" title="Reset this part (0)">⟲</button>
              <button class="cz-ctx-btn cz-ctx-close" id="czCtxClose" title="Deselect (Esc)">✕</button>
            </div>

            <!-- Floating toolbar (OpenSCAD-style) -->
            <div class="cz-toolbar-float" id="czToolbar">
              <button class="cz-tool" data-tool="move" data-key="G" title="Move (G)"><span class="cz-tool-ico">↔</span><span class="cz-tool-key">G</span></button>
              <button class="cz-tool" data-tool="rotate" data-key="R" title="Rotate (R)"><span class="cz-tool-ico">⟳</span><span class="cz-tool-key">R</span></button>
              <div class="cz-tool-sep"></div>
              <button class="cz-tool" data-tool="reset" data-key="0" title="Reset transforms (0)"><span class="cz-tool-ico">⟲</span><span class="cz-tool-key">0</span></button>
              <button class="cz-tool" data-tool="frame" data-key="F" title="Frame all (F)"><span class="cz-tool-ico">⊡</span><span class="cz-tool-key">F</span></button>
              <div class="cz-tool-sep"></div>
              <button class="cz-tool" data-tool="top" data-key="7" title="Top view (7)"><span class="cz-tool-ico">⊤</span><span class="cz-tool-key">7</span></button>
              <button class="cz-tool" data-tool="front" data-key="1" title="Front view (1)"><span class="cz-tool-ico">▣</span><span class="cz-tool-key">1</span></button>
              <button class="cz-tool" data-tool="side" data-key="3" title="Side view (3)"><span class="cz-tool-ico">◫</span><span class="cz-tool-key">3</span></button>
              <button class="cz-tool" data-tool="iso" data-key="5" title="Iso view (5)"><span class="cz-tool-ico">◆</span><span class="cz-tool-key">5</span></button>
            </div>

            <!-- View axis indicator -->
            <div class="cz-axis-ind" id="czAxisInd"></div>

            <!-- Shortcut hint chip -->
            <button class="cz-help-chip" id="czHelpChip" title="Keyboard shortcuts">?</button>
            <div class="cz-help-pop" id="czHelpPop" hidden>
              <div class="cz-help-title">Shortcuts</div>
              <div class="cz-help-grid">
                <kbd>G</kbd><span>Move tool</span>
                <kbd>R</kbd><span>Rotate tool</span>
                <kbd>0</kbd><span>Reset transforms</span>
                <kbd>F</kbd><span>Frame all</span>
                <kbd>Enter</kbd><span>Render (parametric)</span>
                <kbd>1</kbd><span>Front view</span>
                <kbd>3</kbd><span>Side view</span>
                <kbd>7</kbd><span>Top view</span>
                <kbd>5</kbd><span>Iso view</span>
                <kbd>Esc</kbd><span>Deselect</span>
              </div>
            </div>
          </div>

          <div id="czArrange" hidden>
            <strong style="font-size:13px;">Arrange parts</strong>
            <p class="cz-proj-meta" style="margin:4px 0 8px;">Click a part in the view to select it — move/rotate tools appear right at the part.</p>
          </div>

          <div id="czVariants" hidden>
            <strong style="font-size:13px;">Choose a pose / variant</strong>
            <div class="cz-poses" id="czPoses"></div>
          </div>

          <div id="czDesign" hidden>
            <div id="czCode" hidden>
              <strong style="font-size:13px;">OpenSCAD source</strong>
              <textarea id="czCodeArea" spellcheck="false"
                style="width:100%;box-sizing:border-box;min-height:220px;margin:8px 0;background:#0e1116;color:#e6e6e6;border:1px solid #333b45;border-radius:8px;padding:10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5;"></textarea>
              <button class="lib-btn lib-btn-primary" id="czCodeApply" type="button">Apply &amp; Render</button>
              <span class="cz-proj-meta" id="czCodeMsg" style="margin-left:6px;"></span>
            </div>
            <div id="czNodes" hidden>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <strong style="font-size:13px;">Node graph</strong>
                <span class="cz-proj-meta">Build a CSG tree — it compiles to OpenSCAD.</span>
              </div>
              <div id="czNodePalette" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;"></div>
              <div id="czNodeTree" style="background:#0e1116;border:1px solid #333b45;border-radius:8px;padding:10px;min-height:120px;font-size:13px;"></div>
              <button class="lib-btn lib-btn-primary" id="czNodeApply" type="button" style="margin-top:10px;">Apply &amp; Render</button>
              <span class="cz-proj-meta" id="czNodeMsg" style="margin-left:6px;"></span>
            </div>
          </div>

          <div id="czParam" hidden>
            <div id="czParamControls"></div>
            <div class="cz-actions" style="margin-top:12px;">
              <button class="lib-btn lib-btn-primary" id="czRenderBtn" type="button">⚙ Render</button>
              <label style="font-size:12px;color:var(--muted);display:inline-flex;align-items:center;gap:5px;margin-left:8px;cursor:pointer;">
                <input type="checkbox" id="czAutoRender"> Auto
              </label>
              <span class="cz-proj-meta" id="czRenderMsg" style="margin-left:4px;"></span>
            </div>
          </div>

          <div class="cz-export">
            <h3>Save to library</h3>
            <div class="cz-export-row">
              <div>
                <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:5px;">Name</label>
                <input class="cz-input" id="czExportName" placeholder="e.g. Skeleton — sitting" style="min-width:220px;">
              </div>
              <div class="cz-fmt">
                <label><input type="radio" name="czFmt" value="stl" checked> STL</label>
                <label title="3MF export needs OpenSCAD"><input type="radio" name="czFmt" value="3mf"> 3MF</label>
              </div>
              <button class="lib-btn lib-btn-primary" id="czSaveBtn" type="button" <?= $posesOk ? '' : 'disabled' ?>>💾 Save to /poses</button>
            </div>
            <p class="cz-proj-meta" id="czSaveMsg" style="margin-top:10px;"></p>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- Create-project modal -->
  <div class="cz-modal" id="czModal" hidden>
    <div class="cz-modal-card">
      <h2>New project</h2>
      <div class="cz-modal-sub">Import a model to pose/customize, or design one from scratch. Everything lives in a private workspace.</div>

      <div class="cz-mode-tabs" id="czModeTabs" style="display:flex;gap:8px;margin:4px 0 14px;">
        <button class="cz-srcpill active" data-newmode="import" type="button">📥 Import a model</button>
        <button class="cz-srcpill" data-newmode="design" type="button">✏️ Design your own</button>
      </div>

      <label style="font-size:12px;color:var(--muted);">Project name</label>
      <input class="cz-input" id="czNewName" placeholder="My design" style="width:100%;box-sizing:border-box;margin:6px 0 14px;">

      <!-- Design-your-own: authoring mode picker -->
      <div id="czDesignPick" hidden>
        <label style="font-size:12px;color:var(--muted);">How do you want to build it?</label>
        <div class="cz-src-pills" id="czDesignModes" style="margin:6px 0 4px;">
          <button class="cz-srcpill active" data-dmode="sliders" type="button">🎚 Sliders</button>
          <button class="cz-srcpill" data-dmode="code" type="button">⌨ Code (OpenSCAD)</button>
          <button class="cz-srcpill" data-dmode="nodes" type="button">🔗 Nodes</button>
        </div>
        <p class="cz-proj-meta" id="czDesignHint" style="margin:8px 2px 0;">Start from a parametric template and drive it with sliders.</p>
      </div>

      <!-- Import: source picker -->
      <div id="czImportPick">
      <label style="font-size:12px;color:var(--muted);">Import from</label>
      <input class="cz-input" id="czSrcSearch" placeholder="Search models…" style="width:100%;box-sizing:border-box;margin:6px 0 10px;">
      <div class="cz-src-pills" id="czSrcPills">
        <button class="cz-srcpill active" data-src="">All</button>
        <?php foreach ($srcSlugs as $sl): ?>
          <button class="cz-srcpill" data-src="<?= e($sl) ?>"><?= e(ucfirst($sl)) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="cz-src-list" id="czSrcList">
        <?php if ($allModels === []): ?>
          <p style="font-size:12.5px;color:var(--muted);">No models in your library yet. Download something first.</p>
        <?php else: ?>
          <?php foreach ($allModels as $im): ?>
            <div class="cz-src" data-src="<?= e($im['src']) ?>" data-folder="<?= e($im['folder']) ?>"
                 data-search="<?= e(strtolower($im['title'] . ' ' . $im['src'])) ?>">
              <span class="cz-src-name"><?= e($im['title']) ?></span>
              <span class="cz-src-right">
                <span class="cz-src-from"><?= e($im['src']) ?></span>
                <?php if ($im['mode'] === 'variants'): ?>
                  <span class="cz-src-tag variants">Poses</span>
                <?php elseif ($im['mode'] === 'parametric'): ?>
                  <span class="cz-src-tag parametric">Param</span>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      </div><!-- /czImportPick -->
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button class="lib-btn lib-btn-ghost" id="czCancelBtn" type="button">Cancel</button>
        <button class="lib-btn lib-btn-primary" id="czCreateBtn" type="button" disabled>Create project</button>
      </div>
      <p class="cz-proj-meta" id="czCreateMsg" style="margin-top:8px;"></p>
    </div>
  </div>

<script type="module">
  import { createViewer } from './js/viewer-core.js';
  import * as THREE from 'three';
  import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
  import { TransformControls } from 'three/addons/controls/TransformControls.js';
  import { STLLoader } from 'three/addons/loaders/STLLoader.js';
  const CSRF = JSON.parse(document.getElementById('cz-csrf').textContent || '""');

  const editor = document.getElementById('czEditor');
  const editorEmpty = document.getElementById('czEditorEmpty');
  const hint = document.getElementById('czHint');
  const vWrap = document.getElementById('czVariants');
  const pWrap = document.getElementById('czParam');
  const poses = document.getElementById('czPoses');
  let viewer = null, activeProject = null, activeFile = null;

  function ensureViewer() {
    if (!viewer) viewer = createViewer(document.getElementById('czCanvas'), { background: 0x1c1b1a });
    // Container may have just been unhidden — flush a resize so the renderer
    // fills the full stage width instead of a stale/zero measurement.
    requestAnimationFrame(() => { if (viewer && viewer.resize) viewer.resize(); });
    return viewer;
  }
  function post(payload) {
    return fetch('project_action.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)) })
      .then(r => r.json().catch(() => ({ ok:false, error:'Request failed' })));
  }
  function projFileUrl(id, file) {
    return 'project_file.php?id=' + encodeURIComponent(id) + '&file=' + encodeURIComponent(file);
  }

  async function openProject(id, mode, name, design) {
    activeProject = { id, mode, name, design: design || '' };
    editorEmpty.hidden = true; editor.hidden = false;
    document.querySelectorAll('.cz-proj').forEach(el => el.classList.toggle('active', +el.dataset.id === id));
    document.getElementById('czExportName').value = name || '';
    hint.style.display = ''; hint.textContent = 'Loading…';
    arr = null; selectedPart = null; document.getElementById('czCtxTools').hidden = true; // reset arrange state

    // Design projects: show the authoring editor for their mode. All modes still
    // render through the parametric param panel below (the .scad is the source).
    const dz = document.getElementById('czDesign');
    const cz = document.getElementById('czCode');
    const nz = document.getElementById('czNodes');
    if (activeProject.design) {
      dz.hidden = false;
      cz.hidden = (activeProject.design !== 'code');
      nz.hidden = (activeProject.design !== 'nodes');
      if (activeProject.design === 'code') loadScadIntoEditor(id);
      if (activeProject.design === 'nodes') initNodeEditor(id);
    } else {
      dz.hidden = true; cz.hidden = true; nz.hidden = true;
    }

    const meta = await fetch(projFileUrl(id, '') + '&list=1').then(r => r.json()).catch(() => null);
    if (!meta || !meta.ok) { hint.textContent = 'Could not load project.'; return; }

    if (meta.mode === 'variants') {
      if (arrangeAvailable(meta)) {
        pWrap.hidden = true; vWrap.hidden = true;
        document.getElementById('czArrange').hidden = false;
        startArrange(id, meta.variants);
        activeFile = '__arrange__';
        return;
      }
      pWrap.hidden = true; vWrap.hidden = false;
      document.getElementById('czArrange').hidden = true;
      poses.innerHTML = '';
      (meta.variants || []).forEach((v, i) => {
        const b = document.createElement('button');
        b.className = 'cz-pose' + (i === 0 ? ' active' : '');
        b.textContent = v.label;
        b.addEventListener('click', () => {
          poses.querySelectorAll('.cz-pose').forEach(x => x.classList.remove('active'));
          b.classList.add('active');
          loadFile(v.file);
        });
        poses.appendChild(b);
      });
      if (meta.variants && meta.variants.length) loadFile(meta.variants[0].file);
      else { hint.textContent = 'No meshes in this project.'; }
    } else {
      // parametric — load params, build controls, render
      vWrap.hidden = true; pWrap.hidden = false;
      loadParams(id);
    }
  }

  let paramDefs = [], renderedFile = null;
  async function loadParams(id) {
    const controls = document.getElementById('czParamControls');
    controls.innerHTML = '<p class="cz-proj-meta">Loading parameters…</p>';
    const d = await fetch('scad_render.php?id=' + id + '&params=1').then(r => r.json()).catch(() => null);
    if (!d || !d.ok) { controls.innerHTML = '<p class="cz-proj-meta">Could not read parameters.</p>'; return; }
    if (!d.available) {
      controls.innerHTML = '<div class="cz-param-note">OpenSCAD isn\'t available on the server, so this can\'t be rendered. Install OpenSCAD to enable parametric rendering.</div>';
      document.getElementById('czRenderBtn').disabled = true;
      return;
    }
    document.getElementById('czRenderBtn').disabled = false;
    paramDefs = d.params || [];
    if (!paramDefs.length) {
      controls.innerHTML = '<p class="cz-proj-meta">No adjustable parameters found in this script.</p>';
    } else {
      controls.innerHTML = '';
      paramDefs.forEach(p => controls.appendChild(buildControl(p)));
    }
    hint.textContent = 'Set parameters, then Render.';
  }

  function buildControl(p) {
    const row = document.createElement('div');
    row.className = 'cz-pctl';
    const label = document.createElement('label');
    label.textContent = p.name;
    row.appendChild(label);
    const NUM_CSS = 'flex:0 0 auto;width:66px;background:#14171c;color:#e6e6e6;border:1px solid #333b45;border-radius:6px;padding:3px 6px;font-size:12px;text-align:right;font-family:ui-monospace,Menlo,Consolas,monospace;';
    let input;
    if (p.type === 'select') {
      input = document.createElement('select');
      p.options.forEach(o => {
        const opt = document.createElement('option');
        opt.value = o.value; opt.textContent = o.label;
        if (String(o.value) === String(p.default)) opt.selected = true;
        input.appendChild(opt);
      });
      input.addEventListener('change', scheduleAutoRender);
    } else if (p.type === 'bool') {
      input = document.createElement('input'); input.type = 'checkbox'; input.checked = !!p.default;
      input.addEventListener('change', scheduleAutoRender);
    } else if (p.type === 'number' && p.min !== undefined) {
      // Slider is the source of truth (carries data-name); the number box is a
      // two-way-bound editable mirror so exact values can be typed — CAD-style.
      input = document.createElement('input'); input.type = 'range';
      const min = parseFloat(p.min), max = parseFloat(p.max), step = parseFloat(p.step) || 1;
      input.min = min; input.max = max; input.step = step; input.value = p.default;
      input.dataset.name = p.name; input.dataset.kind = p.type;

      const num = document.createElement('input');
      num.type = 'number'; num.min = min; num.max = max; num.step = step;
      num.value = p.default; num.style.cssText = NUM_CSS;

      const clamp = (v) => Math.min(max, Math.max(min, v));
      input.addEventListener('input', () => { num.value = input.value; });
      input.addEventListener('change', scheduleAutoRender);
      num.addEventListener('input', () => {
        const v = parseFloat(num.value);
        if (isFinite(v)) input.value = clamp(v);   // keep slider synced while typing
      });
      num.addEventListener('change', () => {
        let v = parseFloat(num.value);
        if (!isFinite(v)) v = parseFloat(input.value);
        v = clamp(v);
        num.value = v; input.value = v;
        scheduleAutoRender();
      });
      row.appendChild(input); row.appendChild(num);
      return row;
    } else if (p.type === 'string') {
      input = document.createElement('input'); input.type = 'text'; input.value = p.default;
      input.addEventListener('change', scheduleAutoRender);
    } else {
      input = document.createElement('input'); input.type = 'number'; input.step = 'any'; input.value = p.default;
      input.addEventListener('change', scheduleAutoRender);
    }
    input.dataset.name = p.name; input.dataset.kind = p.type;
    row.appendChild(input);
    return row;
  }

  function collectValues() {
    const vals = {};
    document.querySelectorAll('#czParamControls [data-name]').forEach(el => {
      const k = el.dataset.name, kind = el.dataset.kind;
      if (kind === 'bool') vals[k] = el.checked;
      else if (el.type === 'range' || el.type === 'number') vals[k] = parseFloat(el.value);
      else vals[k] = el.value;
    });
    return vals;
  }

  // Render is guarded against overlap: concurrent OpenSCAD calls waste CPU and
  // race the viewer. If a render is requested while one is in flight, we mark it
  // pending and fire once the current one settles (coalescing rapid edits).
  let renderInFlight = false, renderPending = false, autoTimer = null;

  async function renderNow() {
    if (!activeProject) return;
    if (renderInFlight) { renderPending = true; return; }
    renderInFlight = true;
    const btn = document.getElementById('czRenderBtn');
    const msg = document.getElementById('czRenderMsg');
    btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Rendering…';
    const fmt = (document.querySelector('input[name="czFmt"]:checked') || {}).value || 'stl';
    const d = await post2('scad_render.php', { id: activeProject.id, values: collectValues(), fmt });
    if (d.ok) {
      renderedFile = d.file; activeFile = d.file;
      hint.style.display = 'none';
      ensureViewer().loadFile(projFileUrl(activeProject.id, d.file), fmt);
      msg.style.color = 'var(--ok)'; msg.textContent = d.cached ? '✓ (cached)' : '✓ Rendered';
    } else {
      msg.style.color = 'var(--err)'; msg.textContent = d.error || 'Render failed.';
    }
    btn.disabled = false;
    renderInFlight = false;
    if (renderPending) { renderPending = false; renderNow(); }   // flush coalesced edit
  }

  // Debounced live render — only when the Auto toggle is on. Coalesces bursts of
  // slider/number edits into a single render ~400ms after the last change.
  function scheduleAutoRender() {
    const auto = document.getElementById('czAutoRender');
    if (!auto || !auto.checked) return;
    if (document.getElementById('czRenderBtn').disabled && !renderInFlight) return; // OpenSCAD unavailable
    clearTimeout(autoTimer);
    autoTimer = setTimeout(renderNow, 400);
  }

  document.getElementById('czRenderBtn').addEventListener('click', renderNow);

  function post2(url, payload) {
    return fetch(url, { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)) })
      .then(r => r.json().catch(() => ({ ok:false, error:'Request failed' })));
  }

  function loadFile(file) {
    activeFile = file;
    hint.style.display = 'none';
    const ext = file.split('.').pop().toLowerCase();
    ensureViewer().loadFile(projFileUrl(activeProject.id, file), ext);
  }

  // ---------------- Design authoring (code + nodes) ----------------
  const DESIGN_STATE = JSON.parse(document.getElementById('cz-design-state').textContent || '{}');

  // Re-parse params from the current .scad and render immediately.
  async function reloadAndRender(id) {
    await loadParams(id);
    const rb = document.getElementById('czRenderBtn');
    if (rb && !rb.disabled) rb.click();
  }

  // --- Code mode ---
  async function loadScadIntoEditor(id) {
    const ta = document.getElementById('czCodeArea');
    ta.value = 'Loading…';
    try {
      const txt = await fetch(projFileUrl(id, 'design.scad')).then(r => r.text());
      ta.value = txt;
    } catch (e) { ta.value = '// (could not load design.scad)\n'; }
  }
  document.getElementById('czCodeApply').addEventListener('click', async () => {
    if (!activeProject) return;
    const code = document.getElementById('czCodeArea').value;
    const msg = document.getElementById('czCodeMsg');
    const btn = document.getElementById('czCodeApply');
    btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Saving…';
    const d = await post({ action:'write_scad', id: activeProject.id, code });
    if (!d.ok) { msg.style.color = 'var(--err)'; msg.textContent = d.error || 'Failed.'; btn.disabled = false; return; }
    msg.style.color = 'var(--ok)'; msg.textContent = '✓ Applied';
    btn.disabled = false;
    reloadAndRender(activeProject.id);
  });

  // --- Node mode: a CSG tree that serializes to OpenSCAD ---
  // Leaf primitives (3D + 2D) and container operators. Everything serializes to
  // a CSG tree: leaves emit a solid; ops emit `op(){ children }`.
  const NODE_PRIMS = ['cube', 'sphere', 'cylinder', 'cone', 'torus', 'roundedcube', 'text3d', 'circle', 'square', 'text2d'];
  const NODE_OPS = ['union', 'difference', 'intersection',
                    'translate', 'rotate', 'scale', 'mirror', 'resize', 'color',
                    'linear_extrude', 'rotate_extrude', 'offset2d',
                    'hull', 'minkowski',
                    'linear_pattern', 'circular_pattern', 'mirror_copy'];
  // Palette grouping (label → types).
  const NODE_CATS = [
    ['3D', ['cube', 'sphere', 'cylinder', 'cone', 'torus', 'roundedcube', 'text3d']],
    ['2D', ['circle', 'square', 'text2d']],
    ['Extrude', ['linear_extrude', 'rotate_extrude', 'offset2d']],
    ['Boolean', ['union', 'difference', 'intersection']],
    ['Transform', ['translate', 'rotate', 'scale', 'mirror', 'resize', 'color']],
    ['Combine', ['hull', 'minkowski']],
    ['Pattern', ['linear_pattern', 'circular_pattern', 'mirror_copy']]
  ];
  const NODE_DEFAULTS = {
    cube: { x:20, y:20, z:20, center:true },
    sphere: { r:10, fn:48 },
    cylinder: { h:20, r:8, center:true, fn:48 },
    cone: { h:20, r1:10, r2:2, center:true, fn:48 },
    torus: { R:20, r:5, fn:64 },
    roundedcube: { x:24, y:24, z:24, rad:3, fn:32 },
    text3d: { str:'Text', size:10, h:3, fn:24 },
    circle: { r:10, fn:48 },
    square: { x:20, y:20, center:true },
    text2d: { str:'Text', size:10 },
    translate: { x:0, y:0, z:0 }, rotate: { x:0, y:0, z:0 }, scale: { x:1, y:1, z:1 },
    mirror: { x:1, y:0, z:0 }, resize: { x:20, y:20, z:20 }, color: { r:1, g:0.5, b:0.2 },
    linear_extrude: { h:10, twist:0, scale:1, center:false, fn:48 },
    rotate_extrude: { angle:360, fn:64 }, offset2d: { r:2 },
    union: {}, difference: {}, intersection: {}, hull: {}, minkowski: {},
    linear_pattern: { n:5, dx:15, dy:0, dz:0 },
    circular_pattern: { n:6, rad:30 },
    mirror_copy: { x:1, y:0, z:0 }
  };
  // Field kinds: default = number, 'b' = bool checkbox, 't' = text.
  const NODE_FIELDS = {
    cube: [['x','X'],['y','Y'],['z','Z'],['center','Center','b']],
    sphere: [['r','R'],['fn','$fn']],
    cylinder: [['h','H'],['r','R'],['fn','$fn'],['center','Center','b']],
    cone: [['h','H'],['r1','R1'],['r2','R2'],['fn','$fn'],['center','Center','b']],
    torus: [['R','Ring R'],['r','Tube r'],['fn','$fn']],
    roundedcube: [['x','X'],['y','Y'],['z','Z'],['rad','Radius'],['fn','$fn']],
    text3d: [['str','Text','t'],['size','Size'],['h','Height'],['fn','$fn']],
    circle: [['r','R'],['fn','$fn']],
    square: [['x','X'],['y','Y'],['center','Center','b']],
    text2d: [['str','Text','t'],['size','Size']],
    translate: [['x','X'],['y','Y'],['z','Z']],
    rotate: [['x','X°'],['y','Y°'],['z','Z°']],
    scale: [['x','X'],['y','Y'],['z','Z']],
    mirror: [['x','X'],['y','Y'],['z','Z']],
    resize: [['x','X'],['y','Y'],['z','Z']],
    color: [['r','R'],['g','G'],['b','B']],
    linear_extrude: [['h','Height'],['twist','Twist°'],['scale','Scale'],['center','Center','b'],['fn','$fn']],
    rotate_extrude: [['angle','Angle°'],['fn','$fn']],
    offset2d: [['r','Radius']],
    linear_pattern: [['n','Count'],['dx','dX'],['dy','dY'],['dz','dZ']],
    circular_pattern: [['n','Count'],['rad','Radius']],
    mirror_copy: [['x','X'],['y','Y'],['z','Z']]
  };
  const isOp = t => NODE_OPS.includes(t);
  let nodeTree = [];      // array of top-level nodes
  let nodeSeq = 1;
  const newNode = (type) => ({ id: nodeSeq++, type, params: Object.assign({}, NODE_DEFAULTS[type] || {}), children: isOp(type) ? [] : undefined });

  function initNodeEditor(id) {
    const saved = (DESIGN_STATE[id] && DESIGN_STATE[id].nodes) || [];
    nodeTree = Array.isArray(saved) && saved.length ? normalizeTree(saved) : [];
    // rebuild id sequence so new nodes don't collide
    let maxId = 0; (function walk(a){ (a||[]).forEach(n => { maxId = Math.max(maxId, n.id||0); walk(n.children); }); })(nodeTree);
    nodeSeq = maxId + 1;
    buildPalette(); renderTree();
  }
  function normalizeTree(a) {
    return (a || []).map(n => ({
      id: n.id || nodeSeq++, type: n.type,
      params: Object.assign({}, NODE_DEFAULTS[n.type] || {}, n.params || {}),
      children: isOp(n.type) ? normalizeTree(n.children || []) : undefined
    }));
  }
  function buildPalette() {
    const pal = document.getElementById('czNodePalette');
    pal.innerHTML = '';
    pal.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin:8px 0;';
    NODE_CATS.forEach(([label, types]) => {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:5px;flex-wrap:wrap;align-items:center;';
      const lbl = document.createElement('span');
      lbl.className = 'cz-proj-meta';
      lbl.textContent = label; lbl.style.cssText = 'min-width:74px;font-weight:600;';
      row.appendChild(lbl);
      types.forEach(t => {
        const b = document.createElement('button');
        b.className = 'cz-srcpill'; b.type = 'button'; b.textContent = t;
        b.addEventListener('click', () => { nodeTree.push(newNode(t)); renderTree(); });
        row.appendChild(b);
      });
      pal.appendChild(row);
    });
  }
  function childMenuHTML() {
    return '<option value="">+ child…</option>' +
      NODE_CATS.map(([label, types]) =>
        '<optgroup label="' + label + '">' +
        types.map(t => '<option>' + t + '</option>').join('') +
        '</optgroup>').join('');
  }
  function renderTree() {
    const host = document.getElementById('czNodeTree');
    host.innerHTML = '';
    if (!nodeTree.length) { host.innerHTML = '<span class="cz-proj-meta">Empty — add a primitive or operation above.</span>'; return; }
    nodeTree.forEach((n, i) => host.appendChild(renderNodeRow(n, nodeTree, i, 0)));
  }
  function renderNodeRow(n, parentArr, idx, depth) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin:4px 0 4px ' + (depth * 16) + 'px;border-left:2px solid #2a313b;padding-left:8px;';
    const head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;gap:6px;flex-wrap:wrap;';
    const tag = document.createElement('strong');
    tag.textContent = (isOp(n.type) ? '▸ ' : '') + n.type;
    tag.style.cssText = 'color:' + (isOp(n.type) ? 'var(--clay)' : '#e6e6e6') + ';font-size:12.5px;';
    head.appendChild(tag);
    (NODE_FIELDS[n.type] || []).forEach(([key, lbl, kind]) => {
      const w = document.createElement('label');
      w.style.cssText = 'font-size:11px;color:var(--muted);display:inline-flex;align-items:center;gap:3px;';
      if (kind === 'b' || key === 'center') {
        const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!n.params[key];
        cb.addEventListener('change', () => { n.params[key] = cb.checked; });
        w.append(lbl + ' ', cb);
      } else if (kind === 't') {
        const inp = document.createElement('input'); inp.type = 'text';
        inp.value = n.params[key] != null ? n.params[key] : '';
        inp.style.cssText = 'width:110px;background:#14171c;color:#e6e6e6;border:1px solid #333b45;border-radius:5px;padding:2px 5px;font-size:11px;';
        inp.addEventListener('input', () => { n.params[key] = inp.value; });
        w.append(lbl, inp);
      } else {
        const inp = document.createElement('input'); inp.type = 'number'; inp.step = 'any';
        inp.value = n.params[key]; inp.style.cssText = 'width:56px;background:#14171c;color:#e6e6e6;border:1px solid #333b45;border-radius:5px;padding:2px 4px;font-size:11px;';
        inp.addEventListener('input', () => { n.params[key] = parseFloat(inp.value); });
        w.append(lbl, inp);
      }
      head.appendChild(w);
    });
    if (isOp(n.type)) {
      const sel = document.createElement('select');
      sel.style.cssText = 'font-size:11px;background:#14171c;color:#e6e6e6;border:1px solid #333b45;border-radius:5px;';
      sel.innerHTML = childMenuHTML();
      sel.addEventListener('change', () => { if (sel.value) { n.children.push(newNode(sel.value)); sel.value = ''; renderTree(); } });
      head.appendChild(sel);
    }
    const del = document.createElement('button');
    del.className = 'cz-srcpill'; del.type = 'button'; del.textContent = '🗑';
    del.style.cssText = 'padding:1px 7px;';
    del.addEventListener('click', () => { parentArr.splice(idx, 1); renderTree(); });
    head.appendChild(del);
    wrap.appendChild(head);
    if (isOp(n.type)) (n.children || []).forEach((c, ci) => wrap.appendChild(renderNodeRow(c, n.children, ci, depth + 1)));
    return wrap;
  }

  // Recursive tree → OpenSCAD.
  function nn(v, d) { const f = parseFloat(v); return isFinite(f) ? f : d; }
  function ni(v, d) { return Math.max(1, Math.round(nn(v, d))); }        // positive integer (loop counts)
  function sstr(v) { return '"' + String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ') + '"'; }
  function emitNode(n, ind) {
    const pad = '  '.repeat(ind), P = n.params || {};
    const block = (inner) => `${inner} {\n${emitChildren(n, ind + 1)}\n${pad}}`;
    switch (n.type) {
      // --- 3D leaves ---
      case 'cube': return `${pad}cube([${nn(P.x,10)}, ${nn(P.y,10)}, ${nn(P.z,10)}], center=${P.center?'true':'false'});`;
      case 'sphere': return `${pad}sphere(r=${nn(P.r,5)}, $fn=${ni(P.fn,48)});`;
      case 'cylinder': return `${pad}cylinder(h=${nn(P.h,10)}, r=${nn(P.r,5)}, center=${P.center?'true':'false'}, $fn=${ni(P.fn,48)});`;
      case 'cone': return `${pad}cylinder(h=${nn(P.h,10)}, r1=${nn(P.r1,6)}, r2=${nn(P.r2,2)}, center=${P.center?'true':'false'}, $fn=${ni(P.fn,48)});`;
      case 'torus': return `${pad}rotate_extrude($fn=${ni(P.fn,64)}) translate([${nn(P.R,20)}, 0, 0]) circle(r=${nn(P.r,5)}, $fn=${ni(P.fn,64)});`;
      case 'roundedcube': {
        const rad = nn(P.rad,3), x = Math.max(0, nn(P.x,24)-2*rad), y = Math.max(0, nn(P.y,24)-2*rad), z = Math.max(0, nn(P.z,24)-2*rad);
        return `${pad}minkowski() {\n${pad}  cube([${x}, ${y}, ${z}], center=true);\n${pad}  sphere(r=${rad}, $fn=${ni(P.fn,32)});\n${pad}}`;
      }
      case 'text3d': return `${pad}linear_extrude(height=${nn(P.h,3)}) text(${sstr(P.str)}, size=${nn(P.size,10)}, halign="center", valign="center", $fn=${ni(P.fn,24)});`;
      // --- 2D leaves ---
      case 'circle': return `${pad}circle(r=${nn(P.r,5)}, $fn=${ni(P.fn,48)});`;
      case 'square': return `${pad}square([${nn(P.x,20)}, ${nn(P.y,20)}], center=${P.center?'true':'false'});`;
      case 'text2d': return `${pad}text(${sstr(P.str)}, size=${nn(P.size,10)}, halign="center", valign="center");`;
      // --- booleans / combine ---
      case 'union': case 'difference': case 'intersection': case 'hull': case 'minkowski':
        return block(`${pad}${n.type}()`);
      // --- transforms ---
      case 'translate': return block(`${pad}translate([${nn(P.x,0)}, ${nn(P.y,0)}, ${nn(P.z,0)}])`);
      case 'rotate': return block(`${pad}rotate([${nn(P.x,0)}, ${nn(P.y,0)}, ${nn(P.z,0)}])`);
      case 'scale': return block(`${pad}scale([${nn(P.x,1)}, ${nn(P.y,1)}, ${nn(P.z,1)}])`);
      case 'mirror': return block(`${pad}mirror([${nn(P.x,1)}, ${nn(P.y,0)}, ${nn(P.z,0)}])`);
      case 'resize': return block(`${pad}resize([${nn(P.x,20)}, ${nn(P.y,20)}, ${nn(P.z,20)}])`);
      case 'color': return block(`${pad}color([${nn(P.r,1)}, ${nn(P.g,0.5)}, ${nn(P.b,0.2)}])`);
      // --- extrusion (2D → 3D) ---
      case 'linear_extrude': return block(`${pad}linear_extrude(height=${nn(P.h,10)}, twist=${nn(P.twist,0)}, scale=${nn(P.scale,1)}, center=${P.center?'true':'false'}, $fn=${ni(P.fn,48)})`);
      case 'rotate_extrude': return block(`${pad}rotate_extrude(angle=${nn(P.angle,360)}, $fn=${ni(P.fn,64)})`);
      case 'offset2d': return block(`${pad}offset(r=${nn(P.r,2)})`);
      // --- patterns (generative repeaters) ---
      case 'linear_pattern':
        return `${pad}for (i = [0 : ${ni(P.n,5) - 1}]) translate([i*${nn(P.dx,15)}, i*${nn(P.dy,0)}, i*${nn(P.dz,0)}]) {\n${emitChildren(n, ind + 1)}\n${pad}}`;
      case 'circular_pattern': {
        const cnt = ni(P.n,6);
        return `${pad}for (i = [0 : ${cnt - 1}]) rotate([0, 0, i*${(360 / cnt)}]) translate([${nn(P.rad,30)}, 0, 0]) {\n${emitChildren(n, ind + 1)}\n${pad}}`;
      }
      case 'mirror_copy':
        return `${pad}union() {\n${emitChildren(n, ind + 1)}\n${pad}  mirror([${nn(P.x,1)}, ${nn(P.y,0)}, ${nn(P.z,0)}]) {\n${emitChildren(n, ind + 2)}\n${pad}  }\n${pad}}`;
      default: return `${pad}// unknown node ${n.type}`;
    }
  }
  function emitChildren(n, ind) {
    const kids = (n.children || []);
    if (!kids.length) return '  '.repeat(ind) + '// (empty)';
    return kids.map(c => emitNode(c, ind)).join('\n');
  }
  function treeToScad() {
    if (!nodeTree.length) return '$fn = 48;\n// empty design\n';
    return '$fn = 48;\n' + nodeTree.map(n => emitNode(n, 0)).join('\n') + '\n';
  }
  document.getElementById('czNodeApply').addEventListener('click', async () => {
    if (!activeProject) return;
    const msg = document.getElementById('czNodeMsg');
    const btn = document.getElementById('czNodeApply');
    btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Saving…';
    const code = treeToScad();
    const state = { designMode:'nodes', nodes: nodeTree };
    const d = await post({ action:'write_scad', id: activeProject.id, code, state });
    if (!d.ok) { msg.style.color = 'var(--err)'; msg.textContent = d.error || 'Failed.'; btn.disabled = false; return; }
    DESIGN_STATE[activeProject.id] = state; // keep local copy in sync
    msg.style.color = 'var(--ok)'; msg.textContent = '✓ Applied';
    btn.disabled = false;
    reloadAndRender(activeProject.id);
  });

  // Project rail clicks
  document.getElementById('czProjects').addEventListener('click', async (e) => {
    const del = e.target.closest('.cz-proj-del');
    if (del) {
      e.stopPropagation();
      if (!confirm('Delete this project? (Exports already in your library stay.)')) return;
      const d = await post({ action:'delete', id:+del.dataset.id });
      if (d.ok) location.reload();
      return;
    }
    const proj = e.target.closest('.cz-proj');
    if (proj) openProject(+proj.dataset.id, proj.dataset.mode, proj.dataset.name, proj.dataset.design || '');
  });

  // Save to library
  document.getElementById('czSaveBtn').addEventListener('click', async () => {
    if (!activeProject || !activeFile) return;
    const name = document.getElementById('czExportName').value.trim() || activeProject.name;
    const msg = document.getElementById('czSaveMsg');
    const btn = document.getElementById('czSaveBtn');
    btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Saving…';
    let d;
    if (activeFile === '__arrange__') {
      const stl = exportArrange();
      if (!stl) { msg.style.color = 'var(--err)'; msg.textContent = 'Nothing to export.'; btn.disabled = false; return; }
      d = await post({ action:'export_raw', id:activeProject.id, name, stl });
    } else {
      d = await post({ action:'export', id:activeProject.id, file:activeFile, name });
    }
    if (d.ok) {
      msg.style.color = 'var(--ok)';
      msg.innerHTML = '✓ Saved to library as <strong>' + d.folder + '</strong> — find it under the <em>poses</em> source in My Library.';
    } else {
      msg.style.color = 'var(--err)';
      msg.textContent = d.error || 'Export failed.';
    }
    btn.disabled = false;
  });

  // ---- Create modal ----
  const modal = document.getElementById('czModal');
  let pickSrc = null;
  let newMode = 'import';        // 'import' | 'design'
  let designMode = 'sliders';    // 'sliders' | 'code' | 'nodes'
  const DESIGN_HINTS = {
    sliders: 'Start from a parametric template and drive it with sliders.',
    code: 'Write OpenSCAD directly. Parameters you declare become sliders too.',
    nodes: 'Snap together primitives and boolean operations — no code required.'
  };
  function refreshCreateEnabled() {
    document.getElementById('czCreateBtn').disabled =
      (newMode === 'import') ? !pickSrc : false;
  }
  document.getElementById('czNewBtn').addEventListener('click', () => { modal.hidden = false; });
  document.getElementById('czCancelBtn').addEventListener('click', () => { modal.hidden = true; });
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.hidden = true; });

  document.getElementById('czModeTabs').addEventListener('click', (e) => {
    const b = e.target.closest('[data-newmode]'); if (!b) return;
    newMode = b.dataset.newmode;
    document.querySelectorAll('#czModeTabs .cz-srcpill').forEach(x => x.classList.toggle('active', x === b));
    document.getElementById('czImportPick').hidden = (newMode !== 'import');
    document.getElementById('czDesignPick').hidden = (newMode !== 'design');
    refreshCreateEnabled();
  });
  document.getElementById('czDesignModes').addEventListener('click', (e) => {
    const b = e.target.closest('[data-dmode]'); if (!b) return;
    designMode = b.dataset.dmode;
    document.querySelectorAll('#czDesignModes .cz-srcpill').forEach(x => x.classList.toggle('active', x === b));
    document.getElementById('czDesignHint').textContent = DESIGN_HINTS[designMode] || '';
  });

  document.getElementById('czSrcList').addEventListener('click', (e) => {
    const s = e.target.closest('.cz-src');
    if (!s) return;
    document.querySelectorAll('.cz-src').forEach(x => x.classList.remove('active'));
    s.classList.add('active');
    pickSrc = { src: s.dataset.src, folder: s.dataset.folder };
    refreshCreateEnabled();
  });

  // Search + source-pill filtering of the import list.
  let czFilterSrc = '';
  function applyImportFilter() {
    const q = (document.getElementById('czSrcSearch').value || '').toLowerCase().trim();
    document.querySelectorAll('#czSrcList .cz-src').forEach(row => {
      const matchSrc = !czFilterSrc || row.dataset.src === czFilterSrc;
      const matchQ = !q || (row.dataset.search || '').includes(q);
      row.style.display = (matchSrc && matchQ) ? '' : 'none';
    });
  }
  document.getElementById('czSrcSearch').addEventListener('input', applyImportFilter);
  document.getElementById('czSrcPills').addEventListener('click', (e) => {
    const pill = e.target.closest('.cz-srcpill');
    if (!pill) return;
    document.querySelectorAll('.cz-srcpill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    czFilterSrc = pill.dataset.src || '';
    applyImportFilter();
  });

  document.getElementById('czCreateBtn').addEventListener('click', async () => {
    const name = document.getElementById('czNewName').value.trim();
    const msg = document.getElementById('czCreateMsg');
    if (!name) { msg.style.color = 'var(--err)'; msg.textContent = 'Give the project a name.'; return; }
    const btn = document.getElementById('czCreateBtn');
    if (newMode === 'design') {
      btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Creating…';
      const d = await post({ action:'create_design', name, designMode });
      if (d.ok) { modal.hidden = true; location.href = 'customize.php'; }
      else { msg.style.color = 'var(--err)'; msg.textContent = d.error || 'Failed.'; btn.disabled = false; }
      return;
    }
    if (!pickSrc) { msg.style.color = 'var(--err)'; msg.textContent = 'Pick a model to import.'; return; }
    btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = 'Creating…';
    const d = await post({ action:'create', name, src:pickSrc.src, folder:pickSrc.folder });
    if (d.ok) { modal.hidden = true; location.href = 'customize.php'; }
    else { msg.style.color = 'var(--err)'; msg.textContent = d.error || 'Failed.'; btn.disabled = false; }
  });

  // Deep link: customize.php?src=<slug>&folder=<folder> opens the New Project
  // modal with that model preselected (used by the viewer/library "Customize
  // this" links for .scad-only parametric models).
  (function () {
    const p = new URLSearchParams(location.search);
    const dsrc = p.get('src'), dfolder = p.get('folder');
    if (!dsrc || !dfolder) return;
    modal.hidden = false;
    const sel = '#czSrcList .cz-src[data-src="' + CSS.escape(dsrc) + '"][data-folder="' + CSS.escape(dfolder) + '"]';
    const row = document.querySelector(sel);
    if (!row) return;
    document.querySelectorAll('.cz-src').forEach(x => x.classList.remove('active'));
    row.classList.add('active');
    row.style.display = '';
    pickSrc = { src: row.dataset.src, folder: row.dataset.folder };
    document.getElementById('czCreateBtn').disabled = false;
    row.scrollIntoView({ block: 'center' });
    const nameEl = document.getElementById('czNewName');
    if (nameEl && !nameEl.value) nameEl.value = (row.dataset.folder || '').replace(/^\d+\s*-\s*/, '').slice(0, 60);
  })();
  // ===== Unified tool + keyboard system (works for both viewers) =====
  let selectedPart = null, arr_updateCtxPos = null, ctxMode = 'move';

  function showCtxTools() {
    document.getElementById('czCtxTools').hidden = false;
    setCtxMode(ctxMode);
  }
  function hideCtxTools() {
    document.getElementById('czCtxTools').hidden = true;
    selectedPart = null;
    if (arr && arr.tcontrols) arr.tcontrols.detach();
  }
  function setCtxMode(mode) {
    ctxMode = mode;
    if (arr && arr.tcontrols) arr.tcontrols.setMode(mode === 'rotate' ? 'rotate' : 'translate');
    const mv = document.getElementById('czCtxMove'), rt = document.getElementById('czCtxRot');
    if (mv) mv.classList.toggle('active', mode === 'move');
    if (rt) rt.classList.toggle('active', mode === 'rotate');
  }
  function resetSelectedPart() {
    if (selectedPart && selectedPart.userData.home) {
      selectedPart.position.copy(selectedPart.userData.home.pos);
      selectedPart.rotation.copy(selectedPart.userData.home.rot);
    }
  }

  // Contextual toolbar buttons
  document.getElementById('czCtxMove').addEventListener('click', () => setCtxMode('move'));
  document.getElementById('czCtxRot').addEventListener('click', () => setCtxMode('rotate'));
  document.getElementById('czCtxReset').addEventListener('click', resetSelectedPart);
  document.getElementById('czCtxClose').addEventListener('click', hideCtxTools);

  function setTool(tool) {
    if (tool === 'move' || tool === 'rotate') setCtxMode(tool);
  }
  function setView(which) {
    // Works on whichever scene is live (arrange = arr, else viewer-core orbit).
    const cam = arr ? arr.camera : (viewer && viewer.getCamera && viewer.getCamera());
    const orbit = arr ? arr.orbit : (viewer && viewer.getControls && viewer.getControls());
    if (!cam || !orbit) return;
    const t = orbit.target.clone();
    const d = cam.position.distanceTo(t) || 200;
    const pos = { front:[0,0,d], side:[d,0,0], top:[0,d,0.001], iso:[d*0.7,d*0.7,d*0.7] }[which];
    if (!pos) return;
    cam.position.set(t.x+pos[0], t.y+pos[1], t.z+pos[2]);
    cam.lookAt(t); orbit.update();
  }
  function doFrame() {
    if (arr && arr.frameAll) arr.frameAll();
    else if (viewer && viewer.frameAll) viewer.frameAll();
  }
  function resetArrange() {
    resetSelectedPart();
  }

  // Toolbar clicks
  document.getElementById('czToolbar').addEventListener('click', e => {
    const b = e.target.closest('.cz-tool'); if (!b) return;
    const tool = b.dataset.tool;
    if (tool === 'move' || tool === 'rotate') setTool(tool);
    else if (tool === 'reset') resetArrange();
    else if (tool === 'frame') doFrame();
    else if (['top','front','side','iso'].includes(tool)) setView(tool);
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', e => {
    if (editor.hidden) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) return;
    const k = e.key.toLowerCase();
    if (k === 'g') setTool('move');
    else if (k === 'r') setTool('rotate');
    else if (k === '0') resetArrange();
    else if (k === 'f') doFrame();
    else if (k === '1') setView('front');
    else if (k === '3') setView('side');
    else if (k === '7') setView('top');
    else if (k === '5') setView('iso');
    else if (k === 'escape') { hideCtxTools(); }
    else if (e.key === 'Enter') { const rb = document.getElementById('czRenderBtn'); if (rb && !pWrap.hidden && !rb.disabled) rb.click(); }
  });

  // Help popover
  document.getElementById('czHelpChip').addEventListener('click', () => {
    const p = document.getElementById('czHelpPop'); p.hidden = !p.hidden;
  });


  let arr = null; // {scene,camera,renderer,orbit,tcontrols,parts:[],raycaster}
  function arrangeAvailable(meta) {
    // Multi-part = variants mode with 2+ STL meshes we can load as movable parts.
    return meta.mode === 'variants' && (meta.variants || []).length >= 2;
  }

  function startArrange(id, variants) {
    document.getElementById('czArrange').hidden = false;
    const host = document.getElementById('czCanvas');
    host.innerHTML = '';
    const w = host.clientWidth || 600, h = host.clientHeight || 440;
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 5000);
    camera.position.set(150, 150, 150);
    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(w, h); renderer.setClearColor(0x1c1b1a);
    host.appendChild(renderer.domElement);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x444444, 1.1));
    const dl = new THREE.DirectionalLight(0xffffff, 0.8); dl.position.set(1, 1, 1); scene.add(dl);
    const grid = new THREE.GridHelper(300, 30, 0x444444, 0x2a2a2a); scene.add(grid);
    const orbit = new OrbitControls(camera, renderer.domElement);
    const tcontrols = new TransformControls(camera, renderer.domElement);
    tcontrols.addEventListener('dragging-changed', e => orbit.enabled = !e.value);
    // three r160+: TransformControls is no longer an Object3D — its visual gizmo
    // must be added to the scene via getHelper(). Using scene.add(tcontrols)
    // (pre-r160 style) silently fails, so the gizmo never appears and parts
    // can't be moved/selected. getHelper() restores the gizmo.
    scene.add(typeof tcontrols.getHelper === 'function' ? tcontrols.getHelper() : tcontrols);
    const raycaster = new THREE.Raycaster(), mouse = new THREE.Vector2();
    const parts = [];
    const loader = new STLLoader();

    let loaded = 0;
    variants.forEach((v, i) => {
      loader.load(projFileUrl(id, v.file), geo => {
        geo.computeVertexNormals();
        // Center the geometry on its own bounding-box center, then push the mesh
        // back out by that offset. The part stays exactly where it was visually,
        // but its ORIGIN is now its center — so TransformControls puts the gizmo
        // at the middle of each part instead of all at the shared world origin.
        geo.computeBoundingBox();
        const center = new THREE.Vector3();
        geo.boundingBox.getCenter(center);
        geo.translate(-center.x, -center.y, -center.z);
        const mat = new THREE.MeshStandardMaterial({ color: 0xd0883f, metalness: .1, roughness: .7 });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(center.x, center.y, center.z);
        mesh.userData = { file: v.file, label: v.label, home: null };
        scene.add(mesh); parts.push(mesh);
        loaded++;
        if (loaded === variants.length) frameAll();
      });
    });

    function frameAll() {
      const box = new THREE.Box3();
      parts.forEach(p => box.expandByObject(p));
      const c = box.getCenter(new THREE.Vector3()), s = box.getSize(new THREE.Vector3());
      const d = Math.max(s.x, s.y, s.z) || 100;
      orbit.target.copy(c);
      camera.position.set(c.x + d, c.y + d, c.z + d);
      camera.near = d / 100; camera.far = d * 100; camera.updateProjectionMatrix();
      orbit.update();
      parts.forEach(p => p.userData.home = { pos: p.position.clone(), rot: p.rotation.clone() });
      document.getElementById('czHint').style.display = 'none';
    }

    renderer.domElement.addEventListener('pointerdown', e => {
      if (tcontrols.dragging) return;
      const r = renderer.domElement.getBoundingClientRect();
      mouse.x = ((e.clientX - r.left) / r.width) * 2 - 1;
      mouse.y = -((e.clientY - r.top) / r.height) * 2 + 1;
      raycaster.setFromCamera(mouse, camera);
      const hit = raycaster.intersectObjects(parts, false)[0];
      // Only (re)select when a part is actually clicked. Clicking empty space
      // keeps the current selection — deselect is via the ✕ button or Esc.
      if (hit) { tcontrols.attach(hit.object); selectedPart = hit.object; showCtxTools(); }
    });

    // Position the contextual toolbar under the selected part each frame.
    function updateCtxPos() {
      if (!selectedPart) return;
      const box = new THREE.Box3().setFromObject(selectedPart);
      const c = box.getCenter(new THREE.Vector3());
      c.y = box.min.y; // bottom of the part
      const v = c.clone().project(camera);
      const host = renderer.domElement;
      const x = (v.x * 0.5 + 0.5) * host.clientWidth;
      const y = (-v.y * 0.5 + 0.5) * host.clientHeight;
      const tb = document.getElementById('czCtxTools');
      tb.style.left = x + 'px';
      tb.style.top = Math.min(y + 14, host.clientHeight - 46) + 'px';
    }
    arr_updateCtxPos = updateCtxPos;

    (function loop() { requestAnimationFrame(loop); orbit.update(); if (arr_updateCtxPos) arr_updateCtxPos(); renderer.render(scene, camera); })();
    arr = { scene, camera, renderer, orbit, tcontrols, parts, frameAll };

    // Keep the arrange renderer matched to its container.
    const arrResize = () => {
      const nw = host.clientWidth, nh = host.clientHeight;
      if (nw && nh) { renderer.setSize(nw, nh); camera.aspect = nw / nh; camera.updateProjectionMatrix(); }
    };
    window.addEventListener('resize', arrResize);
    if (window.ResizeObserver) { new ResizeObserver(arrResize).observe(host); }
    requestAnimationFrame(arrResize);
  }

  function exportArrange() {
    // Merge all parts (with current transforms) into one STL string.
    if (!arr) return null;
    let out = 'solid farfetched\n';
    const v = new THREE.Vector3();
    arr.parts.forEach(mesh => {
      mesh.updateMatrixWorld(true);
      const geo = mesh.geometry, pos = geo.attributes.position;
      const idx = geo.index;
      const tri = (a, b, c) => {
        const pa = v.clone().fromBufferAttribute(pos, a).applyMatrix4(mesh.matrixWorld);
        const pb = new THREE.Vector3().fromBufferAttribute(pos, b).applyMatrix4(mesh.matrixWorld);
        const pc = new THREE.Vector3().fromBufferAttribute(pos, c).applyMatrix4(mesh.matrixWorld);
        const n = pb.clone().sub(pa).cross(pc.clone().sub(pa)).normalize();
        out += `facet normal ${n.x} ${n.y} ${n.z}\nouter loop\n`;
        out += `vertex ${pa.x} ${pa.y} ${pa.z}\nvertex ${pb.x} ${pb.y} ${pb.z}\nvertex ${pc.x} ${pc.y} ${pc.z}\n`;
        out += 'endloop\nendfacet\n';
      };
      if (idx) for (let i = 0; i < idx.count; i += 3) tri(idx.getX(i), idx.getX(i + 1), idx.getX(i + 2));
      else for (let i = 0; i < pos.count; i += 3) tri(i, i + 1, i + 2);
    });
    out += 'endsolid farfetched\n';
    return out;
  }

</script>
<script src="js/theme.js"></script>
</body>
</html>
