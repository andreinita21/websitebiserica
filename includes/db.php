<?php
/**
 * SQLite connection and bootstrap.
 *
 * Opens the shared PDO handle, applies the unified schema (schema.sql) and —
 * on a fresh install only — populates every table with rich test data so a
 * newly cloned project has something to look at immediately.
 *
 * Runtime flow:
 *   1. First request opens the DB file (creating it if missing).
 *   2. schema.sql runs; all statements are idempotent.
 *   3. site_settings defaults are seeded every request (INSERT OR IGNORE).
 *   4. If this is a fresh install, bsv_seed_test_data() populates the rest.
 *
 * To rebuild from scratch: delete data/events.db and reload any page.
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

    // Always safe — INSERT OR IGNORE on settings rows that don't exist yet.
    require_once __DIR__ . '/settings.php';
    bsv_settings_seed_defaults($pdo);

    if ($freshInstall) {
        bsv_seed_test_data($pdo);
    }

    return $pdo;
}

/**
 * Populate every domain with test data. Runs exactly once, on a fresh install.
 * Keeping all of this in one function makes it easy to follow what appears
 * where on the site after cloning.
 */
function bsv_seed_test_data(PDO $pdo): void
{
    bsv_seed_event_categories($pdo);
    bsv_seed_event_locations($pdo);
    bsv_seed_sample_events($pdo);
    bsv_seed_sample_announcements($pdo);
    bsv_seed_sample_gallery_categories($pdo);
    bsv_seed_sample_clergy($pdo);
}

function bsv_seed_event_categories(PDO $pdo): void
{
    $defaults = [
        ['liturghie', 'Sfânta Liturghie',     10],
        ['vecernie',  'Vecernie / Utrenie',   20],
        ['praznic',   'Praznic / Sărbătoare', 30],
        ['taina',     'Sfântă Taină',         40],
        ['catehetic', 'Întâlnire catehetică', 50],
        ['caritabil', 'Acțiune caritabilă',   60],
        ['eveniment', 'Eveniment parohial',   70],
        ['pelerinaj', 'Pelerinaj',            80],
        ['concert',   'Concert / Recital',    90],
    ];
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO event_categories (slug, label, position)
         VALUES (:slug, :label, :pos)'
    );
    foreach ($defaults as [$slug, $label, $pos]) {
        $stmt->execute([':slug' => $slug, ':label' => $label, ':pos' => $pos]);
    }
}

function bsv_seed_event_locations(PDO $pdo): void
{
    $defaults = [
        ['Altarul principal',   10],
        ['Sala parohială',      20],
        ['Curtea bisericii',    30],
        ['Sala catehetică',     40],
        ['Paraclisul Sf. Vasile', 50],
        ['Cimitirul parohial',  60],
        ['Centrul social',      70],
    ];
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO event_locations (name, position) VALUES (:name, :pos)'
    );
    foreach ($defaults as [$name, $pos]) {
        $stmt->execute([':name' => $name, ':pos' => $pos]);
    }
}

/**
 * Seed a mix of one-off and recurring events that spans the current week and
 * the next ~2 months, so every UI filter (upcoming, recurring, category-based)
 * has something to display.
 */
function bsv_seed_sample_events(PDO $pdo): void
{
    $today   = new DateTimeImmutable('today');
    $nextSun = $today->modify('+' . ((7 - (int)$today->format('w')) % 7 ?: 7) . ' days');
    $nextSat = $today->modify('+' . ((6 - (int)$today->format('w') + 7) % 7 ?: 7) . ' days');

    $samples = [
        [
            'title'       => 'Sfânta Liturghie duminicală',
            'description' => 'Slujba centrală a săptămânii — aducerea Jertfei nesângeroase și împărtășirea credincioșilor.',
            'event_date'  => $nextSun->format('Y-m-d'),
            'start_time'  => '09:00', 'end_time' => '11:30',
            'category'    => 'liturghie', 'location' => 'Altarul principal',
            'recurrence'  => 'weekly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Vecernia de sâmbătă',
            'description' => 'Slujba de seară a Bisericii, rugăciune de mulțumire și cerere pentru ziua liturgică ce urmează.',
            'event_date'  => $nextSat->format('Y-m-d'),
            'start_time'  => '17:00', 'end_time' => '18:30',
            'category'    => 'vecernie', 'location' => 'Altarul principal',
            'recurrence'  => 'weekly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Întâlnire catehetică pentru tineri',
            'description' => 'Discuții deschise despre credință, rugăciune și viața duhovnicească — toți tinerii sunt bineveniți.',
            'event_date'  => $today->modify('+10 days')->format('Y-m-d'),
            'start_time'  => '19:00', 'end_time' => '20:30',
            'category'    => 'catehetic', 'location' => 'Sala parohială',
            'recurrence'  => 'weekly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Parastas lunar pentru ctitori',
            'description' => 'Pomenirea ctitorilor și a binefăcătorilor parohiei — slujbă lunară cu parastas.',
            'event_date'  => $today->modify('first day of next month')->modify('+14 days')->format('Y-m-d'),
            'start_time'  => '10:00', 'end_time' => '11:00',
            'category'    => 'liturghie', 'location' => 'Altarul principal',
            'recurrence'  => 'monthly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Hramul Sfântului Vasile',
            'description' => 'Prăznuirea Sfântului Vasile cel Mare — Sfânta Liturghie arhierească, procesiune și agapă parohială.',
            'event_date'  => date('Y') . '-01-01',
            'start_time'  => '08:30', 'end_time' => '13:00',
            'category'    => 'praznic', 'location' => 'Altarul principal',
            'recurrence'  => 'yearly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Campanie caritabilă — sprijin pentru familii',
            'description' => 'Strângere de alimente și haine pentru familiile nevoiașe din parohie. Contribuțiile pot fi aduse la sala catehetică.',
            'event_date'  => $today->modify('+14 days')->format('Y-m-d'),
            'start_time'  => '09:00', 'end_time' => '18:00',
            'category'    => 'caritabil', 'location' => 'Sala catehetică',
            'recurrence'  => null, 'recurrence_end' => null,
        ],
        [
            'title'       => 'Concert de muzică bizantină',
            'description' => 'Program de cântări bizantine susținut de corul parohiei. Intrarea este liberă.',
            'event_date'  => $today->modify('+21 days')->format('Y-m-d'),
            'start_time'  => '19:00', 'end_time' => '20:30',
            'category'    => 'concert', 'location' => 'Altarul principal',
            'recurrence'  => null, 'recurrence_end' => null,
        ],
        [
            'title'       => 'Pelerinaj la mănăstirile din Prahova',
            'description' => 'Vizită organizată la mănăstirile Crasna, Suzana și Cheia. Plecare de la biserică, întoarcere seara.',
            'event_date'  => $today->modify('+35 days')->format('Y-m-d'),
            'start_time'  => '07:00', 'end_time' => '20:00',
            'category'    => 'pelerinaj', 'location' => 'Curtea bisericii',
            'recurrence'  => null, 'recurrence_end' => null,
        ],
        [
            'title'       => 'Botez — familia Popescu',
            'description' => 'Taina Sfântului Botez, urmată de agapă în sala parohială.',
            'event_date'  => $today->modify('+7 days')->format('Y-m-d'),
            'start_time'  => '12:30', 'end_time' => '14:00',
            'category'    => 'taina', 'location' => 'Paraclisul Sf. Vasile',
            'recurrence'  => null, 'recurrence_end' => null,
        ],
        [
            'title'       => 'Ajutor social — masa săptămânală',
            'description' => 'Masa caldă săptămânală oferită persoanelor vârstnice și singure din parohie.',
            'event_date'  => $today->modify('+3 days')->format('Y-m-d'),
            'start_time'  => '12:00', 'end_time' => '14:00',
            'category'    => 'caritabil', 'location' => 'Centrul social',
            'recurrence'  => 'weekly',
            'recurrence_end' => $today->modify('+180 days')->format('Y-m-d'),
        ],
        [
            'title'       => 'Întâlnire a Consiliului parohial',
            'description' => 'Ședința lunară a Consiliului parohial — bilanț, planificare, proiecte pastorale.',
            'event_date'  => $today->modify('first day of next month')->modify('+6 days')->format('Y-m-d'),
            'start_time'  => '18:00', 'end_time' => '20:00',
            'category'    => 'eveniment', 'location' => 'Sala parohială',
            'recurrence'  => 'monthly', 'recurrence_end' => null,
        ],
        [
            'title'       => 'Cununie — familia Ionescu',
            'description' => 'Taina Sfintei Cununii.',
            'event_date'  => $today->modify('+42 days')->format('Y-m-d'),
            'start_time'  => '14:00', 'end_time' => '15:30',
            'category'    => 'taina', 'location' => 'Altarul principal',
            'recurrence'  => null, 'recurrence_end' => null,
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
            ':event_date'          => $s['event_date'],
            ':start_time'          => $s['start_time'],
            ':end_time'            => $s['end_time'],
            ':location'            => $s['location'],
            ':category'            => $s['category'],
            ':recurrence_type'     => $s['recurrence'],
            ':recurrence_end_date' => $s['recurrence_end'],
        ]);
    }
}

/**
 * Seed a handful of announcements covering all three date_mode variants so the
 * admin list and public feed render correctly from day one.
 */
function bsv_seed_sample_announcements(PDO $pdo): void
{
    $today = new DateTimeImmutable('today');

    $samples = [
        [
            'title'          => 'Program special de Paști',
            'body'           => 'Denia Prohodului în Vinerea Mare începe la ora 19:00. Slujba Învierii la miezul nopții spre duminică.',
            'tag'            => 'Liturgic',
            'relevant_on'    => $today->modify('+7 days')->format('Y-m-d'),
            'relevant_until' => $today->modify('+14 days')->format('Y-m-d'),
            'visible_days'   => null,
        ],
        [
            'title'          => 'Ajutor pentru familia Ionescu',
            'body'           => 'Parohia strânge fonduri pentru tratamentul medical al unui copil din comunitate. Detalii la preotul paroh.',
            'tag'            => 'Caritate',
            'relevant_on'    => $today->format('Y-m-d'),
            'relevant_until' => null,
            'visible_days'   => 30,
        ],
        [
            'title'          => 'Întâlnire pentru voluntari',
            'body'           => 'Toți cei care doresc să se implice în proiectele de filantropie sunt așteptați la sala parohială.',
            'tag'            => 'Comunitate',
            'relevant_on'    => $today->modify('+3 days')->format('Y-m-d'),
            'relevant_until' => null,
            'visible_days'   => null,
        ],
        [
            'title'          => 'Spovedanii pentru Postul Mare',
            'body'           => 'Programul de spovedanii: în fiecare miercuri și vineri, între orele 17:00 și 19:00, în paraclis.',
            'tag'            => 'Liturgic',
            'relevant_on'    => $today->modify('-2 days')->format('Y-m-d'),
            'relevant_until' => $today->modify('+40 days')->format('Y-m-d'),
            'visible_days'   => null,
        ],
        [
            'title'          => 'Școala de duminică reîncepe',
            'body'           => 'Copiii cu vârste între 6 și 14 ani sunt așteptați duminică la sala catehetică, după Sfânta Liturghie.',
            'tag'            => 'Cateheză',
            'relevant_on'    => $today->modify('+6 days')->format('Y-m-d'),
            'relevant_until' => null,
            'visible_days'   => 14,
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, body, tag, relevant_on, relevant_until, visible_days, is_published)
         VALUES (:title, :body, :tag, :rel_on, :rel_until, :vis_days, 1)'
    );
    foreach ($samples as $s) {
        $stmt->execute([
            ':title'     => $s['title'],
            ':body'      => $s['body'],
            ':tag'       => $s['tag'],
            ':rel_on'    => $s['relevant_on'],
            ':rel_until' => $s['relevant_until'],
            ':vis_days'  => $s['visible_days'],
        ]);
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
        'INSERT OR IGNORE INTO gallery_categories (name, slug, position) VALUES (:n, :s, :p)'
    );
    foreach ($samples as [$n, $s, $p]) {
        $stmt->execute([':n' => $n, ':s' => $s, ':p' => $p]);
    }
}

function bsv_seed_sample_clergy(PDO $pdo): void
{
    $defaults = [
        ['Pr. Mihai Ardelean', 'Paroh',
         'Slujitor al parohiei din anul 1993, cu o lucrare dedicată catehezei familiei și educației duhovnicești a tinerilor.'],
        ['Pr. Ioan Popescu', 'Preot slujitor',
         'Coordonator al proiectelor de filantropie și al grupului de tineri, implicat în voluntariatul parohial.'],
        ['Diac. Ștefan Radu', 'Diacon',
         'Responsabil cu coordonarea strănii și a corului parohial, slujind în toate zilele de praznic.'],
        ['Pr. Andrei Stanciu', 'Cântăreț bisericesc',
         'Îndrumător al tinerilor cântăreți și al programului de muzică psaltică, slujind cu dedicare la sfintele slujbe.'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO clergy (name, role, bio, photo_path, position, is_published)
         VALUES (:n, :r, :b, :p, :pos, 1)'
    );
    $pos = 10;
    foreach ($defaults as [$n, $r, $b]) {
        $stmt->execute([':n' => $n, ':r' => $r, ':b' => $b, ':p' => '', ':pos' => $pos]);
        $pos += 10;
    }
}
