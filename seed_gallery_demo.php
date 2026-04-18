<?php
/**
 * One-shot demo seeder — uploads the photos from Poze_Biserica/ into the
 * gallery using the exact same storage layout the admin uploader produces.
 * Safe to re-run: dedupes by sha256 hash so existing rows are preserved.
 */

declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/gallery.php';

$pdo = bsv_db();

// Map of source filename => [title, description, [category slugs]]
$items = [
    '01-fatada-principala.jpeg' => [
        'Fațada principală a bisericii',
        'Fațada principală a Bisericii Sfântul Vasile, cu pridvorul pictat și icoana de hram așezată deasupra intrării — o priveliște cunoscută tuturor celor care urcă treptele lăcașului în fiecare duminică.',
        ['biserica'],
    ],
    '02-naosul-si-iconostasul.webp' => [
        'Naosul și iconostasul',
        'Privirea din mijlocul naosului către iconostasul aurit și catapeteasma pictată în tehnică fresco, sub luminile domoale ale candelabrului central.',
        ['biserica'],
    ],
    '03-exterior-latura-iarna.webp' => [
        'Biserica în zi senină de iarnă',
        'Latura de miazăzi a lăcașului surprinsă într-o dimineață de februarie, cu turla clopotniței și turla naosului profilate pe cerul limpede.',
        ['biserica'],
    ],
    '04-icoana-maicii-domnului.webp' => [
        'Icoana împărătească a Maicii Domnului',
        'Icoana împărătească a Maicii Domnului cu Pruncul, veșmântată în argint, așezată la stânga ușilor împărătești ale catapetesmei.',
        ['icoane'],
    ],
    '05-icoana-rastignirii.webp' => [
        'Icoana Răstignirii Domnului',
        'Icoana Răstignirii Mântuitorului, încadrată de scenele patimilor, parte din vechea zestre a bisericii — dăruită parohiei în prima jumătate a secolului trecut.',
        ['icoane'],
    ],
    '06-icoana-iisus-pantocrator.webp' => [
        'Icoana Mântuitorului Pantocrator',
        'Icoana împărătească a Mântuitorului Iisus Hristos Pantocrator, ținând Sfânta Evanghelie deschisă la „Eu sunt Alfa și Omega”, la dreapta ușilor împărătești.',
        ['icoane'],
    ],
    '07-pridvorul-bisericii.webp' => [
        'Pridvorul de intrare',
        'Pridvorul bisericii, cu arcadele împodobite și drapelul tricolor al țării — locul unde credincioșii își fac semnul crucii înainte de a intra în lăcaș.',
        ['biserica'],
    ],
    '08-icoana-invierii-argint.webp' => [
        'Icoana Învierii Domnului în ferecătură de argint',
        'Icoana praznicului Sfintelor Paști, îmbrăcată în ferecătură de argint, așezată pe analog în perioada pascală spre a fi sărutată de credincioși.',
        ['icoane', 'praznice'],
    ],
    '09-sfanta-liturghie-craciun.webp' => [
        'Sfânta Liturghie de Nașterea Domnului',
        'Sfânta Liturghie din dimineața Nașterii Domnului, privită de la cafasul bisericii, cu bradul împodobit în stânga altarului și credincioșii adunați la cântarea „Hristos Se naște, slăviți-L!”.',
        ['slujbe', 'praznice'],
    ],
    '10-fresca-coborarea-de-pe-cruce.webp' => [
        'Fresca „Coborârea de pe Cruce”',
        'Fresca „Coborârea de pe Cruce” din boltirea altarului lateral, zugrăvită în tempera pe grund uscat — cu inscripția „Sfântă Treime, slavă Ție, nădejdea mea este Tatăl”.',
        ['biserica'],
    ],
    '11-icoana-sfintii-mina-stelian-parascheva.webp' => [
        'Sfinții Mina, Stelian și Cuvioasa Parascheva',
        'Icoana ocrotitoare a sfinților Marele Mucenic Mina, Cuviosul Stelian — păzitorul copiilor — și Cuvioasa Parascheva de la Iași, dăruită bisericii de obștea parohiei.',
        ['icoane'],
    ],
    '12-noaptea-sfintelor-pasti.webp' => [
        'Noaptea Sfintelor Paști',
        'Biserica luminată în noaptea Învierii, când credincioșii așteaptă ieșirea preotului cu Sfânta Lumină și cântarea „Veniți de luați lumină”.',
        ['praznice', 'comunitate'],
    ],
    '13-icoana-sfanta-teodora-de-la-sihla.webp' => [
        'Sfânta Cuvioasă Teodora de la Sihla',
        'Icoana Cuvioasei Teodora de la Sihla, pictată pe fond de aur, înconjurată de păsările sihăstriei la care se ruga în pustnicia sa din Munții Neamțului.',
        ['icoane'],
    ],
    '14-poarta-parohiei.webp' => [
        'Poarta parohiei',
        'Poarta de fier forjat a curții bisericii, purtând inscripția „Parohia Sfântul Vasile” — intrarea dinspre Strada Nicolae Titulescu.',
        ['biserica'],
    ],
    '15-icoana-izvorul-tamaduirii.webp' => [
        'Izvorul Tămăduirii',
        'Tabloul „Izvorul Tămăduirii”, înfățișând vindecările săvârșite de Maica Domnului la izvorul de la Vlaherne, prăznuit în Vinerea Luminată.',
        ['icoane', 'praznice'],
    ],
    '16-exterior-primavara.webp' => [
        'Lăcașul în lumină de primăvară',
        'Biserica surprinsă dintr-un unghi lateral, în prima căldură a primăverii, înainte ca pomii din curte să dea frunzele.',
        ['biserica'],
    ],
    '17-sfanta-cruce-si-evanghelie.webp' => [
        'Sfânta Cruce și Sfânta Evanghelie',
        'Sfânta Cruce și Sfânta Evanghelie ferecată, așezate pe Sfânta Masă înaintea începerii Dumnezeieștii Liturghii.',
        ['slujbe'],
    ],
    '18-clopotnita-si-fatada.webp' => [
        'Clopotnița și fațada',
        'Clopotnița bisericii, care adăpostește clopotele ce vestesc Sfintele Slujbe, și icoana de hram a Sfântului Ierarh Vasile cel Mare aflată deasupra intrării.',
        ['biserica'],
    ],
    '19-iconostasul-din-naos.webp' => [
        'Iconostasul văzut din naos',
        'Iconostasul bisericii văzut din naos, cu candelele aprinse la vremea Utreniei și icoanele împărătești îmbrăcate în lumină caldă.',
        ['biserica'],
    ],
    '20-intrarea-de-piatra.webp' => [
        'Intrarea de piatră',
        'Intrarea de piatră spre curtea bisericii, cu poarta din lemn masiv deschisă către credincioșii care vin la slujbă.',
        ['biserica', 'comunitate'],
    ],
];

// Resolve category slugs to IDs once.
$slugToId = [];
foreach ($pdo->query('SELECT id, slug FROM gallery_categories')->fetchAll() as $r) {
    $slugToId[(string)$r['slug']] = (int)$r['id'];
}

$srcDir = __DIR__ . '/Poze_Biserica';
$allowed = APP_GALLERY_ALLOWED_MIME;

$added = 0;
$skippedDup = 0;
$errors = [];

foreach ($items as $file => [$title, $description, $catSlugs]) {
    $src = $srcDir . '/' . $file;
    if (!is_file($src)) {
        $errors[] = "$file: nu există pe disc";
        continue;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $src);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        $errors[] = "$file: mime neacceptat ($mime)";
        continue;
    }
    $ext = $allowed[$mime];

    $dims = getimagesize($src);
    if (!$dims || empty($dims[0]) || empty($dims[1])) {
        $errors[] = "$file: dimensiuni ilizibile";
        continue;
    }

    $hash = hash_file('sha256', $src);

    // Dedupe on file_hash.
    $dup = $pdo->prepare('SELECT id FROM gallery_photos WHERE file_hash = :h LIMIT 1');
    $dup->execute([':h' => $hash]);
    if ($dup->fetch()) {
        $skippedDup++;
        continue;
    }

    $year = date('Y');
    $mon  = date('m');
    $dir  = rtrim(APP_GALLERY_UPLOAD_DIR, '/') . "/$year/$mon";
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $errors[] = "$file: nu s-a putut crea directorul de încărcare";
        continue;
    }

    $name = substr($hash, 0, 24) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!is_file($dest) && !copy($src, $dest)) {
        $errors[] = "$file: nu s-a putut copia fișierul";
        continue;
    }
    @chmod($dest, 0664);

    $relPath = rtrim(APP_GALLERY_UPLOAD_URL, '/') . "/$year/$mon/$name";
    $size    = (int) filesize($src);
    $now     = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO gallery_photos
            (title, description, file_path, file_hash, mime_type, width, height, size_bytes,
             position, is_published, created_at, updated_at)
         VALUES
            (:title, :description, :file_path, :file_hash, :mime_type, :width, :height, :size_bytes,
             :position, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':title'       => $title,
        ':description' => $description,
        ':file_path'   => $relPath,
        ':file_hash'   => $hash,
        ':mime_type'   => $mime,
        ':width'       => (int)$dims[0],
        ':height'      => (int)$dims[1],
        ':size_bytes'  => $size,
        ':position'    => 0,
        ':created_at'  => $now,
        ':updated_at'  => $now,
    ]);
    $photoId = (int)$pdo->lastInsertId();

    // Attach categories.
    $ids = [];
    foreach ($catSlugs as $s) {
        if (isset($slugToId[$s])) $ids[] = $slugToId[$s];
    }
    if ($ids) {
        bsv_gallery_set_photo_categories($photoId, $ids);
    }

    // Generate responsive variants exactly like the admin uploader does.
    $res = bsv_gallery_generate_variants($photoId);
    if ($res['status'] === 'error') {
        $errors[] = "$file (variante): " . $res['message'];
    }

    $added++;
    echo sprintf("  · %-50s  #%d  %s\n", $file, $photoId, $res['status']);
}

echo "\nAdăugate:      $added\n";
echo "Duplicate:     $skippedDup\n";
echo "Erori:         " . count($errors) . "\n";
foreach ($errors as $e) echo "  ! $e\n";
