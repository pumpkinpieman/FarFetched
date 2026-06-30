# Changelog — v1.7.0

## Upgrade notes (read first)
- Deploy the complete `webroot/` as one set. The database migrates automatically
  on first page load (new columns added by `run_migrations()`); no manual SQL.
- After updating, recreate the container / reset OPcache so the new code loads.
- OpenSCAD is now baked into the image (Customize & Pose rendering). After
  pulling the new image, the Customize page renders without any manual install.

## Added
- **OpenSCAD baked into the image** — openscad, xvfb, xauth added to the
  Dockerfile so the Customize & Pose parametric engine renders out of the box.
- **Automatic DB migrations** — run_migrations() adds missing columns on
  upgrade (declarative, idempotent). Reaches existing installs, not just fresh.
- **OctoPrint integration (per printer)** — Settings -> OctoPrint: store each
  printer's URL + API key, test connection, view live status, upload files, and
  control prints.
- **3D viewer "View model on bed"** — optional toggle showing the model on a
  brand-tinted printer build plate sized from the printer's bed dimensions.
- **Source thumbnails** — capture the source cover at enqueue and save as
  source.png on completion; "Grab thumbnails from source" backfill button.
- **Author features (Browse)** — clickable creator names (in-app search) plus a
  card footer with "More by author" and "Source" links.
- **Library author display** — downloads now record the creator; My Library shows
  a clickable "by <author>" that searches that creator.
- **CSV bulk import** — Queue page: download a template, import models in bulk
  (Model URL, Source Thumbnail, Collection, Favorites), with per-row error
  reporting logged to the activity log. Imported collection is applied after the
  download completes.
- **Source links in queue** — each job links to its model page on the source.
- **Deselect all** button alongside Select all on page.
- **Skipped status split** — paywalled / no_files / error instead of a single
  vague "skipped", with distinct badges, filters, and sort.

## Fixed
- **SQLite locking / readonly-database crashes** — DB moved off FUSE array+cache
  storage; WAL works on stable storage; busy_timeout ordering; mutex-wrapped
  writes; worker releases its connection during downloads/pacing; detached worker
  launch. Writes dropped from ~600 ms to ~12 ms.
- **Thingiverse downloads** — Thingiverse removed its server-side ZIP endpoint
  (now HTTP 400). The worker now fetches all files for a model with a light
  inter-file delay (full pacing applies only between models), instead of pacing
  the full per-source delay after every single file. Optional local zip bundle
  when keep_zip is on.
- **MakerWorld parametric private source** — when a model's source file is
  private, download the available STL/3MF instances and warn, instead of
  skipping the whole model.
- **Customize & Pose move/select** — three.js r160 changed TransformControls
  (no longer an Object3D); add its gizmo via getHelper(). Parts can be selected
  and moved again.
- **Library author color** — creator text used a hardcoded light color that was
  invisible on the light theme; now uses theme variables and shows on all themes.

## Changed
- Library card footers given more height and a divider for clearer separation.
- schema.sql regenerated to match the current schema (reference only; the app
  builds its schema from bootstrap.php).
