<?php
/**
 * Acceptance by e-mail link — public page (GAS-CP-R97-058). No portal account, no portal session.
 *   GET  sign.php?t=<token>            the page: confirm the mailbox with a 6-digit code, then read and decide
 *   GET  sign.php?t=<token>&f=1        the document file (only after the code)
 *   POST sign.php?t=<token>  a=code | verify | decide
 * The token is 40 random letters / digits and only its SHA-256 is stored. 60 requests per address per minute.
 */
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '0');
if (is_dir(__DIR__ . '/storage') && is_writable(__DIR__ . '/storage')) { ini_set('log_errors', '1'); ini_set('error_log', __DIR__ . '/storage/php-errors.log'); }
ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
// the same modules as the portal itself, in the same order (the list lives in index.php)
if (preg_match_all("#^require __DIR__ \\. '/app/([a-z0-9_]+)\\.php';#m", (string) file_get_contents(__DIR__ . '/index.php'), $mm)) foreach ($mm[1] as $mod) require_once __DIR__ . '/app/' . $mod . '.php';
header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: same-origin');   /* R97-257: the firewall refuses a POST without referrer (FBP007) — the referrer goes to this site only, never outside */  header('Cache-Control: no-store, max-age=0'); header('X-Frame-Options: DENY');
$nonce = csp_nonce();
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'nonce-{$nonce}'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$token = (string)($_GET['t'] ?? ''); if (!preg_match('/^[A-Za-z0-9]{40}$/', $token)) $token = '';
$self = 'sign.php?t=' . $token; $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$page = function (string $title, string $no, string $html, int $code = 200) use ($nonce): void {
    http_response_code($code); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . e(locale()) . '" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#0A1B2E"><meta name="robots" content="noindex"><title>' . e(trim($title . ' ' . $no)) . ' — Golden A/S</title><link rel="icon" href="../assets/favicon.png" type="image/png"><link rel="stylesheet" href="../assets/portal.css?v=' . (@filemtime(__DIR__ . '/../assets/portal.css') ?: 1) . '"></head><body class="sgn">';
    echo '<header class="sgn-bar"><img src="../assets/golden-logo-white.png" alt="Golden A/S"><div><b>' . e($title) . '</b><span>' . e($no) . '</span></div></header><main class="sgn-main">' . $html . '<p class="sgn-foot">Golden A/S · <a href="https://www.goldenas.com.gr/privacy.html" rel="noopener">' . tt('dl.privacy') . '</a></p></main></body></html>'; exit;
};
$box = fn(string $h, string $p, string $tone = '') => '<div class="card sgn-done"><div class="sgn-tick' . ($tone === 'bad' ? ' is-bad' : ($tone === 'mute' ? ' is-mute' : '')) . '">' . ($tone === 'bad' ? '!' : ($tone === 'mute' ? '·' : '✓')) . '</div><h2>' . e($h) . '</h2><p>' . e($p) . '</p></div>';
try {
    // 60 requests per address per minute
    $ip = dl_ip() ?: '0.0.0.0'; $dir = __DIR__ . '/storage/dl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $rf = $dir . '/' . md5($ip) . '.json';
    $hits = is_file($rf) ? (json_decode((string) file_get_contents($rf), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($x) => $x > time() - 60));
    if (count($hits) >= 60) { http_response_code(429); header('Retry-After: 60'); exit; } $hits[] = time(); @file_put_contents($rf, json_encode($hits), LOCK_EX);
    if (random_int(1, 100) === 1) foreach (glob($dir . '/*.json') ?: [] as $g) if (filemtime($g) < time() - 3600) @unlink($g);

    $L = $token !== '' && function_exists('dl_find') ? dl_find($token) : null;
    if (!$L) $page(t('dl.p_title'), '', $box(t('dl.p_invalid'), t('dl.p_invalid_p'), 'bad'), 404);
    locale((string)$L['lang']);
    $title = (string)$L['doc_title']; $no = (string)$L['doc_no'];
    if ($L['used_at']) { $ok = in_array($L['decision'], ['accepted', 'approved', 'approved_comments'], true); $page($title, $no, $box(t($ok ? 'dl.p_used_ok' : 'dl.p_used_back'), t('dl.p_used_p', ['d' => fmt_datetime((string)$L['used_at']), 'n' => (string)$L['signer_name']]), $ok ? '' : 'mute')); }
    if ($L['cancelled_at'] || (int)$L['is_expired']) $page($title, $no, $box(t((int)$L['is_expired'] && !$L['cancelled_at'] ? 'dl.p_expired' : 'dl.p_invalid'), t('dl.p_ask', ['u' => (string)$L['created_name']]), 'bad'), 410);
    $I = dl_run_as($L) ? dl_info((string)$L['doc'], (int)$L['doc_id']) : null;
    if (!$I) $page($title, $no, $box(t('dl.p_invalid'), t('dl.p_ask', ['u' => (string)$L['created_name']]), 'bad'), 410);
    if (!$I['open']) $page($title, $no, $box(t('dl.closed'), t('dl.p_ask', ['u' => (string)$L['created_name']]), 'mute'), 410);
    $doc = (string)$L['doc']; $r = $I['r']; $cookie = 'gasdl' . (int)$L['id']; $passOk = dl_pass_ok($L, (string)($_COOKIE[$cookie] ?? '')); $err = ''; $post = [];
    $go = function (string $m = '') use ($self): void { header('Location: ' . $self . ($m !== '' ? '&m=' . $m : ''), true, 303); exit; };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['a'] ?? '');
        if ($a === 'code') { $c = dl_code_issue($L); if ($c === null) $go((int)$L['code_sent'] >= DL_CODE_SENDS ? 'many' : 'wait'); $go(dl_code_mail($L, $c) ? 'sent' : 'mail'); }
        if ($a === 'verify') {
            $pass = dl_code_check($L, preg_replace('/\D/', '', (string)($_POST['code'] ?? '')));
            if ($pass === null) $go('bad');
            setcookie($cookie, $pass, ['expires' => time() + DL_PASS_MIN * 60, 'path' => rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/customer/sign.php')), '/') . '/sign.php', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']); $go();
        }
        if ($a === 'decide') {
            if (!$passOk) $go('code');
            $post = ['name' => (string)($_POST['signer_name'] ?? ''), 'role' => (string)($_POST['signer_role'] ?? ''), 'remark' => (string)($_POST['remark'] ?? '')];
            try { dl_decide($L, $I, (string)($_POST['decision'] ?? ''), $post['name'], $post['role'], $post['remark'], (string)($_POST['signature'] ?? ''), !empty($_POST['read'])); header('Location: ' . $self, true, 303); exit; }
            catch (RuntimeException $e) { $err = $e->getMessage(); }
        }
    }
    if (isset($_GET['f'])) {
        if (!$passOk || !$I['file']) { http_response_code(403); exit; }
        $f = one('SELECT original_name, mime_type FROM sp_files WHERE id = ?', [(int)$I['file']]); $b = cp_file_bytes((int)$I['file']); if ($b === null) { http_response_code(404); exit; }
        $mime = (string)($f['mime_type'] ?? ''); if (!in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) $mime = 'application/octet-stream';
        header_remove('Content-Security-Policy'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($mime === 'application/octet-stream' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)(($f['original_name'] ?? '') ?: 'document.pdf')) . '"'); header('Content-Length: ' . strlen($b)); echo $b; exit;
    }
    $msg = ['sent' => ['good', 'dl.x_sent'], 'wait' => ['bad', 'dl.x_wait'], 'many' => ['bad', 'dl.x_many'], 'mail' => ['bad', 'dl.x_mail'], 'bad' => ['bad', 'dl.x_bad'], 'code' => ['bad', 'dl.x_code']][(string)($_GET['m'] ?? '')] ?? null;
    $L = dl_find($token) ?: $L;   // counters may have moved
    ob_start();
    if ($msg) echo '<div class="flash flash--' . $msg[0] . '">' . e(t($msg[1], ['e' => dl_mask((string)$L['email']), 'm' => DL_CODE_MIN])) . '</div>';
    if ($err !== '') echo '<div class="flash flash--bad">' . e($err) . '</div>';
    if (!$passOk): ?>
  <div class="card"><h2><?= tt('dl.c_title') ?></h2>
    <p><?= e(t('dl.c_lede', ['u' => (string)$L['created_name'], 't' => $title, 'no' => $no])) ?></p>
    <p><?= e(t('dl.c_how', ['e' => dl_mask((string)$L['email'])])) ?></p>
    <?php if ((int)$L['code_live']): ?>
    <form method="post" action="<?= e($self) ?>" class="sgn-code"><input type="hidden" name="a" value="verify">
      <div class="field"><label for="code"><?= tt('dl.c_code') ?></label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus></div>
      <div class="btn-row sgn-actions"><button class="btn btn--gold" type="submit">✓ <?= tt('dl.c_confirm') ?></button></div>
    </form>
    <form method="post" action="<?= e($self) ?>" style="margin-top:10px;"><input type="hidden" name="a" value="code"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('dl.c_again') ?></button></form>
    <?php else: ?>
    <form method="post" action="<?= e($self) ?>"><input type="hidden" name="a" value="code"><div class="btn-row sgn-actions"><button class="btn btn--gold" type="submit">✉ <?= tt('dl.c_send') ?></button></div></form>
    <?php endif; ?>
    <p class="hint" style="margin-top:10px;"><?= e(t('dl.c_valid', ['d' => fmt_datetime((string)$L['expires_at'])])) ?></p>
  </div>
<?php else:
    if ($doc === 'rcoffer') { $sd = $r['start_date'] ? date('d/m/Y', strtotime((string)$r['start_date'])) : '—'; ?>
  <div class="card"><h2><?= tt('rc.of_doc') ?></h2>
    <dl class="sgn-dl"><dt><?= tt('rc.of_position') ?></dt><dd><?= e((string)$r['position']) ?></dd><dt><?= tt('rc.of_start') ?></dt><dd><b><?= e($sd) ?></b></dd><?php if ($r['valid_until']): ?><dt><?= tt('rc.of_valid') ?></dt><dd><?= e(date('d/m/Y', strtotime((string)$r['valid_until']))) ?></dd><?php endif; ?></dl>
    <div style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:15px;line-height:1.5;border-top:1px solid #E3E7EE;padding-top:12px;"><?= e((string)$r['body']) ?></div>
  </div>
<?php } else { $pdfUrl = $I['file'] ? $self . '&f=1' : ''; require __DIR__ . '/views/_sgn_preview.php'; }
    $pad = $doc === 'fsr' || $doc === 'fc';
    $btns = $pad ? [['accepted', 'sgn.accept', 'gold', 'read,sign'], ['disputed', 'sgn.dispute', 'danger', 'remark']]
          : ($doc === 'rcoffer' ? [['accepted', 'rc.of_dl_accept', 'gold', 'read'], ['declined', 'rc.of_dl_decline', 'danger', 'remark']]
          : ($doc === 'offer' ? [['approved', 'of.approve', 'gold', 'read'], ['rejected', 'of.reject', 'danger', 'remark']]
          : [['approved', 'oa.approve', 'gold', 'read'], ['approved_comments', 'dl.oa_comments', 'plain', 'remark'], ['rejected', 'oa.reject', 'danger', 'remark']])); ?>
  <div class="card" id="sign"><h2>✍ <?= tt($pad ? 'sgn.h_sign' : 'dl.h_decide') ?></h2><p class="hint"><?= tt($pad ? 'sgn.sign_lede' : ($doc === 'rcoffer' ? 'rc.of_dl_lede' : 'dl.decide_lede')) ?></p>
    <form method="post" action="<?= e($self) ?>" id="sgnform" novalidate>
      <input type="hidden" name="a" value="decide"><input type="hidden" name="decision" value=""><input type="hidden" name="signature" value="">
      <div class="field"><label for="signer_name"><?= tt('sgn.name') ?> *</label><input id="signer_name" name="signer_name" maxlength="90" autocomplete="name" required value="<?= e($post['name'] ?? (string)($L['name'] ?? '')) ?>"></div>
      <div class="field"<?= $doc === 'rcoffer' ? ' hidden' : '' ?>><label for="signer_role"><?= tt('sgn.role') ?></label><input id="signer_role" name="signer_role" maxlength="80" placeholder="<?= tt('sgn.role_ph') ?>" value="<?= e($post['role'] ?? '') ?>"></div>
      <div class="field"><label for="remark"><?= tt($doc === 'oa' ? 'dl.comments' : 'fsr.remark') ?></label><textarea id="remark" name="remark" rows="3" maxlength="2000" placeholder="<?= tt('sgn.remark_ph') ?>"><?= e($post['remark'] ?? '') ?></textarea></div>
      <label class="check"><input type="checkbox" name="read" value="1" id="sgnread"> <?= $doc === 'rcoffer' ? e(t('rc.of_dl_read', ['d' => $sd])) : tt($pad ? 'sgn.read' : ($doc === 'offer' ? 'dl.read_offer' : 'dl.read_doc')) ?></label>
      <?php if ($pad): ?><div class="field" style="margin-top:12px;"><label><?= tt('sgn.pad') ?></label><canvas id="sgnpad" class="sigpad" width="600" height="220"></canvas><button type="button" class="btn btn--ghost btn--sm" id="sgnclear"><?= tt('btn.clear') ?></button></div><?php endif; ?>
      <p class="flash flash--bad" id="sgnerr" hidden></p>
      <div class="btn-row sgn-actions sgn-actions--<?= count($btns) ?>"><?php foreach ($btns as [$dec, $key, $tone, $need]): ?><button class="btn<?= $tone === 'gold' ? ' btn--gold' : ($tone === 'danger' ? ' btn--ghost btn--danger-ghost' : '') ?>" type="button" data-dec="<?= $dec ?>" data-need="<?= $need ?>"><?= tt($key) ?></button><?php endforeach; ?></div>
      <p class="hint" style="margin-top:10px;"><?= e(t('dl.legal', ['e' => (string)$L['email']])) ?></p>
    </form>
  </div>
  <script nonce="<?= $nonce ?>">
  (function () {
    var f = document.getElementById('sgnform'), c = document.getElementById('sgnpad'), err = document.getElementById('sgnerr'), drawn = false;
    var M = <?= json_encode(['name' => t('sgn.name_needed'), 'read' => t('sgn.read_needed'), 'sign' => t('sgn.sign_needed'), 'remark' => t('dl.remark_needed')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    if (c) { var ctx = c.getContext('2d'), drawing = false, last = null; ctx.lineWidth = 2.8; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#14285A';
      var pos = function (e) { var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * c.width / r.width, y: (t.clientY - r.top) * c.height / r.height }; };
      var start = function (e) { drawing = true; last = pos(e); e.preventDefault(); }, move = function (e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; drawn = true; e.preventDefault(); }, end = function () { drawing = false; };
      c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
      c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
      document.getElementById('sgnclear').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); drawn = false; }); }
    function fail(m) { err.textContent = m; err.hidden = false; err.scrollIntoView({ block: 'center' }); }
    f.addEventListener('submit', function (e) { e.preventDefault(); });
    document.querySelectorAll('[data-dec]').forEach(function (b) { b.addEventListener('click', function () {
      var need = b.dataset.need.split(','); err.hidden = true;
      if (f.signer_name.value.trim().length < 3) return fail(M.name);
      if (need.indexOf('remark') >= 0 && f.remark.value.trim() === '') return fail(M.remark);
      if (need.indexOf('read') >= 0 && !document.getElementById('sgnread').checked) return fail(M.read);
      if (need.indexOf('sign') >= 0) { if (!drawn) return fail(M.sign); f.signature.value = c.toDataURL('image/png'); }
      f.decision.value = b.dataset.dec; document.querySelectorAll('[data-dec]').forEach(function (x) { x.disabled = true; }); f.submit();
    }); });
  })();
  </script>
<?php endif;
    $page($title, $no, (string) ob_get_clean());
} catch (Throwable $e) {
    error_log('[sign] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); while (ob_get_level()) ob_end_clean();
    $page('Golden A/S', '', '<div class="card sgn-done"><div class="sgn-tick is-bad">!</div><h2>Something went wrong</h2><p>Please try again in a few minutes.</p></div>', 500);
}
