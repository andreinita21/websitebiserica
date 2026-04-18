<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$errors = [];
$form   = ['name' => '', 'position' => 0];

// --- Delete ----------------------------------------------------------------
// Locations are a suggestion library only — deleting one never affects
// existing events (which keep their text-based location).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = bsv_db()->prepare('DELETE FROM event_locations WHERE id = :id');
            $del->execute([':id' => $id]);
            bsv_flash_set('success', 'Locația a fost ștearsă din listă.');
        }
    }
    header('Location: locations.php');
    exit;
}

// --- Create / Update -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['create', 'update'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }
    $form['name']     = trim((string)($_POST['name'] ?? ''));
    $form['position'] = (int)($_POST['position'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);

    if ($form['name'] === '' || mb_strlen($form['name']) > 200) {
        $errors['name'] = 'Numele este obligatoriu (maxim 200 caractere).';
    }

    if (empty($errors['name'])) {
        $uniq = bsv_db()->prepare('SELECT id FROM event_locations WHERE name = :n AND id != :id');
        $uniq->execute([':n' => $form['name'], ':id' => $action === 'update' ? $id : 0]);
        if ($uniq->fetchColumn()) {
            $errors['name'] = 'O locație cu acest nume există deja.';
        }
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        if ($action === 'update' && $id > 0) {
            // Propagate the rename to events that were pointing at the old value.
            $oldStmt = bsv_db()->prepare('SELECT name FROM event_locations WHERE id = :id');
            $oldStmt->execute([':id' => $id]);
            $oldName = (string)$oldStmt->fetchColumn();

            $upd = bsv_db()->prepare(
                'UPDATE event_locations
                    SET name = :name, position = :pos, updated_at = :now
                  WHERE id = :id'
            );
            $upd->execute([
                ':name' => $form['name'], ':pos' => $form['position'],
                ':now' => $now, ':id' => $id,
            ]);
            if ($oldName !== '' && $oldName !== $form['name']) {
                $re = bsv_db()->prepare('UPDATE events SET location = :new WHERE location = :old');
                $re->execute([':new' => $form['name'], ':old' => $oldName]);
            }
            bsv_flash_set('success', 'Locația a fost actualizată.');
        } else {
            $ins = bsv_db()->prepare(
                'INSERT INTO event_locations (name, position, created_at, updated_at)
                 VALUES (:name, :pos, :now, :now)'
            );
            $ins->execute([
                ':name' => $form['name'], ':pos' => $form['position'], ':now' => $now,
            ]);
            bsv_flash_set('success', 'Locația a fost adăugată în listă.');
        }
        header('Location: locations.php');
        exit;
    }
}

// --- Load edit target ------------------------------------------------------
if ($editId && !$errors) {
    $stmt = bsv_db()->prepare('SELECT * FROM event_locations WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row) {
        $form = [
            'name'     => (string)$row['name'],
            'position' => (int)$row['position'],
        ];
    } else {
        $editId = 0;
    }
}

// --- List with usage counts ------------------------------------------------
$locs = bsv_db()->query(
    "SELECT l.id, l.name, l.position,
            (SELECT COUNT(*) FROM events WHERE events.location = l.name) AS usage_count
       FROM event_locations l
   ORDER BY l.position ASC, l.name ASC"
)->fetchAll();

$csrf = bsv_csrf_token();

$actions = '<a href="index.php" class="adm-btn adm-btn--ghost">'
         . '<span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>'
         . '<span>Înapoi la evenimente</span></a>';

bsv_admin_header(
    'Locații',
    'Gestionați lista de locații sugerate pentru evenimente. Ștergerea unei locații nu afectează evenimentele existente.',
    $actions,
    'locations'
);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="locations.php<?= $editId ? '?edit=' . (int)$editId : '' ?>" novalidate>
  <div class="admin-card__head">
    <h2><?= $editId ? 'Editează locația' : 'Adaugă locație nouă' ?></h2>
    <p>Numele va apărea în lista de selecție a formularului de eveniment.</p>
  </div>

  <div class="form-grid">
    <div class="field field-full">
      <label for="loc-name">Nume <span class="req">*</span></label>
      <input type="text" id="loc-name" name="name" maxlength="200" required
             value="<?= h($form['name']) ?>"
             placeholder="ex.: Altarul principal, Sala parohială, Curtea bisericii">
      <?php if (!empty($errors['name'])): ?><span class="err-msg"><?= h($errors['name']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="loc-position">Ordine</label>
      <input type="number" id="loc-position" name="position" min="0" step="1"
             value="<?= (int)$form['position'] ?>">
      <span class="hint">Mai mic = afișat mai sus în selecții.</span>
    </div>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="<?= $editId ? 'update' : 'create' ?>">
  <?php if ($editId): ?><input type="hidden" name="id" value="<?= (int)$editId ?>"><?php endif; ?>

  <div class="form-actions">
    <?php if ($editId): ?>
      <a href="locations.php" class="adm-btn adm-btn--ghost">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
        <span>Anulează editarea</span>
      </a>
    <?php endif; ?>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span><?= $editId ? 'Salvează modificările' : 'Adaugă locația' ?></span>
    </button>
  </div>
</form>

<?php if (empty($locs)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">location_on</span>
    <h3>Nicio locație salvată</h3>
    <p>Adăugați locațiile pe care le folosiți des pentru a le selecta rapid la crearea evenimentelor.</p>
  </div>
<?php else: ?>
  <table class="events-table">
    <thead>
      <tr>
        <th>Nume</th>
        <th>Ordine</th>
        <th>Folosită</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($locs as $l): $usage = (int)$l['usage_count']; ?>
        <tr>
          <td>
            <strong><?= h($l['name']) ?></strong>
          </td>
          <td><?= (int)$l['position'] ?></td>
          <td><?= $usage ?> <?= $usage === 1 ? 'eveniment' : 'evenimente' ?></td>
          <td class="col-actions">
            <a href="locations.php?edit=<?= (int)$l['id'] ?>" class="adm-btn adm-btn--ghost adm-btn--sm">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </a>
            <form method="post" class="inline-form" action="locations.php"
                  onsubmit="return confirm('Ștergeți locația „<?= h(addslashes($l['name'])) ?>” din listă?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
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

<?php bsv_admin_footer(); ?>
