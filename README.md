# Biserica Sfântul Vasile — Calendar, announcements & gallery

A lightweight, build-free PHP + SQLite extension to the existing static site.
It adds a public calendar page, a protected admin area for managing events,
announcements, and a photo gallery, plus dynamic blocks on the homepage.
No `npm`, no bundlers, no frontend framework — everything is served directly.

---

## 1. Architecture at a glance

```
┌──────────────────────────────────────────────┐
│ Visitor                                       │
│   index.html  ← homepage-events.js  ──┐       │
│   calendar.html ← calendar.js  ───────┤       │
│                                       ▼       │
│                              api/events.php   │ (read-only JSON feed)
│                                       │       │
│                                       ▼       │
│                               data/events.db  │  SQLite
│                                       ▲       │
│ Administrator                          │      │
│   admin/login.php → admin/index.php ──┘      │
│                     admin/event.php           │  (create / edit / delete)
└──────────────────────────────────────────────┘
```

- **SQLite** (`data/events.db`) is the single source of truth. It is created
  and seeded automatically on the first HTTP request.
- **Public side** (homepage + calendar) only *reads* from the database through
  `api/events.php`, a small JSON endpoint.
- **Admin side** is server-rendered PHP with session auth. No admin JavaScript,
  no separate API — forms post directly to the page, which renders the next
  state. That keeps the attack surface tiny and the code obvious.
- **No build step**: every CSS and JS file is loaded directly from `/assets/…`.
  Two CDN links are kept (Google Fonts + Material Symbols) to match the rest
  of the site.

---

## 2. Folder structure

```
BisericaSfVasile/
├── index.html                 # homepage (upcoming events + announcements dynamic)
├── despre.html
├── contact.html
├── calendar.html              # public calendar page (month/week/list)
├── galerie.html               # NEW — public gallery page
│
├── admin/
│   ├── _layout.php            # shared header/footer for admin pages
│   ├── index.php              # event list + delete
│   ├── event.php              # event create + edit form
│   ├── announcements.php      # announcement list + delete
│   ├── announcement.php       # announcement create + edit form
│   ├── gallery.php            # NEW — photo list, upload, delete
│   ├── gallery-photo.php      # NEW — edit photo title/description/categories
│   ├── gallery-categories.php # NEW — CRUD on gallery categories
│   ├── login.php              # authentication form
│   ├── logout.php
│   └── .htaccess              # blocks direct access to _layout.php
│
├── api/
│   ├── events.php             # JSON feed of published events
│   ├── announcements.php      # JSON feed of active announcements
│   └── gallery.php            # NEW — JSON feed of published photos + categories
│
├── uploads/                   # NEW — web-accessible image storage
│   ├── .htaccess              # allow images only, disable PHP execution
│   └── gallery/YYYY/MM/…      # auto-created by the uploader
│
├── data/
│   ├── events.db              # SQLite database (auto-created, gitignored)
│   └── .htaccess              # deny all — protects the DB on Apache
│
├── includes/
│   ├── config.php             # paths, admin credentials, event categories
│   ├── config.local.example.php
│   ├── config.local.php       # your overrides (gitignored)
│   ├── db.php                 # PDO connection + schema install + seed + migrations
│   ├── auth.php               # session, CSRF, bcrypt login
│   ├── helpers.php            # sanitisation + Romanian date formatting
│   ├── gallery.php            # NEW — upload validation, slug helpers, queries
│   ├── schema.sql             # CREATE TABLE statements (events + announcements + gallery)
│   └── .htaccess              # deny all — no direct access to PHP partials
│
└── assets/
    ├── css/
    │   ├── main.css           # global design system
    │   ├── calendar.css       # calendar widget + homepage loader
    │   ├── gallery.css        # NEW — grid, filter chips, lightbox
    │   └── admin.css          # admin UI (now covers gallery too)
    └── js/
        ├── main.js            # header/menu/reveal
        ├── calendar.js        # custom vanilla calendar (no CDN)
        ├── gallery.js         # NEW — filter + FLIP animation + lightbox
        ├── homepage-announcements.js
        └── homepage-events.js
```

---

## 3. SQLite schema

See [`includes/schema.sql`](includes/schema.sql). The table is intentionally
flat:

| column          | type    | notes                                               |
|-----------------|---------|-----------------------------------------------------|
| id              | INTEGER | primary key                                         |
| title           | TEXT    | required, max 180 chars                             |
| description     | TEXT    | optional, max 5000 chars                            |
| event_date      | TEXT    | ISO `YYYY-MM-DD` — indexed                          |
| start_time      | TEXT    | `HH:MM` or NULL (all-day)                           |
| end_time        | TEXT    | `HH:MM` or NULL                                     |
| location        | TEXT    | optional                                            |
| category        | TEXT    | one of the keys in `APP_CATEGORIES` (config.php)    |
| is_published    | INTEGER | `0` draft / `1` public                              |
| created_at      | TEXT    | `YYYY-MM-DD HH:MM:SS`                               |
| updated_at      | TEXT    | same                                                |

Indexes cover `event_date` and `(is_published, event_date)` — the two lookups
the public API actually performs.

---

## 4. Running locally

The project needs **PHP 7.4+ with `pdo_sqlite`**. Both extensions ship by
default in the official PHP builds, so any recent distribution works.

### macOS

```bash
# Install via Homebrew (https://brew.sh):
brew install php

# From the project root, start the built-in PHP server:
cd /Users/andrei/Desktop/BisericaSfVasile
php -S localhost:8000
```

### Windows

Choose **one** of the options below.

**Option A — Official PHP binary (recommended, no extra software)**

1. Open <https://windows.php.net/download/> and download the latest
   **VS17 x64 Thread Safe** ZIP (e.g. `php-8.3.x-Win32-VS17-x64.zip`).
2. Extract it to a simple path, e.g. `C:\php`.
3. In that folder, make a copy of `php.ini-development` and rename it to
   `php.ini`.
4. Open `php.ini` in Notepad and make sure the following lines are **not**
   commented out (remove a leading `;` if present):
   ```ini
   extension_dir = "ext"
   extension=pdo_sqlite
   extension=sqlite3
   extension=mbstring
   extension=openssl
   ```
5. Add `C:\php` to the system `PATH`:
   *Start → "Edit the system environment variables" → Environment Variables →
   Path → Edit → New → `C:\php` → OK.*
6. Open a **new** PowerShell window and verify:
   ```powershell
   php --version
   php -m | Select-String sqlite
   ```
7. Start the dev server from the project root:
   ```powershell
   cd C:\path\to\BisericaSfVasile
   php -S localhost:8000
   ```

**Option B — XAMPP (everything bundled, GUI)**

1. Download XAMPP from <https://www.apachefriends.org/> and install it
   (PHP + Apache + SQLite come bundled).
2. Copy the whole `BisericaSfVasile` folder into `C:\xampp\htdocs\`.
3. Open *XAMPP Control Panel* → **Start** next to *Apache*.
4. Visit <http://localhost/BisericaSfVasile/>.

**Option C — WSL (use the Linux/macOS commands)**

If you have Windows Subsystem for Linux, open an Ubuntu shell and run
`sudo apt install php php-sqlite3`, then follow the macOS instructions.

### Any platform — after the server is running

Visit in a browser:

- `http://localhost:8000/`              → homepage (loads `api/events.php`)
- `http://localhost:8000/calendar.html` → public calendar
- `http://localhost:8000/admin/login.php` → admin login

Press **Ctrl + C** in the terminal to stop the server.

The SQLite database is created automatically on the first request and seeded
with four sample events so you can see the calendar populated immediately.

### Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| `php: command not found` / `'php' is not recognized` | The PHP folder is not on `PATH`. Reopen the terminal after editing `PATH`. |
| `could not find driver` when opening a page | `pdo_sqlite` / `sqlite3` extensions not enabled in `php.ini`. |
| Admin page never loads the DB | The `data\` folder is read-only. Right-click → Properties → uncheck *Read-only*. |
| Port 8000 is in use | Pick another port: `php -S localhost:8001`. |

### Default admin credentials

For local development:

- user:     `admin`
- password: `schimba-ma`

**Change these before going live.** See the next section.

---

## 5. Setting the admin password

The live password hash is stored in `includes/config.php`. To override it in
a safe, git-ignored way:

1. Generate a bcrypt hash:
   ```bash
   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT), PHP_EOL;"
   ```
2. Copy `includes/config.local.example.php` to `includes/config.local.php`.
3. Paste the hash into `APP_ADMIN_PASSWORD_HASH` and change `APP_ADMIN_USER`
   if you want a different username.

`config.local.php` is gitignored and is auto-loaded after `config.php`, so it
always wins.

---

## 6. Deploying to a shared host

Most Romanian shared-hosting plans ship with Apache + PHP + SQLite enabled.
To deploy:

1. Upload the whole project to your web root (keeping the folder structure).
2. Create `includes/config.local.php` with your real admin hash.
3. Make sure the `data/` directory is **writable by the web-server user**
   (`chmod 775 data/`) so PHP can create `events.db`.
4. The provided `.htaccess` files ensure `data/` and `includes/` are never
   served directly.

If your host runs **Nginx** instead of Apache, translate the two `.htaccess`
files into `location` blocks that deny all:

```nginx
location /data/     { deny all; }
location /includes/ { deny all; }
```

---

## 7. Security notes

- Admin login is bcrypt + PHP sessions with `HttpOnly`, `SameSite=Strict`
  cookies and session-id regeneration on successful login.
- All admin mutations (create/edit/delete) require a CSRF token.
- All database access uses prepared statements — no string concatenation.
- All rendered output runs through `h()` (HTML-escape helper).
- The SQLite file lives **outside the public assets** and is protected by
  `data/.htaccess`.
- `admin/login.php` includes `<meta name="robots" content="noindex, nofollow">`
  so search engines don't index the login form.

---

## 8. How the public calendar works

`assets/js/calendar.js` is a self-contained vanilla widget mounted with:

```js
BsvCalendar.mount('#bsv-calendar', { endpoint: 'api/events.php' });
```

It fetches `/api/events.php?from=…&to=…` for the current viewport, caches the
result, and re-renders on navigation. Three views are provided (`month`,
`week`, `list`) with Romanian locale built-in. Opening an event pops a
`<dialog>` with full details.

If the visitor has JavaScript disabled, a `<noscript>` fallback points them
to the static `Program liturgic` section on the homepage.

---

## 9. How the homepage integration works

The announcements section on `index.html` now uses a container with
`data-upcoming-events`:

```html
<div data-upcoming-events data-limit="3" data-endpoint="api/events.php">
  <!-- static fallback content -->
</div>
```

On page load, `homepage-events.js` requests `api/events.php?upcoming=1&limit=3`
and replaces the content with live event cards styled to match the existing
`.announcement` component. Empty state, skeleton loading state, and network
error all render gracefully; if the backend is unreachable, the static
fallback stays visible.

---

## 10. How the photo gallery works

- **Database tables** (`includes/schema.sql`):
  - `gallery_categories` — `(id, name, slug, position, …)`. Slugs are
    auto-ASCII-ified from the Romanian name if not provided.
  - `gallery_photos` — `(id, title, description, file_path, width, height,
    mime_type, size_bytes, is_published, position, …)`.
  - `gallery_photo_categories` — many-to-many pivot with `ON DELETE CASCADE`
    on both sides.
- **Storage**: images land under `uploads/gallery/YYYY/MM/<sha256-prefix>.<ext>`.
  The file name is derived from the file's content hash so re-uploading the
  same bytes deduplicates automatically. `uploads/.htaccess` forbids PHP
  execution and only serves image MIME types.
- **Admin UI** (`admin/gallery.php`):
  - Drag-and-drop upload zone with live previews (supports multiple files).
  - Optional title, description, categories and publish state are applied to
    every file uploaded in one submission, then editable per-photo later.
  - Category filter bar above the photo grid for quick triage.
  - Per-photo `admin/gallery-photo.php` to change metadata and categories
    without re-uploading.
  - `admin/gallery-categories.php` manages categories (create / rename /
    reorder / delete). Deleting a category cascades to remove its pivot rows
    — photos are preserved.
- **Public page** (`galerie.html` + `assets/js/gallery.js`):
  - CSS-columns masonry grid, responsive from one to four columns.
  - Filter chips animated with a FLIP (First-Last-Invert-Play) technique so
    items smoothly slide into their new positions on category change.
  - Lightbox with dimmed blur backdrop, keyboard navigation (←/→/Esc), swipe
    gestures on touch devices, and preload of neighbouring photos.
  - All visible text honours `prefers-reduced-motion`.
- **API** (`api/gallery.php`): `GET ?category=<slug>&limit=<n>` — returns
  `{ categories, photos }`. Only categories with at least one published
  photo are returned, keeping the filter bar free of empty chips.

### Automatic image optimization (responsive variants)

Every uploaded photo is automatically re-encoded into several smaller
renditions so browsers can fetch just the right size for the viewport
and device pixel ratio. This typically cuts transfer by **70–90 %** on
phones.

| Piece | Role |
|---|---|
| Breakpoints | `400, 800, 1200, 1600, 2000` px — larger than the original are skipped |
| Formats | **WebP** and **AVIF** (when GD supports them) + a resized copy in the original format (JPEG/PNG) as universal fallback |
| Storage | Variants live next to the original — `abc123.jpg` → `abc123-400.webp`, `abc123-400.avif`, `abc123-400.jpg`, …, `abc123-2000.jpg` |
| DB | JSON list in the `variants` column of `gallery_photos` — one record per file (width, height, mime, path, bytes) |
| EXIF | JPEG orientation tag is honoured before resize so portraits are not rotated |

The public page wires these up with:

```html
<picture>
  <source type="image/avif" srcset="abc-400.avif 400w, abc-800.avif 800w, ..." sizes="...">
  <source type="image/webp" srcset="abc-400.webp 400w, abc-800.webp 800w, ..." sizes="...">
  <img src="abc-800.jpg" srcset="abc-400.jpg 400w, abc-800.jpg 800w, ..." sizes="..." loading="lazy">
</picture>
```

The `sizes` attribute mirrors the CSS-columns masonry
(`(min-width: 1200px) 25vw, (min-width: 860px) 33vw,
(min-width: 560px) 50vw, 100vw`), so a phone downloads the 400–800 px
variant, a laptop downloads the 1200 px variant, a 4K retina desktop
the 2000 px variant, and nobody downloads the 10 MB original.

The lightbox picks the smallest variant that matches
`window.innerWidth × devicePixelRatio`, preferring WebP → AVIF → JPEG.
The original stays on disk and is never sent unless all variants are
missing.

### Regenerating variants

Variants are produced automatically on upload, but you can also:

- **One photo** — open **Galerie → edit photo** and click
  *Regenerează variantele*. Useful if an upload ran out of memory or
  you changed breakpoints/quality in `includes/gallery.php`.
- **All photos** — on the gallery index, use the *Optimizare* dropdown:
  - *Optimizează doar cele noi* — processes photos that have no
    variants yet (e.g. after migrating from a version without this
    feature).
  - *Regenerează tot* — drops existing variant files for every photo
    and re-creates them.

### Requirements

- PHP **GD** extension (ships with every stock PHP). Detected at runtime;
  if missing, the gallery still works — it just serves the original for
  every tile.
- WebP support in GD is near-universal (`--with-webp`). AVIF requires
  **PHP 8.1+ with libavif**; when absent, WebP-only variants are
  generated and the public page skips the AVIF `<source>`.
- `uploads/gallery/YYYY/MM/` must be writable. The encoder raises the
  PHP `memory_limit` to 512 MB via `ini_set` during processing and then
  restores the previous value; on hosts that disable `ini_set`, large
  (>30 MP) originals may run out of memory — the photo still works,
  just without variants.

### Uploading images from the admin UI

1. Sign in to `admin/login.php`.
2. Open **Galerie** → **Gestionează categoriile** and add at least one
   category (e.g. *Slujbe*, *Praznice*, *Comunitate*).
3. Back on **Galerie**, drop photos onto the upload card (or click to pick).
4. Fill the optional title/description, tick the categories that apply,
   and submit.
5. The photos now appear on `galerie.html` immediately.

### File-size / format limits

- Max **100 MB per image** (configurable in `includes/gallery.php` via
  `APP_GALLERY_MAX_BYTES`). Make sure your PHP `upload_max_filesize` and
  `post_max_size` are at least as high, otherwise PHP rejects the upload
  before the app sees it.
- Accepted MIME types: JPEG, PNG, WebP, GIF, AVIF (the server re-sniffs the
  uploaded file with `finfo` and refuses anything else).


## 11. Extending the system

- **Add a category**: edit `APP_CATEGORIES` in `includes/config.php`. New keys
  must stay lowercase/ASCII. Update colour accents in `calendar.css` and
  `admin.css` if you want a unique swatch.
- **Change the public fields**: add a column in `schema.sql`, read it in
  `api/events.php`, display it in `calendar.js` / `homepage-events.js`, and
  add the input in `admin/event.php`.
- **Multi-user admin**: swap the single-credential block in `includes/auth.php`
  for a small `users` table — the rest of the flow stays the same.
