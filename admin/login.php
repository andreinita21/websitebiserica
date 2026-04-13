<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

bsv_session_start();

if (bsv_is_admin()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? '';
    $user  = (string)($_POST['username'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');

    if (!bsv_csrf_check($token)) {
        $error = 'Sesiunea a expirat. Reîncărcați pagina și încercați din nou.';
    } elseif ($user === '' || $pass === '') {
        $error = 'Introduceți numele de utilizator și parola.';
    } elseif (!bsv_login($user, $pass)) {
        $error = 'Date de autentificare incorecte. Vă rugăm să încercați din nou.';
        usleep(400000); // slight delay to discourage brute force
    } else {
        header('Location: index.php');
        exit;
    }
}

$csrf = bsv_csrf_token();
?><!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Autentificare administrator — Biserica Sfântul Vasile</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap">
  <link rel="stylesheet" href="../assets/css/main.css">
  <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin">

  <main class="login-screen">
    <form class="login-card" method="post" action="login.php" autocomplete="off" novalidate>
      <div class="login-brand">
        <span class="login-brand__mark" aria-hidden="true">✦</span>
        <span class="login-brand__name">Administrare parohie</span>
        <span class="login-brand__sub">Sfântul Vasile · Ploiești</span>
      </div>

      <?php if ($error !== ''): ?>
        <div class="flash flash--error" role="alert">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <span><?= h($error) ?></span>
        </div>
      <?php endif; ?>

      <div class="field">
        <label for="username">Nume utilizator</label>
        <input type="text" id="username" name="username" required autofocus
               value="<?= h($_POST['username'] ?? '') ?>">
      </div>

      <div class="field" style="margin-top: var(--s-5);">
        <label for="password">Parolă</label>
        <input type="password" id="password" name="password" required
               autocomplete="current-password">
      </div>

      <input type="hidden" name="_token" value="<?= h($csrf) ?>">

      <div style="margin-top: var(--s-6);">
        <button type="submit" class="adm-btn adm-btn--primary">
          <span class="material-symbols-outlined" aria-hidden="true">login</span>
          <span>Intră în administrare</span>
        </button>
      </div>

      <p class="login-back">
        <a href="../index.html">← Înapoi la site</a>
      </p>
    </form>
  </main>

</body>
</html>
