<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$errors = [];
$form   = ['slug' => '', 'label' => '', 'color' => '#C9A24A', 'position' => 0];

// --- Delete ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $slugStmt = bsv_db()->prepare('SELECT slug FROM event_categories WHERE id = :id');
        $slugStmt->execute([':id' => $id]);
        $slug = (string)$slugStmt->fetchColumn();
        if ($slug !== '') {
            $usageStmt = bsv_db()->prepare('SELECT COUNT(*) FROM events WHERE category = :s');
            $usageStmt->execute([':s' => $slug]);
            $usage = (int)$usageStmt->fetchColumn();
            if ($usage > 0) {
                bsv_flash_set('error', "Categoria este folosită de $usage eveniment(e). Mutați-le pe altă categorie înainte de ștergere.");
            } else {
                $del = bsv_db()->prepare('DELETE FROM event_categories WHERE id = :id');
                $del->execute([':id' => $id]);
                bsv_flash_set('success', 'Categoria a fost ștearsă.');
            }
        }
    }
    header('Location: categories.php');
    exit;
}

// --- Create / Update -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['create', 'update'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }
    $form['slug']     = strtolower(trim((string)($_POST['slug'] ?? '')));
    $form['label']    = trim((string)($_POST['label'] ?? ''));
    $form['color']    = trim((string)($_POST['color'] ?? '#C9A24A'));
    $form['position'] = (int)($_POST['position'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);

    if (!preg_match('/^[a-z0-9_-]{2,40}$/', $form['slug'])) {
        $errors['slug'] = 'Slug invalid — doar a–z, 0–9, "-" și "_" (2–40 caractere).';
    }
    if ($form['label'] === '' || mb_strlen($form['label']) > 120) {
        $errors['label'] = 'Numele este obligatoriu (maxim 120 caractere).';
    }
    if ($form['color'] !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $form['color'])) {
        $errors['color'] = 'Culoare invalidă — folosiți formatul #RRGGBB.';
    }

    if (empty($errors['slug'])) {
        $uniq = bsv_db()->prepare('SELECT id FROM event_categories WHERE slug = :s AND id != :id');
        $uniq->execute([':s' => $form['slug'], ':id' => $action === 'update' ? $id : 0]);
        if ($uniq->fetchColumn()) {
            $errors['slug'] = 'Acest slug este deja folosit de altă categorie.';
        }
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $colorVal = $form['color'] !== '' ? $form['color'] : null;
        if ($action === 'update' && $id > 0) {
            // If the slug changed, keep existing events pointing at the new slug.
            $oldStmt = bsv_db()->prepare('SELECT slug FROM event_categories WHERE id = :id');
            $oldStmt->execute([':id' => $id]);
            $oldSlug = (string)$oldStmt->fetchColumn();

            $upd = bsv_db()->prepare(
                'UPDATE event_categories
                    SET slug = :slug, label = :label, color = :color, position = :pos, updated_at = :now
                  WHERE id = :id'
            );
            $upd->execute([
                ':slug' => $form['slug'], ':label' => $form['label'],
                ':color' => $colorVal, ':pos' => $form['position'],
                ':now' => $now, ':id' => $id,
            ]);
            if ($oldSlug !== '' && $oldSlug !== $form['slug']) {
                $re = bsv_db()->prepare('UPDATE events SET category = :new WHERE category = :old');
                $re->execute([':new' => $form['slug'], ':old' => $oldSlug]);
            }
            bsv_flash_set('success', 'Categoria a fost actualizată.');
        } else {
            $ins = bsv_db()->prepare(
                'INSERT INTO event_categories (slug, label, color, position, created_at, updated_at)
                 VALUES (:slug, :label, :color, :pos, :now, :now)'
            );
            $ins->execute([
                ':slug' => $form['slug'], ':label' => $form['label'],
                ':color' => $colorVal, ':pos' => $form['position'], ':now' => $now,
            ]);
            bsv_flash_set('success', 'Categoria a fost creată.');
        }
        header('Location: categories.php');
        exit;
    }
}

// --- Load edit target ------------------------------------------------------
if ($editId && !$errors) {
    $stmt = bsv_db()->prepare('SELECT * FROM event_categories WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row) {
        $form = [
            'slug'     => (string)$row['slug'],
            'label'    => (string)$row['label'],
            'color'    => (string)($row['color'] ?? '#C9A24A'),
            'position' => (int)$row['position'],
        ];
    } else {
        $editId = 0;
    }
}

// --- List with usage counts ------------------------------------------------
$cats = bsv_db()->query(
    "SELECT c.id, c.slug, c.label, c.color, c.position,
            (SELECT COUNT(*) FROM events WHERE events.category = c.slug) AS usage_count
       FROM event_categories c
   ORDER BY c.position ASC, c.id ASC"
)->fetchAll();

$csrf = bsv_csrf_token();

$actions = '<a href="index.php" class="adm-btn adm-btn--ghost">'
         . '<span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>'
         . '<span>Înapoi la evenimente</span></a>';

bsv_admin_header(
    'Categorii',
    'Gestionați lista de categorii pentru evenimente (nume, slug, culoare, ordine).',
    $actions,
    'categories'
);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="categories.php<?= $editId ? '?edit=' . (int)$editId : '' ?>" novalidate>
  <div class="admin-card__head">
    <h2><?= $editId ? 'Editează categoria' : 'Adaugă categorie nouă' ?></h2>
    <p>Numele este afișat public; slug-ul rămâne intern pentru stiluri și URL-uri.</p>
  </div>

  <div class="form-grid">
    <div class="field">
      <label for="cat-label">Nume <span class="req">*</span></label>
      <input type="text" id="cat-label" name="label" maxlength="120" required
             value="<?= h($form['label']) ?>"
             placeholder="ex.: Sfânta Liturghie">
      <?php if (!empty($errors['label'])): ?><span class="err-msg"><?= h($errors['label']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="cat-slug">Slug <span class="req">*</span></label>
      <input type="text" id="cat-slug" name="slug" maxlength="40" required
             value="<?= h($form['slug']) ?>" pattern="[a-z0-9_-]{2,40}"
             placeholder="ex.: liturghie">
      <span class="hint">ASCII, folosit intern (a–z, 0–9, "-", "_"; 2–40 caractere).</span>
      <?php if (!empty($errors['slug'])): ?><span class="err-msg"><?= h($errors['slug']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="cat-color">Culoare</label>
      <input type="color" id="cat-color" name="color"
             value="<?= h($form['color'] !== '' ? $form['color'] : '#C9A24A') ?>">
      <span class="hint">Apare ca punct colorat în listă și în legenda calendarului.</span>
      <?php if (!empty($errors['color'])): ?><span class="err-msg"><?= h($errors['color']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="cat-position">Ordine</label>
      <input type="number" id="cat-position" name="position" min="0" step="1"
             value="<?= (int)$form['position'] ?>">
      <span class="hint">Mai mic = afișat mai sus în listă și în selecții.</span>
    </div>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="<?= $editId ? 'update' : 'create' ?>">
  <?php if ($editId): ?><input type="hidden" name="id" value="<?= (int)$editId ?>"><?php endif; ?>

  <div class="form-actions">
    <?php if ($editId): ?>
      <a href="categories.php" class="adm-btn adm-btn--ghost">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
        <span>Anulează editarea</span>
      </a>
    <?php endif; ?>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span><?= $editId ? 'Salvează modificările' : 'Adaugă categoria' ?></span>
    </button>
  </div>
</form>

<?php if (empty($cats)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">sell</span>
    <h3>Nicio categorie</h3>
    <p>Folosiți formularul de mai sus pentru a adăuga prima categorie.</p>
  </div>
<?php else: ?>
  <table class="events-table">
    <thead>
      <tr>
        <th>Nume</th>
        <th>Slug</th>
        <th>Culoare</th>
        <th>Ordine</th>
        <th>Folosită</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($cats as $c):
        $usage = (int)$c['usage_count'];
        $color = (string)($c['color'] ?? '');
      ?>
        <tr>
          <td>
            <span class="pill-cat" data-cat="<?= h($c['slug']) ?>"<?= $color !== '' ? ' style="--pill-color: ' . h($color) . '"' : '' ?>>
              <?= h($c['label']) ?>
            </span>
          </td>
          <td><code><?= h($c['slug']) ?></code></td>
          <td>
            <?php if ($color !== ''): ?>
              <span class="color-swatch" style="background: <?= h($color) ?>"></span>
              <code><?= h($color) ?></code>
            <?php else: ?>
              <span class="hint">—</span>
            <?php endif; ?>
          </td>
          <td><?= (int)$c['position'] ?></td>
          <td><?= $usage ?> <?= $usage === 1 ? 'eveniment' : 'evenimente' ?></td>
          <td class="col-actions">
            <a href="categories.php?edit=<?= (int)$c['id'] ?>" class="adm-btn adm-btn--ghost adm-btn--sm">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </a>
            <form method="post" class="inline-form" action="categories.php"
                  onsubmit="return confirm('Sigur doriți să ștergeți categoria „<?= h(addslashes($c['label'])) ?>”?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">
              <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"
                      <?= $usage > 0 ? 'disabled title="Este folosită de evenimente — mutați-le pe altă categorie întâi."' : '' ?>>
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

<?php bsv_admin_footer(); ?>
