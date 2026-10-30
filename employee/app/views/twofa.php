<?php
$uid = (int)($_SESSION['pending_uid'] ?? 0);
if (!$uid) redirect('login');

$u         = one('SELECT id, full_name, email, totp_secret FROM users WHERE id = ?', [$uid]);
$enrolling = !empty($_SESSION['enrolling']);
$issuer    = setting('company_name', 'Golden A/S');
$uri       = $enrolling ? totp_uri($u['totp_secret'], $u['email'], $issuer) : '';
$pretty    = $enrolling ? trim(chunk_split($u['totp_secret'], 4, ' ')) : '';
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= tt('twofa.confirm_title') ?> — <?= tt('app.name') ?></title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="../assets/favicon.png">
<link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../../assets/app.css') ?: 1 ?>">
</head>
<body>
<!-- GAS-RENDERED <?= gmdate('Y-m-d\\TH:i:s\\Z') ?> r6 -->
<div class="gate">
  <div>
    <form class="gate-card" method="post" action="<?= url('twofa') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="twofa">

      <img class="brandmark" src="../assets/golden-logo.png" alt="Golden Automation Systems">
      <p class="eyebrow"><?= tt('twofa.step') ?></p>

      <?php if ($enrolling): ?>
        <h1><?= tt('twofa.setup_title') ?></h1>
        <p class="lede"><?= tt('twofa.setup_lede') ?></p>

        <div class="qr" id="qr" data-uri="<?= e($uri) ?>"
             data-fallback="<?= tt('twofa.qr_fail') ?>"></div>
        <p class="hint" style="text-align:center;margin-bottom:6px;"><?= tt('twofa.cannot_scan') ?></p>
        <p class="secret"><?= e($pretty) ?></p>
        <p class="hint"><?= tt('twofa.secret_warn') ?></p>
      <?php else: ?>
        <h1><?= tt('twofa.confirm_title') ?></h1>
        <p class="lede"><?= t('twofa.confirm_lede', ['email' => '<strong>' . e($u['email']) . '</strong>']) ?></p>
      <?php endif; ?>

      <?php foreach (flash_pull() as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <div class="field">
        <label for="code"><?= tt('twofa.code') ?></label>
        <input id="code" name="code" type="text" class="code-input" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" required autofocus autocomplete="one-time-code">
      </div>

      <button class="btn btn--gold" type="submit"><?= tt('twofa.signin') ?></button>
    </form>

    <?php language_picker('langsel langsel--gate'); ?>
    <p class="gate-foot"><a href="<?= url('login') ?>" style="color:#8FA0AE;"><?= tt('twofa.other') ?></a></p>
  </div>
</div>

<?php if ($enrolling): ?>
<script src="assets/qrcode.min.js"></script>
<script>
(function () {
  // SVG rendering: immune to canvas quirks, and if the library file is
  // corrupted (ASCII FTP transfer) the person still gets a clear hint
  // plus the manual key already shown above.
  var el = document.getElementById('qr');
  function fail() {
    el.innerHTML = '<p class="hint" style="text-align:center;">' + el.dataset.fallback + '</p>';
  }
  if (!window.QRCode || !window.QRCode.toString) { fail(); return; }
  QRCode.toString(el.dataset.uri,
    { type: 'svg', margin: 1, width: 200, color: { dark: '#0B1E2D', light: '#FFFFFF' } },
    function (err, svg) { if (err) { fail(); return; } el.innerHTML = svg; });
})();
</script>
<?php endif; ?>
<script src="assets/app.js"></script>
</body>
</html>
