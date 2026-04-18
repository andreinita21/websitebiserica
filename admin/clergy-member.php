<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/clergy.php';

bsv_require_admin();

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew   = $id <= 0;
$errors  = [];
$current = $isNew ? null : bsv_clergy_get($id);
if (!$isNew && !$current) {
    bsv_flash_set('error', 'Membrul nu a fost găsit.');
    header('Location: clergy.php');
    exit;
}

$data = $isNew ? [
    'id' => 0, 'name' => '', 'role' => '', 'bio' => '',
    'photo_path' => '', 'position' => 0, 'is_published' => 1,
] : [
    'id'           => (int)$current['id'],
    'name'         => (string)$current['name'],
    'role'         => (string)$current['role'],
    'bio'          => (string)$current['bio'],
    'photo_path'   => (string)$current['photo_path'],
    'position'     => (int)$current['position'],
    'is_published' => (int)$current['is_published'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    $data['name']         = trim((string)($_POST['name'] ?? ''));
    $data['role']         = trim((string)($_POST['role'] ?? ''));
    $data['bio']          = trim((string)($_POST['bio'] ?? ''));
    $data['position']     = (int)($_POST['position'] ?? 0);
    $data['is_published'] = isset($_POST['is_published']) ? 1 : 0;
    $removePhoto          = !empty($_POST['remove_photo']);

    if ($data['name'] === '' || mb_strlen($data['name']) > 180) {
        $errors['name'] = 'Numele este obligatoriu (maxim 180 de caractere).';
    }
    if (mb_strlen($data['role']) > 120) {
        $errors['role'] = 'Rolul este prea lung (maxim 120 de caractere).';
    }
    if (mb_strlen($data['bio']) > 2000) {
        $errors['bio'] = 'Biografia este prea lungă (maxim 2000 de caractere).';
    }

    // Handle photo upload (replaces existing photo) or explicit removal.
    $newPhotoPath = null;
    $upload = $_FILES['photo'] ?? null;
    if ($upload && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $newPhotoPath = bsv_clergy_store_upload($upload);
        } catch (Throwable $e) {
            $errors['photo'] = $e->getMessage();
        }
    }

    if (!$errors) {
        $oldPhoto = (string)($current['photo_path'] ?? '');

        if ($newPhotoPath !== null) {
            $data['photo_path'] = $newPhotoPath;
        } elseif ($removePhoto) {
            $data['photo_path'] = '';
        } else {
            $data['photo_path'] = $oldPhoto;
        }

        $savedId = bsv_clergy_save($data);

        // Clean up the previous file if it was replaced or removed.
        if ($oldPhoto !== '' && $oldPhoto !== $data['photo_path']) {
            bsv_clergy_delete_file($oldPhoto);
        }

        bsv_flash_set('success', $isNew ? 'Membrul a fost adăugat.' : 'Modificările au fost salvate.');
        header('Location: clergy-member.php?id=' . $savedId);
        exit;
    }
}

$csrf = bsv_csrf_token();

$actions = '
  <a href="clergy.php" class="adm-btn adm-btn--ghost">
    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
    <span>Înapoi la listă</span>
  </a>';

bsv_admin_header(
    $isNew ? 'Adaugă membru al clerului' : 'Editează membrul clerului',
    'Numele, rolul, biografia, fotografia și ordinea în pagina „Despre”.',
    $actions,
    'clergy'
);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="clergy-member.php<?= $isNew ? '' : '?id=' . (int)$id ?>" enctype="multipart/form-data" novalidate>
  <div class="admin-card__head">
    <h2>Detalii membru</h2>
    <p>Cardul afișat pe pagina publică <code>despre.html</code>, secțiunea „Clerul parohiei”.</p>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">

  <div class="form-grid">
    <div class="field">
      <label for="name">Nume <span class="req">*</span></label>
      <input type="text" id="name" name="name" maxlength="180" required
             value="<?= h($data['name']) ?>"
             placeholder="Pr. Ioan Popescu">
      <?php if (!empty($errors['name'])): ?><span class="err-msg"><?= h($errors['name']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="role">Rol</label>
      <input type="text" id="role" name="role" maxlength="120"
             value="<?= h($data['role']) ?>"
             placeholder="Paroh / Preot slujitor / Diacon">
      <span class="hint">Apare scris cu majuscule deasupra numelui.</span>
      <?php if (!empty($errors['role'])): ?><span class="err-msg"><?= h($errors['role']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="bio">Biografie</label>
      <textarea id="bio" name="bio" rows="4" maxlength="2000"
                placeholder="Câteva propoziții despre slujirea părintelui."><?= h($data['bio']) ?></textarea>
      <?php if (!empty($errors['bio'])): ?><span class="err-msg"><?= h($errors['bio']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="position">Poziție în listă</label>
      <input type="number" id="position" name="position" min="0" step="1"
             value="<?= (int)$data['position'] ?>">
      <span class="hint">Numerele mai mici apar primele. Folosiți pași de 10 (10, 20, 30…) ca să puteți insera ușor între ei.</span>
    </div>

    <div class="field field--check">
      <input type="checkbox" id="is_published" name="is_published" value="1"
             <?= (int)$data['is_published'] === 1 ? 'checked' : '' ?>>
      <label for="is_published">Publică pe pagina „Despre”</label>
    </div>

    <div class="field field-full">
      <label>Fotografie</label>
      <div class="photo-editor">
        <div class="photo-editor__preview">
          <?php
            $previewSrc = !empty($data['photo_path'])
              ? '../' . h($data['photo_path'])
              : '../' . APP_CLERGY_PLACEHOLDER;
          ?>
          <img src="<?= $previewSrc ?>" alt="Previzualizare fotografie" data-photo-preview>
        </div>
        <div class="photo-editor__side">
          <label class="adm-btn adm-btn--ghost" for="photo" data-pick-photo>
            <span class="material-symbols-outlined" aria-hidden="true">upload</span>
            <span>Alege o imagine</span>
          </label>
          <input type="file" id="photo" name="photo"
                 accept="image/jpeg,image/png,image/webp" hidden data-photo-input>
          <span class="hint">JPG, PNG sau WebP. Maxim <?= (int)(APP_CLERGY_MAX_BYTES / (1024 * 1024)) ?> MB. După alegere veți putea ajusta zoom-ul și poziționa fața în cerc.</span>
          <?php if (!empty($errors['photo'])): ?><span class="err-msg"><?= h($errors['photo']) ?></span><?php endif; ?>
          <?php if (!empty($data['photo_path'])): ?>
            <label class="photo-editor__remove">
              <input type="checkbox" name="remove_photo" value="1">
              <span>Șterge fotografia actuală (revine la placeholder)</span>
            </label>
          <?php endif; ?>
        </div>
      </div>

      <div class="cropper" data-cropper hidden>
        <div class="cropper__stage" data-cropper-stage>
          <canvas class="cropper__canvas" data-cropper-canvas width="320" height="320"
                  aria-label="Zonă de decupare circulară"></canvas>
          <div class="cropper__viewport" aria-hidden="true"></div>
        </div>
        <div class="cropper__controls">
          <label for="cropper-zoom" class="cropper__zoom-label">
            <span class="material-symbols-outlined" aria-hidden="true">zoom_in</span>
            Zoom
          </label>
          <input type="range" id="cropper-zoom" min="1" max="3" step="0.01" value="1"
                 data-cropper-zoom aria-label="Nivel de zoom">
          <div class="cropper__actions">
            <button type="button" class="adm-btn adm-btn--ghost adm-btn--sm" data-cropper-reset>
              <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
              <span>Resetează</span>
            </button>
          </div>
          <p class="cropper__hint">Trageți pentru a repoziționa. Cercul auriu este ceea ce va apărea pe pagină.</p>
        </div>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <a href="clergy.php" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      <span>Anulează</span>
    </a>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span><?= $isNew ? 'Adaugă membru' : 'Salvează modificările' ?></span>
    </button>
  </div>
</form>

<script>
/*
 * Circular photo cropper for the clergy card.
 *
 * Shows the user a 320×320 stage with a 240px golden viewport. The image is
 * drawn zoomed/panned onto the canvas; the viewport shows what gets kept.
 * On submit, we render the crop to an offscreen 512×512 canvas, convert to a
 * JPEG blob, wrap in a File, and replace the input's selection so the server
 * receives the cropped image (not the raw upload).
 *
 * No external dependencies — plain canvas + pointer events.
 */
(function () {
  var STAGE = 320, VIEW = 240, OUT = 512;

  var form       = document.querySelector('form.admin-card');
  var fileInput  = document.querySelector('[data-photo-input]');
  var preview    = document.querySelector('[data-photo-preview]');
  var wrap       = document.querySelector('[data-cropper]');
  var stage      = document.querySelector('[data-cropper-stage]');
  var canvas     = document.querySelector('[data-cropper-canvas]');
  var zoom       = document.querySelector('[data-cropper-zoom]');
  var resetBtn   = document.querySelector('[data-cropper-reset]');

  if (!form || !fileInput || !canvas || !zoom || !stage) return;

  var ctx = canvas.getContext('2d');
  var state = null;       // { img, minScale, maxScale, scale, tx, ty }
  var originalFile = null;

  function loadImage(file) {
    var img = new Image();
    var url = URL.createObjectURL(file);
    img.onload = function () {
      URL.revokeObjectURL(url);
      // Min scale fills the viewport; start at that scale, centered.
      var minScale = VIEW / Math.min(img.naturalWidth, img.naturalHeight);
      state = {
        img: img,
        minScale: minScale,
        maxScale: minScale * 3,
        scale: minScale,
        tx: (STAGE - img.naturalWidth * minScale) / 2,
        ty: (STAGE - img.naturalHeight * minScale) / 2,
      };
      zoom.min = '1'; zoom.max = '3'; zoom.step = '0.01'; zoom.value = '1';
      wrap.hidden = false;
      clampOffsets();
      draw();
    };
    img.onerror = function () {
      URL.revokeObjectURL(url);
      wrap.hidden = true;
      state = null;
    };
    img.src = url;
  }

  /** Keep the image covering the viewport at all times — no blank inside the circle. */
  function clampOffsets() {
    if (!state) return;
    var w = state.img.naturalWidth  * state.scale;
    var h = state.img.naturalHeight * state.scale;
    var vLeft = (STAGE - VIEW) / 2;
    var vTop  = (STAGE - VIEW) / 2;
    var vRight  = vLeft + VIEW;
    var vBottom = vTop  + VIEW;
    // Left edge: image right must reach viewport right  → tx + w >= vRight
    //            image left  must stay <= viewport left → tx <= vLeft
    state.tx = Math.min(state.tx, vLeft);
    state.tx = Math.max(state.tx, vRight - w);
    state.ty = Math.min(state.ty, vTop);
    state.ty = Math.max(state.ty, vBottom - h);
  }

  function draw() {
    ctx.clearRect(0, 0, STAGE, STAGE);
    if (!state) return;
    var w = state.img.naturalWidth  * state.scale;
    var h = state.img.naturalHeight * state.scale;
    ctx.drawImage(state.img, state.tx, state.ty, w, h);
    updatePreview();
  }

  /** Render the cropped circle into the small preview thumb so the user sees the result. */
  function updatePreview() {
    if (!preview || !state) return;
    var previewCanvas = document.createElement('canvas');
    previewCanvas.width = 132;
    previewCanvas.height = 132;
    var pctx = previewCanvas.getContext('2d');
    var sx = ((STAGE - VIEW) / 2 - state.tx) / state.scale;
    var sy = ((STAGE - VIEW) / 2 - state.ty) / state.scale;
    var sSize = VIEW / state.scale;
    pctx.fillStyle = '#16161A';
    pctx.fillRect(0, 0, 132, 132);
    pctx.drawImage(state.img, sx, sy, sSize, sSize, 0, 0, 132, 132);
    preview.src = previewCanvas.toDataURL('image/jpeg', 0.88);
  }

  function zoomToSlider(val) {
    // 1 → minScale, 3 → maxScale (linear)
    if (!state) return state ? state.minScale : 1;
    return state.minScale + (state.maxScale - state.minScale) * ((val - 1) / 2);
  }

  // --- File picker ---------------------------------------------------------
  fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0];
    if (!file) { wrap.hidden = true; state = null; return; }
    originalFile = file;
    loadImage(file);
  });

  // --- Zoom slider --------------------------------------------------------
  zoom.addEventListener('input', function () {
    if (!state) return;
    var oldScale = state.scale;
    var newScale = zoomToSlider(parseFloat(zoom.value));
    // Zoom around the center of the viewport so the framing stays sensible.
    var cx = STAGE / 2, cy = STAGE / 2;
    state.tx = cx - (cx - state.tx) * (newScale / oldScale);
    state.ty = cy - (cy - state.ty) * (newScale / oldScale);
    state.scale = newScale;
    clampOffsets();
    draw();
  });

  // --- Reset --------------------------------------------------------------
  resetBtn.addEventListener('click', function () {
    if (!state) return;
    state.scale = state.minScale;
    state.tx = (STAGE - state.img.naturalWidth  * state.scale) / 2;
    state.ty = (STAGE - state.img.naturalHeight * state.scale) / 2;
    zoom.value = '1';
    clampOffsets();
    draw();
  });

  // --- Pan with pointer ---------------------------------------------------
  var drag = null;
  stage.addEventListener('pointerdown', function (e) {
    if (!state) return;
    drag = { x: e.clientX, y: e.clientY, tx: state.tx, ty: state.ty };
    stage.setPointerCapture(e.pointerId);
  });
  stage.addEventListener('pointermove', function (e) {
    if (!drag || !state) return;
    state.tx = drag.tx + (e.clientX - drag.x);
    state.ty = drag.ty + (e.clientY - drag.y);
    clampOffsets();
    draw();
  });
  function endDrag(e) {
    if (!drag) return;
    try { stage.releasePointerCapture(e.pointerId); } catch (_) {}
    drag = null;
  }
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
  stage.addEventListener('pointerleave', endDrag);

  // --- On submit, replace the raw file with the cropped output -----------
  form.addEventListener('submit', function (e) {
    if (!state || !originalFile) return;
    if (typeof DataTransfer === 'undefined' || typeof File === 'undefined') return;

    e.preventDefault();

    var outCanvas = document.createElement('canvas');
    outCanvas.width = OUT;
    outCanvas.height = OUT;
    var octx = outCanvas.getContext('2d');
    octx.fillStyle = '#16161A';
    octx.fillRect(0, 0, OUT, OUT);

    var sx = ((STAGE - VIEW) / 2 - state.tx) / state.scale;
    var sy = ((STAGE - VIEW) / 2 - state.ty) / state.scale;
    var sSize = VIEW / state.scale;
    octx.drawImage(state.img, sx, sy, sSize, sSize, 0, 0, OUT, OUT);

    outCanvas.toBlob(function (blob) {
      if (!blob) { form.submit(); return; }
      var baseName = (originalFile.name || 'photo').replace(/\.[^.]+$/, '');
      var cropped  = new File([blob], baseName + '-crop.jpg', { type: 'image/jpeg' });
      var dt = new DataTransfer();
      dt.items.add(cropped);
      fileInput.files = dt.files;
      // Let the browser handle the now-updated form.
      HTMLFormElement.prototype.submit.call(form);
    }, 'image/jpeg', 0.92);
  });
})();
</script>

<?php bsv_admin_footer(); ?>
