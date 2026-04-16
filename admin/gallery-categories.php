<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gallery.php';

bsv_require_admin();

$pdo = bsv_db();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$form = [
    'id'       => 0,
    'name'     => '',
    'slug'     => '',
    'position' => 0,
];
$errors = [];

if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT id, name, slug, position FROM gallery_categories WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if (!$row) {
        bsv_flash_set('error', 'Categoria nu a fost găsită.');
        header('Location: gallery-categories.php');
        exit;
    }
    $form = [
        'id'       => (int)$row['id'],
        'name'     => (string)$row['name'],
        'slug'     => (string)$row['slug'],
        'position' => (int)$row['position'],
    ];
}

// --- Delete handler --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM gallery_categories WHERE id = :id')->execute([':id' => $id]);
            // The photo_categories pivot has ON DELETE CASCADE, so orphan rows
            // are cleaned automatically by the FK.
            bsv_flash_set('success', 'Categoria a fost ștearsă.');
        }
    }
    header('Location: gallery-categories.php');
    exit;
}

// --- Create / update handler ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $form['id']       = (int)($_POST['id'] ?? 0);
    $form['name']     = trim((string)($_POST['name'] ?? ''));
    $form['slug']     = trim((string)($_POST['slug'] ?? ''));
    $form['position'] = (int)($_POST['position'] ?? 0);

    if ($form['name'] === '' || mb_strlen($form['name']) > 80) {
        $errors['name'] = 'Numele este obligatoriu (maxim 80 de caractere).';
    }
    if ($form['slug'] === '') {
        $form['slug'] = bsv_gallery_slugify($form['name']);
    } else {
        $form['slug'] = bsv_gallery_slugify($form['slug']);
    }
    if ($form['position'] < 0 || $form['position'] > 10000) {
        $errors['position'] = 'Poziția trebuie să fie între 0 și 10000.';
    }

    if (!$errors) {
        $form['slug'] = bsv_gallery_unique_slug($form['slug'], $form['id']);
        $now = date('Y-m-d H:i:s');
        if ($form['id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE gallery_categories
                    SET name = :name, slug = :slug, position = :position, updated_at = :updated_at
                  WHERE id = :id'
            );
            $stmt->execute([
                ':name'       => $form['name'],
                ':slug'       => $form['slug'],
                ':position'   => $form['position'],
                ':updated_at' => $now,
                ':id'         => $form['id'],
            ]);
            bsv_flash_set('success', 'Categoria a fost actualizată.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO gallery_categories (name, slug, position, created_at, updated_at)
                 VALUES (:name, :slug, :position, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':name'       => $form['name'],
                ':slug'       => $form['slug'],
                ':position'   => $form['position'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            bsv_flash_set('success', 'Categoria a fost creată.');
        }
        header('Location: gallery-categories.php');
        exit;
    }
}

$categories = $pdo->query(
    'SELECT c.id, c.name, c.slug, c.position,
            (SELECT COUNT(*) FROM gallery_photo_categories pc WHERE pc.category_id = c.id) AS photo_count
       FROM gallery_categories c
      ORDER BY c.position ASC, c.name ASC'
)->fetchAll();

$csrf = bsv_csrf_token();

$actions = '
  <a href="gallery.php" class="adm-btn adm-btn--ghost">
    <span class="material-symbols-outlined" aria-hidden="true">photo_library</span>
    <span>Înapoi la galerie</span>
  </a>';

bsv_admin_header(
    'Categorii galerie',
    'Creați, redenumiți sau ștergeți categoriile folosite pentru a organiza fotografiile.',
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

<div class="gallery-cats">
  <form method="post" class="admin-card" action="gallery-categories.php" novalidate>
    <div class="admin-card__head">
      <h2><?= $form['id'] > 0 ? 'Editează categoria' : 'Categorie nouă' ?></h2>
      <p><?= $form['id'] > 0 ? 'Redenumiți categoria sau schimbați ordinea ei în filtre.' : 'Categoriile apar ca butoane de filtru pe pagina publică.' ?></p>
    </div>

    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
    <input type="hidden" name="_token" value="<?= h($csrf) ?>">

    <div class="form-grid">
      <div class="field field-full">
        <label for="name">Nume <span class="req">*</span></label>
        <input type="text" id="name" name="name" maxlength="80" required
               value="<?= h($form['name']) ?>"
               placeholder="Ex.: Praznice, Comunitate, Biserica">
        <?php if (!empty($errors['name'])): ?><span class="err-msg"><?= h($errors['name']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="slug">Slug</label>
        <input type="text" id="slug" name="slug" maxlength="64"
               value="<?= h($form['slug']) ?>"
               placeholder="ex.: praznice">
        <span class="hint">Folosit în URL-ul categoriei. Generat automat dacă îl lăsați gol.</span>
      </div>

      <div class="field">
        <label for="position">Poziție</label>
        <input type="number" id="position" name="position" min="0" max="10000"
               value="<?= (int)$form['position'] ?>">
        <span class="hint">Cu cât este mai mic, cu atât apare mai devreme în filtre.</span>
        <?php if (!empty($errors['position'])): ?><span class="err-msg"><?= h($errors['position']) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="form-actions">
      <?php if ($form['id'] > 0): ?>
        <a href="gallery-categories.php" class="adm-btn adm-btn--ghost">
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
          <span>Renunță la editare</span>
        </a>
      <?php else: ?>
        <a href="gallery.php" class="adm-btn adm-btn--ghost">
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          <span>Anulează</span>
        </a>
      <?php endif; ?>
      <button type="submit" class="adm-btn adm-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">save</span>
        <span><?= $form['id'] > 0 ? 'Salvează modificările' : 'Adaugă categoria' ?></span>
      </button>
    </div>
  </form>

  <?php if (!$categories): ?>
    <div class="table-empty" style="margin-top: var(--s-5);">
      <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">folder</span>
      <h3>Nu există încă nicio categorie</h3>
      <p>Folosiți formularul de mai sus pentru a crea prima categorie.</p>
    </div>
  <?php else: ?>
    <table class="events-table" style="margin-top: var(--s-5);">
      <thead>
        <tr>
          <th class="col-date" style="width: 80px;">Poziție</th>
          <th>Nume</th>
          <th class="col-cat">Slug</th>
          <th class="col-status">Fotografii</th>
          <th class="col-actions">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td class="col-date">
              <div class="date-cell">
                <strong><?= (int)$c['position'] ?></strong>
              </div>
            </td>
            <td>
              <div class="title-cell">
                <strong><?= h($c['name']) ?></strong>
              </div>
            </td>
            <td class="col-cat">
              <code style="font-family: var(--f-sans); font-size: 0.82rem; color: var(--c-ink-muted);"><?= h($c['slug']) ?></code>
            </td>
            <td class="col-status">
              <span class="pill"><?= (int)$c['photo_count'] ?> foto</span>
            </td>
            <td class="col-actions">
              <a class="adm-btn adm-btn--ghost adm-btn--sm" href="?edit=<?= (int)$c['id'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                <span>Editează</span>
              </a>
              <form method="post" class="inline-form" action="gallery-categories.php"
                    onsubmit="return confirm('Ștergeți categoria „<?= h(addslashes($c['name'])) ?>”? Fotografiile rămân, dar își pierd această etichetă.');">
                <input type="hidden" name="action" value="delete">
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
</div>

<?php bsv_admin_footer(); ?>
