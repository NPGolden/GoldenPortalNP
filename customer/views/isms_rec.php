<?php
/** ISO 27001 — one record of a register: fields, files, history.   GAS-CP-R97-080   ?p=isms_rec&id=   |   ?p=isms_rec&reg=  (new)   |   ?p=isms_rec&file= (download) */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('ism_can') || !ism_can()) { http_response_code(403); layout_head(t('ism.title'), 'isms'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
if (!empty($_GET['file'])) { ism_file_send((int)$_GET['file']); exit; }
$id = (int)($_GET['id'] ?? 0); $r = $id ? ism_get($id) : null; $reg = $r ? (string)$r['reg'] : (string)($_GET['reg'] ?? ''); $R = ism_reg($reg);
if (!$R || ($id && !$r)) { http_response_code(404); layout_head(t('ism.title'), 'isms'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$d = $r ? ism_data($r) : []; $users = ism_users(); $self = url('isms_rec', $id ? ['id' => $id] : ['reg' => $reg]); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$files = $id ? ism_files($id) : []; $events = $id ? ism_events($id) : []; $why = $r ? ism_due($r) : null;
layout_head(($r ? $r['code'] . ' · ' : '') . ism_reg_title($reg), 'isms');
?>
<p class="eyebrow"><a href="<?= url('isms') ?>"><?= tt('ism.title') ?></a> › <a href="<?= url('isms', ['reg' => $reg]) ?>"><?= e(ism_reg_title($reg)) ?></a></p>
<div class="page-head"><div><h1><?= $R['icon'] ?> <?= $r ? '<span class="mono">' . e($r['code']) . '</span> ' . e($r['title']) : e(t('ism.new_in', ['r' => ism_reg_title($reg)])) ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= $r ? ism_status_pill($reg, (string)$r['status']) . ' ' : '' ?><span class="hint" style="display:inline;"><?= e($R['clause']) ?></span><?= $why ? ' ' . pill($why === 'due' ? 'bad' : 'gold', t($why === 'due' ? 'ism.w_due' : 'ism.w_review')) : '' ?></p></div>
  <?php if ($r && ism_manage()): ?><div class="btn-row"><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= e(t('ism.delete_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ism_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('btn.delete') ?></button></form></div><?php endif; ?></div>

<div class="cols" style="grid-template-columns: 1.6fr 1fr;">
<div class="card">
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_save"><input type="hidden" name="reg" value="<?= e($reg) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('ism.code') ?></label><input name="code" maxlength="40" value="<?= e((string)($r['code'] ?? '')) ?>" placeholder="<?= e(ism_next_code($reg)) ?>"></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('ism.title_f') ?> *</label><input name="title" maxlength="255" required value="<?= e((string)($r['title'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach ($R['st'] as $sk => $_): ?><option value="<?= $sk ?>"<?= $sel($sk, $r['status'] ?? array_key_first($R['st'])) ?>><?= tt('ism.s_' . $sk) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('ism.owner') ?></label><select name="owner_user_id"><option value="">—</option><?php foreach ($users as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $r['owner_user_id'] ?? '') ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!empty($R['ref']) || $reg === 'soa'): ?><div class="field"><label><?= tt($reg === 'soa' ? 'ism.theme' : 'ism.ref') ?></label><input name="ref" maxlength="60" value="<?= e((string)($r['ref'] ?? '')) ?>" placeholder="<?= $reg === 'soa' ? '' : tt('ism.ref_ph') ?>"></div><?php endif; ?>
      <?php if (!empty($R['due'])): ?><div class="field"><label><?= tt('ism.due_' . $reg) === 'ism.due_' . $reg ? tt('ism.due_date') : tt('ism.due_' . $reg) ?></label><input type="date" name="due_date" value="<?= e((string)($r['due_date'] ?? '')) ?>"></div><?php endif; ?>
      <?php if (!empty($R['review'])): ?><div class="field"><label><?= tt('ism.review_date') ?></label><input type="date" name="review_date" value="<?= e((string)($r['review_date'] ?? '')) ?>"></div><?php endif; ?>
    </div>
    <div class="grid grid-2" style="margin-top:6px;">
      <?php foreach ($R['f'] as $fk => $type): $v = $d[$fk] ?? ''; $full = $type === 'area'; ?>
      <div class="field"<?= $full ? ' style="grid-column: 1 / -1;"' : '' ?>><label><?= tt('ism.f_' . $fk) ?></label>
        <?php if ($type === 'area'): ?><textarea name="d[<?= $fk ?>]" rows="3" maxlength="6000"><?= e((string)$v) ?></textarea>
        <?php elseif ($type === 'date'): ?><input type="date" name="d[<?= $fk ?>]" value="<?= e((string)$v) ?>">
        <?php elseif ($type === 'num'): ?><input type="number" step="any" name="d[<?= $fk ?>]" value="<?= e((string)$v) ?>">
        <?php elseif ($type === 'score'): ?><select name="d[<?= $fk ?>]" data-nosearch><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= $sel($i, $v) ?>><?= $i ?> — <?= tt('ism.sc_' . $i) ?></option><?php endfor; ?></select>
        <?php elseif (str_starts_with($type, 'sel:')): ?><select name="d[<?= $fk ?>]" data-nosearch><option value="">—</option><?php foreach (ism_opts($type) as $o): ?><option value="<?= $o ?>"<?= $sel($o, $v) ?>><?= tt('ism.o_' . $o) ?></option><?php endforeach; ?></select>
        <?php else: ?><input name="d[<?= $fk ?>]" maxlength="255" value="<?= e((string)$v) ?>"><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($reg === 'risk'): $s1 = ism_score($d); $s2 = ism_score($d, 'res_'); [$t1, $l1] = ism_level($s1); [$t2, $l2] = ism_level($s2); ?><p class="hint"><?= tt('ism.score') ?>: <?= $s1 ? pill($t1, $s1 . ' · ' . $l1) : '—' ?> · <?= tt('ism.res_score') ?>: <?= $s2 ? pill($t2, $s2 . ' · ' . $l2) : '—' ?> — <?= tt('ism.score_hint') ?></p><?php endif; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('isms', ['reg' => $reg]) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<div>
<?php if ($r): ?>
<div class="card" id="files">
  <h2>📎 <?= tt('ism.files') ?> <span class="count"><?= count($files) ?></span></h2>
  <?php if (!$files): ?><p class="hint"><?= tt('ism.files_none') ?></p><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($files as $f): ?><tr><td><a href="<?= url('isms_rec', ['file' => (int)$f['id']]) ?>"><b><?= e($f['original_name']) ?></b></a><?= $f['note'] ? '<br><span class="hint">' . e($f['note']) . '</span>' : '' ?><br><span class="hint"><?= e((string)$f['uploaded_name']) ?> · <?= e(fmt_datetime($f['created_at'])) ?> · <?= number_format((int)$f['size_bytes'] / 1024, 0, ',', '.') ?> KB</span></td>
    <td class="num"><form method="post" action="<?= $self ?>#files" class="inline" data-lock data-confirm="<?= e(t('ism.file_del_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ism_file_del"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <form method="post" action="<?= $self ?>#files" enctype="multipart/form-data" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="ism_file_add"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><input type="file" name="file" required></div><div class="field"><input name="note" maxlength="255" placeholder="<?= tt('ism.file_note_ph') ?>"></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">⤒ <?= tt('ism.file_add') ?></button><span class="hint"><?= tt('ism.file_hint') ?></span></div>
  </form>
</div>
<div class="card">
  <h2>🕘 <?= tt('ism.history') ?></h2>
  <ul class="hint" style="margin:0;padding-left:18px;"><?php foreach ($events as $ev): ?><li><?= e(fmt_datetime($ev['created_at'])) ?> · <b><?= tt('ism.ev_' . $ev['event']) ?></b> · <?= e((string)$ev['user_name']) ?><?= $ev['note'] ? ' — ' . e($ev['note']) : '' ?></li><?php endforeach; ?></ul>
</div>
<?php else: ?><div class="card"><p class="hint"><?= tt('ism.h_' . $reg) ?></p><p class="hint"><?= tt('ism.new_hint') ?></p></div><?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>
