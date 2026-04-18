<?php
/**
 * Gallery admin — two sub-views driven by ?view=photos|categories.
 *
 *   photos      (default) — upload form + grid of photos, with category chips
 *   categories            — inline manager for gallery_categories (modal editor)
 *
 * Everything that used to live in admin/gallery-categories.php now lives in
 * this file under the "categories" sub-view so the nav stays flat.
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gallery.php';

bsv_require_admin();

$pdo = bsv_db();

$view = $_GET['view'] ?? 'photos';
if (!in_array($view, ['photos', 'categories'], true)) $view = 'photos';

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------

$catErrors    = [];
$catForm      = ['id' => 0, 'name' => '', 'slug' => ''];
$catModalOpen = false;

function bsv_ajax_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Upload photo(s) --------------------------------------------------------
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

    $saved  = 0;
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

        $variantResult = bsv_gallery_generate_variants($photoId);
        if ($variantResult['status'] === 'error') {
            $errors[] = ($file['name'] ?? 'imagine') . ' (variante): ' . $variantResult['message'];
        }
        $saved++;
    }

    if ($saved > 0 && !$errors) {
        bsv_flash_set('success', $saved === 1
            ? 'Fotografia a fost adăugată și optimizată.'
            : "$saved fotografii au fost adăugate și optimizate.");
    } elseif ($saved > 0 && $errors) {
        bsv_flash_set('info', "$saved fotografii salvate. Erori: " . implode(' · ', $errors));
    } else {
        bsv_flash_set('error', 'Nu am putut salva: ' . implode(' · ', $errors));
    }

    header('Location: gallery.php');
    exit;
}

// --- Batch regenerate -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_all') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $force = !empty($_POST['force']);
        $res = bsv_gallery_regenerate_all($force);
        bsv_flash_set(
            'success',
            sprintf(
                'Optimizare finalizată: %d procesate (%d reușite, %d sărite, %d erori).',
                $res['processed'], $res['ok'], $res['skipped'], $res['errors']
            )
        );
    }
    header('Location: gallery.php');
    exit;
}

// --- Photo delete -----------------------------------------------------------
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
                bsv_gallery_delete_variant_files($id);
                $pdo->prepare('DELETE FROM gallery_photos WHERE id = :id')->execute([':id' => $id]);
                bsv_gallery_delete_file((string)$row['file_path']);
                bsv_flash_set('success', 'Fotografia a fost ștearsă.');
            }
        }
    }
    header('Location: gallery.php' . (!empty($_GET['cat']) ? '?cat=' . urlencode($_GET['cat']) : ''));
    exit;
}

// --- Category create / update ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['cat_create', 'cat_update'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $catErrors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $catForm['id']   = (int)($_POST['id'] ?? 0);
    $catForm['name'] = trim((string)($_POST['name'] ?? ''));
    $catForm['slug'] = trim((string)($_POST['slug'] ?? ''));

    if ($catForm['name'] === '' || mb_strlen($catForm['name']) > 80) {
        $catErrors['name'] = 'Numele este obligatoriu (maxim 80 de caractere).';
    }
    if ($catForm['slug'] === '') {
        $catForm['slug'] = bsv_gallery_slugify($catForm['name']);
    } else {
        $catForm['slug'] = bsv_gallery_slugify($catForm['slug']);
    }

    if (!$catErrors) {
        $catForm['slug'] = bsv_gallery_unique_slug($catForm['slug'], $catForm['id']);
        $now = date('Y-m-d H:i:s');
        if ($catForm['id'] > 0) {
            // Position stays whatever the row already has — reorder happens via the drag UI.
            $stmt = $pdo->prepare(
                'UPDATE gallery_categories
                    SET name = :name, slug = :slug, updated_at = :updated_at
                  WHERE id = :id'
            );
            $stmt->execute([
                ':name'       => $catForm['name'],
                ':slug'       => $catForm['slug'],
                ':updated_at' => $now,
                ':id'         => $catForm['id'],
            ]);
            bsv_flash_set('success', 'Categoria a fost actualizată.');
        } else {
            $nextPos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 10 FROM gallery_categories')->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO gallery_categories (name, slug, position, created_at, updated_at)
                 VALUES (:name, :slug, :position, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':name'       => $catForm['name'],
                ':slug'       => $catForm['slug'],
                ':position'   => $nextPos,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            bsv_flash_set('success', 'Categoria a fost creată.');
        }
        header('Location: gallery.php?view=categories');
        exit;
    }

    $view = 'categories';
    $catModalOpen = true;
}

// --- Category reorder (AJAX) -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cat_reorder') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_ajax_json(['ok' => false, 'error' => 'Sesiunea a expirat'], 403);
    }
    $raw = (string)($_POST['order'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
    if (!$ids) bsv_ajax_json(['ok' => false, 'error' => 'Listă de ordine goală'], 400);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE gallery_categories SET position = :pos, updated_at = :now WHERE id = :id');
        $now = date('Y-m-d H:i:s');
        $pos = 10;
        foreach ($ids as $id) {
            $stmt->execute([':pos' => $pos, ':now' => $now, ':id' => $id]);
            $pos += 10;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        bsv_ajax_json(['ok' => false, 'error' => 'Eroare la salvare'], 500);
    }
    bsv_ajax_json(['ok' => true]);
}

// --- Category delete --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cat_delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM gallery_categories WHERE id = :id')->execute([':id' => $id]);
            // ON DELETE CASCADE cleans the pivot rows automatically.
            bsv_flash_set('success', 'Categoria a fost ștearsă.');
        }
    }
    header('Location: gallery.php?view=categories');
    exit;
}

// ---------------------------------------------------------------------------
// Data loading per view
// ---------------------------------------------------------------------------
$categories = bsv_gallery_all_categories();

$photos          = [];
$photoCategories = [];
$counts          = ['total' => 0, 'published' => 0, 'drafts' => 0, 'unoptimized' => 0];
$filterCat       = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

if ($view === 'photos') {
    $sql = 'SELECT p.id, p.title, p.description, p.file_path, p.width, p.height,
                   p.variants, p.is_published, p.created_at
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

    $countsRow = $pdo->query(
        "SELECT
            COUNT(*)                                                                AS total,
            SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END)                       AS published,
            SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END)                       AS drafts,
            SUM(CASE WHEN variants IS NULL OR variants = '' THEN 1 ELSE 0 END)      AS unoptimized
         FROM gallery_photos"
    )->fetch();
    if ($countsRow) $counts = array_merge($counts, $countsRow);
}

$catRows = [];
if ($view === 'categories') {
    $catRows = $pdo->query(
        'SELECT c.id, c.name, c.slug, c.position,
                (SELECT COUNT(*) FROM gallery_photo_categories pc WHERE pc.category_id = c.id) AS photo_count
           FROM gallery_categories c
          ORDER BY c.position ASC, c.name ASC'
    )->fetchAll();
}

$imgCaps = bsv_gallery_image_support();
$csrf    = bsv_csrf_token();

$actions = '';
if ($view === 'photos') {
    $actions = '
      <a href="#gallery-upload" class="adm-btn adm-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
        <span>Încarcă fotografii</span>
      </a>';
} else {
    $actions = '
      <button type="button" class="adm-btn adm-btn--ghost" data-enter-reorder data-hide-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">reorder</span>
        <span>Ordonează</span>
      </button>
      <button type="button" class="adm-btn adm-btn--primary" data-open-modal="gal-cat-modal" data-modal-mode="create" data-hide-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        <span>Adaugă categorie</span>
      </button>
      <button type="button" class="adm-btn adm-btn--ghost" data-cancel-reorder data-show-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
        <span>Anulează</span>
      </button>
      <button type="button" class="adm-btn adm-btn--primary" data-save-reorder data-show-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">save</span>
        <span>Salvează ordinea</span>
      </button>';
}

$headerTitle = $view === 'categories' ? 'Categorii galerie' : 'Galerie';
$headerSub   = $view === 'categories'
    ? 'Creați, redenumiți sau ștergeți categoriile folosite pentru a organiza fotografiile.'
    : 'Adăugați, organizați și publicați fotografii pentru pagina publică de galerie.';

bsv_admin_header($headerTitle, $headerSub, $actions, 'gallery');
?>

<nav class="admin-subnav" aria-label="Sub-secțiuni Galerie">
  <a href="gallery.php?view=photos" class="admin-subnav__link <?= $view === 'photos' ? 'is-active' : '' ?>">
    <span class="material-symbols-outlined" aria-hidden="true">photo_library</span>
    <span>Fotografii</span>
  </a>
  <a href="gallery.php?view=categories" class="admin-subnav__link <?= $view === 'categories' ? 'is-active' : '' ?>">
    <span class="material-symbols-outlined" aria-hidden="true">sell</span>
    <span>Categorii</span>
  </a>
</nav>

<?php if ($view === 'photos'): ?>

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
              <a href="gallery.php?view=categories" style="color: var(--c-gold-deep);">Adăugați prima categorie</a>.
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
  <div style="display: flex; gap: var(--s-3); align-items: center; flex-wrap: wrap;">
    <span style="font-size: 0.82rem; color: var(--c-ink-muted);">
      <?= (int)($counts['published'] ?? 0) ?> publicate · <?= (int)($counts['drafts'] ?? 0) ?> ciorne
      <?php if ((int)($counts['unoptimized'] ?? 0) > 0): ?>
        · <strong style="color: var(--c-gold-deep);"><?= (int)$counts['unoptimized'] ?> neoptimizate</strong>
      <?php endif; ?>
    </span>
    <?php if ((int)($counts['total'] ?? 0) > 0 && $imgCaps['gd']): ?>
      <details class="regen-menu">
        <summary class="adm-btn adm-btn--ghost adm-btn--sm" role="button">
          <span class="material-symbols-outlined" aria-hidden="true">auto_fix_high</span>
          <span>Optimizare</span>
        </summary>
        <div class="regen-menu__sheet">
          <form method="post" action="gallery.php" class="regen-menu__form">
            <input type="hidden" name="action" value="regenerate_all">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <button type="submit" class="regen-menu__item">
              <span class="material-symbols-outlined" aria-hidden="true">bolt</span>
              <span>
                <strong>Optimizează doar cele noi</strong>
                <small>Procesează fotografiile fără variante.</small>
              </span>
            </button>
          </form>
          <form method="post" action="gallery.php" class="regen-menu__form"
                onsubmit="return confirm('Regenerați variantele pentru TOATE fotografiile? Poate dura câteva minute.');">
            <input type="hidden" name="action" value="regenerate_all">
            <input type="hidden" name="force" value="1">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <button type="submit" class="regen-menu__item regen-menu__item--warn">
              <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
              <span>
                <strong>Regenerează tot</strong>
                <small>Șterge variantele existente și le recreează.</small>
              </span>
            </button>
          </form>
        </div>
      </details>
    <?php endif; ?>
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
      $pVariants = bsv_gallery_decode_variants($p['variants'] ?? null);
      $thumbSrc = $p['file_path'];
      foreach ($pVariants as $v) {
        if (($v['fmt'] ?? '') === 'webp' && (int)($v['w'] ?? 0) >= 400) {
          $thumbSrc = $v['path'];
          break;
        }
      }
    ?>
      <article class="admin-photo-card">
        <a class="admin-photo-card__thumb" href="gallery-photo.php?id=<?= (int)$p['id'] ?>" aria-label="Editează fotografia">
          <img src="../<?= h($thumbSrc) ?>" alt="<?= h($p['title'] ?: 'Fotografie din galerie') ?>"
               loading="lazy" width="<?= (int)$p['width'] ?>" height="<?= (int)$p['height'] ?>">
          <?php if ((int)$p['is_published'] === 0): ?>
            <span class="admin-photo-card__badge">Ciornă</span>
          <?php endif; ?>
          <?php if (!$pVariants): ?>
            <span class="admin-photo-card__badge admin-photo-card__badge--warn"
                  style="right: 10px; left: auto; background: var(--c-gold); color: var(--c-ink);"
                  title="Fotografia nu are variante optimizate — folosiți „Optimizare”.">Neoptimizat</span>
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

<?php else: /* view === 'categories' */ ?>

<?php if (!empty($catErrors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($catErrors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<?php if (!$catRows): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">folder</span>
    <h3>Nu există încă nicio categorie</h3>
    <p>Folosiți butonul „Adaugă categorie” pentru a crea prima.</p>
    <button type="button" class="adm-btn adm-btn--primary" data-open-modal="gal-cat-modal" data-modal-mode="create">
      <span class="material-symbols-outlined" aria-hidden="true">add</span>
      <span>Adaugă categorie</span>
    </button>
  </div>
<?php else: ?>
  <div class="reorder-banner">
    <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
    <span><strong>Mod ordonare:</strong> Trageți rândurile pentru a schimba ordinea. Apăsați „Salvează ordinea” când ați terminat.</span>
  </div>
  <table class="events-table" data-sortable="cat_reorder">
    <thead>
      <tr>
        <th class="col-drag" aria-hidden="true"></th>
        <th>Nume</th>
        <th>Fotografii</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($catRows as $c): ?>
        <tr data-sortable-id="<?= (int)$c['id'] ?>">
          <td class="col-drag">
            <span class="drag-handle" data-drag-handle aria-label="Trage pentru a reordona" title="Trage pentru a reordona">
              <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
            </span>
          </td>
          <td>
            <div class="title-cell">
              <strong><?= h($c['name']) ?></strong>
              <span><code><?= h($c['slug']) ?></code></span>
            </div>
          </td>
          <td><span class="pill"><?= (int)$c['photo_count'] ?> foto</span></td>
          <td class="col-actions">
            <button type="button"
                    class="adm-btn adm-btn--ghost adm-btn--sm"
                    data-open-modal="gal-cat-modal"
                    data-modal-mode="edit"
                    data-id="<?= (int)$c['id'] ?>"
                    data-name="<?= h($c['name']) ?>"
                    data-slug="<?= h($c['slug']) ?>">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </button>
            <form method="post" class="inline-form" action="gallery.php?view=categories"
                  onsubmit="return confirm('Ștergeți categoria „<?= h(addslashes($c['name'])) ?>”? Fotografiile rămân, dar își pierd această etichetă.');">
              <input type="hidden" name="action" value="cat_delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">
              <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">
                <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                <span>Șterge</span>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php endif; /* /view */ ?>

<!-- ====================================================================== -->
<!-- Gallery category modal                                                  -->
<!-- ====================================================================== -->
<dialog class="modal" id="gal-cat-modal" data-modal<?= $catModalOpen ? ' data-autoopen' : '' ?> aria-labelledby="gal-cat-modal-title">
  <form method="post" action="gallery.php?view=categories" class="modal__dialog" novalidate data-modal-form>
    <header class="modal__head">
      <h2 id="gal-cat-modal-title" data-modal-title>Editează categoria</h2>
      <button type="button" class="modal__close" data-close-modal aria-label="Închide">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>

    <div class="modal__body">
      <div class="form-grid form-grid--modal">
        <div class="field field-full">
          <label for="gal-cat-name">Nume <span class="req">*</span></label>
          <input type="text" id="gal-cat-name" name="name" maxlength="80" required
                 value="<?= h($catForm['name']) ?>"
                 placeholder="Ex.: Praznice, Comunitate, Biserica">
          <?php if (!empty($catErrors['name'])): ?><span class="err-msg"><?= h($catErrors['name']) ?></span><?php endif; ?>
        </div>

        <div class="field field-full">
          <label for="gal-cat-slug">Slug</label>
          <input type="text" id="gal-cat-slug" name="slug" maxlength="64"
                 value="<?= h($catForm['slug']) ?>"
                 placeholder="ex.: praznice">
          <span class="hint">Folosit în URL. Generat automat dacă îl lăsați gol.</span>
        </div>
      </div>
    </div>

    <footer class="modal__foot">
      <input type="hidden" name="_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="cat_create" data-modal-action>
      <input type="hidden" name="id" value="0" data-modal-id>

      <button type="button" class="adm-btn adm-btn--ghost" data-close-modal>
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
        <span>Anulează</span>
      </button>
      <button type="submit" class="adm-btn adm-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">save</span>
        <span data-modal-submit>Salvează</span>
      </button>
    </footer>
  </form>
</dialog>

<script>
(function () {
  var modals = document.querySelectorAll('[data-modal]');
  if (!modals.length) return;

  function openModal(modal) {
    if (typeof modal.showModal === 'function') modal.showModal();
    else modal.setAttribute('open', '');
    var firstInput = modal.querySelector('input[type="text"], input[type="number"]');
    if (firstInput) setTimeout(function () { firstInput.focus(); firstInput.select && firstInput.select(); }, 40);
  }
  function closeModal(modal) {
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
  }

  document.querySelectorAll('[data-open-modal]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var id = trigger.getAttribute('data-open-modal');
      var modal = document.getElementById(id);
      if (!modal) return;

      var mode = trigger.getAttribute('data-modal-mode') || 'create';
      var form = modal.querySelector('[data-modal-form]');
      if (!form) return;

      var title  = modal.querySelector('[data-modal-title]');
      var action = form.querySelector('[data-modal-action]');
      var idInp  = form.querySelector('[data-modal-id]');
      var submit = form.querySelector('[data-modal-submit]');

      if (mode === 'edit') {
        if (title)  title.textContent  = 'Editează categoria';
        if (submit) submit.textContent = 'Salvează modificările';
        if (action) action.value = 'cat_update';
        if (idInp)  idInp.value = trigger.getAttribute('data-id') || '0';
      } else {
        if (title)  title.textContent  = 'Adaugă categorie';
        if (submit) submit.textContent = 'Adaugă categoria';
        if (action) action.value = 'cat_create';
        if (idInp)  idInp.value = '0';
      }

      Array.prototype.forEach.call(trigger.attributes, function (attr) {
        if (!attr.name.startsWith('data-')) return;
        var key = attr.name.slice(5);
        if (['open-modal', 'modal-mode', 'id'].indexOf(key) !== -1) return;
        var input = form.querySelector('[name="' + key + '"]');
        if (input) input.value = attr.value;
      });

      if (mode === 'create') {
        form.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (inp) {
          if (inp.name === 'position') inp.value = '0';
          else inp.value = '';
        });
      }

      form.querySelectorAll('.err-msg').forEach(function (el) { el.remove(); });
      openModal(modal);
    });
  });

  modals.forEach(function (modal) {
    modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () { closeModal(modal); });
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal(modal);
    });
  });

  document.querySelectorAll('[data-autoopen]').forEach(function (modal) {
    openModal(modal);
  });
})();
</script>

<script>
/* Drag-to-reorder for gallery_categories (deferred save + FLIP animation).
 * Mirrors the controller on the events admin page — see admin/index.php for
 * the fully annotated version. */
(function () {
  var body  = document.body;
  var table = document.querySelector('table[data-sortable]');

  if (!table) return;
  var tbody  = table.querySelector('tbody');
  var action = table.getAttribute('data-sortable');
  var csrf   = <?= json_encode($csrf) ?>;
  if (!tbody || !action) return;

  var baseline = null;
  var drag     = null;

  document.querySelectorAll('[data-enter-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      baseline = snapshotOrder();
      body.classList.add('is-reorder');
    });
  });
  document.querySelectorAll('[data-cancel-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (baseline) animatedRestore(baseline);
      baseline = null;
      body.classList.remove('is-reorder');
    });
  });
  document.querySelectorAll('[data-save-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var current = snapshotOrder();
      if (!baseline || current.join(',') === baseline.join(',')) {
        baseline = null;
        body.classList.remove('is-reorder');
        return;
      }
      btn.disabled = true;
      persistOrder(current, function (ok) {
        btn.disabled = false;
        if (ok) {
          baseline = null;
          body.classList.remove('is-reorder');
          toast('Ordine salvată.', 'success');
        } else {
          toast('Nu am putut salva. Încercați din nou.', 'error');
        }
      });
    });
  });

  function snapshotOrder() {
    return Array.prototype.map.call(
      tbody.querySelectorAll('tr[data-sortable-id]'),
      function (r) { return r.getAttribute('data-sortable-id'); }
    );
  }

  function animatedRestore(ids) {
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-sortable-id]'));
    var oldTops = new Map();
    rows.forEach(function (r) { oldTops.set(r, r.getBoundingClientRect().top); });
    ids.forEach(function (id) {
      var row = tbody.querySelector('tr[data-sortable-id="' + id + '"]');
      if (row) tbody.appendChild(row);
    });
    flipAnimate(rows, oldTops);
  }

  function flipAnimate(rows, oldTops) {
    rows.forEach(function (r) {
      var oldTop = oldTops.get(r);
      if (oldTop == null) return;
      var newTop = r.getBoundingClientRect().top;
      var dy = oldTop - newTop;
      if (Math.abs(dy) < 0.5) return;
      r.style.transition = 'none';
      r.style.transform  = 'translateY(' + dy + 'px)';
      r.getBoundingClientRect();
      r.style.transition = '';
      r.style.transform  = '';
    });
  }

  function persistOrder(ids, done) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('order',  ids.join(','));
    fd.append('_token', csrf);
    fetch('gallery.php?view=categories', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
    .then(function (j) { done(!!(j && j.ok)); })
    .catch(function () { done(false); });
  }

  function toast(msg, type) {
    var t = document.querySelector('.reorder-toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'reorder-toast';
      (table.parentNode || document.body).insertBefore(t, table);
    }
    t.textContent = msg;
    t.setAttribute('data-type', type);
    t.classList.remove('is-hiding');
    t.classList.add('is-showing');
    clearTimeout(t._hideT);
    t._hideT = setTimeout(function () {
      t.classList.remove('is-showing');
      t.classList.add('is-hiding');
    }, 2400);
  }

  tbody.addEventListener('pointerdown', function (e) {
    if (!body.classList.contains('is-reorder')) return;
    var handle = e.target.closest('[data-drag-handle]');
    if (!handle) return;
    var row = handle.closest('tr[data-sortable-id]');
    if (!row) return;
    e.preventDefault();
    drag = { row: row };
    row.classList.add('is-dragging');
    try { handle.setPointerCapture(e.pointerId); } catch (_) {}
  });

  tbody.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var y = e.clientY;
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-sortable-id]'));
    if (rows.length < 2) return;

    var target;
    var resolved = false;
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (r === drag.row) continue;
      var rect = r.getBoundingClientRect();
      if (y < rect.top || y > rect.bottom) continue;
      var mid = rect.top + rect.height / 2;
      target = (y < mid) ? r : r.nextElementSibling;
      resolved = true;
      break;
    }
    if (!resolved) {
      var first = rows[0].getBoundingClientRect();
      var last  = rows[rows.length - 1].getBoundingClientRect();
      if (y < first.top)      target = rows[0];
      else if (y > last.bottom) target = null;
      else return;
    }
    if (drag.row === target || drag.row.nextSibling === target) return;

    var oldTops = new Map();
    rows.forEach(function (r) {
      if (r === drag.row) return;
      oldTops.set(r, r.getBoundingClientRect().top);
    });

    tbody.insertBefore(drag.row, target);

    flipAnimate(rows.filter(function (r) { return r !== drag.row; }), oldTops);
  });

  function endDrag() {
    if (!drag) return;
    drag.row.classList.remove('is-dragging');
    drag = null;
  }
  tbody.addEventListener('pointerup', endDrag);
  tbody.addEventListener('pointercancel', endDrag);
})();
</script>

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
