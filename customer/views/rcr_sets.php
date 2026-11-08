<?php
/** HR › Recruiting › Screening sets (GAS-CP-R89-001): the questionnaires the website renders; option scores and knock-out groups edited here only.  ?p=rc_sets[&id=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('rcr_has') || !rcr_has() || !rcr_can()) { http_response_code(403); layout_head(t('rc.sets'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $s = $id ? rcr_set($id) : null; $sets = rcr_sets(false);
layout_head(t('rc.sets'), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <?= tt('rc.sets') ?></p>
<div class="page-head"><div><h1><?= tt('rc.sets') ?></h1><p class="lede"><?= tt('rc.sets_lede') ?></p></div><div class="btn-row"><a class="btn btn--gold" href="<?= url('rcr_sets', ['id' => 0, 'new' => 1]) ?>">＋ <?= tt('rc.new_set') ?></a></div></div>
<div class="cols" style="grid-template-columns:1fr 2fr;"><div><div class="card"><table class="tbl"><tbody><?php foreach ($sets as $x): ?><tr><td><a href="<?= url('rcr_sets', ['id' => (int)$x['id']]) ?>"><b><?= e($x['code']) ?></b></a><br><span class="hint"><?= e($x['title']) ?></span></td><td><?= $x['is_active'] ? pill('gold', 'active') : pill('grey', 'off') ?></td><td class="hint"><?= count(json_decode((string)$x['questions'], true) ?: []) ?> Q · ≥<?= (int)$x['pass_threshold'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div><?php if ($s || isset($_GET['new'])): ?><form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_set_save"><input type="hidden" name="id" value="<?= $id ?>">
  <label><?= tt('rc.code') ?> *<input name="code" required maxlength="40" value="<?= e((string)($s['code'] ?? '')) ?>" placeholder="ENG-STD-7"></label><label><?= tt('rc.set_title') ?> *<input name="title" required maxlength="120" value="<?= e((string)($s['title'] ?? '')) ?>"></label>
  <label><?= tt('rc.pass_threshold') ?><input type="number" name="pass_threshold" min="1" value="<?= (int)($s['pass_threshold'] ?? 6) ?>"></label><label><?= tt('rc.knockout_groups') ?> <span class="hint"><?= tt('rc.comma') ?></span><input name="knockout_groups" value="<?= e((string)($s['knockout_groups'] ?? 'loyalty')) ?>"></label>
  <label class="span2"><?= tt('rc.questions_json') ?> <span class="hint"><?= tt('rc.questions_hint') ?></span><textarea name="questions" rows="18" style="font-family:monospace;font-size:12px;"><?= e($s ? json_encode(json_decode((string)$s['questions'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : "[\n  {\"id\":\"q1\",\"group\":\"tech\",\"text\":\"…\",\"options\":[{\"id\":\"a\",\"text\":\"…\",\"score\":1},{\"id\":\"b\",\"text\":\"…\",\"score\":0}]}\n]") ?></textarea></label>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= ($s['is_active'] ?? 1) ? ' checked' : '' ?>> active</label>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button></div></form>
<?php else: ?><div class="card empty"><?= tt('rc.pick_set') ?></div><?php endif; ?></div></div>
<?php layout_foot();
