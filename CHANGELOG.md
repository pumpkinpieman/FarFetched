# Changelog

## v1.7.0

### ⚠️ Upgrade notes (read first)
- **Deploy the complete `webroot/` as one set.** Partial deploys (e.g. updating
  `settings.php` without `bootstrap.php`) will fail with `no such column`.
- **Restart the container / reset PHP OPcache after updating** so the new
  `bootstrap.php` (with auto-migrations) loads instead of a cached copy.
- **Database migrates automatically.** On first page load after upgrade,
  `run_migrations()` adds any missing columns. No manual SQL required.
- **Storage:** keep the SQLite database (`private/fetcher.db`) on stable local
  storage — NOT on FUSE / NFS / SMB / Unraid array+cache user-shares. On Unraid,
  set the `appdata` share to **cache-only**. See the README storage note.

### Fixed — database locking & stability
- Resolve persistent `database is locked`, `readonly database`, and
  `database error while queueing` crashes. Root cause was the SQLite DB on an
  Unraid FUSE array+cache share; once on stable storage, WAL functions and
  writes drop from ~600 ms to ~12 ms.
- Set `busy_timeout` before `journal_mode`; only change `journal_mode` when
  needed; run `init_schema` only on fresh/incomplete DBs (no write-on-connect).
- Worker releases its DB connection during downloads and pacing
  (`db_disconnect()`), so it no longer holds the database open across long ops.
- `db_exec_retry()` releases the write mutex before its backoff sleep
  (previously blocked the UI for the full backoff).
- Wrap the enqueue transaction in the write mutex + busy-retry; detach the
  worker launch (`setsid nohup … < /dev/null &`) so queueing returns instantly.
- Route all "My Printers" writes through the mutex (fixes printers not saving).

### Added — automatic DB migrations
- `run_migrations()` runs unconditionally (once per process) and adds missing
  columns on upgrade. New schema columns reach existing installs, not just
  fresh ones. Declarative `$columns` table — one line per future migration.

### Added — source thumbnails
- Capture the source's cover image URL at enqueue and save it as `source.png`
  on download completion, so My Library shows the real image instead of a
  generated render. Confirmed resolvers: MakerWorld, Thingiverse.
- New **"Grab thumbnails from source"** button in My Library (backfill).

### Added — 3D viewer "View model on bed"
- Optional toggle to show the model sitting on a printer build plate, sized from
  the selected printer's bed dimensions, with a brand-tinted PEI-style surface.

### Added — OctoPrint integration (per printer)
- New **OctoPrint** tab in Settings. Each printer can store its own OctoPrint
  URL + API key. Test connection, view live status (state, temps, job progress),
  upload downloaded files, and control prints (start/pause/resume/cancel).
- Note: OctoPrint prints **gcode**; STL/3MF upload as model files but must be
  sliced before printing.

### Changed
- Force `keep_zip` for Hex3D to prevent a re-download loop.
- `printers_enabled.php` now returns each printer's `brand`.
- `schema.sql` updated to reflect the current schema (all tables + OctoPrint
  columns). Reference only — the app builds its schema from `bootstrap.php`.

### Docs
- README: storage-placement warning (don't run the DB on FUSE/network/array+
  cache shares; Unraid → appdata cache-only).
