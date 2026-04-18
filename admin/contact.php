<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/settings.php';

bsv_require_admin();

$errors  = [];
$current = bsv_settings_all();
$data    = $current;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        $errors['_csrf'] = 'Sesiunea a expirat. Reîncărcați pagina.';
    }

    foreach (array_keys(BSV_CONTACT_DEFAULTS) as $k) {
        $data[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $data['contact_phone_link'] = preg_replace('/[^\d+]/', '', $data['contact_phone_link']);

    if ($data['contact_address'] === '' || mb_strlen($data['contact_address']) > 400) {
        $errors['contact_address'] = 'Adresa este obligatorie (maxim 400 de caractere).';
    }
    if ($data['contact_phone_display'] !== '' && mb_strlen($data['contact_phone_display']) > 60) {
        $errors['contact_phone_display'] = 'Numărul afișat este prea lung (maxim 60 de caractere).';
    }
    if ($data['contact_phone_link'] !== '' && !preg_match('/^\+?\d{6,20}$/', $data['contact_phone_link'])) {
        $errors['contact_phone_link'] = 'Numărul pentru link trebuie să conțină doar cifre și opțional „+”.';
    }
    if ($data['contact_email'] !== '' && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['contact_email'] = 'Introduceți o adresă de email validă.';
    }
    foreach (['contact_schedule_visiting', 'contact_schedule_liturgy'] as $k) {
        if (mb_strlen($data[$k]) > 400) {
            $errors[$k] = 'Textul este prea lung (maxim 400 de caractere).';
        }
    }
    foreach (['contact_map_embed_url', 'contact_map_link_url'] as $k) {
        if ($data[$k] !== '' && !filter_var($data[$k], FILTER_VALIDATE_URL)) {
            $errors[$k] = 'Introduceți o adresă URL validă (https://…).';
        }
    }

    if (!$errors) {
        bsv_settings_save($data);
        bsv_flash_set('success', 'Datele de contact au fost salvate.');
        header('Location: contact.php');
        exit;
    }
}

$csrf = bsv_csrf_token();

bsv_admin_header(
    'Date de contact',
    'Modificați adresa, telefonul, emailul, programul slujbelor și harta. Schimbările apar imediat pe site.',
    '<a href="../contact.html" target="_blank" rel="noopener" class="adm-btn adm-btn--ghost">
        <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
        <span>Vezi pagina publică</span>
      </a>',
    'contact'
);
?>

<?php if (!empty($errors['_csrf'])): ?>
  <div class="flash flash--error">
    <span class="material-symbols-outlined" aria-hidden="true">error</span>
    <span><?= h($errors['_csrf']) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="admin-card" action="contact.php" novalidate>
  <div class="admin-card__head">
    <h2>Informații publice</h2>
    <p>Aceste valori sunt folosite în pagina de contact, în secțiunea „Vizitează-ne” de pe pagina principală și în subsolul site-ului.</p>
  </div>

  <input type="hidden" name="_token" value="<?= h($csrf) ?>">

  <div class="form-grid">
    <div class="field field-full">
      <label for="contact_address">Adresă <span class="req">*</span></label>
      <textarea id="contact_address" name="contact_address" rows="2" maxlength="400" required><?= h($data['contact_address']) ?></textarea>
      <span class="hint">Pe mai multe linii — fiecare rând nou apare ca rând nou pe site.</span>
      <?php if (!empty($errors['contact_address'])): ?><span class="err-msg"><?= h($errors['contact_address']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="contact_phone_display">Telefon (afișat)</label>
      <input type="text" id="contact_phone_display" name="contact_phone_display" maxlength="60"
             value="<?= h($data['contact_phone_display']) ?>"
             placeholder="+40 722 000 000">
      <span class="hint">Cum apare numărul pentru vizitatori.</span>
      <?php if (!empty($errors['contact_phone_display'])): ?><span class="err-msg"><?= h($errors['contact_phone_display']) ?></span><?php endif; ?>
    </div>

    <div class="field">
      <label for="contact_phone_link">Telefon (pentru link)</label>
      <input type="text" id="contact_phone_link" name="contact_phone_link" maxlength="20"
             value="<?= h($data['contact_phone_link']) ?>"
             placeholder="+40722000000">
      <span class="hint">Format internațional, doar cifre și „+”. Folosit pentru apel direct de pe mobil.</span>
      <?php if (!empty($errors['contact_phone_link'])): ?><span class="err-msg"><?= h($errors['contact_phone_link']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="contact_email">Email</label>
      <input type="email" id="contact_email" name="contact_email" maxlength="180"
             value="<?= h($data['contact_email']) ?>"
             placeholder="contact@parohie.ro">
      <?php if (!empty($errors['contact_email'])): ?><span class="err-msg"><?= h($errors['contact_email']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="contact_schedule_visiting">Program vizitare / slujbe</label>
      <textarea id="contact_schedule_visiting" name="contact_schedule_visiting" rows="3" maxlength="400"><?= h($data['contact_schedule_visiting']) ?></textarea>
      <span class="hint">Pe mai multe linii — fiecare rând nou apare ca rând separat.</span>
      <?php if (!empty($errors['contact_schedule_visiting'])): ?><span class="err-msg"><?= h($errors['contact_schedule_visiting']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="contact_schedule_liturgy">Sfânta Liturghie</label>
      <textarea id="contact_schedule_liturgy" name="contact_schedule_liturgy" rows="3" maxlength="400"><?= h($data['contact_schedule_liturgy']) ?></textarea>
      <span class="hint">Programul liturgic principal — afișat doar în pagina de contact.</span>
      <?php if (!empty($errors['contact_schedule_liturgy'])): ?><span class="err-msg"><?= h($errors['contact_schedule_liturgy']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="contact_map_embed_url">URL hartă (embed)</label>
      <input type="url" id="contact_map_embed_url" name="contact_map_embed_url" maxlength="500"
             value="<?= h($data['contact_map_embed_url']) ?>"
             placeholder="https://maps.google.com/maps?q=…&output=embed">
      <span class="hint">URL-ul folosit în iframe-ul cu harta. Trebuie să se termine cu <code>output=embed</code>.</span>
      <?php if (!empty($errors['contact_map_embed_url'])): ?><span class="err-msg"><?= h($errors['contact_map_embed_url']) ?></span><?php endif; ?>
    </div>

    <div class="field field-full">
      <label for="contact_map_link_url">URL hartă (link „Deschide în Google Maps”)</label>
      <input type="url" id="contact_map_link_url" name="contact_map_link_url" maxlength="500"
             value="<?= h($data['contact_map_link_url']) ?>"
             placeholder="https://www.google.com/maps/place/…">
      <?php if (!empty($errors['contact_map_link_url'])): ?><span class="err-msg"><?= h($errors['contact_map_link_url']) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <a href="index.php" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      <span>Înapoi</span>
    </a>
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">save</span>
      <span>Salvează modificările</span>
    </button>
  </div>
</form>

<?php bsv_admin_footer(); ?>
