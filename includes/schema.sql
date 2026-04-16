-- Schema for the Biserica Sfântul Vasile event calendar.
-- Applied automatically by includes/db.php on the first request.

CREATE TABLE IF NOT EXISTS events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    description   TEXT    NOT NULL DEFAULT '',
    event_date    TEXT    NOT NULL,                  -- ISO date: YYYY-MM-DD
    start_time    TEXT,                              -- HH:MM or NULL
    end_time      TEXT,                              -- HH:MM or NULL
    location      TEXT    NOT NULL DEFAULT '',
    category      TEXT    NOT NULL DEFAULT 'liturghie',
    is_published  INTEGER NOT NULL DEFAULT 1,        -- 0 draft, 1 public
    created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE INDEX IF NOT EXISTS idx_events_date      ON events(event_date);
CREATE INDEX IF NOT EXISTS idx_events_published ON events(is_published, event_date);

CREATE TABLE IF NOT EXISTS announcements (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    title          TEXT    NOT NULL,
    body           TEXT    NOT NULL DEFAULT '',
    tag            TEXT    NOT NULL DEFAULT '',         -- short label shown as pill
    relevant_on    TEXT    NOT NULL,                    -- ISO "valid from" / single-date
    relevant_until TEXT,                                -- ISO "valid until" (interval mode); NULL otherwise
    visible_days   INTEGER,                             -- auto-hide N days after created_at; NULL otherwise
    is_published   INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE INDEX IF NOT EXISTS idx_announcements_pub ON announcements(is_published, relevant_on);

CREATE TABLE IF NOT EXISTS gallery_categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    position   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE IF NOT EXISTS gallery_photos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    description TEXT    NOT NULL DEFAULT '',
    file_path   TEXT    NOT NULL,                      -- webroot-relative, eg "uploads/gallery/2026/04/abcd.jpg"
    file_hash   TEXT,                                  -- sha256 of file bytes (dedup)
    mime_type   TEXT    NOT NULL DEFAULT '',
    width       INTEGER,
    height      INTEGER,
    size_bytes  INTEGER,
    position    INTEGER NOT NULL DEFAULT 0,            -- manual ordering within gallery
    is_published INTEGER NOT NULL DEFAULT 1,           -- 0 draft / 1 public
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE INDEX IF NOT EXISTS idx_gallery_photos_pub ON gallery_photos(is_published, position, id);

CREATE TABLE IF NOT EXISTS gallery_photo_categories (
    photo_id    INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY (photo_id, category_id),
    FOREIGN KEY (photo_id)    REFERENCES gallery_photos(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES gallery_categories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_gallery_pc_cat ON gallery_photo_categories(category_id);

