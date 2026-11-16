<?php
/** Customer signs on the engineer's device (GAS-CP-R97-057): a clean preview of a submitted service report or function check,
 *  without the portal menu, with the signer's name, capacity, remark and signature pad.   ?p=sign_onsite&doc=fsr|fc&id=N */
$u = require_staff();
$doc = (string)($_GET['doc'] ?? ''); $id = (int)($_GET['id'] ?? 0); $D = function_exists('sgn_load') ? sgn_load($doc, $id) : null; $done = (string)($_GET['done'] ?? '');
if (!$D) { http_response_code(404); }
$r = $D['r'] ?? []; $can = $D && sgn_can($D);
?><!doctype html>
<html lang="<?= e(locale()) ?>" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1B2E">
<meta name="robots" content="noindex">
<title><?= e(($D['title'] ?? t('sgn.title')) . ' ' . ($D['no'] ?? '')) ?></title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
</head>
<body class="sgn">
<header class="sgn-bar"><img src="../assets/golden-logo-white.png" alt="Golden A/S"><div><b><?= e($D['title'] ?? t('sgn.title')) ?></b><span><?= e($D['no'] ?? '') ?></span></div></header>
<main class="sgn-main">
<?php foreach (flash_pull() as $f): ?><div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
<?php if (!$D): ?>
  <div class="card"><h2><?= tt('err.notfound') ?></h2><p><a class="btn" href="<?= url('dashboard') ?>"><?= tt('sgn.back') ?></a></p></div>
<?php elseif ($done === 'accepted' || $done === 'disputed'): ?>
  <div class="card sgn-done"><div class="sgn-tick<?= $done === 'disputed' ? ' is-bad' : '' ?>"><?= $done === 'accepted' ? '✓' : '↩' ?></div>
    <h2><?= tt($done === 'accepted' ? 'sgn.done_ok' : 'sgn.done_back') ?></h2><p><?= tt($done === 'accepted' ? 'sgn.done_ok_p' : 'sgn.done_back_p') ?></p>
    <?php if ($done === 'accepted' && $D['pdf']): ?><p><a class="btn btn--gold" href="<?= url('file', ['id' => $D['pdf']]) ?>" target="_blank" rel="noopener">⤓ <?= tt('sgn.open_signed') ?></a></p><?php endif; ?>
    <p class="hint"><?= tt('sgn.hand_back') ?></p><p><a class="btn btn--ghost" href="<?= e($D['back']) ?>"><?= tt('sgn.back') ?></a></p></div>
<?php elseif (!$can): ?>
  <div class="card"><h2><?= tt('fsr.not_pending') ?></h2><p class="hint"><?= tt(sgn_has() ? 'sgn.only_submitted' : 'sgn.not_ready') ?></p><p><a class="btn" href="<?= e($D['back']) ?>"><?= tt('sgn.back') ?></a></p></div>
<?php else: ?>
  <p class="sgn-staff"><?= e(t('sgn.staff_note', ['u' => (string)$u['full_name']])) ?> · <a href="<?= e($D['back']) ?>"><?= tt('sgn.back') ?></a></p>
  <?php $pdfUrl = $D['pdf'] ? url('file', ['id' => $D['pdf']]) : ''; require __DIR__ . '/_sgn_preview.php'; ?>

  <div class="card" id="sign"><h2>✍ <?= tt('sgn.h_sign') ?></h2><p class="hint"><?= tt('sgn.sign_lede') ?></p>
    <form method="post" action="<?= url('sign_onsite', ['doc' => $doc, 'id' => $id]) ?>" id="sgnform" novalidate><?= csrf_field() ?>
      <input type="hidden" name="action" value="sgn_decide"><input type="hidden" name="doc" value="<?= e($doc) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="decision" value="accepted"><input type="hidden" name="signature" value="">
      <div class="field"><label for="signer_name"><?= tt('sgn.name') ?> *</label><input id="signer_name" name="signer_name" maxlength="90" autocomplete="name" required></div>
      <div class="field"><label for="signer_role"><?= tt('sgn.role') ?></label><input id="signer_role" name="signer_role" maxlength="80" placeholder="<?= tt('sgn.role_ph') ?>"></div>
      <div class="field"><label for="remark"><?= tt('fsr.remark') ?></label><textarea id="remark" name="remark" rows="3" maxlength="2000" placeholder="<?= tt('sgn.remark_ph') ?>"></textarea></div>
      <label class="check"><input type="checkbox" name="read" value="1" id="sgnread"> <?= tt('sgn.read') ?></label>
      <div class="field" style="margin-top:12px;"><label><?= tt('sgn.pad') ?></label><canvas id="sgnpad" class="sigpad" width="600" height="220"></canvas><button type="button" class="btn btn--ghost btn--sm" id="sgnclear"><?= tt('btn.clear') ?></button></div>
      <p class="flash flash--bad" id="sgnerr" hidden></p>
      <div class="btn-row sgn-actions"><button class="btn btn--gold" type="submit" id="sgnok">✓ <?= tt('sgn.accept') ?></button><button class="btn btn--ghost btn--danger-ghost" type="button" id="sgnback">↩ <?= tt('sgn.dispute') ?></button></div>
      <p class="hint" style="margin-top:10px;"><?= tt('sgn.legal') ?></p>
    </form>
  </div>
<?php endif; ?>
</main>
<?php if ($can && !$done): ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var f = document.getElementById('sgnform'), c = document.getElementById('sgnpad'), ctx = c.getContext('2d'), drawing = false, drawn = false, last = null, err = document.getElementById('sgnerr');
  var M = <?= json_encode(['name' => t('sgn.name_needed'), 'read' => t('sgn.read_needed'), 'sign' => t('sgn.sign_needed'), 'remark' => t('fsr.remark_needed')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  ctx.lineWidth = 2.8; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#14285A';
  function pos(e) { var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * c.width / r.width, y: (t.clientY - r.top) * c.height / r.height }; }
  function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
  function move(e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; drawn = true; e.preventDefault(); }
  function end() { drawing = false; }
  c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
  c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
  document.getElementById('sgnclear').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); drawn = false; });
  function fail(m) { err.textContent = m; err.hidden = false; err.scrollIntoView({ block: 'center' }); return false; }
  function send(decision) {
    err.hidden = true;
    if (f.signer_name.value.trim().length < 3) return fail(M.name);
    if (decision === 'disputed') { if (f.remark.value.trim() === '') return fail(M.remark); }
    else { if (!document.getElementById('sgnread').checked) return fail(M.read); if (!drawn) return fail(M.sign); f.signature.value = c.toDataURL('image/png'); }
    f.decision.value = decision; document.getElementById('sgnok').disabled = true; document.getElementById('sgnback').disabled = true; f.submit(); return true;
  }
  f.addEventListener('submit', function (e) { e.preventDefault(); send('accepted'); });
  document.getElementById('sgnback').addEventListener('click', function () { send('disputed'); });
})();
</script>
<?php endif; ?>
</body>
</html>
