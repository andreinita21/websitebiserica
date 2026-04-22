<?php
/**
 * Backup & Restore — admin UI.
 *
 * Pure UI + request handlers. All reusable archive logic (build, validate,
 * restore, prune, daily-backup scheduler) lives in includes/backup-lib.php
 * so it can also be invoked from bin/backup-daily.php (cron).
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/backup-lib.php';

bsv_require_admin();

// ---------------------------------------------------------------------------
// Request handlers
// ---------------------------------------------------------------------------

function bsv_backup_handle_export(): void
{
    $stamp    = date('Y-m-d_His');
    $filename = 'backup-bsv-' . $stamp . '.zip';

    $tmpZip = tempnam(sys_get_temp_dir(), 'bsv-bk-');
    if ($tmpZip === false) {
        bsv_flash_set('error', 'Nu am putut crea fișierul temporar pentru backup.');
        header('Location: backup.php'); exit;
    }

    try {
        bsv_backup_build_zip($tmpZip);
    } catch (Throwable $e) {
        @unlink($tmpZip);
        bsv_flash_set('error', 'Eroare la crearea arhivei: ' . $e->getMessage());
        header('Location: backup.php'); exit;
    }

    $size = filesize($tmpZip);

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

function bsv_backup_handle_import(): void
{
    $confirm = trim((string)($_POST['confirm'] ?? ''));
    if ($confirm !== BSV_BACKUP_CONFIRM) {
        bsv_flash_set('error', 'Restaurarea a fost anulată: lipsește textul de confirmare.');
        header('Location: backup.php'); exit;
    }

    if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
        bsv_flash_set('error', 'Nu ați selectat niciun fișier.');
        header('Location: backup.php'); exit;
    }
    $f = $_FILES['backup_file'];
    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        bsv_flash_set('error', 'Încărcarea a eșuat: ' . bsv_backup_upload_error_text($f['error'] ?? -1));
        header('Location: backup.php'); exit;
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        bsv_flash_set('error', 'Fișier invalid.');
        header('Location: backup.php'); exit;
    }
    if ($f['size'] <= 0 || $f['size'] > BSV_BACKUP_MAX_UPLOAD) {
        bsv_flash_set('error', 'Arhiva este goală sau depășește limita aplicației.');
        header('Location: backup.php'); exit;
    }

    $fh = fopen($f['tmp_name'], 'rb');
    $magic = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);
    if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") {
        bsv_flash_set('error', 'Fișierul nu este o arhivă zip validă.');
        header('Location: backup.php'); exit;
    }

    $work = bsv_backup_tempdir('bsv-restore-');
    try {
        bsv_backup_extract_zip_safely($f['tmp_name'], $work);
        bsv_backup_validate_extracted($work);
        $snapPath = bsv_backup_auto_snapshot_current();
        bsv_backup_apply_restore($work);
    } catch (Throwable $e) {
        bsv_backup_rrmdir($work);
        bsv_flash_set('error', 'Restaurare eșuată: ' . $e->getMessage());
        header('Location: backup.php'); exit;
    }

    bsv_backup_rrmdir($work);

    $msg = 'Baza de date și fișierele au fost restaurate cu succes.';
    if ($snapPath) {
        $msg .= ' Snapshot-ul stării anterioare: ' . basename($snapPath);
    }
    bsv_flash_set('success', $msg);
    header('Location: backup.php'); exit;
}

function bsv_backup_handle_snapshot_download(string $name): void
{
    $path = bsv_backup_resolve_snapshot($name);
    if ($path === null) {
        bsv_flash_set('error', 'Snapshot inexistent.');
        header('Location: backup.php'); exit;
    }

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');

    readfile($path);
    exit;
}

function bsv_backup_handle_snapshot_delete(?string $name): void
{
    $path = bsv_backup_resolve_snapshot($name);
    if ($path === null) {
        bsv_flash_set('error', 'Snapshot inexistent.');
    } else {
        @unlink($path);
        bsv_flash_set('success', 'Snapshot-ul a fost șters.');
    }
    header('Location: backup.php'); exit;
}

function bsv_backup_handle_run_daily_now(): void
{
    $r = bsv_backup_run_daily_if_due();
    switch ($r['status']) {
        case 'created':
            bsv_flash_set('success', 'Backup-ul zilnic a fost creat: ' . basename($r['path']));
            break;
        case 'up_to_date':
            bsv_flash_set('info', 'Backup-ul zilnic de astăzi există deja.');
            break;
        case 'locked':
            bsv_flash_set('info', 'Un alt proces creează deja backup-ul. Reîncercați în câteva secunde.');
            break;
        case 'error':
        default:
            bsv_flash_set('error', 'Eroare la crearea backup-ului zilnic: ' . ($r['message'] ?? 'necunoscută'));
            break;
    }
    header('Location: backup.php'); exit;
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Silent-truncation detection: if the browser sent more than post_max_size,
    // PHP drops $_POST and $_FILES entirely — give a clear message instead of
    // the generic "CSRF failed" that would otherwise fire below.
    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $ceiling    = bsv_backup_upload_ceiling();
    if (empty($_POST) && $contentLen > 0 && $contentLen > $ceiling) {
        bsv_flash_set('error',
            'Fișierul depășește limita serverului (' . bsv_backup_human_size($ceiling) .
            '). Măriți post_max_size și upload_max_filesize în php.ini și reîncercați.'
        );
        header('Location: backup.php'); exit;
    }

    if (!bsv_csrf_check($_POST['_token'] ?? null)) {
        bsv_flash_set('error', 'Sesiunea a expirat. Încercați din nou.');
        header('Location: backup.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'export') {
        bsv_backup_handle_export();
    } elseif ($action === 'import') {
        bsv_backup_handle_import();
    } elseif ($action === 'snapshot_download') {
        bsv_backup_handle_snapshot_download((string)($_POST['name'] ?? ''));
    } elseif ($action === 'snapshot_delete') {
        bsv_backup_handle_snapshot_delete($_POST['name'] ?? null);
    } elseif ($action === 'run_daily_now') {
        bsv_backup_handle_run_daily_now();
    } else {
        bsv_flash_set('error', 'Acțiune necunoscută.');
        header('Location: backup.php'); exit;
    }
}

// ---------------------------------------------------------------------------
// GET — render the page
// ---------------------------------------------------------------------------

$csrf = bsv_csrf_token();

$dbPath   = bsv_backup_db_path();
$dbExists = is_file($dbPath);
$dbSize   = $dbExists ? (int)filesize($dbPath) : 0;
$uplSize  = bsv_backup_dir_size(bsv_backup_uploads_dir());
$uplCount = is_dir(bsv_backup_uploads_dir()) ? count(bsv_backup_list_files(bsv_backup_uploads_dir())) : 0;

// Collect snapshots, split by kind.
$snapshotsManual = [];
$snapshotsDaily  = [];
foreach (glob(bsv_backup_snapshots_dir() . '/*.zip') ?: [] as $p) {
    $entry = [
        'name'  => basename($p),
        'size'  => (int)filesize($p),
        'mtime' => (int)filemtime($p),
    ];
    if (strpos($entry['name'], 'daily-') === 0) {
        $snapshotsDaily[] = $entry;
    } elseif (strpos($entry['name'], 'pre-restore-') === 0
           || strpos($entry['name'], 'pre-gallery-wipe-') === 0) {
        $snapshotsManual[] = $entry;
    }
}
usort($snapshotsManual, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
usort($snapshotsDaily,  fn($a, $b) => $b['mtime'] <=> $a['mtime']);

$latestDaily    = bsv_backup_latest_daily();
$todayIso       = date('Y-m-d');
$haveTodayDaily = is_file(bsv_backup_daily_path_for($todayIso));

$uploadCeiling  = bsv_backup_upload_ceiling();
$estimatedTotal = $dbSize + $uplSize;
$ceilingTooLow  = $estimatedTotal > 0 && $uploadCeiling < $estimatedTotal;

bsv_admin_header(
    'Backup & Restaurare',
    'Exportați întreaga bază de date și fișierele uploadate, sau restaurați dintr-o arhivă salvată anterior.',
    '',
    'backup'
);
?>

<div class="admin-card">
  <div class="admin-card__head">
    <h2>Backup automat zilnic</h2>
    <p>
      Se creează automat o arhivă completă o dată pe zi. Sunt păstrate ultimele <?= (int)BSV_BACKUP_KEEP_DAILY ?> zile.
      Rulează prin cron (<code>bin/backup-daily.php</code>) sau, dacă nu aveți cron, se declanșează automat la prima vizită în admin din ziua respectivă.
    </p>
  </div>

  <dl class="backup-stats">
    <div>
      <dt>Stare astăzi (<?= h($todayIso) ?>)</dt>
      <dd>
        <?php if ($haveTodayDaily): ?>
          <span class="pill pill--pub">Creat</span>
        <?php else: ?>
          <span class="pill pill--draft">În așteptare</span>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>Ultimul backup zilnic</dt>
      <dd>
        <?php if ($latestDaily): ?>
          <?= h(date('Y-m-d H:i', $latestDaily['mtime'])) ?>
          <span class="backup-muted">· <?= h(bsv_backup_human_size($latestDaily['size'])) ?></span>
        <?php else: ?>
          <span class="backup-muted">niciunul încă</span>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>Arhive zilnice păstrate</dt>
      <dd><?= count($snapshotsDaily) ?> / <?= (int)BSV_BACKUP_KEEP_DAILY ?></dd>
    </div>
  </dl>

  <form method="post" action="backup.php" class="form-actions" style="margin-top: var(--s-5);">
    <input type="hidden" name="_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="run_daily_now">
    <button type="submit" class="adm-btn adm-btn--ghost">
      <span class="material-symbols-outlined" aria-hidden="true">play_arrow</span>
      <span>Rulează acum backup-ul zilnic</span>
    </button>
  </form>

  <?php if (!empty($snapshotsDaily)): ?>
    <details class="backup-details">
      <summary>Arhive zilnice (<?= count($snapshotsDaily) ?>)</summary>
      <table class="events-table">
        <thead>
          <tr>
            <th>Dată</th>
            <th>Creat</th>
            <th>Mărime</th>
            <th class="col-actions">Acțiuni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($snapshotsDaily as $s): ?>
            <tr>
              <td><code><?= h($s['name']) ?></code></td>
              <td><?= h(date('Y-m-d H:i:s', $s['mtime'])) ?></td>
              <td><?= h(bsv_backup_human_size($s['size'])) ?></td>
              <td class="col-actions">
                <form method="post" action="backup.php" class="inline-form">
                  <input type="hidden" name="_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="snapshot_download">
                  <input type="hidden" name="name" value="<?= h($s['name']) ?>">
                  <button type="submit" class="adm-btn adm-btn--ghost adm-btn--sm">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span>
                    <span>Descarcă</span>
                  </button>
                </form>
                <form method="post" action="backup.php" class="inline-form"
                      onsubmit="return confirm('Sigur doriți să ștergeți acest backup zilnic?');">
                  <input type="hidden" name="_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="snapshot_delete">
                  <input type="hidden" name="name" value="<?= h($s['name']) ?>">
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
    </details>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card__head">
    <h2>Export — descarcă o copie completă</h2>
    <p>Arhiva include baza de date (<code>data/events.db</code>) și toate fișierele din <code>uploads/</code>. Păstrați-o într-un loc sigur — o puteți importa ulterior pe acest server sau pe altul.</p>
  </div>

  <dl class="backup-stats">
    <div>
      <dt>Baza de date</dt>
      <dd><?= $dbExists ? h(bsv_backup_human_size($dbSize)) : '<span class="backup-muted">nu există încă</span>' ?></dd>
    </div>
    <div>
      <dt>Fișiere uploadate</dt>
      <dd><?= (int)$uplCount ?> fișiere · <?= h(bsv_backup_human_size($uplSize)) ?></dd>
    </div>
    <div>
      <dt>Mărime estimată arhivă</dt>
      <dd><?= h(bsv_backup_human_size($estimatedTotal)) ?></dd>
    </div>
  </dl>

  <form method="post" action="backup.php" class="form-actions" style="margin-top: var(--s-5);">
    <input type="hidden" name="_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="export">
    <button type="submit" class="adm-btn adm-btn--primary">
      <span class="material-symbols-outlined" aria-hidden="true">download</span>
      <span>Descarcă arhiva de backup</span>
    </button>
  </form>
</div>

<div class="admin-card">
  <div class="admin-card__head">
    <h2>Restaurare — importă dintr-o arhivă</h2>
    <p>
      Fișierul trebuie să fie o arhivă <code>.zip</code> produsă de acest ecran.
      <strong>Atenție:</strong> importul înlocuiește complet baza de date curentă și toate fișierele din <code>uploads/</code>.
      Pentru siguranță, starea actuală va fi salvată automat ca „snapshot înainte de restaurare” înainte de a aplica schimbările.
    </p>
  </div>

  <form method="post" action="backup.php" enctype="multipart/form-data" class="backup-restore-form"
        onsubmit="return confirm('Sigur doriți să înlocuiți baza de date și fișierele curente cu cele din arhivă?');">
    <input type="hidden" name="_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="import">

    <div class="form-grid">
      <div class="field field-full">
        <label for="backup_file">Arhivă de backup (.zip)</label>
        <input type="file" id="backup_file" name="backup_file" accept=".zip,application/zip" required>
        <span class="hint">
          Limită server: <?= h(bsv_backup_human_size($uploadCeiling)) ?>
          (din php.ini — <code>post_max_size</code> și <code>upload_max_filesize</code>).
          Limita aplicației: <?= h(bsv_backup_human_size(BSV_BACKUP_MAX_UPLOAD)) ?>.
        </span>
        <?php if ($ceilingTooLow): ?>
          <span class="err-msg">
            Atenție: limita PHP (<?= h(bsv_backup_human_size($uploadCeiling)) ?>) este mai mică decât arhiva estimată
            (<?= h(bsv_backup_human_size($estimatedTotal)) ?>). Restaurarea va eșua până când măriți
            <code>post_max_size</code> și <code>upload_max_filesize</code> în php.ini.
          </span>
        <?php endif; ?>
      </div>

      <div class="field field-full">
        <label for="confirm">Confirmare</label>
        <input type="text" id="confirm" name="confirm" autocomplete="off" spellcheck="false"
               placeholder="Tastați <?= h(BSV_BACKUP_CONFIRM) ?>" required pattern="<?= h(BSV_BACKUP_CONFIRM) ?>">
        <span class="hint">Tastați exact <code><?= h(BSV_BACKUP_CONFIRM) ?></code> pentru a confirma restaurarea.</span>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="adm-btn adm-btn--danger">
        <span class="material-symbols-outlined" aria-hidden="true">restore</span>
        <span>Restaurează din arhivă</span>
      </button>
    </div>
  </form>
</div>

<div class="admin-card">
  <div class="admin-card__head">
    <h2>Snapshot-uri manuale</h2>
    <p>Snapshot-uri create automat înainte de fiecare restaurare (ultimele <?= (int)BSV_BACKUP_KEEP_SNAPS ?>) și orice alte arhive salvate de administrator.</p>
  </div>

  <?php if (empty($snapshotsManual)): ?>
    <p class="backup-muted" style="padding: var(--s-4) 0;">Niciun snapshot manual.</p>
  <?php else: ?>
    <table class="events-table">
      <thead>
        <tr>
          <th>Snapshot</th>
          <th>Creat</th>
          <th>Mărime</th>
          <th class="col-actions">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($snapshotsManual as $s): ?>
          <tr>
            <td><code><?= h($s['name']) ?></code></td>
            <td><?= h(date('Y-m-d H:i:s', $s['mtime'])) ?></td>
            <td><?= h(bsv_backup_human_size($s['size'])) ?></td>
            <td class="col-actions">
              <form method="post" action="backup.php" class="inline-form">
                <input type="hidden" name="_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="snapshot_download">
                <input type="hidden" name="name" value="<?= h($s['name']) ?>">
                <button type="submit" class="adm-btn adm-btn--ghost adm-btn--sm">
                  <span class="material-symbols-outlined" aria-hidden="true">download</span>
                  <span>Descarcă</span>
                </button>
              </form>
              <form method="post" action="backup.php" class="inline-form"
                    onsubmit="return confirm('Sigur doriți să ștergeți acest snapshot?');">
                <input type="hidden" name="_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="snapshot_delete">
                <input type="hidden" name="name" value="<?= h($s['name']) ?>">
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
</div>

<style>
.backup-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--s-4);
  margin: 0;
}
.backup-stats > div {
  background: var(--c-cream);
  border: 1px solid var(--c-line-light);
  border-radius: var(--r-md);
  padding: var(--s-4);
}
.backup-stats dt {
  font-family: var(--f-sans);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--c-ink-muted);
  margin-bottom: 6px;
}
.backup-stats dd {
  font-family: var(--f-serif);
  font-size: 1.15rem;
  color: var(--c-ink-text);
  margin: 0;
}
.backup-muted { color: var(--c-ink-muted); }
.backup-restore-form .form-actions { margin-top: var(--s-5); }
.backup-details {
  margin-top: var(--s-5);
  border-top: 1px solid var(--c-line-light);
  padding-top: var(--s-4);
}
.backup-details summary {
  cursor: pointer;
  font-family: var(--f-sans);
  font-size: 0.82rem;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--c-ink-muted);
  padding: var(--s-2) 0;
}
.backup-details[open] summary { margin-bottom: var(--s-4); }
</style>

<?php bsv_admin_footer(); ?>
