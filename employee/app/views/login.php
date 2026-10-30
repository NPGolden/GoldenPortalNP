<?php if (current_user()) redirect('dashboard'); ?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= tt('twofa.signin') ?> — <?= tt('app.name') ?></title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="../assets/favicon.png">
<link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../../assets/app.css') ?: 1 ?>">
</head>
<body>
<!-- GAS-RENDERED <?= gmdate('Y-m-d\\TH:i:s\\Z') ?> r6 -->
<div class="gate">
  <div>
    <form class="gate-card" method="post" action="<?= url('login') ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">

      <img class="brandmark" src="../assets/golden-logo.png" alt="Golden Automation Systems">
      <p class="eyebrow"><?= tt('login.eyebrow') ?></p>
      <h1><?= tt('login.title') ?></h1>
      <p class="lede"><?= tt('login.lede') ?></p>

      <?php foreach (flash_pull() as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <div class="field">
        <label for="email"><?= tt('login.email') ?></label>
        <input id="email" name="email" type="email" required autofocus autocomplete="off"
               value="<?= e($_GET['email'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="password"><?= tt('login.password') ?></label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
      </div>

      <button class="btn btn--gold" type="submit"><?= tt('login.continue') ?></button>
    </form>

    <?php language_picker('langsel langsel--gate'); ?>
    <p class="gate-foot"><?= tt('login.footnote') ?></p>
  </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
