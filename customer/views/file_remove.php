<?php
/** Remove a protected file (issued, sent or signed) — administrators only, with a written reason (GAS-CP-R97-190).  ?p=file_remove&t=<table>&id=N&back=… */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); $tbl = (string)($_GET['t'] ?? ''); $id = (int)($_GET['id'] ?? 0); $back = ft_back_ok((string)($_GET['back'] ?? ''));
$row = ft_has() ? ft_row($tbl, $id) : null;
if (!$row) { http_response_code(404); layout_head(t('err.notfound'), ''); echo '<h1>' . tt('err.notfound') . '</h1><p><a class="btn btn--sm" href="' . e($back) . '">‹ ' . tt('sb.back') . '</a></p>'; layout_foot(); return; }
$i = ft_info($tbl, $row);
layout_head(t('ft.rm_title'), '');
?>
<div class="page-head"><div><h1>🔒🗑 <?= tt('ft.rm_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($i['label']) ?><?= $i['context'] !== '' ? ' · ' . e($i['context']) : '' ?></p></div></div>
<div class="card" style="max-width:760px;">
  <p class="flash flash--warn" style="margin:0 0 12px;text-align:justify;"><?= e(t($i['protected'] ? 'ft.rm_warn' : 'ft.rm_plain', ['d' => FT_DAYS])) ?></p>
  <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ft_delete"><input type="hidden" name="t" value="<?= e($tbl) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="field"><label><?= tt('ft.reason') ?><?= $i['protected'] ? ' *' : '' ?></label><textarea name="reason" rows="3" maxlength="300" minlength="5"<?= $i['protected'] ? ' required' : '' ?> placeholder="<?= tt('ft.reason_ph') ?>"></textarea></div>
    <div class="btn-row"><button class="btn btn--danger" type="submit">🗑 <?= tt('ft.rm_btn') ?></button><a class="btn btn--ghost" href="<?= e($back) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php layout_foot();
