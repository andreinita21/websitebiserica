<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

$defaults = [
    'title'               => '',
    'description'         => '',
    'event_date'          => '',
    'start_time'          => '',
    'end_time'            => '',
    'location'            => '',
    'location_choice'     => '',
    'category'            => '',
    'recurrence_type'     => '',
    'recurrence_end_date' => '',
    'is_published'        => 1,
    // Inline "new category" + "new location" fields — only used when the
    // user picks the "+ add new" sentinel option in the respective select.
    'new_category_slug'   => '',
    'new_category_label'  => '',
    'new_location_name'   => '',
];

// Default selected category is the first available one for fresh forms.
$allCategories = bsv_categories();
$defaults['category'] = $allCategories ? (array_key_first($allCategories)) : '';
$allLocations = bsv_locations();

$recurrenceLabels = [
    ''        => 'Fără recurență (o singură dată)',
    'weekly'  => 'Săptămânal (în aceeași zi a săptămânii)',
    'monthly' => 'Lunar (în aceeași zi a lunii)',
    'yearly'  => 'Anual (în aceeași dată în fiecare an)',
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
        'title'               => $row['title'],
        'description'         => $row['description'],
        'event_date'          => $row['event_date'],
        'start_time'          => $row['start_time'] ? substr($row['start_time'], 0, 5) : '',
        'end_time'            => $row['end_time']   ? substr($row['end_time'], 0, 5)   : '',
        'location'            => (string)$row['location'],
        'location_choice'     => (string)$row['location'],
        'category'            => $row['category'],
        'recurrence_type'     => $row['recurrence_type'] ?? '',
        'recurrence_end_date' => $row['recurrence_end_date'] ?? '',
        'is_published'        => (int)$row['is_published'],
    ]);
}

// --- Submit ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $data['title']               = trim((string)($_POST['title'] ?? ''));
    $data['description']         = trim((string)($_POST['description'] ?? ''));
    $data['event_date']          = trim((string)($_POST['event_date'] ?? ''));
    $data['start_time']          = trim((string)($_POST['start_time'] ?? ''));
    $data['end_time']            = trim((string)($_POST['end_time'] ?? ''));
    $data['location_choice']     = (string)($_POST['location_choice'] ?? '');
    $data['category']            = (string)($_POST['category'] ?? '');
    $data['recurrence_type']     = trim((string)($_POST['recurrence_type'] ?? ''));
    $data['recurrence_end_date'] = trim((string)($_POST['recurrence_end_date'] ?? ''));
    $data['is_published']        = isset($_POST['is_published']) ? 1 : 0;

    // Inline create fields — only meaningful when the corresponding select is
    // set to the '__new__' sentinel.
    $data['new_category_slug']   = strtolower(trim((string)($_POST['new_category_slug']  ?? '')));
    $data['new_category_label']  = trim((string)($_POST['new_category_label'] ?? ''));
    $data['new_location_name']   = trim((string)($_POST['new_location_name']  ?? ''));

    // Resolve the effective location value from either the select or the
    // "new location" input. The canonical events.location column is always
    // the plain-text string.
    if ($data['location_choice'] === '__new__') {
        $data['location'] = $data['new_location_name'];
    } else {
        $data['location'] = $data['location_choice'];
    }

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
    if ($data['category'] === '__new__') {
        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $data['new_category_slug'])) {
            $errors['new_category_slug'] = 'Slug invalid — doar a–z, 0–9, "-" și "_" (2–40 caractere).';
        }
        if ($data['new_category_label'] === '' || mb_strlen($data['new_category_label']) > 120) {
            $errors['new_category_label'] = 'Numele categoriei este obligatoriu (maxim 120 caractere).';
        }
        if (empty($errors['new_category_slug'])) {
            $chk = bsv_db()->prepare('SELECT id FROM event_categories WHERE slug = :s');
            $chk->execute([':s' => $data['new_category_slug']]);
            if ($chk->fetchColumn()) {
                $errors['new_category_slug'] = 'Acest slug există deja — alegeți altul sau selectați categoria din listă.';
            }
        }
    } elseif (!bsv_valid_category($data['category'])) {
        $errors['category'] = 'Categoria selectată nu este validă.';
    }

    if ($data['location_choice'] === '__new__') {
        if ($data['new_location_name'] === '' || mb_strlen($data['new_location_name']) > 200) {
            $errors['new_location_name'] = 'Numele locației este obligatoriu (maxim 200 caractere).';
        }
    } elseif (mb_strlen($data['location']) > 200) {
        $errors['location'] = 'Locația este prea lungă (maxim 200 de caractere).';
    }
    if (mb_strlen($data['description']) > 5000) {
        $errors['description'] = 'Descrierea este prea lungă (maxim 5000 de caractere).';
    }
    if (!bsv_valid_recurrence($data['recurrence_type'])) {
        $errors['recurrence_type'] = 'Tipul de recurență nu este valid.';
    }
    // End-of-recurrence is only meaningful when a recurrence is chosen. If the
    // user filled it in without picking a recurrence type, silently drop it.
    if ($data['recurrence_type'] === '') {
        $data['recurrence_end_date'] = '';
    } elseif ($data['recurrence_end_date'] !== '') {
        if (!bsv_valid_date($data['recurrence_end_date'])) {
            $errors['recurrence_end_date'] = 'Data de sfârșit a recurenței nu este validă.';
        } elseif (bsv_valid_date($data['event_date']) && $data['recurrence_end_date'] < $data['event_date']) {
            $errors['recurrence_end_date'] = 'Data de sfârșit trebuie să fie după data evenimentului.';
        }
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $recType = $data['recurrence_type'] === '' ? null : $data['recurrence_type'];
        $recEnd  = $data['recurrence_end_date'] === '' ? null : $data['recurrence_end_date'];

        // Persist a new category (if requested) before the event row so the
        // foreign-ish reference is valid by the time the event is saved.
        if ($data['category'] === '__new__') {
            $insCat = bsv_db()->prepare(
                'INSERT INTO event_categories (slug, label, position, created_at, updated_at)
                 VALUES (:slug, :label, 1000, :now, :now)'
            );
            $insCat->execute([
                ':slug'  => $data['new_category_slug'],
                ':label' => $data['new_category_label'],
                ':now'   => $now,
            ]);
            $data['category'] = $data['new_category_slug'];
        }

        // Persist a new location in the suggestion library. If the name is
        // already there (unique constraint), quietly skip.
        if ($data['location_choice'] === '__new__' && $data['new_location_name'] !== '') {
            try {
                $insLoc = bsv_db()->prepare(
                    'INSERT INTO event_locations (name, position, created_at, updated_at)
                     VALUES (:name, 1000, :now, :now)'
                );
                $insLoc->execute([':name' => $data['new_location_name'], ':now' => $now]);
            } catch (Throwable $e) { /* duplicate → ignore */ }
        }
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
                        recurrence_type     = :recurrence_type,
                        recurrence_end_date = :recurrence_end_date,
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
                ':recurrence_type'     => $recType,
                ':recurrence_end_date' => $recEnd,
                ':is_published' => $data['is_published'],
                ':updated_at' => $now,
                ':id'         => $id,
            ]);
            bsv_flash_set('success', 'Evenimentul a fost actualizat.');
        } else {
            $stmt = bsv_db()->prepare(
                'INSERT INTO events (title, description, event_date, start_time, end_time,
                                     location, category, recurrence_type, recurrence_end_date,
                                     is_published, created_at, updated_at)
                 VALUES (:title, :description, :event_date, :start_time, :end_time,
                         :location, :category, :recurrence_type, :recurrence_end_date,
                         :is_published, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':event_date' => $data['event_date'],
                ':start_time' => $startClean,
                ':end_time'   => $endClean,
                ':location'   => $data['location'],
                ':category'   => $data['category'],
                ':recurrence_type'     => $recType,
                ':recurrence_end_date' => $recEnd,
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

// Pre-compute picker pre-selections from the current anchor date so a server
// round-trip (including validation errors) shows the right circle highlighted.
$preDt = $data['event_date'] !== ''
    ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$data['event_date'])
    : null;
$preWeekday     = $preDt ? (int)$preDt->format('w') : (int)date('w');  // 0=Sun..6=Sat
$preDayOfMonth  = $preDt ? (int)$preDt->format('j') : (int)date('j');
$activePicker   = $data['recurrence_type'] === '' ? 'none' : $data['recurrence_type'];

// Romanian weekday labels, ordered Mon..Sun for the picker row.
$weekdayOrder = [1, 2, 3, 4, 5, 6, 0];
$weekdayShort = [1 => 'L', 2 => 'M', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 0 => 'D'];
$weekdayLong  = [
    1 => 'Luni', 2 => 'Marți', 3 => 'Miercuri', 4 => 'Joi',
    5 => 'Vineri', 6 => 'Sâmbătă', 0 => 'Duminică',
];

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
      <label for="recurrence_type">Recurență</label>
      <select id="recurrence_type" name="recurrence_type" data-recurrence-select>
        <?php foreach ($recurrenceLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= (string)$data['recurrence_type'] === (string)$key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['recurrence_type'])): ?><span class="err-msg"><?= h($errors['recurrence_type']) ?></span><?php endif; ?>
    </div>

    <div class="field" data-recurrence-end<?= $data['recurrence_type'] === '' ? ' hidden' : '' ?>>
      <label for="recurrence_end_date">Se repetă până la</label>
      <input type="date" id="recurrence_end_date" name="recurrence_end_date"
             value="<?= h($data['recurrence_end_date']) ?>">
      <span class="hint">Opțional — lăsați gol pentru recurență pe termen nelimitat.</span>
      <?php if (!empty($errors['recurrence_end_date'])): ?><span class="err-msg"><?= h($errors['recurrence_end_date']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full" data-date-field>
      <label data-date-label>Data <span class="req">*</span></label>

      <div class="rec-picker" data-picker="none"<?= $activePicker !== 'none' ? ' hidden' : '' ?>>
        <input type="date" data-picker-date-input value="<?= h($data['event_date']) ?>">
        <span class="hint">Alegeți data evenimentului.</span>
      </div>

      <div class="rec-picker" data-picker="weekly"<?= $activePicker !== 'weekly' ? ' hidden' : '' ?>>
        <div class="rec-weekdays" role="radiogroup" aria-label="Ziua săptămânii">
          <?php foreach ($weekdayOrder as $wd): ?>
            <button type="button"
                    class="rec-weekday<?= $preWeekday === $wd ? ' is-selected' : '' ?>"
                    data-weekday="<?= (int)$wd ?>"
                    role="radio"
                    aria-checked="<?= $preWeekday === $wd ? 'true' : 'false' ?>"
                    aria-label="<?= h($weekdayLong[$wd]) ?>"><?= h($weekdayShort[$wd]) ?></button>
          <?php endforeach; ?>
        </div>
        <span class="hint">Alegeți ziua săptămânii în care evenimentul se repetă.</span>
      </div>

      <div class="rec-picker" data-picker="monthly"<?= $activePicker !== 'monthly' ? ' hidden' : '' ?>>
        <div class="rec-month-grid" role="radiogroup" aria-label="Ziua lunii">
          <?php for ($i = 1; $i <= 31; $i++): ?>
            <button type="button"
                    class="rec-mday<?= $preDayOfMonth === $i ? ' is-selected' : '' ?>"
                    data-mday="<?= $i ?>"
                    role="radio"
                    aria-checked="<?= $preDayOfMonth === $i ? 'true' : 'false' ?>"
                    aria-label="Ziua <?= $i ?>"><?= $i ?></button>
          <?php endfor; ?>
        </div>
        <span class="hint">Alegeți ziua lunii în care evenimentul se repetă.</span>
      </div>

      <div class="rec-picker" data-picker="yearly"<?= $activePicker !== 'yearly' ? ' hidden' : '' ?>>
        <input type="date" data-picker-yearly-input value="<?= h($data['event_date']) ?>">
        <span class="hint">Alegeți luna și ziua în care evenimentul se repetă anual.</span>
      </div>

      <input type="hidden" name="event_date" id="event_date" value="<?= h($data['event_date']) ?>">
      <?php if (!empty($errors['event_date'])): ?><span class="err-msg"><?= h($errors['event_date']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full" data-category-field>
      <label for="category">Categorie <span class="req">*</span></label>
      <select id="category" name="category" data-category-select>
        <?php foreach (bsv_categories() as $slug => $label): ?>
          <option value="<?= h($slug) ?>" <?= $data['category'] === $slug ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
        <option value="__new__" <?= $data['category'] === '__new__' ? 'selected' : '' ?>>+ Adaugă categorie nouă…</option>
      </select>
      <?php if (!empty($errors['category'])): ?><span class="err-msg"><?= h($errors['category']) ?></span><?php endif; ?>

      <div class="inline-new" data-new-category<?= $data['category'] === '__new__' ? '' : ' hidden' ?>>
        <div class="inline-new__grid">
          <div class="field">
            <label for="new_category_label">Nume <span class="req">*</span></label>
            <input type="text" id="new_category_label" name="new_category_label" maxlength="120"
                   value="<?= h($data['new_category_label']) ?>" placeholder="ex.: Școala de duminică">
            <?php if (!empty($errors['new_category_label'])): ?><span class="err-msg"><?= h($errors['new_category_label']) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label for="new_category_slug">Slug <span class="req">*</span></label>
            <input type="text" id="new_category_slug" name="new_category_slug" maxlength="40"
                   pattern="[a-z0-9_-]{2,40}"
                   value="<?= h($data['new_category_slug']) ?>" placeholder="ex.: scoala-duminica">
            <span class="hint">ASCII, folosit intern (a–z, 0–9, "-", "_").</span>
            <?php if (!empty($errors['new_category_slug'])): ?><span class="err-msg"><?= h($errors['new_category_slug']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
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

    <div class="field field-full" data-location-field>
      <label for="location_choice">Locație</label>
      <?php
        $savedLocNames = array_map(static fn($l) => (string)$l['name'], $allLocations);
        $currentLoc    = (string)$data['location_choice'];
        $isCustomLoc   = $currentLoc !== '' && $currentLoc !== '__new__' && !in_array($currentLoc, $savedLocNames, true);
      ?>
      <select id="location_choice" name="location_choice" data-location-select>
        <option value="" <?= $currentLoc === '' ? 'selected' : '' ?>>— Fără locație —</option>
        <?php if ($isCustomLoc): ?>
          <option value="<?= h($currentLoc) ?>" selected><?= h($currentLoc) ?> (salvat pe eveniment)</option>
        <?php endif; ?>
        <?php foreach ($allLocations as $loc): ?>
          <option value="<?= h($loc['name']) ?>" <?= $currentLoc === $loc['name'] ? 'selected' : '' ?>><?= h($loc['name']) ?></option>
        <?php endforeach; ?>
        <option value="__new__" <?= $currentLoc === '__new__' ? 'selected' : '' ?>>+ Adaugă locație nouă…</option>
      </select>
      <?php if (!empty($errors['location'])): ?><span class="err-msg"><?= h($errors['location']) ?></span><?php endif; ?>

      <div class="inline-new" data-new-location<?= $currentLoc === '__new__' ? '' : ' hidden' ?>>
        <div class="field">
          <label for="new_location_name">Nume locație <span class="req">*</span></label>
          <input type="text" id="new_location_name" name="new_location_name" maxlength="200"
                 value="<?= h($data['new_location_name']) ?>"
                 placeholder="ex.: Altarul principal, Sala parohială, Curtea bisericii">
          <span class="hint">Locația va fi salvată și în lista de locații pentru refolosire.</span>
          <?php if (!empty($errors['new_location_name'])): ?><span class="err-msg"><?= h($errors['new_location_name']) ?></span><?php endif; ?>
        </div>
      </div>
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

<script>
  /*
   * Recurrence-aware date picker.
   *
   * The canonical field is the hidden #event_date (always an ISO date). Four
   * UI variants feed into it:
   *   - none:    native date input — user picks any date.
   *   - weekly:  7 weekday circles — hidden becomes the next matching weekday.
   *   - monthly: 31 day-of-month circles — hidden becomes the next matching day.
   *   - yearly:  native date input — year is kept as entered; the server only
   *              cares about month/day for yearly recurrence, but needs a valid
   *              anchor date.
   */
  (function () {
    var form   = document.querySelector('form.admin-card');
    if (!form) return;
    var rec    = form.querySelector('[data-recurrence-select]');
    var hidden = form.querySelector('#event_date');
    if (!rec || !hidden) return;

    var endBox     = form.querySelector('[data-recurrence-end]');
    var pickers    = form.querySelectorAll('[data-picker]');
    var noneInput  = form.querySelector('[data-picker-date-input]');
    var yearInput  = form.querySelector('[data-picker-yearly-input]');
    var weekBtns   = form.querySelectorAll('.rec-weekday');
    var monthBtns  = form.querySelectorAll('.rec-mday');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parseIso(s) {
      var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
      return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }

    /** Today or the next day whose weekday matches `dow` (0=Sun..6=Sat). */
    function nextWeekdayDate(dow) {
      var today = new Date(); today.setHours(0, 0, 0, 0);
      for (var i = 0; i < 7; i++) {
        var d = new Date(today); d.setDate(today.getDate() + i);
        if (d.getDay() === dow) return d;
      }
      return today;
    }

    /** Today or the next day whose day-of-month matches `dom` (1..31), skipping
     * months that don't contain that day (e.g. day 31 in February). */
    function nextDayOfMonthDate(dom) {
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var y = today.getFullYear();
      var m = today.getMonth();
      for (var i = 0; i < 24; i++) {
        var d = new Date(y, m + i, dom);
        if (d.getDate() === dom && d >= today) return d;
      }
      return today;
    }

    function showPicker(key) {
      pickers.forEach(function (p) {
        if (p.getAttribute('data-picker') === key) p.removeAttribute('hidden');
        else p.setAttribute('hidden', '');
      });
      var label = form.querySelector('[data-date-label]');
      if (label) {
        var caption = 'Data';
        if (key === 'weekly')      caption = 'Ziua săptămânii';
        else if (key === 'monthly') caption = 'Ziua lunii';
        else if (key === 'yearly')  caption = 'Luna și ziua';
        label.innerHTML = caption + ' <span class="req">*</span>';
      }
    }

    function selectWeekday(dow) {
      weekBtns.forEach(function (b) {
        var on = +b.getAttribute('data-weekday') === dow;
        b.classList.toggle('is-selected', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
    }

    function selectMday(dom) {
      monthBtns.forEach(function (b) {
        var on = +b.getAttribute('data-mday') === dom;
        b.classList.toggle('is-selected', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
    }

    function syncHiddenFromPicker() {
      var v = rec.value || 'none';
      if (v === 'none') {
        if (noneInput && noneInput.value) hidden.value = noneInput.value;
      } else if (v === 'weekly') {
        var wsel = form.querySelector('.rec-weekday.is-selected');
        if (wsel) hidden.value = iso(nextWeekdayDate(+wsel.getAttribute('data-weekday')));
      } else if (v === 'monthly') {
        var msel = form.querySelector('.rec-mday.is-selected');
        if (msel) hidden.value = iso(nextDayOfMonthDate(+msel.getAttribute('data-mday')));
      } else if (v === 'yearly') {
        if (yearInput && yearInput.value) hidden.value = yearInput.value;
      }
    }

    function syncEndBox() {
      if (!endBox) return;
      if (rec.value === '') {
        endBox.setAttribute('hidden', '');
        var inp = endBox.querySelector('input');
        if (inp) inp.value = '';
      } else {
        endBox.removeAttribute('hidden');
      }
    }

    // --- initial state: use the currently-stored anchor for visual selection ---
    var seed = parseIso(hidden.value) || new Date();
    if (noneInput && !noneInput.value) noneInput.value = iso(seed);
    if (yearInput && !yearInput.value) yearInput.value = iso(seed);
    selectWeekday(seed.getDay());
    selectMday(seed.getDate());

    showPicker(rec.value || 'none');
    syncEndBox();

    // --- wire changes ---
    rec.addEventListener('change', function () {
      var key = rec.value || 'none';
      showPicker(key);
      syncEndBox();
      syncHiddenFromPicker();
    });

    if (noneInput) {
      noneInput.addEventListener('change', function () {
        if (rec.value === '') hidden.value = noneInput.value;
      });
    }
    if (yearInput) {
      yearInput.addEventListener('change', function () {
        if (rec.value === 'yearly') hidden.value = yearInput.value;
      });
    }
    weekBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        var dow = +b.getAttribute('data-weekday');
        selectWeekday(dow);
        if (rec.value === 'weekly') hidden.value = iso(nextWeekdayDate(dow));
      });
    });
    monthBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        var dom = +b.getAttribute('data-mday');
        selectMday(dom);
        if (rec.value === 'monthly') hidden.value = iso(nextDayOfMonthDate(dom));
      });
    });

    // --- Inline "new category / new location" disclosure -------------------
    // The selects include an '__new__' sentinel option — when it's chosen,
    // reveal the corresponding inline block of extra fields.
    function toggleInlineNew(selectEl, box) {
      if (!selectEl || !box) return;
      function sync() {
        if (selectEl.value === '__new__') box.removeAttribute('hidden');
        else box.setAttribute('hidden', '');
      }
      selectEl.addEventListener('change', sync);
      sync();
    }
    toggleInlineNew(
      form.querySelector('[data-category-select]'),
      form.querySelector('[data-new-category]')
    );
    toggleInlineNew(
      form.querySelector('[data-location-select]'),
      form.querySelector('[data-new-location]')
    );
  })();
</script>

<?php bsv_admin_footer(); ?>
