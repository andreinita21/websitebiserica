<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

// Helper: build a link that preserves the current filter state but overrides
// one or more params. Pass `null` as a value to remove a param.
function bsv_events_url(array $current, array $overrides): string {
    $params = $current;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

$currentQuery = [];
foreach (['filter', 'q', 'cat', 'rec'] as $k) {
    if (!empty($_GET[$k])) $currentQuery[$k] = (string)$_GET[$k];
}

// --- Delete handler (POST only, CSRF-protected) -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = bsv_db()->prepare('DELETE FROM events WHERE id = :id');
            $stmt->execute([':id' => $id]);
            bsv_flash_set('success', 'Evenimentul a fost șters.');
        }
    }
    header('Location: ' . bsv_events_url($currentQuery, []));
    exit;
}

// --- Filter + fetch ---------------------------------------------------------
$filter = $_GET['filter'] ?? 'upcoming';
$allowedFilters = ['upcoming', 'past', 'drafts', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'upcoming';

$q         = trim((string)($_GET['q']   ?? ''));
$catFilter = trim((string)($_GET['cat'] ?? ''));
$recFilter = trim((string)($_GET['rec'] ?? ''));
$allowedRec = ['weekly', 'monthly', 'yearly', 'none'];
if ($recFilter !== '' && !in_array($recFilter, $allowedRec, true)) $recFilter = '';
if ($catFilter !== '' && !bsv_valid_category($catFilter)) $catFilter = '';

$sql = 'SELECT id, title, description, event_date, start_time, end_time, location,
               category, recurrence_type, recurrence_end_date, is_published
        FROM events';
$where = [];
$params = [];

// An event is "active/upcoming" if its anchor is in the future OR it is a
// recurring series whose end date has not yet passed (or is open-ended).
$activeClause = "(
    (recurrence_type IS NULL AND event_date >= date('now','localtime'))
    OR
    (recurrence_type IS NOT NULL
     AND (recurrence_end_date IS NULL OR recurrence_end_date >= date('now','localtime')))
)";

switch ($filter) {
    case 'upcoming':
        $where[] = 'is_published = 1';
        $where[] = $activeClause;
        break;
    case 'past':
        $where[] = 'is_published = 1';
        $where[] = 'NOT ' . $activeClause;
        break;
    case 'drafts':
        $where[] = 'is_published = 0';
        break;
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

// Stats for the header. Recurring series count as "upcoming" as long as the
// rule hasn't ended — mirror the $activeClause used for filtering.
$counts = bsv_db()->query(
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

$csrf = bsv_csrf_token();

$recLabels = [
    'none'    => 'Fără recurență',
    'weekly'  => 'Săptămânal',
    'monthly' => 'Lunar',
    'yearly'  => 'Anual',
];
$hasSecondary = $q !== '' || $catFilter !== '' || $recFilter !== '';

bsv_admin_header('Evenimente', 'Gestionați programul parohiei — adăugați, modificați sau eliminați evenimente.', null, 'events');
?>

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
      <a class="adm-btn adm-btn--ghost adm-btn--sm" href="<?= h(bsv_events_url(['filter' => $filter], [])) ?>">
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
        $catColor = bsv_category_color($catKey);
        $time = '';
        if ($e['start_time']) {
            $time = substr($e['start_time'], 0, 5);
            if ($e['end_time']) $time .= ' – ' . substr($e['end_time'], 0, 5);
        } else {
            $time = 'Toată ziua';
        }
        $recType = (string)($e['recurrence_type'] ?? '');
        $recLabels = [
            'weekly'  => 'Săptămânal',
            'monthly' => 'Lunar',
            'yearly'  => 'Anual',
        ];
        $recLabel = $recLabels[$recType] ?? '';
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
            <span class="pill-cat" data-cat="<?= h($catKey) ?>"<?= $catColor ? ' style="--pill-color: ' . h($catColor) . '"' : '' ?>><?= h($catLabel) ?></span>
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
              <input type="hidden" name="action" value="delete">
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

<?php bsv_admin_footer(); ?>
