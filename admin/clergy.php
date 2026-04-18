<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/clergy.php';

bsv_require_admin();

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

$members = bsv_clergy_all(false);
$csrf    = bsv_csrf_token();

$actions = '
  <a href="../despre.html#clerul" target="_blank" rel="noopener" class="adm-btn adm-btn--ghost">
    <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
    <span>Vezi pagina publică</span>
  </a>
  <a href="clergy-member.php" class="adm-btn adm-btn--primary">
    <span class="material-symbols-outlined" aria-hidden="true">add</span>
    <span>Adaugă membru</span>
  </a>';

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
  <div class="admin-gallery-grid">
    <?php foreach ($members as $m):
      $photo = !empty($m['photo_path'])
        ? '../' . htmlspecialchars((string)$m['photo_path'], ENT_QUOTES)
        : '../' . APP_CLERGY_PLACEHOLDER;
    ?>
      <article class="admin-photo-card">
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
          <div class="admin-photo-card__cats">
            <span class="pill-cat">Poziție: <?= (int)$m['position'] ?></span>
          </div>
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
<?php endif; ?>

<?php bsv_admin_footer(); ?>
