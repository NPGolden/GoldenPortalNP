<?php /* Customer sign-in */ ?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1B2E">
<title><?= tt('login.title') ?> — Golden Automation Systems</title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="../assets/favicon.png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
</head>
<body>
<!-- GAS-RENDERED <?= gmdate('Y-m-d\\TH:i:s\\Z') ?> r6 -->
<div class="gate">
  <div>
    <form class="gate-card" method="post" action="index.php?p=login" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <img class="logo" src="../assets/golden-logo.png" alt="Golden Automation Systems">
      <p class="eyebrow" style="text-align:center;"><?= tt('app.name') ?></p>
      <h1 style="text-align:center;font-size:21px;"><?= tt('login.title') ?></h1>
      <p class="lede" style="text-align:center;"><?= tt('login.lede') ?></p>

      <?php foreach (flash_pull() as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <div class="field">
        <label for="email"><?= tt('login.email') ?></label>
        <input id="email" name="email" type="email" required autofocus autocomplete="username" inputmode="email">
      </div>
      <div class="field">
        <label for="password"><?= tt('login.password') ?></label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
      </div>
      <button class="btn btn--gold" type="submit" style="width:100%;"><?= tt('login.continue') ?></button>
    </form>
    <?php if (function_exists('pk_enabled') && pk_enabled()): ?>
    <div class="pk-login" style="margin-top:12px;">
      <div style="display:flex;align-items:center;gap:10px;margin:6px 0;"><span style="flex:1;height:1px;background:var(--line);"></span><span class="hint"><?= tt('pk.or') ?></span><span style="flex:1;height:1px;background:var(--line);"></span></div>
      <button type="button" class="btn btn--ghost" id="pk-login" style="width:100%;" data-wait="<?= tt('pk.wait') ?>" data-ok="<?= tt('pk.ok') ?>" data-cancel="<?= tt('pk.cancelled') ?>">🔑 <?= tt('pk.signin') ?></button>
      <p id="pk-msg" class="flash" hidden style="margin-top:8px;"></p>
    </div>
    <script nonce="<?= csp_nonce() ?>" src="../assets/passkey.js?v=<?= @filemtime(__DIR__ . '/../../assets/passkey.js') ?: 1 ?>"></script>
    <?php endif; ?>

    <div style="display:flex;justify-content:center;margin-top:18px;"><?= lang_selector(false) ?></div>
    <p class="buildtag buildtag--gate">v<?= e(CP_VERSION) ?> · <?= e(CP_BUILD) ?></p>
    <p class="gate-foot"><?= tt('login.help') ?></p>
  </div>
</div>
</body>
</html>
