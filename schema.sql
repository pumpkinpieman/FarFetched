-- schema.sql — reference only.
-- bootstrap.php::init_schema() creates this automatically on first run, and
-- run_migrations() adds any missing columns on upgrade. This file is provided
-- for documentation / manual inspection only:
--     sqlite3 fetcher.db < schema.sql
--
-- Keep this in sync with init_schema() in webroot/bootstrap.php when the schema
-- changes. The application never reads this file.

-- ===========================================================================
-- Download queue — one row per requested model/file across all sources.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS download_jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source       TEXT    NOT NULL DEFAULT 'printables', -- printables|makerworld|thingiverse|cults3d|stlflix|creality|nikko|hex3dforum
    model_id     TEXT    NOT NULL,
    slug         TEXT    NOT NULL DEFAULT '',
    name         TEXT    NOT NULL DEFAULT '',
    creator      TEXT    NOT NULL DEFAULT '',
    file_type    TEXT    NOT NULL DEFAULT 'STL',         -- STL | 3MF | PACK
    status       TEXT    NOT NULL DEFAULT 'queued',      -- queued|working|done|failed|skipped
    attempts     INTEGER NOT NULL DEFAULT 0,
    last_error   TEXT    NOT NULL DEFAULT '',
    saved_path   TEXT    NOT NULL DEFAULT '',
    cover_url    TEXT    NOT NULL DEFAULT '',             -- source cover image, saved as source.png on completion
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(source, model_id, file_type)                  -- re-queue = no-op
);

CREATE INDEX IF NOT EXISTS idx_jobs_status         ON download_jobs(status);
CREATE INDEX IF NOT EXISTS idx_jobs_status_updated ON download_jobs(status, updated_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_jobs_src_unique ON download_jobs(source, model_id, file_type);

-- ===========================================================================
-- Favorites — starred models, server-side so they persist across devices.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS favorites (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source       TEXT    NOT NULL,
    model_id     TEXT    NOT NULL,
    slug         TEXT    NOT NULL DEFAULT '',
    name         TEXT    NOT NULL DEFAULT '',
    creator      TEXT    NOT NULL DEFAULT '',
    thumb        TEXT    NOT NULL DEFAULT '',
    price        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(source, model_id)
);

CREATE INDEX IF NOT EXISTS idx_fav_source ON favorites(source);

-- ===========================================================================
-- Prints — per-folder print tracking (counts, notes, last printed).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS prints (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source       TEXT    NOT NULL,
    folder       TEXT    NOT NULL,
    print_count  INTEGER NOT NULL DEFAULT 0,
    notes        TEXT    NOT NULL DEFAULT '',
    last_printed TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(source, folder)
);

-- ===========================================================================
-- Collections — user-defined groupings of downloaded models.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(name)
);

CREATE TABLE IF NOT EXISTS collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    source        TEXT    NOT NULL,
    folder        TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(collection_id, source, folder),
    FOREIGN KEY(collection_id) REFERENCES collections(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_colitems_col ON collection_items(collection_id);

-- ===========================================================================
-- Printers — "My Printers", incl. per-printer OctoPrint connection.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS printers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL,
    nickname          TEXT    NOT NULL DEFAULT '',
    brand             TEXT    NOT NULL DEFAULT '',
    bed_x             INTEGER NOT NULL DEFAULT 0,
    bed_y             INTEGER NOT NULL DEFAULT 0,
    bed_z             INTEGER NOT NULL DEFAULT 0,
    image             TEXT    NOT NULL DEFAULT '',
    enabled           INTEGER NOT NULL DEFAULT 1,
    is_custom         INTEGER NOT NULL DEFAULT 0,
    octoprint_url     TEXT    NOT NULL DEFAULT '',         -- e.g. http://octopi.local
    octoprint_api_key TEXT    NOT NULL DEFAULT '',         -- OctoPrint API key
    octoprint_enabled INTEGER NOT NULL DEFAULT 0,          -- 0|1
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ===========================================================================
-- Hex3D Forum crawler — indexed topics and crawl state (singleton row).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS hex3d_topics (
    forum_id       TEXT    NOT NULL,
    topic_id       TEXT    NOT NULL,
    forum_name     TEXT    NOT NULL DEFAULT '',
    title          TEXT    NOT NULL DEFAULT '',
    thumb          TEXT    NOT NULL DEFAULT '',
    attachment_ids TEXT    NOT NULL DEFAULT '[]',
    detail_done    INTEGER NOT NULL DEFAULT 0,
    first_seen     TEXT    NOT NULL DEFAULT (datetime('now')),
    indexed_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (forum_id, topic_id)
);

CREATE INDEX IF NOT EXISTS idx_hex3d_forum  ON hex3d_topics(forum_id);
CREATE INDEX IF NOT EXISTS idx_hex3d_detail ON hex3d_topics(detail_done);

CREATE TABLE IF NOT EXISTS hex3d_crawl_state (
    id           INTEGER PRIMARY KEY CHECK (id = 1),
    status       TEXT    NOT NULL DEFAULT 'idle',
    started_at   TEXT    NOT NULL DEFAULT '',
    finished_at  TEXT    NOT NULL DEFAULT '',
    topics_seen  INTEGER NOT NULL DEFAULT 0,
    details_done INTEGER NOT NULL DEFAULT 0,
    last_error   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ===========================================================================
-- Projects — Customize & Pose workshop (variants / parametric / arrange).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    src_slug   TEXT,
    src_folder TEXT,
    mode       TEXT NOT NULL DEFAULT 'variants',
    work_dir   TEXT NOT NULL,
    state      TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
