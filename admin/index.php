<?php
/**
 * Events admin — three sub-views driven by ?view=list|categories|locations.
 *
 *   list        (default) — filterable table of events (create/edit/delete)
 *   categories            — inline manager for event_categories (modal editor)
 *   locations             — inline manager for event_locations   (modal editor)
 *
 * The manager sub-views live here so the nav stays flat: categories and
 * locations used to have their own top-level admin pages, but they only make
 * sense scoped to "Evenimente".
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

$view = $_GET['view'] ?? 'list';
$allowedViews = ['list', 'categories', 'locations'];
if (!in_array($view, $allowedViews, true)) $view = 'list';

// --- URL helper that preserves current filters but lets callers override ----
function bsv_events_url(array $current, array $overrides): string
{
    $params = $current;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

$currentQuery = ['view' => $view];
foreach (['filter', 'q', 'cat', 'rec'] as $k) {
    if (!empty($_GET[$k])) $currentQuery[$k] = (string)$_GET[$k];
}

// ---------------------------------------------------------------------------
// POST handlers — namespaced by action so all three sub-views can share this file.
// ---------------------------------------------------------------------------

// Validation state kept across renders so the modal can re-open with errors.
$catErrors    = [];
$catForm      = ['id' => 0, 'slug' => '', 'label' => ''];
$catModalOpen = false;

$locErrors    = [];
$locForm      = ['id' => 0, 'name' => ''];
$locModalOpen = false;

// Small JSON responder for the AJAX reorder endpoints.
function bsv_ajax_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Event delete (unchanged from previous behaviour) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            bsv_db()->prepare('DELETE FROM events WHERE id = :id')->execute([':id' => $id]);
            bsv_flash_set('success', 'Evenimentul a fost șters.');
        }
    }
    header('Location: ' . bsv_events_url($currentQuery, []));
    exit;
}

// --- Category create / update ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['cat_create', 'cat_update'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $catErrors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }
    $catForm = [
        'id'    => (int)($_POST['id'] ?? 0),
        'slug'  => strtolower(trim((string)($_POST['slug'] ?? ''))),
        'label' => trim((string)($_POST['label'] ?? '')),
    ];

    if (!preg_match('/^[a-z0-9_-]{2,40}$/', $catForm['slug'])) {
        $catErrors['slug'] = 'Slug invalid — doar a–z, 0–9, "-" și "_" (2–40 caractere).';
    }
    if ($catForm['label'] === '' || mb_strlen($catForm['label']) > 120) {
        $catErrors['label'] = 'Numele este obligatoriu (maxim 120 caractere).';
    }
    if (empty($catErrors['slug'])) {
        $uniq = bsv_db()->prepare('SELECT id FROM event_categories WHERE slug = :s AND id != :id');
        $uniq->execute([':s' => $catForm['slug'], ':id' => $action === 'cat_update' ? $catForm['id'] : 0]);
        if ($uniq->fetchColumn()) {
            $catErrors['slug'] = 'Acest slug este deja folosit de altă categorie.';
        }
    }

    if (!$catErrors) {
        $now = date('Y-m-d H:i:s');
        if ($action === 'cat_update' && $catForm['id'] > 0) {
            $oldStmt = bsv_db()->prepare('SELECT slug FROM event_categories WHERE id = :id');
            $oldStmt->execute([':id' => $catForm['id']]);
            $oldSlug = (string)$oldStmt->fetchColumn();

            // Position stays whatever the row already has — reorder happens via the drag UI.
            $upd = bsv_db()->prepare(
                'UPDATE event_categories
                    SET slug = :slug, label = :label, updated_at = :now
                  WHERE id = :id'
            );
            $upd->execute([
                ':slug' => $catForm['slug'], ':label' => $catForm['label'],
                ':now' => $now, ':id' => $catForm['id'],
            ]);
            if ($oldSlug !== '' && $oldSlug !== $catForm['slug']) {
                $re = bsv_db()->prepare('UPDATE events SET category = :new WHERE category = :old');
                $re->execute([':new' => $catForm['slug'], ':old' => $oldSlug]);
            }
            bsv_flash_set('success', 'Categoria a fost actualizată.');
        } else {
            // New rows go to the end of the list. The user can drag them up later.
            $nextPos = (int)bsv_db()->query('SELECT COALESCE(MAX(position), 0) + 10 FROM event_categories')->fetchColumn();
            $ins = bsv_db()->prepare(
                'INSERT INTO event_categories (slug, label, position, created_at, updated_at)
                 VALUES (:slug, :label, :pos, :now, :now)'
            );
            $ins->execute([
                ':slug' => $catForm['slug'], ':label' => $catForm['label'],
                ':pos' => $nextPos, ':now' => $now,
            ]);
            bsv_flash_set('success', 'Categoria a fost creată.');
        }
        header('Location: index.php?view=categories');
        exit;
    }

    // Validation error → re-render categories view with modal open.
    $view = 'categories';
    $catModalOpen = true;
}

// --- Category delete -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cat_delete') {
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
                bsv_db()->prepare('DELETE FROM event_categories WHERE id = :id')->execute([':id' => $id]);
                bsv_flash_set('success', 'Categoria a fost ștearsă.');
            }
        }
    }
    header('Location: index.php?view=categories');
    exit;
}

// --- Location create / update ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['loc_create', 'loc_update'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $locErrors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }
    $locForm = [
        'id'   => (int)($_POST['id'] ?? 0),
        'name' => trim((string)($_POST['name'] ?? '')),
    ];

    if ($locForm['name'] === '' || mb_strlen($locForm['name']) > 200) {
        $locErrors['name'] = 'Numele este obligatoriu (maxim 200 caractere).';
    }
    if (empty($locErrors['name'])) {
        $uniq = bsv_db()->prepare('SELECT id FROM event_locations WHERE name = :n AND id != :id');
        $uniq->execute([':n' => $locForm['name'], ':id' => $action === 'loc_update' ? $locForm['id'] : 0]);
        if ($uniq->fetchColumn()) {
            $locErrors['name'] = 'O locație cu acest nume există deja.';
        }
    }

    if (!$locErrors) {
        $now = date('Y-m-d H:i:s');
        if ($action === 'loc_update' && $locForm['id'] > 0) {
            $oldStmt = bsv_db()->prepare('SELECT name FROM event_locations WHERE id = :id');
            $oldStmt->execute([':id' => $locForm['id']]);
            $oldName = (string)$oldStmt->fetchColumn();

            $upd = bsv_db()->prepare(
                'UPDATE event_locations
                    SET name = :name, updated_at = :now
                  WHERE id = :id'
            );
            $upd->execute([
                ':name' => $locForm['name'], ':now' => $now, ':id' => $locForm['id'],
            ]);
            if ($oldName !== '' && $oldName !== $locForm['name']) {
                $re = bsv_db()->prepare('UPDATE events SET location = :new WHERE location = :old');
                $re->execute([':new' => $locForm['name'], ':old' => $oldName]);
            }
            bsv_flash_set('success', 'Locația a fost actualizată.');
        } else {
            $nextPos = (int)bsv_db()->query('SELECT COALESCE(MAX(position), 0) + 10 FROM event_locations')->fetchColumn();
            $ins = bsv_db()->prepare(
                'INSERT INTO event_locations (name, position, created_at, updated_at)
                 VALUES (:name, :pos, :now, :now)'
            );
            $ins->execute([
                ':name' => $locForm['name'], ':pos' => $nextPos, ':now' => $now,
            ]);
            bsv_flash_set('success', 'Locația a fost adăugată în listă.');
        }
        header('Location: index.php?view=locations');
        exit;
    }

    $view = 'locations';
    $locModalOpen = true;
}

// --- Reorder (AJAX) --------------------------------------------------------
// Body: action=cat_reorder|loc_reorder, order=1,3,2,5, _token=...
// Response: {"ok": true} on success, {"ok": false, "error": "..."} on failure.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['cat_reorder', 'loc_reorder'], true)) {
    $action = $_POST['action'];
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_ajax_json(['ok' => false, 'error' => 'Sesiunea a expirat'], 403);
    }
    $raw = (string)($_POST['order'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
    if (!$ids) bsv_ajax_json(['ok' => false, 'error' => 'Listă de ordine goală'], 400);

    $table = $action === 'cat_reorder' ? 'event_categories' : 'event_locations';
    $pdo = bsv_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE {$table} SET position = :pos, updated_at = :now WHERE id = :id");
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

// --- Location delete -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'loc_delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            bsv_db()->prepare('DELETE FROM event_locations WHERE id = :id')->execute([':id' => $id]);
            bsv_flash_set('success', 'Locația a fost ștearsă din listă.');
        }
    }
    header('Location: index.php?view=locations');
    exit;
}

// ---------------------------------------------------------------------------
// Data loading per view
// ---------------------------------------------------------------------------
$events = [];
$counts = ['upcoming' => 0, 'past' => 0, 'drafts' => 0, 'total' => 0];
$filter = $_GET['filter'] ?? 'upcoming';
$allowedFilters = ['upcoming', 'past', 'drafts', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'upcoming';
$q = trim((string)($_GET['q'] ?? ''));
$catFilter = trim((string)($_GET['cat'] ?? ''));
$recFilter = trim((string)($_GET['rec'] ?? ''));
$allowedRec = ['weekly', 'monthly', 'yearly', 'none'];
if ($recFilter !== '' && !in_array($recFilter, $allowedRec, true)) $recFilter = '';
if ($catFilter !== '' && !bsv_valid_category($catFilter)) $catFilter = '';
$hasSecondary = $q !== '' || $catFilter !== '' || $recFilter !== '';

if ($view === 'list') {
    $sql = 'SELECT id, title, description, event_date, start_time, end_time, location,
                   category, recurrence_type, recurrence_end_date, is_published
            FROM events';
    $where = [];
    $params = [];

    $activeClause = "(
        (recurrence_type IS NULL AND event_date >= date('now','localtime'))
        OR
        (recurrence_type IS NOT NULL
         AND (recurrence_end_date IS NULL OR recurrence_end_date >= date('now','localtime')))
    )";

    switch ($filter) {
        case 'upcoming': $where[] = 'is_published = 1'; $where[] = $activeClause; break;
        case 'past':     $where[] = 'is_published = 1'; $where[] = 'NOT ' . $activeClause; break;
        case 'drafts':   $where[] = 'is_published = 0'; break;
    }
    if ($q !== '') {
        $where[] = '(title LIKE :q OR description LIKE :q OR location LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($catFilter !== '') {
        $where[] = 'category = :cat';
        $params[':cat'] = $catFilter;
    }
    if ($recFilter !== '') {
        if ($recFilter === 'none') {
            $where[] = 'recurrence_type IS NULL';
        } else {
            $where[] = 'recurrence_type = :rec';
            $params[':rec'] = $recFilter;
        }
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY event_date ' . ($filter === 'past' ? 'DESC' : 'ASC') . ', start_time ASC';

    $stmt = bsv_db()->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    $countsRow = bsv_db()->query(
        "SELECT
            SUM(CASE WHEN is_published = 1 AND (
                    (recurrence_type IS NULL AND event_date >= date('now','localtime'))
                    OR (recurrence_type IS NOT NULL
                        AND (recurrence_end_date IS NULL OR recurrence_end_date >= date('now','localtime')))
                ) THEN 1 ELSE 0 END) AS upcoming,
            SUM(CASE WHEN is_published = 1 AND NOT (
                    (recurrence_type IS NULL AND event_date >= date('now','localtime'))
                    OR (recurrence_type IS NOT NULL
                        AND (recurrence_end_date IS NULL OR recurrence_end_date >= date('now','localtime')))
                ) THEN 1 ELSE 0 END) AS past,
            SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) AS drafts,
            COUNT(*) AS total
         FROM events"
    )->fetch();
    if ($countsRow) $counts = array_merge($counts, $countsRow);
}

$cats = [];
if ($view === 'categories') {
    $cats = bsv_db()->query(
        "SELECT c.id, c.slug, c.label, c.position,
                (SELECT COUNT(*) FROM events WHERE events.category = c.slug) AS usage_count
           FROM event_categories c
       ORDER BY c.position ASC, c.id ASC"
    )->fetchAll();
}

$locs = [];
if ($view === 'locations') {
    $locs = bsv_db()->query(
        "SELECT l.id, l.name, l.position,
                (SELECT COUNT(*) FROM events WHERE events.location = l.name) AS usage_count
           FROM event_locations l
       ORDER BY l.position ASC, l.name ASC"
    )->fetchAll();
}

$csrf = bsv_csrf_token();

$recLabels = [
    'none'    => 'Fără recurență',
    'weekly'  => 'Săptămânal',
    'monthly' => 'Lunar',
    'yearly'  => 'Anual',
];

// ---------------------------------------------------------------------------
// Header actions change per view so the primary CTA matches context.
// ---------------------------------------------------------------------------
$actions = '';
if ($view === 'list') {
    $actions = '
      <a href="event.php" class="adm-btn adm-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        <span>Adaugă eveniment</span>
      </a>';
} elseif ($view === 'categories') {
    $actions = '
      <button type="button" class="adm-btn adm-btn--ghost" data-enter-reorder data-hide-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">reorder</span>
        <span>Ordonează</span>
      </button>
      <button type="button" class="adm-btn adm-btn--primary" data-open-modal="cat-modal" data-modal-mode="create" data-hide-in-reorder>
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
} elseif ($view === 'locations') {
    $actions = '
      <button type="button" class="adm-btn adm-btn--ghost" data-enter-reorder data-hide-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">reorder</span>
        <span>Ordonează</span>
      </button>
      <button type="button" class="adm-btn adm-btn--primary" data-open-modal="loc-modal" data-modal-mode="create" data-hide-in-reorder>
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
        <span>Adaugă locație</span>
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

$headerTitle = $view === 'categories' ? 'Categorii evenimente'
             : ($view === 'locations' ? 'Locații evenimente'
             : 'Evenimente');
$headerSub   = $view === 'categories' ? 'Gestionați lista de categorii pentru evenimente (nume, culoare). Folosiți „Ordonează” pentru a le rearanja prin drag-and-drop.'
             : ($view === 'locations'  ? 'Gestionați locațiile sugerate pentru evenimente. Folosiți „Ordonează” pentru a le rearanja.'
             : 'Gestionați programul parohiei — adăugați, modificați sau eliminați evenimente.');

bsv_admin_header($headerTitle, $headerSub, $actions, 'events');
?>

<nav class="admin-subnav" aria-label="Sub-secțiuni Evenimente">
  <a href="index.php?view=list" class="admin-subnav__link <?= $view === 'list' ? 'is-active' : '' ?>">
    <span class="material-symbols-outlined" aria-hidden="true">event</span>
    <span>Evenimente</span>
  </a>
  <a href="index.php?view=categories" class="admin-subnav__link <?= $view === 'categories' ? 'is-active' : '' ?>">
    <span class="material-symbols-outlined" aria-hidden="true">sell</span>
    <span>Categorii</span>
  </a>
  <a href="index.php?view=locations" class="admin-subnav__link <?= $view === 'locations' ? 'is-active' : '' ?>">
    <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
    <span>Locații</span>
  </a>
</nav>

<?php if ($view === 'list'): ?>
<div class="toolbar">
  <div class="toolbar__filters" role="tablist" aria-label="Filtru evenimente">
    <a href="<?= h(bsv_events_url($currentQuery, ['filter' => 'upcoming'])) ?>" class="toolbar__filter <?= $filter === 'upcoming' ? 'is-active' : '' ?>">
      Viitoare <span>(<?= (int)($counts['upcoming'] ?? 0) ?>)</span>
    </a>
    <a href="<?= h(bsv_events_url($currentQuery, ['filter' => 'past'])) ?>" class="toolbar__filter <?= $filter === 'past' ? 'is-active' : '' ?>">
      Trecute <span>(<?= (int)($counts['past'] ?? 0) ?>)</span>
    </a>
    <a href="<?= h(bsv_events_url($currentQuery, ['filter' => 'drafts'])) ?>" class="toolbar__filter <?= $filter === 'drafts' ? 'is-active' : '' ?>">
      Ciorne <span>(<?= (int)($counts['drafts'] ?? 0) ?>)</span>
    </a>
    <a href="<?= h(bsv_events_url($currentQuery, ['filter' => 'all'])) ?>" class="toolbar__filter <?= $filter === 'all' ? 'is-active' : '' ?>">
      Toate <span>(<?= (int)($counts['total'] ?? 0) ?>)</span>
    </a>
  </div>
</div>

<form method="get" class="events-search" action="index.php" role="search">
  <input type="hidden" name="view" value="list">
  <input type="hidden" name="filter" value="<?= h($filter) ?>">
  <div class="events-search__group events-search__group--q">
    <span class="material-symbols-outlined events-search__icon" aria-hidden="true">search</span>
    <input type="search" name="q" value="<?= h($q) ?>"
           placeholder="Caută după titlu, descriere sau locație…"
           aria-label="Caută evenimente">
  </div>
  <div class="events-search__group">
    <label for="filter-cat" class="events-search__label">Categorie</label>
    <select id="filter-cat" name="cat">
      <option value="">Toate</option>
      <?php foreach (bsv_categories() as $slug => $label): ?>
        <option value="<?= h($slug) ?>" <?= $catFilter === $slug ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="events-search__group">
    <label for="filter-rec" class="events-search__label">Recurență</label>
    <select id="filter-rec" name="rec">
      <option value="">Toate</option>
      <?php foreach ($recLabels as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $recFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="events-search__actions">
    <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">
      <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
      <span>Aplică</span>
    </button>
    <?php if ($hasSecondary): ?>
      <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= h(bsv_events_url(['view' => 'list', 'filter' => $filter], [])) ?>">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
        <span>Resetează</span>
      </a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($events)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">event_note</span>
    <h3>Niciun eveniment în această categorie</h3>
    <p>Începeți prin a adăuga primul eveniment în calendar.</p>
    <a href="event.php" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">add</span>
      <span>Adaugă eveniment</span>
    </a>
  </div>
<?php else: ?>
  <table class="events-table">
    <thead>
      <tr>
        <th class="col-date">Data</th>
        <th>Eveniment</th>
        <th class="col-cat">Categorie</th>
        <th class="col-status">Stare</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e):
        $catKey   = (string)$e['category'];
        $catLabel = bsv_category_label($catKey);
        $time = '';
        if ($e['start_time']) {
            $time = substr($e['start_time'], 0, 5);
            if ($e['end_time']) $time .= ' – ' . substr($e['end_time'], 0, 5);
        } else {
            $time = 'Toată ziua';
        }
        $recType = (string)($e['recurrence_type'] ?? '');
        $recRowLabels = [
            'weekly'  => 'Săptămânal',
            'monthly' => 'Lunar',
            'yearly'  => 'Anual',
        ];
        $recLabel = $recRowLabels[$recType] ?? '';
      ?>
        <tr>
          <td class="col-date">
            <div class="date-cell">
              <strong><?= h(bsv_format_date_ro($e['event_date'])) ?></strong>
              <span><?= h($time) ?></span>
            </div>
          </td>
          <td>
            <div class="title-cell">
              <strong><?= h($e['title']) ?></strong>
              <?php if ($recLabel !== ''): ?>
                <span style="display: inline-flex; align-items: center; gap: 4px; color: var(--c-gold-deep); font-weight: 600;">
                  <span class="material-symbols-outlined" style="font-size: 1em; vertical-align: -2px;" aria-hidden="true">autorenew</span>
                  <?= h($recLabel) ?><?php if (!empty($e['recurrence_end_date'])): ?> · până la <?= h(bsv_format_date_ro($e['recurrence_end_date'])) ?><?php endif; ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($e['location'])): ?>
                <span><span class="material-symbols-outlined" style="font-size: 1em; vertical-align: -2px;" aria-hidden="true">location_on</span> <?= h($e['location']) ?></span>
              <?php elseif (!empty($e['description'])): ?>
                <span><?= h($e['description']) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td class="col-cat">
            <span class="pill-cat" data-cat="<?= h($catKey) ?>"><?= h($catLabel) ?></span>
          </td>
          <td class="col-status">
            <?php if ((int)$e['is_published'] === 1): ?>
              <span class="pill pill--pub">Publicat</span>
            <?php else: ?>
              <span class="pill pill--draft">Ciornă</span>
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <a class="adm-btn adm-btn--ghost adm-btn--sm" href="event.php?id=<?= (int)$e['id'] ?>">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </a>
            <form method="post" class="inline-form" action="<?= h(bsv_events_url($currentQuery, [])) ?>"
                  onsubmit="return confirm('Sigur doriți să ștergeți evenimentul „<?= h(addslashes($e['title'])) ?>”?');">
              <input type="hidden" name="action" value="delete_event">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">
              <button class="adm-btn adm-btn--danger adm-btn--sm" type="submit">
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

<?php elseif ($view === 'categories'): ?>

<?php if (!empty($catErrors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($catErrors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<?php if (empty($cats)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">sell</span>
    <h3>Nicio categorie</h3>
    <p>Folosiți butonul „Adaugă categorie” pentru a crea prima categorie.</p>
    <button type="button" class="adm-btn adm-btn--primary" data-open-modal="cat-modal" data-modal-mode="create">
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
        <th>În câte evenimente</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($cats as $c): $usage = (int)$c['usage_count']; ?>
        <tr data-sortable-id="<?= (int)$c['id'] ?>">
          <td class="col-drag">
            <span class="drag-handle" data-drag-handle aria-label="Trage pentru a reordona" title="Trage pentru a reordona">
              <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
            </span>
          </td>
          <td>
            <span class="pill-cat" data-cat="<?= h($c['slug']) ?>"><?= h($c['label']) ?></span>
          </td>
          <td><?= $usage ?> <?= $usage === 1 ? 'eveniment' : 'evenimente' ?></td>
          <td class="col-actions">
            <button type="button"
                    class="adm-btn adm-btn--ghost adm-btn--sm"
                    data-open-modal="cat-modal"
                    data-modal-mode="edit"
                    data-id="<?= (int)$c['id'] ?>"
                    data-slug="<?= h($c['slug']) ?>"
                    data-label="<?= h($c['label']) ?>">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </button>
            <form method="post" class="inline-form" action="index.php?view=categories"
                  onsubmit="return confirm('Sigur doriți să ștergeți categoria „<?= h(addslashes($c['label'])) ?>”?');">
              <input type="hidden" name="action" value="cat_delete">
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

<?php elseif ($view === 'locations'): ?>

<?php if (!empty($locErrors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($locErrors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<?php if (empty($locs)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">location_on</span>
    <h3>Nicio locație salvată</h3>
    <p>Adăugați locațiile pe care le folosiți des pentru a le selecta rapid la crearea evenimentelor.</p>
    <button type="button" class="adm-btn adm-btn--primary" data-open-modal="loc-modal" data-modal-mode="create">
      <span class="material-symbols-outlined" aria-hidden="true">add</span>
      <span>Adaugă locație</span>
    </button>
  </div>
<?php else: ?>
  <div class="reorder-banner">
    <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
    <span><strong>Mod ordonare:</strong> Trageți rândurile pentru a schimba ordinea. Apăsați „Salvează ordinea” când ați terminat.</span>
  </div>
  <table class="events-table" data-sortable="loc_reorder">
    <thead>
      <tr>
        <th class="col-drag" aria-hidden="true"></th>
        <th>Nume</th>
        <th>În câte evenimente</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($locs as $l): $usage = (int)$l['usage_count']; ?>
        <tr data-sortable-id="<?= (int)$l['id'] ?>">
          <td class="col-drag">
            <span class="drag-handle" data-drag-handle aria-label="Trage pentru a reordona" title="Trage pentru a reordona">
              <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
            </span>
          </td>
          <td><strong><?= h($l['name']) ?></strong></td>
          <td><?= $usage ?> <?= $usage === 1 ? 'eveniment' : 'evenimente' ?></td>
          <td class="col-actions">
            <button type="button"
                    class="adm-btn adm-btn--ghost adm-btn--sm"
                    data-open-modal="loc-modal"
                    data-modal-mode="edit"
                    data-id="<?= (int)$l['id'] ?>"
                    data-name="<?= h($l['name']) ?>">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </button>
            <form method="post" class="inline-form" action="index.php?view=locations"
                  onsubmit="return confirm('Ștergeți locația „<?= h(addslashes($l['name'])) ?>” din listă?');">
              <input type="hidden" name="action" value="loc_delete">
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

<?php endif; /* /view === '…' */ ?>


<!-- ====================================================================== -->
<!-- Category modal (Nume + Slug) — order is managed inline                  -->
<!-- ====================================================================== -->
<dialog class="modal" id="cat-modal" data-modal<?= $catModalOpen ? ' data-autoopen' : '' ?> aria-labelledby="cat-modal-title">
  <form method="post" action="index.php?view=categories" class="modal__dialog" novalidate data-modal-form>
    <header class="modal__head">
      <h2 id="cat-modal-title" data-modal-title>Editează categoria</h2>
      <button type="button" class="modal__close" data-close-modal aria-label="Închide">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>

    <div class="modal__body">
      <div class="form-grid form-grid--modal">
        <div class="field field-full">
          <label for="cat-label">Nume <span class="req">*</span></label>
          <input type="text" id="cat-label" name="label" maxlength="120" required
                 value="<?= h($catForm['label']) ?>"
                 placeholder="ex.: Sfânta Liturghie">
          <?php if (!empty($catErrors['label'])): ?><span class="err-msg"><?= h($catErrors['label']) ?></span><?php endif; ?>
        </div>

        <div class="field field-full">
          <label for="cat-slug">Slug <span class="req">*</span></label>
          <input type="text" id="cat-slug" name="slug" maxlength="40" required
                 value="<?= h($catForm['slug']) ?>" pattern="[a-z0-9_-]{2,40}"
                 placeholder="ex.: liturghie">
          <span class="hint">Identificator intern — a–z, 0–9, „-”, „_” (2–40 caractere).</span>
          <?php if (!empty($catErrors['slug'])): ?><span class="err-msg"><?= h($catErrors['slug']) ?></span><?php endif; ?>
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

<!-- ====================================================================== -->
<!-- Location modal (Nume only) — order is managed inline                    -->
<!-- ====================================================================== -->
<dialog class="modal" id="loc-modal" data-modal<?= $locModalOpen ? ' data-autoopen' : '' ?> aria-labelledby="loc-modal-title">
  <form method="post" action="index.php?view=locations" class="modal__dialog" novalidate data-modal-form>
    <header class="modal__head">
      <h2 id="loc-modal-title" data-modal-title>Editează locația</h2>
      <button type="button" class="modal__close" data-close-modal aria-label="Închide">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>

    <div class="modal__body">
      <div class="form-grid form-grid--modal">
        <div class="field field-full">
          <label for="loc-name">Nume <span class="req">*</span></label>
          <input type="text" id="loc-name" name="name" maxlength="200" required
                 value="<?= h($locForm['name']) ?>"
                 placeholder="ex.: Altarul principal, Sala parohială, Curtea bisericii">
          <?php if (!empty($locErrors['name'])): ?><span class="err-msg"><?= h($locErrors['name']) ?></span><?php endif; ?>
        </div>
      </div>
    </div>

    <footer class="modal__foot">
      <input type="hidden" name="_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="loc_create" data-modal-action>
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
/*
 * Lightweight modal controller for the categories/locations editors.
 *
 *   <button data-open-modal="cat-modal" data-modal-mode="edit"
 *           data-id=".." data-label=".." data-slug="..">
 *
 * When clicked, populates the matching <dialog>'s inputs (fields named in
 * data-attrs map 1:1 to [name=...] inputs in the modal) and opens it.
 * If the <dialog> is rendered with data-autoopen (server validation error),
 * we restore it on page load so the user sees their unsaved changes.
 */
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

  // Openers — any element with data-open-modal="<id>".
  document.querySelectorAll('[data-open-modal]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      var id = trigger.getAttribute('data-open-modal');
      var modal = document.getElementById(id);
      if (!modal) return;

      var mode = trigger.getAttribute('data-modal-mode') || 'create';
      var form = modal.querySelector('[data-modal-form]');
      if (!form) return;

      // Update title + action hidden + submit label.
      var title  = modal.querySelector('[data-modal-title]');
      var action = form.querySelector('[data-modal-action]');
      var idInp  = form.querySelector('[data-modal-id]');
      var submit = form.querySelector('[data-modal-submit]');
      var prefix = id === 'cat-modal' ? 'cat' : (id === 'loc-modal' ? 'loc' : null);

      if (mode === 'edit') {
        if (title)  title.textContent  = id === 'cat-modal' ? 'Editează categoria' : 'Editează locația';
        if (submit) submit.textContent = 'Salvează modificările';
        if (action && prefix) action.value = prefix + '_update';
        if (idInp) idInp.value = trigger.getAttribute('data-id') || '0';
      } else {
        if (title)  title.textContent  = id === 'cat-modal' ? 'Adaugă categorie' : 'Adaugă locație';
        if (submit) submit.textContent = id === 'cat-modal' ? 'Adaugă categoria' : 'Adaugă locația';
        if (action && prefix) action.value = prefix + '_create';
        if (idInp) idInp.value = '0';
      }

      // Copy all other data-<field> attrs onto matching [name=<field>] inputs.
      Array.prototype.forEach.call(trigger.attributes, function (attr) {
        if (!attr.name.startsWith('data-')) return;
        var key = attr.name.slice(5);
        if (['open-modal', 'modal-mode', 'id'].indexOf(key) !== -1) return;
        var input = form.querySelector('[name="' + key + '"]');
        if (input) input.value = attr.value;
      });

      // When creating, reset all fields to their defaults (blank inputs).
      if (mode === 'create') {
        form.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (inp) {
          if (inp.name === 'position') inp.value = '0';
          else inp.value = '';
        });
      }

      // Clear any stale error messages from a previous open.
      form.querySelectorAll('.err-msg').forEach(function (el) { el.remove(); });

      openModal(modal);
    });
  });

  // Close buttons inside each modal.
  modals.forEach(function (modal) {
    modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () { closeModal(modal); });
    });
    // Click on the backdrop (native <dialog>::backdrop area) closes the modal.
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal(modal);
    });
  });

  // If the server sent us back with validation errors, reopen the right modal.
  document.querySelectorAll('[data-autoopen]').forEach(function (modal) {
    openModal(modal);
  });
})();
</script>


<?php bsv_admin_footer(); ?>
