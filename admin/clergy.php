<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/clergy.php';

bsv_require_admin();

function bsv_clergy_ajax_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Delete -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            bsv_clergy_delete($id);
            bsv_flash_set('success', 'Membrul clerului a fost șters.');
        }
    }
    header('Location: clergy.php');
    exit;
}

// --- Reorder (AJAX) ---------------------------------------------------------
// Body: action=clergy_reorder, order=1,3,2, _token=...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clergy_reorder') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_clergy_ajax_json(['ok' => false, 'error' => 'Sesiunea a expirat'], 403);
    }
    $raw = (string)($_POST['order'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
    if (!$ids) bsv_clergy_ajax_json(['ok' => false, 'error' => 'Listă de ordine goală'], 400);

    $pdo = bsv_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE clergy SET position = :pos, updated_at = :now WHERE id = :id');
        $now = date('Y-m-d H:i:s');
        $pos = 10;
        foreach ($ids as $id) {
            $stmt->execute([':pos' => $pos, ':now' => $now, ':id' => $id]);
            $pos += 10;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        bsv_clergy_ajax_json(['ok' => false, 'error' => 'Eroare la salvare'], 500);
    }
    bsv_clergy_ajax_json(['ok' => true]);
}

$members = bsv_clergy_all(false);
$csrf    = bsv_csrf_token();

$actions = '
  <a href="../despre.html#clerul" target="_blank" rel="noopener" class="adm-btn adm-btn--ghost" data-hide-in-reorder>
    <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
    <span>Vezi pagina publică</span>
  </a>
  <button type="button" class="adm-btn adm-btn--ghost" data-enter-reorder data-hide-in-reorder>
    <span class="material-symbols-outlined" aria-hidden="true">reorder</span>
    <span>Ordonează</span>
  </button>
  <a href="clergy-member.php" class="adm-btn adm-btn--primary" data-hide-in-reorder>
    <span class="material-symbols-outlined" aria-hidden="true">add</span>
    <span>Adaugă membru</span>
  </a>
  <button type="button" class="adm-btn adm-btn--ghost" data-cancel-reorder data-show-in-reorder>
    <span class="material-symbols-outlined" aria-hidden="true">close</span>
    <span>Anulează</span>
  </button>
  <button type="button" class="adm-btn adm-btn--primary" data-save-reorder data-show-in-reorder>
    <span class="material-symbols-outlined" aria-hidden="true">save</span>
    <span>Salvează ordinea</span>
  </button>';

bsv_admin_header(
    'Clerul parohiei',
    'Gestionați cardurile cu membrii clerului afișate pe pagina „Despre”.',
    $actions,
    'clergy'
);
?>

<?php if (!$members): ?>
  <div class="table-empty">
    <span class="material-symbols-outlined" style="font-size: 2.4rem; color: var(--c-gold);" aria-hidden="true">groups</span>
    <h3>Niciun membru al clerului</h3>
    <p>Adăugați primul preot, diacon sau cântăreț bisericesc.</p>
    <a href="clergy-member.php" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">add</span>
      <span>Adaugă membru</span>
    </a>
  </div>
<?php else: ?>
  <div class="reorder-banner">
    <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
    <span><strong>Mod ordonare:</strong> Trageți cardurile pentru a schimba ordinea. Apăsați „Salvează ordinea” când ați terminat.</span>
  </div>
  <div class="admin-gallery-grid" data-sortable-grid="clergy_reorder">
    <?php foreach ($members as $m):
      $photo = !empty($m['photo_path'])
        ? '../' . htmlspecialchars((string)$m['photo_path'], ENT_QUOTES)
        : '../' . APP_CLERGY_PLACEHOLDER;
    ?>
      <article class="admin-photo-card" data-sortable-id="<?= (int)$m['id'] ?>">
        <span class="card-drag-handle" data-drag-handle aria-label="Trage pentru a reordona" title="Trage pentru a reordona">
          <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
        </span>
        <a class="admin-photo-card__thumb" href="clergy-member.php?id=<?= (int)$m['id'] ?>" aria-label="Editează membrul">
          <img src="<?= $photo ?>" alt="<?= h($m['name']) ?>" loading="lazy">
          <?php if ((int)$m['is_published'] === 0): ?>
            <span class="admin-photo-card__badge">Ciornă</span>
          <?php endif; ?>
        </a>
        <div class="admin-photo-card__body">
          <h3 class="admin-photo-card__title"><?= h($m['name']) ?></h3>
          <?php if (!empty($m['role'])): ?>
            <p class="admin-photo-card__desc" style="color: var(--c-gold-deep); text-transform: uppercase; letter-spacing: 0.18em; font-size: 0.7rem; font-weight: 700;">
              <?= h($m['role']) ?>
            </p>
          <?php endif; ?>
          <?php if (!empty($m['bio'])): ?>
            <p class="admin-photo-card__desc"><?= h(mb_strimwidth((string)$m['bio'], 0, 140, '…', 'UTF-8')) ?></p>
          <?php endif; ?>
        </div>
        <div class="admin-photo-card__actions">
          <a class="adm-btn adm-btn--ghost adm-btn--sm" href="clergy-member.php?id=<?= (int)$m['id'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            <span>Editează</span>
          </a>
          <form method="post" class="inline-form" action="clergy.php"
                onsubmit="return confirm('Sigur doriți să ștergeți „<?= h(addslashes($m['name'])) ?>”?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">
              <span class="material-symbols-outlined" aria-hidden="true">delete</span>
              <span>Șterge</span>
            </button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

<?php endif; /* Reorder controller lives in /assets/js/admin-sortable.js, loaded by _layout.php */ ?>

<?php bsv_admin_footer(); ?>
