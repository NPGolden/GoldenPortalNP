<?php
/** First sign-in: the account sets its own password (GAS-CP-R97-167). Reached only through index.php while must_change_pw = 1. */
$u = require_login();
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1B2E">
<title><?= tt('pwc.title') ?> — Golden Automation Systems</title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
</head>
<body>
<div class="gate">
  <div>
    <form class="gate-card" method="post" action="index.php?p=password_change" data-lock autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password_change">
      <img class="logo" src="../assets/golden-logo.png" alt="Golden Automation Systems">
      <p class="eyebrow" style="text-align:center;"><?= tt('pwc.step') ?></p>
      <h1 style="text-align:center;"><?= tt('pwc.title') ?></h1>
      <p class="hint" style="text-align:center;"><?= e(t('pwc.lede', ['name' => (string)$u['full_name']])) ?></p>
      <?php foreach (flash_pull() as $fl): ?><div class="flash flash--<?= e($fl['type']) ?>"><?= e($fl['message']) ?></div><?php endforeach; ?>
      <div class="field"><label for="pw1"><?= tt('pwc.new') ?></label><input id="pw1" name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password" autofocus></div>
      <div class="field"><label for="pw2"><?= tt('pwc.again') ?></label><input id="pw2" name="password2" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></div>
      <ul class="hint" style="margin:0 0 12px 18px;padding:0;">
        <li><?= tt('pwc.r_len') ?></li><li><?= tt('pwc.r_mix') ?></li><li><?= tt('pwc.r_name') ?></li><li><?= tt('pwc.r_same') ?></li>
      </ul>
      <button class="btn btn--gold" type="submit" style="width:100%;"><?= tt('pwc.save') ?></button>
      <p class="hint" style="text-align:center;margin-top:12px;"><?= tt('pwc.tip') ?></p>
    </form>
    <form method="post" action="index.php?p=login" style="text-align:center;margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="linkbtn" type="submit"><?= tt('nav.signout') ?></button></form>
  </div>
</div>
</body>
</html>
