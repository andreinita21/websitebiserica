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
            $stmt = bsv_db()->prepare('DELETE FROM announcements WHERE id = :id');
            $stmt->execute([':id' => $id]);
            bsv_flash_set('success', 'Anunțul a fost șters.');
        }
    }
    header('Location: announcements.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

// --- Filter + fetch ---------------------------------------------------------
$filter = $_GET['filter'] ?? 'published';
$allowedFilters = ['published', 'drafts', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'published';

$sql = 'SELECT id, title, body, tag, relevant_on, relevant_until, visible_days, is_published
        FROM announcements';
$where = [];

switch ($filter) {
    case 'published':
        $where[] = 'is_published = 1';
        break;
    case 'drafts':
        $where[] = 'is_published = 0';
        break;
}

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY relevant_on DESC, id DESC';

$stmt = bsv_db()->query($sql);
$announcements = $stmt->fetchAll();

$counts = bsv_db()->query(
    "SELECT
        SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) AS drafts,
        COUNT(*) AS total
     FROM announcements"
)->fetch();

$csrf = bsv_csrf_token();

$actions = '
  <a href="announcement.php" class="adm-btn adm-btn--primary">
    <span class="material-symbols-outlined" aria-hidden="true">add</span>
    <span>Adaugă anunț</span>
  </a>';

bsv_admin_header('Anunțuri', 'Gestionați anunțurile publicate pe pagina principală.', $actions, 'announcements');
?>

<div class="toolbar">
  <div class="toolbar__filters" role="tablist" aria-label="Filtru anunțuri">
    <a href="?filter=published" class="toolbar__filter <?= $filter === 'published' ? 'is-active' : '' ?>">
      Publicate <span>(<?= (int)($counts['published'] ?? 0) ?>)</span>
    </a>
    <a href="?filter=drafts" class="toolbar__filter <?= $filter === 'drafts' ? 'is-active' : '' ?>">
      Ciorne <span>(<?= (int)($counts['drafts'] ?? 0) ?>)</span>
    </a>
    <a href="?filter=all" class="toolbar__filter <?= $filter === 'all' ? 'is-active' : '' ?>">
      Toate <span>(<?= (int)($counts['total'] ?? 0) ?>)</span>
    </a>
  </div>
</div>

<?php if (empty($announcements)): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">campaign</span>
    <h3>Niciun anunț în această categorie</h3>
    <p>Adăugați primul anunț pentru a-l afișa pe pagina principală.</p>
    <a href="announcement.php" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">add</span>
      <span>Adaugă anunț</span>
    </a>
  </div>
<?php else: ?>
  <table class="events-table">
    <thead>
      <tr>
        <th class="col-date">Valabilitate</th>
        <th>Anunț</th>
        <th class="col-cat">Etichetă</th>
        <th class="col-status">Stare</th>
        <th class="col-actions">Acțiuni</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($announcements as $a): ?>
        <tr>
          <td class="col-date">
            <div class="date-cell">
              <?php if (!empty($a['relevant_until'])): ?>
                <strong><?= h(bsv_format_date_ro($a['relevant_on'])) ?> – <?= h(bsv_format_date_ro($a['relevant_until'])) ?></strong>
                <span>Interval</span>
              <?php elseif (!empty($a['visible_days'])): ?>
                <strong><?= (int)$a['visible_days'] ?> <?= ((int)$a['visible_days'] === 1) ? 'zi' : 'zile' ?></strong>
                <span>de la creare</span>
              <?php else: ?>
                <strong><?= h(bsv_format_date_ro($a['relevant_on'])) ?></strong>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="title-cell">
              <strong><?= h($a['title']) ?></strong>
              <?php if (!empty($a['body'])): ?>
                <span><?= h(mb_strimwidth($a['body'], 0, 120, '…', 'UTF-8')) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td class="col-cat">
            <?php if (!empty($a['tag'])): ?>
              <span class="pill-cat"><?= h($a['tag']) ?></span>
            <?php else: ?>
              <span style="color: var(--c-ink-muted);">—</span>
            <?php endif; ?>
          </td>
          <td class="col-status">
            <?php if ((int)$a['is_published'] === 1): ?>
              <span class="pill pill--pub">Publicat</span>
            <?php else: ?>
              <span class="pill pill--draft">Ciornă</span>
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <a class="adm-btn adm-btn--ghost adm-btn--sm" href="announcement.php?id=<?= (int)$a['id'] ?>">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              <span>Editează</span>
            </a>
            <form method="post" class="inline-form" action="announcements.php?filter=<?= h($filter) ?>"
                  onsubmit="return confirm('Sigur doriți să ștergeți anunțul „<?= h(addslashes($a['title'])) ?>”?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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
