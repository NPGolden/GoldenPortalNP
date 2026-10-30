<?php
/** Customer sign-in — step 2: the authenticator code.          GAS-CP-R6-001
 *  First time: shows the QR / manual key to enrol the authenticator app. */
$u = pending_twofa_user();
if (!$u) redirect('login');
$enrolling = !empty($_SESSION['enrol_secret']);
$secret    = $enrolling ? (string)$_SESSION['enrol_secret'] : '';
$issuer    = 'Golden A/S Customer Portal';
$uri       = $enrolling ? totp_uri($secret, $u['email'], $issuer) : '';
$pretty    = $enrolling ? trim(chunk_split($secret, 4, ' ')) : '';
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1B2E">
<title><?= tt('twofa.confirm_title') ?> — Golden Automation Systems</title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
</head>
<body>
<!-- GAS-RENDERED <?= gmdate('Y-m-d\\TH:i:s\\Z') ?> r6 -->
<div class="gate">
  <div>
    <form class="gate-card" method="post" action="index.php?p=twofa" data-lock>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="twofa">
      <img class="logo" src="../assets/golden-logo.png" alt="Golden Automation Systems">
      <p class="eyebrow" style="text-align:center;"><?= tt('twofa.step') ?></p>

      <?php if ($enrolling): ?>
        <h1 style="text-align:center;font-size:21px;"><?= tt('twofa.setup_title') ?></h1>
        <p class="lede" style="text-align:center;"><?= tt('twofa.setup_lede') ?></p>
        <div class="qr" id="qr" data-uri="<?= e($uri) ?>" data-fallback="<?= tt('twofa.qr_fail') ?>"></div>
        <p class="hint" style="text-align:center;margin-bottom:6px;"><?= tt('twofa.cannot_scan') ?></p>
        <p class="secret"><?= e($pretty) ?></p>
        <p class="hint" style="text-align:center;"><?= tt('twofa.secret_warn') ?></p>
      <?php else: ?>
        <h1 style="text-align:center;font-size:21px;"><?= tt('twofa.confirm_title') ?></h1>
        <p class="lede" style="text-align:center;"><?= t('twofa.confirm_lede', ['email' => '<strong>' . e($u['email']) . '</strong>']) ?></p>
      <?php endif; ?>

      <?php foreach (flash_pull() as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <div class="field">
        <label for="code" style="text-align:center;"><?= tt('twofa.code') ?></label>
        <input id="code" name="code" type="text" class="code-input" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" required autofocus autocomplete="one-time-code">
      </div>
      <button class="btn btn--gold" type="submit" style="width:100%;"><?= tt('twofa.signin') ?></button>
    </form>

    <div style="display:flex;justify-content:center;margin-top:18px;"><?= lang_selector(false) ?></div>
    <p class="buildtag buildtag--gate">v<?= e(CP_VERSION) ?> · <?= e(CP_BUILD) ?></p>
    <p class="gate-foot"><a href="<?= url('login') ?>"><?= tt('twofa.other') ?></a></p>
  </div>
</div>
<?php if ($enrolling): ?>
<script nonce="<?= csp_nonce() ?>" src="../assets/qrcode.min.js?v=<?= @filemtime(__DIR__ . '/../../assets/qrcode.min.js') ?: 1 ?>"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var el = document.getElementById('qr');
  function fail() { el.innerHTML = '<p class="hint" style="text-align:center;">' + el.dataset.fallback + '</p>'; }
  if (!window.QRCode || !window.QRCode.toString) { fail(); return; }
  QRCode.toString(el.dataset.uri, { type: 'svg', margin: 1, width: 200, color: { dark: '#0B1E2D', light: '#FFFFFF' } },
    function (err, svg) { if (err) { fail(); return; } el.innerHTML = svg; });
})();
</script>
<?php endif; ?>
<script nonce="<?= csp_nonce() ?>" src="../assets/portal.js?v=<?= @filemtime(__DIR__ . '/../../assets/portal.js') ?: 1 ?>"></script>
</body>
</html>
