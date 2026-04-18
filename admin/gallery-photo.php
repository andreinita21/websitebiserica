<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gallery.php';

bsv_require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    bsv_flash_set('error', 'Fotografie invalidă.');
    header('Location: gallery.php');
    exit;
}

$pdo = bsv_db();

$photo = bsv_gallery_photo_with_categories($id);
if (!$photo) {
    bsv_flash_set('error', 'Fotografia nu a fost găsită.');
    header('Location: gallery.php');
    exit;
}

$categories = bsv_gallery_all_categories();

// Form state, pre-filled from DB.
$data = [
    'title'        => (string)$photo['title'],
    'description'  => (string)$photo['description'],
    'is_published' => (int)$photo['is_published'],
    'category_ids' => array_map(static fn($c) => (int)$c['id'], $photo['categories']),
];
$errors = [];

// Regenerate variants for this single photo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $res = bsv_gallery_generate_variants($id);
        if ($res['status'] === 'error') {
            bsv_flash_set('error', 'Regenerare eșuată: ' . $res['message']);
        } else {
            bsv_flash_set('success', 'Variante regenerate: ' . $res['count'] . '.');
        }
    }
    header('Location: gallery-photo.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $data['title']        = trim((string)($_POST['title'] ?? ''));
    $data['description']  = trim((string)($_POST['description'] ?? ''));
    $data['is_published'] = isset($_POST['is_published']) ? 1 : 0;
    $data['category_ids'] = isset($_POST['categories']) && is_array($_POST['categories'])
        ? array_map('intval', $_POST['categories']) : [];

    if (mb_strlen($data['title']) > 180) {
        $errors['title'] = 'Titlul este prea lung (maxim 180 de caractere).';
    }
    if (mb_strlen($data['description']) > 5000) {
        $errors['description'] = 'Descrierea este prea lungă (maxim 5000 de caractere).';
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE gallery_photos
                SET title = :title,
                    description = :description,
                    is_published = :is_published,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':title'        => $data['title'],
            ':description'  => $data['description'],
            ':is_published' => $data['is_published'],
            ':updated_at'   => $now,
            ':id'           => $id,
        ]);
        bsv_gallery_set_photo_categories($id, $data['category_ids']);
        bsv_flash_set('success', 'Fotografia a fost actualizată.');
        header('Location: gallery-photo.php?id=' . $id);
        exit;
    }
}

$csrf = bsv_csrf_token();

$actions = '
  <a href="gallery.php" class="adm-btn adm-btn--ghost">
    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
    <span>Înapoi la galerie</span>
  </a>';

bsv_admin_header(
    'Editează fotografia',
    'Actualizați titlul, descrierea și categoriile. Imaginea rămâne neschimbată.',
    $actions,
    'gallery'
);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<?php
  $variants = bsv_gallery_decode_variants($photo['variants'] ?? null);
  $variantBytes = array_sum(array_map(static fn($v) => (int)($v['bytes'] ?? 0), $variants));
  $byFmt = [];
  foreach ($variants as $v) {
      $fmt = (string)($v['fmt'] ?? 'webp');
      $byFmt[$fmt][] = (int)($v['w'] ?? 0);
  }
?>

<section class="admin-card gallery-edit">
  <div class="admin-card__head">
    <h2>Imagine și variante</h2>
    <p>Fișierul original plus versiunile optimizate folosite pe pagina publică.</p>
  </div>

  <div class="gallery-edit__grid">
    <figure class="gallery-edit__media">
      <img src="../<?= h($photo['file_path']) ?>" alt="<?= h($data['title'] ?: 'Fotografie') ?>"
           width="<?= (int)$photo['width'] ?>" height="<?= (int)$photo['height'] ?>">
      <figcaption>
        <span><?= (int)$photo['width'] ?> × <?= (int)$photo['height'] ?> px</span>
        <?php if (!empty($photo['size_bytes'])): ?>
          <span> · <?= number_format((int)$photo['size_bytes'] / 1024, 0, ',', '.') ?> KB</span>
        <?php endif; ?>
        <?php if (!empty($photo['mime_type'])): ?>
          <span> · <?= h($photo['mime_type']) ?></span>
        <?php endif; ?>
      </figcaption>
    </figure>

    <div class="gallery-edit__variants">
      <div class="gallery-edit__variants-head">
        <span class="material-symbols-outlined" aria-hidden="true">tune</span>
        <strong>Variante responsive</strong>
        <?php if ($variants): ?>
          <span class="variants-badge variants-badge--ok"><?= count($variants) ?> fișiere · <?= number_format($variantBytes / 1024, 0, ',', '.') ?> KB</span>
        <?php else: ?>
          <span class="variants-badge variants-badge--warn">Niciuna</span>
        <?php endif; ?>
      </div>
      <?php if ($byFmt): ?>
        <ul class="gallery-edit__variants-list">
          <?php foreach ($byFmt as $fmt => $widths): sort($widths); ?>
            <li>
              <span class="variants-fmt"><?= h(strtoupper($fmt)) ?></span>
              <span><?= h(implode(' · ', array_map(static fn($w) => $w . 'px', $widths))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="hint" style="margin-top: 6px;">
          Creați versiuni optimizate în WebP/AVIF (și o copie redimensionată în formatul original ca
          rezervă). Pagina publică va încărca imaginea potrivită pentru fiecare ecran.
        </p>
      <?php endif; ?>
      <form method="post" action="gallery-photo.php?id=<?= (int)$id ?>" class="gallery-edit__variants-actions">
        <input type="hidden" name="action" value="regenerate">
        <input type="hidden" name="_token" value="<?= h($csrf) ?>">
        <button type="submit" class="adm-btn adm-btn--ghost adm-btn--sm">
          <span class="material-symbols-outlined" aria-hidden="true">auto_fix_high</span>
          <span><?= $variants ? 'Regenerează variantele' : 'Generează variantele' ?></span>
        </button>
      </form>
    </div>
  </div>
</section>

<form method="post" class="admin-card" action="gallery-photo.php?id=<?= (int)$id ?>" novalidate>
  <div class="admin-card__head">
    <h2>Detalii fotografie</h2>
    <p>Titlu, descriere, categorii și stare de publicare.</p>
  </div>

  <div class="form-grid">
        <div class="field field-full">
          <label for="title">Titlu</label>
          <input type="text" id="title" name="title" maxlength="180"
                 value="<?= h($data['title']) ?>"
                 placeholder="Ex.: Sfânta Liturghie de Paști">
          <?php if (!empty($errors['title'])): ?><span class="err-msg"><?= h($errors['title']) ?></span><?php endif; ?>
        </div>

        <div class="field field-full">
          <label for="description">Descriere</label>
          <textarea id="description" name="description" rows="5" maxlength="5000"
                    placeholder="Textul apare în vizualizarea pe ecran complet."><?= h($data['description']) ?></textarea>
          <?php if (!empty($errors['description'])): ?><span class="err-msg"><?= h($errors['description']) ?></span><?php endif; ?>
        </div>

        <div class="field field-full">
          <label>Categorii</label>
          <?php if (!$categories): ?>
            <p class="hint">
              Încă nu există categorii.
              <a href="gallery.php?view=categories" style="color: var(--c-gold-deep);">Adăugați prima categorie</a>.
            </p>
          <?php else: ?>
            <div class="chip-picker" role="group" aria-label="Categorii">
              <?php foreach ($categories as $c):
                $checked = in_array((int)$c['id'], $data['category_ids'], true);
              ?>
                <label class="chip-picker__option">
                  <input type="checkbox" name="categories[]" value="<?= (int)$c['id'] ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <span><?= h($c['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="field field--check field-full">
          <input type="checkbox" id="is_published" name="is_published" value="1"
                 <?= $data['is_published'] === 1 ? 'checked' : '' ?>>
          <label for="is_published">Publică fotografia (vizibilă pe pagina publică de galerie)</label>
        </div>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">

  <div class="form-actions">
    <a href="gallery.php" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      <span>Anulează</span>
    </a>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span>Salvează modificările</span>
    </button>
  </div>
</form>

<?php bsv_admin_footer(); ?>
