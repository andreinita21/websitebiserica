<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$defaults = [
    'title'          => '',
    'body'           => '',
    'tag'            => '',
    'relevant_on'    => date('Y-m-d'),
    'relevant_until' => '',
    'visible_days'   => 7,
    'date_mode'      => 'single',
    'is_published'   => 1,
];

$data   = $defaults;
$errors = [];

// --- Load on edit ----------------------------------------------------------
if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = bsv_db()->prepare('SELECT * FROM announcements WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        bsv_flash_set('error', 'Anunțul nu a fost găsit.');
        header('Location: announcements.php');
        exit;
    }

    $relevantUntil = $row['relevant_until'] ?? null;
    $visibleDays   = $row['visible_days']   ?? null;
    $mode = 'single';
    if (!empty($relevantUntil)) {
        $mode = 'interval';
    } elseif ($visibleDays !== null && (int)$visibleDays > 0) {
        $mode = 'duration';
    }

    $data = array_merge($defaults, [
        'title'          => $row['title'],
        'body'           => $row['body'],
        'tag'            => $row['tag'],
        'relevant_on'    => $row['relevant_on'],
        'relevant_until' => $relevantUntil ?? '',
        'visible_days'   => $visibleDays !== null ? (int)$visibleDays : 7,
        'date_mode'      => $mode,
        'is_published'   => (int)$row['is_published'],
    ]);
}

// --- Submit ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $data['title']          = trim((string)($_POST['title'] ?? ''));
    $data['body']           = trim((string)($_POST['body'] ?? ''));
    $data['tag']            = trim((string)($_POST['tag'] ?? ''));
    $data['relevant_on']    = trim((string)($_POST['relevant_on'] ?? ''));
    $data['relevant_until'] = trim((string)($_POST['relevant_until'] ?? ''));
    $data['visible_days']   = (int)($_POST['visible_days'] ?? 0);
    $data['date_mode']      = (string)($_POST['date_mode'] ?? 'single');
    $data['is_published']   = isset($_POST['is_published']) ? 1 : 0;

    if (!in_array($data['date_mode'], ['single', 'interval', 'duration'], true)) {
        $data['date_mode'] = 'single';
    }

    if ($data['title'] === '' || mb_strlen($data['title']) > 180) {
        $errors['title'] = 'Titlul este obligatoriu (maxim 180 de caractere).';
    }

    // Mode-specific validation + normalization of the columns we'll actually save.
    $saveRelevantOn    = null;
    $saveRelevantUntil = null;
    $saveVisibleDays   = null;

    if ($data['date_mode'] === 'single') {
        if (!bsv_valid_date($data['relevant_on'])) {
            $errors['relevant_on'] = 'Introduceți o dată validă (YYYY-MM-DD).';
        } else {
            $saveRelevantOn = $data['relevant_on'];
        }
    } elseif ($data['date_mode'] === 'interval') {
        if (!bsv_valid_date($data['relevant_on'])) {
            $errors['relevant_on'] = 'Introduceți o dată de început validă.';
        }
        if (!bsv_valid_date($data['relevant_until'])) {
            $errors['relevant_until'] = 'Introduceți o dată de sfârșit validă.';
        }
        if (empty($errors['relevant_on']) && empty($errors['relevant_until'])
            && $data['relevant_until'] < $data['relevant_on']) {
            $errors['relevant_until'] = 'Data de sfârșit trebuie să fie după data de început.';
        }
        if (empty($errors['relevant_on']) && empty($errors['relevant_until'])) {
            $saveRelevantOn    = $data['relevant_on'];
            $saveRelevantUntil = $data['relevant_until'];
        }
    } else { // duration
        if ($data['visible_days'] < 1 || $data['visible_days'] > 365) {
            $errors['visible_days'] = 'Introduceți un număr de zile între 1 și 365.';
        } else {
            // keep relevant_on as a sortable reference; the actual expiry is driven by visible_days
            $saveRelevantOn  = bsv_valid_date($data['relevant_on']) ? $data['relevant_on'] : date('Y-m-d');
            $saveVisibleDays = $data['visible_days'];
        }
    }

    if (mb_strlen($data['tag']) > 40) {
        $errors['tag'] = 'Eticheta este prea lungă (maxim 40 de caractere).';
    }
    if (mb_strlen($data['body']) > 5000) {
        $errors['body'] = 'Conținutul este prea lung (maxim 5000 de caractere).';
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        if ($isEdit) {
            $stmt = bsv_db()->prepare(
                'UPDATE announcements
                    SET title          = :title,
                        body           = :body,
                        tag            = :tag,
                        relevant_on    = :relevant_on,
                        relevant_until = :relevant_until,
                        visible_days   = :visible_days,
                        is_published   = :is_published,
                        updated_at     = :updated_at
                  WHERE id = :id'
            );
            $stmt->execute([
                ':title'          => $data['title'],
                ':body'           => $data['body'],
                ':tag'            => $data['tag'],
                ':relevant_on'    => $saveRelevantOn,
                ':relevant_until' => $saveRelevantUntil,
                ':visible_days'   => $saveVisibleDays,
                ':is_published'   => $data['is_published'],
                ':updated_at'     => $now,
                ':id'             => $id,
            ]);
            bsv_flash_set('success', 'Anunțul a fost actualizat.');
        } else {
            $stmt = bsv_db()->prepare(
                'INSERT INTO announcements
                    (title, body, tag, relevant_on, relevant_until, visible_days,
                     is_published, created_at, updated_at)
                 VALUES
                    (:title, :body, :tag, :relevant_on, :relevant_until, :visible_days,
                     :is_published, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':title'          => $data['title'],
                ':body'           => $data['body'],
                ':tag'            => $data['tag'],
                ':relevant_on'    => $saveRelevantOn,
                ':relevant_until' => $saveRelevantUntil,
                ':visible_days'   => $saveVisibleDays,
                ':is_published'   => $data['is_published'],
                ':created_at'     => $now,
                ':updated_at'     => $now,
            ]);
            bsv_flash_set('success', 'Anunțul a fost creat.');
        }
        header('Location: announcements.php');
        exit;
    }
}

$csrf = bsv_csrf_token();
$title = $isEdit ? 'Editează anunțul' : 'Adaugă anunț nou';
$subtitle = $isEdit
    ? 'Modificați conținutul și modul de valabilitate al anunțului.'
    : 'Completați câmpurile de mai jos — alegeți dacă anunțul este valabil pentru o zi, un interval, sau o perioadă în zile.';

$actions = '
  <a href="announcement.php" class="adm-btn adm-btn--primary">
    <span class="material-symbols-outlined" aria-hidden="true">add</span>
    <span>Adaugă anunț</span>
  </a>';

bsv_admin_header($title, $subtitle, $actions, 'announcements');
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="announcement.php<?= $isEdit ? '?id=' . (int)$id : '' ?>" novalidate
      data-announcement-form>
  <div class="admin-card__head">
    <h2><?= $isEdit ? 'Detalii anunț' : 'Anunț nou' ?></h2>
    <p>Câmpurile marcate cu <span class="req">*</span> sunt obligatorii.</p>
  </div>

  <div class="form-grid">
    <div class="field field-full">
      <label for="title">Titlu <span class="req">*</span></label>
      <input type="text" id="title" name="title" maxlength="180"
             value="<?= h($data['title']) ?>" required>
      <?php if (!empty($errors['title'])): ?><span class="err-msg"><?= h($errors['title']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label>Mod de valabilitate <span class="req">*</span></label>
      <div class="mode-group" role="radiogroup" aria-label="Mod de valabilitate">
        <label class="mode-option">
          <input type="radio" name="date_mode" value="single"
                 <?= $data['date_mode'] === 'single' ? 'checked' : '' ?>>
          <span class="mode-option__title">Dată unică</span>
          <span class="mode-option__hint">Valabil pentru o singură zi.</span>
        </label>
        <label class="mode-option">
          <input type="radio" name="date_mode" value="interval"
                 <?= $data['date_mode'] === 'interval' ? 'checked' : '' ?>>
          <span class="mode-option__title">Interval</span>
          <span class="mode-option__hint">Valabil între două date.</span>
        </label>
        <label class="mode-option">
          <input type="radio" name="date_mode" value="duration"
                 <?= $data['date_mode'] === 'duration' ? 'checked' : '' ?>>
          <span class="mode-option__title">Durată (N zile)</span>
          <span class="mode-option__hint">Dispare automat după N zile de la creare.</span>
        </label>
      </div>
    </div>

    <div class="field" data-mode-field="single interval">
      <label for="relevant_on">
        <span data-mode-label="single">Data de valabilitate</span>
        <span data-mode-label="interval" hidden>Valabil de la</span>
        <span class="req">*</span>
      </label>
      <input type="date" id="relevant_on" name="relevant_on"
             value="<?= h($data['relevant_on']) ?>">
      <span class="hint" data-mode-label="single">Ziua pentru care anunțul este relevant — nu data postării.</span>
      <span class="hint" data-mode-label="interval" hidden>Prima zi în care anunțul este vizibil.</span>
      <?php if (!empty($errors['relevant_on'])): ?><span class="err-msg"><?= h($errors['relevant_on']) ?></span><?php endif; ?>
    </div>

    <div class="field" data-mode-field="interval">
      <label for="relevant_until">Valabil până la <span class="req">*</span></label>
      <input type="date" id="relevant_until" name="relevant_until"
             value="<?= h($data['relevant_until']) ?>">
      <span class="hint">Ultima zi în care anunțul rămâne afișat.</span>
      <?php if (!empty($errors['relevant_until'])): ?><span class="err-msg"><?= h($errors['relevant_until']) ?></span><?php endif; ?>
    </div>

    <div class="field" data-mode-field="duration">
      <label for="visible_days">Afișează timp de <span class="req">*</span></label>
      <input type="number" id="visible_days" name="visible_days" min="1" max="365"
             value="<?= h($data['visible_days']) ?>">
      <span class="hint">Număr de zile după crearea anunțului. Apoi anunțul dispare automat.</span>
      <?php if (!empty($errors['visible_days'])): ?><span class="err-msg"><?= h($errors['visible_days']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="tag">Etichetă</label>
      <input type="text" id="tag" name="tag" maxlength="40"
             value="<?= h($data['tag']) ?>"
             placeholder="De ex.: Comunitate, Liturgic, Caritate">
      <span class="hint">Textul pilulei afișate deasupra titlului. Opțional.</span>
      <?php if (!empty($errors['tag'])): ?><span class="err-msg"><?= h($errors['tag']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="body">Conținut</label>
      <textarea id="body" name="body" maxlength="5000" rows="8"
                placeholder="Textul anunțului care va apărea sub titlu."><?= h($data['body']) ?></textarea>
      <?php if (!empty($errors['body'])): ?><span class="err-msg"><?= h($errors['body']) ?></span><?php endif; ?>
    </div>

    <div class="field field--check field-full">
      <input type="checkbox" id="is_published" name="is_published" value="1"
             <?= (int)$data['is_published'] === 1 ? 'checked' : '' ?>>
      <label for="is_published">
        Publică anunțul (vizibil public în secțiunea „Anunțuri importante”)
      </label>
    </div>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">

  <div class="form-actions">
    <a href="announcements.php" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      <span>Anulează</span>
    </a>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span><?= $isEdit ? 'Salvează modificările' : 'Creează anunțul' ?></span>
    </button>
  </div>
</form>

<script>
(function () {
  var form = document.querySelector('[data-announcement-form]');
  if (!form) return;

  function applyMode(mode) {
    form.querySelectorAll('[data-mode-field]').forEach(function (el) {
      var modes = el.getAttribute('data-mode-field').split(/\s+/);
      el.hidden = modes.indexOf(mode) === -1;
    });
    form.querySelectorAll('[data-mode-label]').forEach(function (el) {
      el.hidden = el.getAttribute('data-mode-label') !== mode;
    });
  }

  var radios = form.querySelectorAll('input[name="date_mode"]');
  radios.forEach(function (r) {
    r.addEventListener('change', function () { if (r.checked) applyMode(r.value); });
  });
  var checked = form.querySelector('input[name="date_mode"]:checked');
  applyMode(checked ? checked.value : 'single');
})();
</script>

<?php bsv_admin_footer(); ?>
