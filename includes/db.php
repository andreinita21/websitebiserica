<?php
/**
 * SQLite connection helper. Opens a shared PDO instance, applies the schema
 * on first use, and seeds a few sample events so a freshly-cloned project
 * has something to display immediately.
 */

require_once __DIR__ . '/config.php';

function bsv_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dir = dirname(APP_DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $freshInstall = !file_exists(APP_DB_PATH);

    $pdo = new PDO('sqlite:' . APP_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema !== false) {
        $pdo->exec($schema);
    }

    bsv_migrate_announcements_column($pdo);
    bsv_migrate_events_recurrence($pdo);
    bsv_migrate_gallery($pdo);
    bsv_seed_event_categories($pdo);
    bsv_seed_event_locations($pdo);

    require_once __DIR__ . '/settings.php';
    bsv_settings_seed_defaults($pdo);

    if ($freshInstall) {
        bsv_seed_sample_events($pdo);
        bsv_seed_sample_gallery_categories($pdo);
    }

    return $pdo;
}

/**
 * Populate event_categories from the APP_CATEGORIES constant the first time
 * the table is empty. After that the admin UI owns the list; we never touch
 * existing rows on subsequent requests.
 */
function bsv_seed_event_categories(PDO $pdo): void
{
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM event_categories')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($count > 0) return;

    // Default colors mirror the calendar legend so seeded categories look
    // identical to the hardcoded palette used before this table existed.
    $defaults = [
        'liturghie' => ['Sfânta Liturghie',        '#C9A24A'],
        'vecernie'  => ['Vecernie / Utrenie',      '#8C6E2B'],
        'praznic'   => ['Praznic / Sărbătoare',    '#E3BD61'],
        'taina'     => ['Sfântă Taină',            '#C98A57'],
        'catehetic' => ['Întâlnire catehetică',    '#6E8F7A'],
        'caritabil' => ['Acțiune caritabilă',      '#B86A6A'],
        'eveniment' => ['Eveniment parohial',      '#9E7BB0'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO event_categories (slug, label, color, position) VALUES (:slug, :label, :color, :pos)'
    );
    $pos = 10;
    foreach ($defaults as $slug => [$label, $color]) {
        // Prefer labels from APP_CATEGORIES if it exists (in case a deployment
        // has customised the constant before this migration ran).
        if (defined('APP_CATEGORIES') && is_array(APP_CATEGORIES) && isset(APP_CATEGORIES[$slug])) {
            $label = (string)APP_CATEGORIES[$slug];
        }
        try {
            $stmt->execute([':slug' => $slug, ':label' => $label, ':color' => $color, ':pos' => $pos]);
            $pos += 10;
        } catch (Throwable $e) {}
    }
}

/**
 * Populate event_locations the first time it is empty by pulling distinct
 * non-empty location strings out of the existing events table. After that the
 * admin UI owns the list.
 */
function bsv_seed_event_locations(PDO $pdo): void
{
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM event_locations')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($count > 0) return;

    try {
        $names = $pdo->query(
            "SELECT DISTINCT location FROM events
              WHERE location IS NOT NULL AND TRIM(location) != ''
              ORDER BY location ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $names = [];
    }

    $stmt = $pdo->prepare('INSERT INTO event_locations (name, position) VALUES (:name, :pos)');
    $pos = 10;
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        try {
            $stmt->execute([':name' => $name, ':pos' => $pos]);
            $pos += 10;
        } catch (Throwable $e) {}
    }
}

/**
 * Ensure the gallery tables and their expected columns exist. Safe to run on
 * every request — every step is idempotent. Useful for installs that existed
 * before the gallery feature shipped.
 */
function bsv_migrate_gallery(PDO $pdo): void
{
    try {
        $cols = $pdo->query('PRAGMA table_info(gallery_photos)')->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    $names = array_map(static fn($c) => (string)($c['name'] ?? ''), $cols);
    if (!$names) return;

    foreach ([
        'title'       => "ALTER TABLE gallery_photos ADD COLUMN title TEXT NOT NULL DEFAULT ''",
        'file_hash'   => "ALTER TABLE gallery_photos ADD COLUMN file_hash TEXT",
        'width'       => "ALTER TABLE gallery_photos ADD COLUMN width INTEGER",
        'height'      => "ALTER TABLE gallery_photos ADD COLUMN height INTEGER",
        'size_bytes'  => "ALTER TABLE gallery_photos ADD COLUMN size_bytes INTEGER",
        'variants'    => "ALTER TABLE gallery_photos ADD COLUMN variants TEXT",
        'position'    => "ALTER TABLE gallery_photos ADD COLUMN position INTEGER NOT NULL DEFAULT 0",
        'is_published'=> "ALTER TABLE gallery_photos ADD COLUMN is_published INTEGER NOT NULL DEFAULT 1",
    ] as $col => $sql) {
        if (!in_array($col, $names, true)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
}

function bsv_seed_sample_gallery_categories(PDO $pdo): void
{
    $samples = [
        ['Sfintele Slujbe', 'slujbe',    10],
        ['Praznice',        'praznice',  20],
        ['Comunitate',      'comunitate',30],
        ['Biserica',        'biserica',  40],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO gallery_categories (name, slug, position) VALUES (:n, :s, :p)'
    );
    foreach ($samples as [$n, $s, $p]) {
        try { $stmt->execute([':n' => $n, ':s' => $s, ':p' => $p]); } catch (Throwable $e) {}
    }
}

/**
 * Add recurrence columns to older events tables that predate the feature.
 * Idempotent — safe on every request.
 */
function bsv_migrate_events_recurrence(PDO $pdo): void
{
    try {
        $cols = $pdo->query('PRAGMA table_info(events)')->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    $names = array_map(static fn($c) => (string)($c['name'] ?? ''), $cols);
    if (!$names) return;

    if (!in_array('recurrence_type', $names, true)) {
        try { $pdo->exec("ALTER TABLE events ADD COLUMN recurrence_type TEXT"); } catch (Throwable $e) {}
    }
    if (!in_array('recurrence_end_date', $names, true)) {
        try { $pdo->exec("ALTER TABLE events ADD COLUMN recurrence_end_date TEXT"); } catch (Throwable $e) {}
    }
    try { $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_recurrence ON events(recurrence_type)'); } catch (Throwable $e) {}
}

/**
 * Reconcile the announcements table with the current schema. Older installs
 * may have an older column layout; this brings them in line without losing
 * data. Safe to run on every request — every step is idempotent.
 */
function bsv_migrate_announcements_column(PDO $pdo): void
{
    try {
        $cols = $pdo->query('PRAGMA table_info(announcements)')->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    $names = array_map(static fn($c) => (string)($c['name'] ?? ''), $cols);

    // published_at → relevant_on (earlier naming)
    if (in_array('published_at', $names, true) && !in_array('relevant_on', $names, true)) {
        try { $pdo->exec('ALTER TABLE announcements RENAME COLUMN published_at TO relevant_on'); } catch (Throwable $e) {}
    }

    if (!in_array('relevant_until', $names, true)) {
        try { $pdo->exec('ALTER TABLE announcements ADD COLUMN relevant_until TEXT'); } catch (Throwable $e) {}
    }
    if (!in_array('visible_days', $names, true)) {
        try { $pdo->exec('ALTER TABLE announcements ADD COLUMN visible_days INTEGER'); } catch (Throwable $e) {}
    }
}

function bsv_seed_sample_events(PDO $pdo): void
{
    $today = new DateTimeImmutable('today');
    $samples = [
        [
            'title'       => 'Sfânta Liturghie duminicală',
            'description' => 'Slujba centrală a săptămânii — aducerea Jertfei nesângeroase și împărtășirea credincioșilor.',
            'days_ahead'  => (7 - (int)$today->format('w')) % 7 ?: 7,
            'start'       => '09:00',
            'end'         => '11:30',
            'category'    => 'liturghie',
            'location'    => 'Altarul principal',
            'recurrence'  => 'weekly',
        ],
        [
            'title'       => 'Vecernia de sâmbătă',
            'description' => 'Slujba de seară a Bisericii, rugăciune de mulțumire și cerere pentru ziua liturgică ce urmează.',
            'days_ahead'  => (6 - (int)$today->format('w') + 7) % 7 ?: 7,
            'start'       => '17:00',
            'end'         => '18:30',
            'category'    => 'vecernie',
            'location'    => 'Altarul principal',
            'recurrence'  => 'weekly',
        ],
        [
            'title'       => 'Întâlnire catehetică pentru tineri',
            'description' => 'Discuții deschise despre credință, rugăciune și viața duhovnicească — toți tinerii sunt bineveniți.',
            'days_ahead'  => 10,
            'start'       => '19:00',
            'end'         => '20:30',
            'category'    => 'catehetic',
            'location'    => 'Sala parohială',
            'recurrence'  => 'weekly',
        ],
        [
            'title'       => 'Campanie caritabilă — sprijin pentru familii',
            'description' => 'Strângere de alimente și haine pentru familiile nevoiașe din parohie. Contribuțiile pot fi aduse la sala catehetică.',
            'days_ahead'  => 14,
            'start'       => '09:00',
            'end'         => '18:00',
            'category'    => 'caritabil',
            'location'    => 'Sala catehetică',
            'recurrence'  => null,
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO events (title, description, event_date, start_time, end_time, location, category,
                             recurrence_type, recurrence_end_date, is_published)
         VALUES (:title, :description, :event_date, :start_time, :end_time, :location, :category,
                 :recurrence_type, :recurrence_end_date, 1)'
    );

    foreach ($samples as $s) {
        $stmt->execute([
            ':title'               => $s['title'],
            ':description'         => $s['description'],
            ':event_date'          => $today->modify('+' . $s['days_ahead'] . ' days')->format('Y-m-d'),
            ':start_time'          => $s['start'],
            ':end_time'            => $s['end'],
            ':location'            => $s['location'],
            ':category'            => $s['category'],
            ':recurrence_type'     => $s['recurrence'],
            ':recurrence_end_date' => null,
        ]);
    }
}
