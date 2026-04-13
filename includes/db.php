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

    if ($freshInstall) {
        bsv_seed_sample_events($pdo);
    }

    return $pdo;
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
        ],
        [
            'title'       => 'Vecernia de sâmbătă',
            'description' => 'Slujba de seară a Bisericii, rugăciune de mulțumire și cerere pentru ziua liturgică ce urmează.',
            'days_ahead'  => (6 - (int)$today->format('w') + 7) % 7 ?: 7,
            'start'       => '17:00',
            'end'         => '18:30',
            'category'    => 'vecernie',
            'location'    => 'Altarul principal',
        ],
        [
            'title'       => 'Întâlnire catehetică pentru tineri',
            'description' => 'Discuții deschise despre credință, rugăciune și viața duhovnicească — toți tinerii sunt bineveniți.',
            'days_ahead'  => 10,
            'start'       => '19:00',
            'end'         => '20:30',
            'category'    => 'catehetic',
            'location'    => 'Sala parohială',
        ],
        [
            'title'       => 'Campanie caritabilă — sprijin pentru familii',
            'description' => 'Strângere de alimente și haine pentru familiile nevoiașe din parohie. Contribuțiile pot fi aduse la sala catehetică.',
            'days_ahead'  => 14,
            'start'       => '09:00',
            'end'         => '18:00',
            'category'    => 'caritabil',
            'location'    => 'Sala catehetică',
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO events (title, description, event_date, start_time, end_time, location, category, is_published)
         VALUES (:title, :description, :event_date, :start_time, :end_time, :location, :category, 1)'
    );

    foreach ($samples as $s) {
        $stmt->execute([
            ':title'       => $s['title'],
            ':description' => $s['description'],
            ':event_date'  => $today->modify('+' . $s['days_ahead'] . ' days')->format('Y-m-d'),
            ':start_time'  => $s['start'],
            ':end_time'    => $s['end'],
            ':location'    => $s['location'],
            ':category'    => $s['category'],
        ]);
    }
}
