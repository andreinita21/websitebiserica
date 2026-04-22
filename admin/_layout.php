<?php
/**
 * Shared admin layout helpers. Call bsv_admin_header($title) at the top of
 * every admin page and bsv_admin_footer() at the bottom.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/backup-lib.php';

function bsv_flash_set(string $type, string $message): void
{
    bsv_session_start();
    $_SESSION['bsv_flash'] = ['type' => $type, 'message' => $message];
}

function bsv_flash_take(): ?array
{
    bsv_session_start();
    if (empty($_SESSION['bsv_flash'])) return null;
    $f = $_SESSION['bsv_flash'];
    unset($_SESSION['bsv_flash']);
    return $f;
}

function bsv_admin_header(string $title, string $subtitle = '', ?string $actionsHtml = null, string $activeSection = 'events'): void
{
    bsv_require_admin();
    // Fire-and-forget daily backup: runs in the shutdown phase, after the
    // response is flushed, so admin pages stay snappy even on the one day
    // per day that actually does work.
    bsv_backup_schedule_daily_after_response();
    $user = h($_SESSION['bsv_admin_user'] ?? 'Administrator');

    if ($actionsHtml === null) {
        $actionsHtml = '
          <a href="event.php" class="adm-btn adm-btn--primary">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            <span>Adaugă eveniment</span>
          </a>';
    }
    ?><!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> — Administrare · Biserica Sfântul Vasile</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="bsv-csrf" content="<?= h(bsv_csrf_token()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap">
  <link rel="stylesheet" href="../assets/css/main.css">
  <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin">
  <div class="admin-shell">
    <header class="admin-header">
      <div class="admin-header__inner">
        <a href="index.php" class="admin-brand">
          <img class="admin-brand__mark" src="../assets/img/LOGO.png" alt="" aria-hidden="true">
          <span>
            <span class="admin-brand__name">Administrare parohie</span>
            <span class="admin-brand__sub">Sfântul Vasile · Ploiești</span>
          </span>
        </a>
        <div class="admin-header__actions">
          <span class="admin-header__user">Conectat ca <strong><?= $user ?></strong></span>
          <a class="admin-logout" href="logout.php">
            <span class="material-symbols-outlined" aria-hidden="true">logout</span>
            <span>Ieșire</span>
          </a>
        </div>
      </div>
    </header>

    <main class="admin-main">
      <?php $flash = bsv_flash_take(); if ($flash): ?>
        <div class="flash flash--<?= h($flash['type']) ?>" role="status">
          <span class="material-symbols-outlined" aria-hidden="true"><?php
            echo $flash['type'] === 'success' ? 'check_circle' : ($flash['type'] === 'error' ? 'error' : 'info');
          ?></span>
          <span><?= h($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <nav class="admin-section-nav" aria-label="Secțiuni">
        <a href="index.php" class="admin-section-nav__link <?= $activeSection === 'events' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">event</span>
          <span>Evenimente</span>
        </a>
        <a href="announcements.php" class="admin-section-nav__link <?= $activeSection === 'announcements' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
          <span>Anunțuri</span>
        </a>
        <a href="gallery.php" class="admin-section-nav__link <?= $activeSection === 'gallery' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">photo_library</span>
          <span>Galerie</span>
        </a>
        <a href="clergy.php" class="admin-section-nav__link <?= $activeSection === 'clergy' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">groups</span>
          <span>Cler</span>
        </a>
        <a href="contact.php" class="admin-section-nav__link <?= $activeSection === 'contact' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">contact_mail</span>
          <span>Contact</span>
        </a>
        <a href="backup.php" class="admin-section-nav__link <?= $activeSection === 'backup' ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined" aria-hidden="true">backup</span>
          <span>Backup</span>
        </a>
      </nav>

      <div class="admin-page-head">
        <div>
          <h1><?= h($title) ?></h1>
          <?php if ($subtitle !== ''): ?><p><?= h($subtitle) ?></p><?php endif; ?>
        </div>
        <div>
          <?= $actionsHtml ?>
        </div>
      </div>
<?php
}

function bsv_admin_footer(): void
{
    ?>
    </main>

    <footer class="admin-footer">
      Biserica Sfântul Vasile · Interfață de administrare
      · <a href="../calendar.html" target="_blank" rel="noopener">Calendar</a>
      · <a href="../galerie.html" target="_blank" rel="noopener">Galerie</a>
    </footer>
  </div>
  <script src="../assets/js/admin-sortable.js" defer></script>
</body>
</html><?php
}
