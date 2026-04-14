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
