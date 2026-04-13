# Biserica Sfântul Vasile — Calendar & event management

A lightweight, build-free PHP + SQLite extension to the existing static site.
It adds a public calendar page, a protected admin area for managing events,
and a dynamic upcoming-events block on the homepage. No `npm`, no bundlers,
no frontend framework — everything is served directly.

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
├── index.html                 # homepage (upcoming events block now dynamic)
├── despre.html                # unchanged
├── contact.html               # unchanged
├── calendar.html              # NEW — public calendar page (month/week/list)
│
├── admin/
│   ├── _layout.php            # shared header/footer for admin pages
│   ├── index.php              # event list + delete
│   ├── event.php              # create + edit form
│   ├── login.php              # authentication form
│   ├── logout.php
│   └── .htaccess              # blocks direct access to _layout.php
│
├── api/
│   └── events.php             # GET /api/events.php — JSON feed of published events
│
├── data/
│   ├── events.db              # SQLite database (auto-created, gitignored)
│   └── .htaccess              # deny all — protects the DB on Apache
│
├── includes/
│   ├── config.php             # paths, admin credentials, categories
│   ├── config.local.example.php
│   ├── config.local.php       # your overrides (gitignored)
│   ├── db.php                 # PDO connection + schema install + seed
│   ├── auth.php               # session, CSRF, bcrypt login
│   ├── helpers.php            # sanitisation + Romanian date formatting
│   ├── schema.sql             # CREATE TABLE statements
│   └── .htaccess              # deny all — no direct access to PHP partials
│
└── assets/
    ├── css/
    │   ├── main.css           # (existing) global design system
    │   ├── calendar.css       # NEW — calendar widget + homepage loader
    │   └── admin.css          # NEW — admin UI
    └── js/
        ├── main.js            # (existing) header/menu/reveal
        ├── calendar.js        # NEW — custom vanilla calendar (no CDN)
        └── homepage-events.js # NEW — loads next 3 upcoming events
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

## 10. Extending the system

- **Add a category**: edit `APP_CATEGORIES` in `includes/config.php`. New keys
  must stay lowercase/ASCII. Update colour accents in `calendar.css` and
  `admin.css` if you want a unique swatch.
- **Change the public fields**: add a column in `schema.sql`, read it in
  `api/events.php`, display it in `calendar.js` / `homepage-events.js`, and
  add the input in `admin/event.php`.
- **Multi-user admin**: swap the single-credential block in `includes/auth.php`
  for a small `users` table — the rest of the flow stays the same.
