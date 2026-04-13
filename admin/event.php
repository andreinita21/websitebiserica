<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$defaults = [
    'title'        => '',
    'description'  => '',
    'event_date'   => '',
    'start_time'   => '',
    'end_time'     => '',
    'location'     => '',
    'category'     => 'liturghie',
    'is_published' => 1,
];

$data   = $defaults;
$errors = [];

// --- Load on edit ----------------------------------------------------------
if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = bsv_db()->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        bsv_flash_set('error', 'Evenimentul nu a fost găsit.');
        header('Location: index.php');
        exit;
    }
    $data = array_merge($defaults, [
        'title'        => $row['title'],
        'description'  => $row['description'],
        'event_date'   => $row['event_date'],
        'start_time'   => $row['start_time'] ? substr($row['start_time'], 0, 5) : '',
        'end_time'     => $row['end_time']   ? substr($row['end_time'], 0, 5)   : '',
        'location'     => $row['location'],
        'category'     => $row['category'],
        'is_published' => (int)$row['is_published'],
    ]);
}

// --- Submit ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $data['title']        = trim((string)($_POST['title'] ?? ''));
    $data['description']  = trim((string)($_POST['description'] ?? ''));
    $data['event_date']   = trim((string)($_POST['event_date'] ?? ''));
    $data['start_time']   = trim((string)($_POST['start_time'] ?? ''));
    $data['end_time']     = trim((string)($_POST['end_time'] ?? ''));
    $data['location']     = trim((string)($_POST['location'] ?? ''));
    $data['category']     = (string)($_POST['category'] ?? 'liturghie');
    $data['is_published'] = isset($_POST['is_published']) ? 1 : 0;

    if ($data['title'] === '' || mb_strlen($data['title']) > 180) {
        $errors['title'] = 'Titlul este obligatoriu (maxim 180 de caractere).';
    }
    if (!bsv_valid_date($data['event_date'])) {
        $errors['event_date'] = 'Introduceți o dată validă (YYYY-MM-DD).';
    }
    $startClean = bsv_clean_time($data['start_time'] ?: null);
    $endClean   = bsv_clean_time($data['end_time']   ?: null);
    if ($data['start_time'] !== '' && $startClean === null) {
        $errors['start_time'] = 'Ora de început nu este validă (format HH:MM).';
    }
    if ($data['end_time'] !== '' && $endClean === null) {
        $errors['end_time'] = 'Ora de sfârșit nu este validă (format HH:MM).';
    }
    if ($startClean && $endClean && $endClean < $startClean) {
        $errors['end_time'] = 'Ora de sfârșit trebuie să fie după ora de început.';
    }
    if (!bsv_valid_category($data['category'])) {
        $errors['category'] = 'Categoria selectată nu este validă.';
    }
    if (mb_strlen($data['location']) > 200) {
        $errors['location'] = 'Locația este prea lungă (maxim 200 de caractere).';
    }
    if (mb_strlen($data['description']) > 5000) {
        $errors['description'] = 'Descrierea este prea lungă (maxim 5000 de caractere).';
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        if ($isEdit) {
            $stmt = bsv_db()->prepare(
                'UPDATE events
                    SET title = :title,
                        description = :description,
                        event_date = :event_date,
                        start_time = :start_time,
                        end_time   = :end_time,
                        location   = :location,
                        category   = :category,
                        is_published = :is_published,
                        updated_at = :updated_at
                  WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':event_date' => $data['event_date'],
                ':start_time' => $startClean,
                ':end_time'   => $endClean,
                ':location'   => $data['location'],
                ':category'   => $data['category'],
                ':is_published' => $data['is_published'],
                ':updated_at' => $now,
                ':id'         => $id,
            ]);
            bsv_flash_set('success', 'Evenimentul a fost actualizat.');
        } else {
            $stmt = bsv_db()->prepare(
                'INSERT INTO events (title, description, event_date, start_time, end_time,
                                     location, category, is_published, created_at, updated_at)
                 VALUES (:title, :description, :event_date, :start_time, :end_time,
                         :location, :category, :is_published, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':event_date' => $data['event_date'],
                ':start_time' => $startClean,
                ':end_time'   => $endClean,
                ':location'   => $data['location'],
                ':category'   => $data['category'],
                ':is_published' => $data['is_published'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            bsv_flash_set('success', 'Evenimentul a fost creat.');
        }
        header('Location: index.php');
        exit;
    }
}

$csrf = bsv_csrf_token();
$title = $isEdit ? 'Editează evenimentul' : 'Adaugă eveniment nou';
$subtitle = $isEdit
    ? 'Modificați detaliile evenimentului și salvați pentru a actualiza calendarul.'
    : 'Completați câmpurile de mai jos — evenimentul va apărea automat în calendarul public după salvare.';

bsv_admin_header($title, $subtitle);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="event.php<?= $isEdit ? '?id=' . (int)$id : '' ?>" novalidate>
  <div class="admin-card__head">
    <h2><?= $isEdit ? 'Detalii eveniment' : 'Eveniment nou' ?></h2>
    <p>Câmpurile marcate cu <span class="req">*</span> sunt obligatorii.</p>
  </div>

  <div class="form-grid">
    <div class="field field-full">
      <label for="title">Titlu <span class="req">*</span></label>
      <input type="text" id="title" name="title" maxlength="180"
             value="<?= h($data['title']) ?>" required>
      <?php if (!empty($errors['title'])): ?><span class="err-msg"><?= h($errors['title']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="event_date">Data <span class="req">*</span></label>
      <input type="date" id="event_date" name="event_date"
             value="<?= h($data['event_date']) ?>" required>
      <?php if (!empty($errors['event_date'])): ?><span class="err-msg"><?= h($errors['event_date']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="category">Categorie <span class="req">*</span></label>
      <select id="category" name="category">
        <?php foreach (APP_CATEGORIES as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $data['category'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['category'])): ?><span class="err-msg"><?= h($errors['category']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="start_time">Ora de început</label>
      <input type="time" id="start_time" name="start_time"
             value="<?= h($data['start_time']) ?>">
      <span class="hint">Lăsați gol pentru un eveniment de toată ziua.</span>
      <?php if (!empty($errors['start_time'])): ?><span class="err-msg"><?= h($errors['start_time']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="end_time">Ora de sfârșit</label>
      <input type="time" id="end_time" name="end_time"
             value="<?= h($data['end_time']) ?>">
      <span class="hint">Opțional — dacă este cunoscută.</span>
      <?php if (!empty($errors['end_time'])): ?><span class="err-msg"><?= h($errors['end_time']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="location">Locație</label>
      <input type="text" id="location" name="location" maxlength="200"
             value="<?= h($data['location']) ?>"
             placeholder="De ex.: Altarul principal, Sala parohială, Curtea bisericii">
      <?php if (!empty($errors['location'])): ?><span class="err-msg"><?= h($errors['location']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="description">Descriere</label>
      <textarea id="description" name="description" maxlength="5000" rows="6"
                placeholder="Câteva rânduri despre eveniment, care vor apărea pe pagina calendarului."><?= h($data['description']) ?></textarea>
      <?php if (!empty($errors['description'])): ?><span class="err-msg"><?= h($errors['description']) ?></span><?php endif; ?>
    </div>

    <div class="field field--check field-full">
      <input type="checkbox" id="is_published" name="is_published" value="1"
             <?= (int)$data['is_published'] === 1 ? 'checked' : '' ?>>
      <label for="is_published">
        Publică evenimentul (vizibil public pe pagina calendarului și pe pagina principală)
      </label>
    </div>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">

  <div class="form-actions">
    <a href="index.php" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      <span>Anulează</span>
    </a>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span><?= $isEdit ? 'Salvează modificările' : 'Creează evenimentul' ?></span>
    </button>
  </div>
</form>

<?php bsv_admin_footer(); ?>
