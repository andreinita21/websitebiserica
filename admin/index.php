<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';

bsv_require_admin();

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
    header('Location: index.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

// --- Filter + fetch ---------------------------------------------------------
$filter = $_GET['filter'] ?? 'upcoming';
$allowedFilters = ['upcoming', 'past', 'drafts', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'upcoming';

$sql = 'SELECT id, title, description, event_date, start_time, end_time, location,
               category, is_published
        FROM events';
$where = [];
$params = [];

switch ($filter) {
    case 'upcoming':
        $where[] = 'is_published = 1';
        $where[] = "event_date >= date('now','localtime')";
        break;
    case 'past':
        $where[] = 'is_published = 1';
        $where[] = "event_date < date('now','localtime')";
        break;
    case 'drafts':
        $where[] = 'is_published = 0';
        break;
}

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY event_date ' . ($filter === 'past' ? 'DESC' : 'ASC') . ', start_time ASC';

$stmt = bsv_db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Stats for the header
$counts = bsv_db()->query(
    "SELECT
        SUM(CASE WHEN is_published = 1 AND event_date >= date('now','localtime') THEN 1 ELSE 0 END) AS upcoming,
        SUM(CASE WHEN is_published = 1 AND event_date <  date('now','localtime') THEN 1 ELSE 0 END) AS past,
        SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) AS drafts,
        COUNT(*) AS total
     FROM events"
)->fetch();

$csrf = bsv_csrf_token();

bsv_admin_header('Evenimente', 'Gestionați programul parohiei — adăugați, modificați sau eliminați evenimente.');
?>

<div class="toolbar">
  <div class="toolbar__filters" role="tablist" aria-label="Filtru evenimente">
    <a href="?filter=upcoming" class="toolbar__filter <?= $filter === 'upcoming' ? 'is-active' : '' ?>">
      Viitoare <span>(<?= (int)($counts['upcoming'] ?? 0) ?>)</span>
    </a>
    <a href="?filter=past" class="toolbar__filter <?= $filter === 'past' ? 'is-active' : '' ?>">
      Trecute <span>(<?= (int)($counts['past'] ?? 0) ?>)</span>
    </a>
    <a href="?filter=drafts" class="toolbar__filter <?= $filter === 'drafts' ? 'is-active' : '' ?>">
      Ciorne <span>(<?= (int)($counts['drafts'] ?? 0) ?>)</span>
    </a>
    <a href="?filter=all" class="toolbar__filter <?= $filter === 'all' ? 'is-active' : '' ?>">
      Toate <span>(<?= (int)($counts['total'] ?? 0) ?>)</span>
    </a>
  </div>
</div>

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
        $catKey = (string)$e['category'];
        $catLabel = APP_CATEGORIES[$catKey] ?? 'Eveniment';
        $time = '';
        if ($e['start_time']) {
            $time = substr($e['start_time'], 0, 5);
            if ($e['end_time']) $time .= ' – ' . substr($e['end_time'], 0, 5);
        } else {
            $time = 'Toată ziua';
        }
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
            <form method="post" class="inline-form" action="index.php?filter=<?= h($filter) ?>"
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
