<?php
/**
 * Key/value store for editable site-wide content (contact details, map URL).
 * Backed by the site_settings table — read via bsv_settings_all(), written
 * via bsv_settings_save() from the admin contact editor.
 */

require_once __DIR__ . '/db.php';

const BSV_CONTACT_DEFAULTS = [
    'contact_address'           => "Strada Nicolae Titulescu\n100054, Ploiești, Prahova",
    'contact_phone_display'     => '+40 722 000 000',
    'contact_phone_link'        => '+40722000000',
    'contact_email'             => 'contact@sfvasile-ploiesti.ro',
    'contact_schedule_visiting' => "Luni – Vineri · 08:00 – 19:00\nSâmbătă – Duminică · 07:30 – 20:00",
    'contact_schedule_liturgy'  => "Duminică · 09:00\nVecernia: Sâmbătă · 17:00",
    'contact_map_embed_url'     => 'https://maps.google.com/maps?q=44.9472211,26.0088999&z=17&output=embed',
    'contact_map_link_url'      => 'https://www.google.com/maps/place/Saint+Basil+Church/@44.9472211,26.0088999,17z',
];

function bsv_settings_seed_defaults(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO site_settings (key, value) VALUES (:k, :v)'
    );
    foreach (BSV_CONTACT_DEFAULTS as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => $v]);
    }
}

function bsv_settings_all(): array
{
    $rows = bsv_db()->query('SELECT key, value FROM site_settings')->fetchAll();
    $out = BSV_CONTACT_DEFAULTS;
    foreach ($rows as $r) {
        $out[(string)$r['key']] = (string)$r['value'];
    }
    return $out;
}

function bsv_settings_save(array $values): void
{
    $pdo = bsv_db();
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (key, value, updated_at) VALUES (:k, :v, :u)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
    );
    $now = date('Y-m-d H:i:s');
    foreach ($values as $k => $v) {
        if (!array_key_exists($k, BSV_CONTACT_DEFAULTS)) continue;
        $stmt->execute([':k' => $k, ':v' => (string)$v, ':u' => $now]);
    }
}
