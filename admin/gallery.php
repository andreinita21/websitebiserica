<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gallery.php';

bsv_require_admin();

$pdo = bsv_db();

// --- Upload handler (POST, CSRF-protected) ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
        header('Location: gallery.php');
        exit;
    }

    $description = trim((string)($_POST['description'] ?? ''));
    $title       = trim((string)($_POST['title'] ?? ''));
    $categoryIds = isset($_POST['categories']) && is_array($_POST['categories'])
        ? array_map('intval', $_POST['categories']) : [];
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Collect files — we always read from $_FILES['photos'] which may be
    // either a single file or a batch (name='photos[]').
    $files = [];
    $raw   = $_FILES['photos'] ?? null;
    if ($raw && is_array($raw['name'])) {
        $count = count($raw['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int)$raw['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'name'     => $raw['name'][$i],
                'type'     => $raw['type'][$i],
                'tmp_name' => $raw['tmp_name'][$i],
                'error'    => $raw['error'][$i],
                'size'     => $raw['size'][$i],
            ];
        }
    } elseif ($raw && (int)($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = $raw;
    }

    if (!$files) {
        bsv_flash_set('error', 'Selectați cel puțin o imagine.');
        header('Location: gallery.php');
        exit;
    }

    $saved = 0;
    $errors = [];
    foreach ($files as $file) {
        try {
            $meta = bsv_gallery_store_upload($file);
        } catch (Throwable $e) {
            $errors[] = ($file['name'] ?? 'imagine') . ': ' . $e->getMessage();
            continue;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO gallery_photos
                (title, description, file_path, file_hash, mime_type, width, height, size_bytes,
                 position, is_published, created_at, updated_at)
             VALUES
                (:title, :description, :file_path, :file_hash, :mime_type, :width, :height, :size_bytes,
                 :position, :is_published, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':title'        => $title,
            ':description'  => $description,
            ':file_path'    => $meta['file_path'],
            ':file_hash'    => $meta['file_hash'],
            ':mime_type'    => $meta['mime_type'],
            ':width'        => $meta['width'],
            ':height'       => $meta['height'],
            ':size_bytes'   => $meta['size_bytes'],
            ':position'     => 0,
            ':is_published' => $isPublished,
            ':created_at'   => $now,
            ':updated_at'   => $now,
        ]);
        $photoId = (int)$pdo->lastInsertId();
        if ($categoryIds) {
            bsv_gallery_set_photo_categories($photoId, $categoryIds);
        }
        $saved++;
    }

    if ($saved > 0 && !$errors) {
        bsv_flash_set('success', $saved === 1
            ? 'Fotografia a fost adăugată.'
            : "$saved fotografii au fost adăugate.");
    } elseif ($saved > 0 && $errors) {
        bsv_flash_set('info', "$saved fotografii salvate. Erori: " . implode(' · ', $errors));
    } else {
        bsv_flash_set('error', 'Nu am putut salva: ' . implode(' · ', $errors));
    }

    header('Location: gallery.php');
    exit;
}

// --- Delete handler --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT file_path FROM gallery_photos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) {
                $pdo->prepare('DELETE FROM gallery_photos WHERE id = :id')->execute([':id' => $id]);
                bsv_gallery_delete_file((string)$row['file_path']);
                bsv_flash_set('success', 'Fotografia a fost ștearsă.');
            }
        }
    }
    header('Location: gallery.php' . (!empty($_GET['cat']) ? '?cat=' . urlencode($_GET['cat']) : ''));
    exit;
}

// --- Filter + fetch ---------------------------------------------------------
$filterCat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

$sql = 'SELECT p.id, p.title, p.description, p.file_path, p.width, p.height,
               p.is_published, p.created_at
        FROM gallery_photos p';
$params = [];
if ($filterCat > 0) {
    $sql .= ' INNER JOIN gallery_photo_categories pc ON pc.photo_id = p.id
              WHERE pc.category_id = :cat';
    $params[':cat'] = $filterCat;
}
$sql .= ' ORDER BY p.position ASC, p.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

// Load categories attached to each photo in one shot.
$photoCategories = [];
if ($photos) {
    $ids = array_column($photos, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT pc.photo_id, c.id, c.name
           FROM gallery_photo_categories pc
           JOIN gallery_categories c ON c.id = pc.category_id
          WHERE pc.photo_id IN ($in)
          ORDER BY c.position ASC, c.name ASC"
    );
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) {
        $photoCategories[(int)$r['photo_id']][] = $r;
    }
}

$categories = bsv_gallery_all_categories();

$counts = $pdo->query(
    "SELECT
        COUNT(*)                                             AS total,
        SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END)    AS published,
        SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END)    AS drafts
     FROM gallery_photos"
)->fetch();

$csrf = bsv_csrf_token();

$actions = '
  <a href="gallery-categories.php" class="adm-btn adm-btn--ghost">
    <span class="material-symbols-outlined" aria-hidden="true">folder_managed</span>
    <span>Gestionează categoriile</span>
  </a>
  <a href="#gallery-upload" class="adm-btn adm-btn--primary">
    <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
    <span>Încarcă fotografii</span>
  </a>';

bsv_admin_header(
    'Galerie',
    'Adăugați, organizați și publicați fotografii pentru pagina publică de galerie.',
    $actions,
    'gallery'
);
?>

<section class="admin-card" id="gallery-upload">
  <div class="admin-card__head">
    <h2>Încarcă fotografii</h2>
    <p>Acceptă JPG, PNG, WebP, GIF sau AVIF. Dimensiunea maximă: <?= (int)(APP_GALLERY_MAX_BYTES / (1024 * 1024)) ?> MB per imagine.</p>
  </div>

  <form method="post" action="gallery.php" enctype="multipart/form-data" novalidate data-upload-form>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="_token" value="<?= h($csrf) ?>">

    <div class="gallery-drop" data-drop>
      <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
             multiple data-drop-input hidden>
      <label for="photos" class="gallery-drop__hit">
        <span class="gallery-drop__icon material-symbols-outlined" aria-hidden="true">add_photo_alternate</span>
        <span class="gallery-drop__title">Trageți imagini aici sau <u>apăsați pentru a selecta</u></span>
        <span class="gallery-drop__hint" data-drop-hint>Puteți selecta mai multe fișiere simultan.</span>
      </label>
      <ul class="gallery-drop__preview" data-drop-preview></ul>
    </div>

    <div class="form-grid" style="margin-top: var(--s-5);">
      <div class="field">
        <label for="title">Titlu (opțional)</label>
        <input type="text" id="title" name="title" maxlength="180"
               placeholder="Ex.: Sfânta Liturghie de Paști">
        <span class="hint">Se aplică tuturor fotografiilor încărcate acum. Poate fi schimbat ulterior.</span>
      </div>

      <div class="field">
        <label>Categorii</label>
        <div class="chip-picker" role="group" aria-label="Categorii">
          <?php if (!$categories): ?>
            <p class="hint" style="grid-column: 1 / -1;">
              Încă nu există categorii.
              <a href="gallery-categories.php" style="color: var(--c-gold-deep);">Adăugați prima categorie</a>.
            </p>
          <?php else: ?>
            <?php foreach ($categories as $c): ?>
              <label class="chip-picker__option">
                <input type="checkbox" name="categories[]" value="<?= (int)$c['id'] ?>">
                <span><?= h($c['name']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="field field-full">
        <label for="description">Descriere (opțional)</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"
                  placeholder="O scurtă descriere vizibilă în vizualizarea pe ecran complet."></textarea>
        <span class="hint">Se aplică tuturor fotografiilor încărcate acum.</span>
      </div>

      <div class="field field--check field-full">
        <input type="checkbox" id="is_published" name="is_published" value="1" checked>
        <label for="is_published">Publică fotografiile (vizibile pe pagina publică de galerie)</label>
      </div>
    </div>

    <div class="form-actions">
      <a href="gallery.php" class="adm-btn adm-btn--ghost">
        <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
        <span>Resetează</span>
      </a>
      <button type="submit" class="adm-btn adm-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
        <span>Încarcă</span>
      </button>
    </div>
  </form>
</section>

<div class="toolbar" style="margin-top: var(--s-7);">
  <div class="toolbar__filters" role="tablist" aria-label="Filtru categorii">
    <a href="gallery.php" class="toolbar__filter <?= $filterCat === 0 ? 'is-active' : '' ?>">
      Toate <span>(<?= (int)($counts['total'] ?? 0) ?>)</span>
    </a>
    <?php foreach ($categories as $c): ?>
      <a href="?cat=<?= (int)$c['id'] ?>" class="toolbar__filter <?= $filterCat === (int)$c['id'] ? 'is-active' : '' ?>">
        <?= h($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div style="font-size: 0.82rem; color: var(--c-ink-muted);">
    <?= (int)($counts['published'] ?? 0) ?> publicate · <?= (int)($counts['drafts'] ?? 0) ?> ciorne
  </div>
</div>

<?php if (!$photos): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">photo_library</span>
    <h3>Nu există încă fotografii</h3>
    <p>Folosiți formularul de mai sus pentru a încărca prima imagine.</p>
  </div>
<?php else: ?>
  <div class="admin-gallery-grid">
    <?php foreach ($photos as $p):
      $cats = $photoCategories[(int)$p['id']] ?? [];
    ?>
      <article class="admin-photo-card">
        <a class="admin-photo-card__thumb" href="gallery-photo.php?id=<?= (int)$p['id'] ?>" aria-label="Editează fotografia">
          <img src="../<?= h($p['file_path']) ?>" alt="<?= h($p['title'] ?: 'Fotografie din galerie') ?>"
               loading="lazy" width="<?= (int)$p['width'] ?>" height="<?= (int)$p['height'] ?>">
          <?php if ((int)$p['is_published'] === 0): ?>
            <span class="admin-photo-card__badge">Ciornă</span>
          <?php endif; ?>
        </a>
        <div class="admin-photo-card__body">
          <?php if (!empty($p['title'])): ?>
            <h3 class="admin-photo-card__title"><?= h($p['title']) ?></h3>
          <?php endif; ?>
          <?php if (!empty($p['description'])): ?>
            <p class="admin-photo-card__desc"><?= h(mb_strimwidth((string)$p['description'], 0, 110, '…', 'UTF-8')) ?></p>
          <?php endif; ?>
          <?php if ($cats): ?>
            <div class="admin-photo-card__cats">
              <?php foreach ($cats as $c): ?>
                <span class="pill-cat"><?= h($c['name']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="admin-photo-card__cats admin-photo-card__cats--empty">Fără categorii</div>
          <?php endif; ?>
        </div>
        <div class="admin-photo-card__actions">
          <a class="adm-btn adm-btn--ghost adm-btn--sm" href="gallery-photo.php?id=<?= (int)$p['id'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            <span>Editează</span>
          </a>
          <form method="post" class="inline-form" action="gallery.php<?= $filterCat ? '?cat=' . (int)$filterCat : '' ?>"
                onsubmit="return confirm('Sigur doriți să ștergeți această fotografie?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">
              <span class="material-symbols-outlined" aria-hidden="true">delete</span>
              <span>Șterge</span>
            </button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  var form  = document.querySelector('[data-upload-form]');
  if (!form) return;
  var input = form.querySelector('[data-drop-input]');
  var drop  = form.querySelector('[data-drop]');
  var list  = form.querySelector('[data-drop-preview]');
  var hint  = form.querySelector('[data-drop-hint]');

  function renderPreview(files) {
    list.innerHTML = '';
    if (!files || !files.length) {
      hint.textContent = 'Puteți selecta mai multe fișiere simultan.';
      return;
    }
    hint.textContent = files.length === 1
      ? 'O imagine selectată. Apăsați „Încarcă".'
      : files.length + ' imagini selectate. Apăsați „Încarcă".';

    Array.prototype.forEach.call(files, function (f) {
      var li = document.createElement('li');
      li.className = 'gallery-drop__chip';
      var img = document.createElement('img');
      img.alt = '';
      img.loading = 'lazy';
      var url = URL.createObjectURL(f);
      img.src = url;
      img.onload = function () { URL.revokeObjectURL(url); };
      li.appendChild(img);
      var name = document.createElement('span');
      name.textContent = f.name;
      li.appendChild(name);
      list.appendChild(li);
    });
  }

  input.addEventListener('change', function () { renderPreview(input.files); });

  ['dragenter', 'dragover'].forEach(function (evt) {
    drop.addEventListener(evt, function (e) {
      e.preventDefault(); e.stopPropagation();
      drop.classList.add('is-dragging');
    });
  });
  ['dragleave', 'drop'].forEach(function (evt) {
    drop.addEventListener(evt, function (e) {
      e.preventDefault(); e.stopPropagation();
      drop.classList.remove('is-dragging');
    });
  });
  drop.addEventListener('drop', function (e) {
    var dt = e.dataTransfer;
    if (!dt || !dt.files || !dt.files.length) return;
    input.files = dt.files;
    renderPreview(input.files);
  });
})();
</script>

<?php bsv_admin_footer(); ?>
