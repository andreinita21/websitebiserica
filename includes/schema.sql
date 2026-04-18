-- ============================================================================
-- Biserica Sfântul Vasile — Unified database schema (SQLite)
-- ----------------------------------------------------------------------------
-- This is the single source of truth for every table, index and foreign key.
-- Applied by includes/db.php on the first request (and re-run on every load —
-- every statement is idempotent thanks to "IF NOT EXISTS"). Seed data is kept
-- in PHP (bsv_seed_test_data in db.php) so the schema file stays pure DDL.
--
-- Layout by domain:
--     1. Events             — event_categories, event_locations, events
--     2. Announcements      — announcements
--     3. Gallery            — gallery_categories, gallery_photos,
--                             gallery_photo_categories (pivot)
--     4. Clergy             — clergy
--     5. Site settings      — site_settings (key/value)
--
-- Conventions:
--   * Every timestamp column is a TEXT "YYYY-MM-DD HH:MM:SS" in UTC-ish
--     localtime. No DATETIME type — SQLite is dynamically typed.
--   * is_published is a tinyint (0/1). Rows with 0 are hidden from the public
--     API but remain visible in /admin.
--   * position is a manual sort key — lower = earlier. Callers should ORDER BY
--     position, then id for a stable secondary sort.
-- ============================================================================


-- 1. EVENTS DOMAIN -----------------------------------------------------------
-- event_categories and event_locations are managed inline from the Events
-- admin section (admin/index.php?view=categories|locations). events.category
-- stores the slug (soft FK); events.location stores the canonical name.

CREATE TABLE IF NOT EXISTS event_categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,                 -- ASCII-safe key stored in events.category
    label      TEXT    NOT NULL,                        -- display name shown in UI
    position   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_event_categories_pos ON event_categories(position, id);

CREATE TABLE IF NOT EXISTS event_locations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,                 -- canonical name copied into events.location
    position   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_event_locations_pos ON event_locations(position, id);

CREATE TABLE IF NOT EXISTS events (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    title                 TEXT    NOT NULL,
    description           TEXT    NOT NULL DEFAULT '',
    event_date            TEXT    NOT NULL,                  -- ISO date: YYYY-MM-DD (anchor / first occurrence)
    start_time            TEXT,                              -- HH:MM or NULL
    end_time              TEXT,                              -- HH:MM or NULL
    location              TEXT    NOT NULL DEFAULT '',       -- plain string, suggested from event_locations
    category              TEXT    NOT NULL DEFAULT 'liturghie', -- slug, soft FK to event_categories.slug
    recurrence_type       TEXT,                              -- NULL | 'weekly' | 'monthly' | 'yearly'
    recurrence_end_date   TEXT,                              -- ISO date, inclusive; NULL = no end
    is_published          INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at            TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_events_date       ON events(event_date);
CREATE INDEX IF NOT EXISTS idx_events_published  ON events(is_published, event_date);
CREATE INDEX IF NOT EXISTS idx_events_recurrence ON events(recurrence_type);


-- 2. ANNOUNCEMENTS -----------------------------------------------------------
-- One row per announcement. The "tag" column is free-form text (not managed
-- by a taxonomy table). Three validity modes coexist:
--   * single:   relevant_on set, relevant_until NULL, visible_days NULL
--   * interval: relevant_on + relevant_until set
--   * duration: relevant_on set, visible_days > 0 (auto-hide N days after
--               created_at — see api/announcements.php)

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


-- 3. GALLERY -----------------------------------------------------------------
-- gallery_categories is managed inline from the Gallery admin section
-- (admin/gallery.php?view=categories). Photos attach to any number of
-- categories via the gallery_photo_categories pivot (cascade on delete).

CREATE TABLE IF NOT EXISTS gallery_categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    position   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_gallery_categories_pos ON gallery_categories(position, id);

CREATE TABLE IF NOT EXISTS gallery_photos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL DEFAULT '',
    description  TEXT    NOT NULL DEFAULT '',
    file_path    TEXT    NOT NULL,                      -- webroot-relative, eg "uploads/gallery/2026/04/abcd.jpg"
    file_hash    TEXT,                                  -- sha256 of file bytes (dedup)
    mime_type    TEXT    NOT NULL DEFAULT '',
    width        INTEGER,
    height       INTEGER,
    size_bytes   INTEGER,
    variants     TEXT,                                  -- JSON array of responsive renditions (webp + fallback)
    position     INTEGER NOT NULL DEFAULT 0,
    is_published INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
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


-- 4. CLERGY ------------------------------------------------------------------
-- role is a free-form TEXT column (no taxonomy) so the admin can write
-- anything: "Paroh", "Diacon", "Cântăreț bisericesc" etc.

CREATE TABLE IF NOT EXISTS clergy (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    role         TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    photo_path   TEXT    NOT NULL DEFAULT '',          -- webroot-relative, e.g. "uploads/clergy/abcd.jpg" or '' for placeholder
    position     INTEGER NOT NULL DEFAULT 0,
    is_published INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_clergy_pub ON clergy(is_published, position, id);


-- 5. SITE SETTINGS -----------------------------------------------------------
-- Flat key/value store for editable site-wide content (contact info, map).
-- Defaults are seeded from BSV_CONTACT_DEFAULTS (see includes/settings.php).

CREATE TABLE IF NOT EXISTS site_settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
