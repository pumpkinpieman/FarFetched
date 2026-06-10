-- schema.sql — reference only.
-- bootstrap.php::init_schema() creates this automatically on first run.
-- Provided for documentation or manual inspection (sqlite3 fetcher.db < schema.sql).

CREATE TABLE IF NOT EXISTS download_jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    model_id     TEXT    NOT NULL,
    slug         TEXT    NOT NULL DEFAULT '',
    name         TEXT    NOT NULL DEFAULT '',
    creator      TEXT    NOT NULL DEFAULT '',
    file_type    TEXT    NOT NULL DEFAULT 'STL',      -- STL | 3MF
    status       TEXT    NOT NULL DEFAULT 'queued',   -- queued|working|done|failed|skipped
    attempts     INTEGER NOT NULL DEFAULT 0,
    last_error   TEXT    NOT NULL DEFAULT '',
    saved_path   TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(model_id, file_type)                       -- re-queue = no-op
);

CREATE INDEX IF NOT EXISTS idx_jobs_status ON download_jobs(status);
