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

<script>
/*
 * Drag-to-reorder for clergy cards (grid layout, 2D FLIP animation).
 * Same deferred-save flow as the event categories/locations pages — see
 * admin/index.php for the fully annotated version.
 */
(function () {
  var body = document.body;
  var grid = document.querySelector('[data-sortable-grid]');
  if (!grid) return;

  var action = grid.getAttribute('data-sortable-grid');
  var csrf   = <?= json_encode($csrf) ?>;

  var baseline = null;
  var drag     = null;

  document.querySelectorAll('[data-enter-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      baseline = snapshotOrder();
      body.classList.add('is-reorder');
    });
  });
  document.querySelectorAll('[data-cancel-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (baseline) animatedRestore(baseline);
      baseline = null;
      body.classList.remove('is-reorder');
    });
  });
  document.querySelectorAll('[data-save-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var current = snapshotOrder();
      if (!baseline || current.join(',') === baseline.join(',')) {
        baseline = null;
        body.classList.remove('is-reorder');
        return;
      }
      btn.disabled = true;
      persistOrder(current, function (ok) {
        btn.disabled = false;
        if (ok) {
          baseline = null;
          body.classList.remove('is-reorder');
          toast('Ordine salvată.', 'success');
        } else {
          toast('Nu am putut salva. Încercați din nou.', 'error');
        }
      });
    });
  });

  function snapshotOrder() {
    return Array.prototype.map.call(
      grid.querySelectorAll('[data-sortable-id]'),
      function (c) { return c.getAttribute('data-sortable-id'); }
    );
  }

  function animatedRestore(ids) {
    var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-sortable-id]'));
    var oldRects = new Map();
    cards.forEach(function (c) { oldRects.set(c, c.getBoundingClientRect()); });
    ids.forEach(function (id) {
      var c = grid.querySelector('[data-sortable-id="' + id + '"]');
      if (c) grid.appendChild(c);
    });
    flipAnimate(cards, oldRects);
  }

  /** FLIP for a grid: each card might move in both X and Y (wrap across rows). */
  function flipAnimate(cards, oldRects) {
    cards.forEach(function (c) {
      var old = oldRects.get(c);
      if (!old) return;
      var now = c.getBoundingClientRect();
      var dx = old.left - now.left;
      var dy = old.top  - now.top;
      if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;
      c.style.transition = 'none';
      c.style.transform  = 'translate(' + dx + 'px,' + dy + 'px)';
      c.getBoundingClientRect();   // force reflow
      c.style.transition = '';
      c.style.transform  = '';
    });
  }

  function persistOrder(ids, done) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('order',  ids.join(','));
    fd.append('_token', csrf);
    fetch('clergy.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
    .then(function (j) { done(!!(j && j.ok)); })
    .catch(function () { done(false); });
  }

  function toast(msg, type) {
    var t = document.querySelector('.reorder-toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'reorder-toast';
      (grid.parentNode || document.body).insertBefore(t, grid);
    }
    t.textContent = msg;
    t.setAttribute('data-type', type);
    t.classList.remove('is-hiding');
    t.classList.add('is-showing');
    clearTimeout(t._hideT);
    t._hideT = setTimeout(function () {
      t.classList.remove('is-showing');
      t.classList.add('is-hiding');
    }, 2400);
  }

  // --- Pointer-based drag with 2D FLIP ------------------------------------
  grid.addEventListener('pointerdown', function (e) {
    if (!body.classList.contains('is-reorder')) return;
    var handle = e.target.closest('[data-drag-handle]');
    if (!handle) return;
    var card = handle.closest('[data-sortable-id]');
    if (!card) return;
    e.preventDefault();
    drag = { card: card };
    card.classList.add('is-dragging');
    try { handle.setPointerCapture(e.pointerId); } catch (_) {}
  });

  grid.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var x = e.clientX, y = e.clientY;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-sortable-id]'));
    if (cards.length < 2) return;

    // Find the card whose center is nearest the pointer (Euclidean),
    // excluding the dragged one. Insert before it if the pointer is in its
    // first half (top → bottom, left → right in reading order), else after.
    var closest = null, minD2 = Infinity, closestRect = null;
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      if (c === drag.card) continue;
      var r = c.getBoundingClientRect();
      var cx = r.left + r.width  / 2;
      var cy = r.top  + r.height / 2;
      var dx = x - cx, dy = y - cy;
      var d2 = dx * dx + dy * dy;
      if (d2 < minD2) { minD2 = d2; closest = c; closestRect = r; }
    }
    if (!closest) return;

    var cx = closestRect.left + closestRect.width / 2;
    var cy = closestRect.top  + closestRect.height / 2;
    var beforeInReadingOrder =
      (y < cy - closestRect.height * 0.25) ||
      (Math.abs(y - cy) <= closestRect.height * 0.5 && x < cx);
    var target = beforeInReadingOrder ? closest : closest.nextElementSibling;

    if (drag.card === target || drag.card.nextSibling === target) return;

    var oldRects = new Map();
    cards.forEach(function (c) {
      if (c === drag.card) return;
      oldRects.set(c, c.getBoundingClientRect());
    });

    grid.insertBefore(drag.card, target);

    flipAnimate(cards.filter(function (c) { return c !== drag.card; }), oldRects);
  });

  function endDrag() {
    if (!drag) return;
    drag.card.classList.remove('is-dragging');
    drag = null;
  }
  grid.addEventListener('pointerup', endDrag);
  grid.addEventListener('pointercancel', endDrag);
})();
</script>
<?php endif; ?>

<?php bsv_admin_footer(); ?>
