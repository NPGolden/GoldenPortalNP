<?php
/** HR Assistant — one flow: the guided wizard for the employee (steps · assistant · documents · review · submit) and the HR review
 *  (field-by-field diff, documents, return / reject / approve & apply, log). GAS-CP-R67-001 */
require __DIR__ . '/_layout.php';
$u = require_staff(); $me = (int)$u['id'];
$f = function_exists('hra_has') && hra_has() ? hra_flow((int)($_GET['id'] ?? 0)) : null;
if (!$f || !hra_can_view($f)) { http_response_code($f ? 403 : 404); layout_head(t('hra.title'), 'hra'); echo '<div class="flash flash--warn">' . tt($f ? 'err.noaccess' : 'err.notfound') . '</div>'; layout_foot(); return; }
$manage = hra_can_manage(); $own = (int)$f['user_id'] === $me; $p = hr_person((int)$f['person_id']) ?? []; $tpl = hra_template($f['kind'], (string)$f['branch']); $steps = $tpl['steps']; $nSteps = count($steps);
$fields = hra_fields((int)$f['id']); $docs = hra_docs((int)$f['id']); $meta = hra_field_meta(); $editing = $own && $f['status'] === 'draft';
$step = (int)($_GET['step'] ?? ($editing ? ($f['consent_at'] ? min(max(1, (int)$f['step']), $nSteps) : 0) : $nSteps)); if ($step < 0) $step = 0; if ($step > $nSteps) $step = $nSteps; if ($editing && !$f['consent_at']) $step = 0;
$title = $f['code'] . ' · ' . (string)($f['name_en'] ?: $f['title']);
layout_head(t('hra.title') . ' · ' . $f['code'], 'hra');
$ai = function_exists('ai_can') && ai_can('hra') && $editing;
$sugg = $_SESSION['hra_ai'][(int)$f['id']][$step - 1] ?? null;
$val = function (string $k) use ($fields, $p): string { return isset($fields[$k]) ? (string)$fields[$k]['new_value'] : hra_current($p, $k); };
$inp = function (string $k, string $v) use ($meta): string {
    $m = $meta[$k]; $id = 'f-' . $k; $attr = 'name="f[' . $k . ']" id="' . $id . '"';
    if ($m[0] === 'select') { $o = ''; foreach ($m[3] as $ov => $ol) $o .= '<option value="' . e((string)$ov) . '"' . ((string)$ov === $v ? ' selected' : '') . '>' . e($ol === '—' ? '' : (str_contains((string)$ol, '.') ? t((string)$ol) : (string)$ol)) . '</option>'; return '<select ' . $attr . ' data-nosearch>' . $o . '</select>'; }
    $type = in_array($m[0], ['date', 'email', 'tel', 'number'], true) ? $m[0] : 'text'; $extra = $m[0] === 'number' ? ' min="0" max="12"' : ' maxlength="' . (int)$m[1] . '"';
    if ($k === 'amka' || $k === 'afm') $extra .= ' inputmode="numeric" pattern="\d{' . ($k === 'amka' ? 11 : 9) . '}"'; if ($k === 'iban') $extra .= ' style="text-transform:uppercase"';
    return '<input type="' . $type . '" ' . $attr . ' value="' . e($v) . '"' . $extra . ' autocomplete="off">';
};
?>
<p class="eyebrow"><a href="<?= url('hra') ?>">🤖 <?= tt('hra.title') ?></a> › <?= e($f['code']) ?></p>
<div class="page-head"><div><h1><?= e(t('hra.k_' . $f['kind'])) ?> · <?= $manage && $f['person_id'] ? '<a href="' . url('hr_person', ['id' => (int)$f['person_id']]) . '">' . e((string)$f['name_en']) . '</a>' : e((string)($f['name_en'] ?: $f['title'])) ?> <?= hra_status_pill($f['status']) ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= e($f['code']) ?> · <?= tt('hra.opened') ?> <?= e(fmt_date(substr((string)$f['created_at'], 0, 10))) ?><?= $f['submitted_at'] ? ' · ' . tt('hra.submitted_at') . ' ' . e(fmt_datetime($f['submitted_at'])) : '' ?><?= $f['decided_at'] ? ' · ' . tt('hra.decided') . ' ' . e(fmt_datetime($f['decided_at'])) : '' ?></p></div>
  <div class="btn-row"><?php if ($own && in_array($f['status'], ['draft', 'hr', 'submitted'], true)): ?><form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock data-confirm="<?= e(t('hra.withdraw_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hra_withdraw"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✖ <?= tt('hra.withdraw') ?></button></form><?php endif; ?></div>
</div>
<?php if ($f['decision_note'] && in_array($f['status'], ['draft', 'rejected', 'applied'], true)): ?><div class="flash <?= $f['status'] === 'rejected' ? 'flash--bad' : 'flash--warn' ?>"><b><?= tt($f['status'] === 'draft' ? 'hra.returned_note' : 'hra.decision_note') ?>:</b> <?= e($f['decision_note']) ?></div><?php endif; ?>

<?php if ($editing): /* ============================================================ filling side */ ?>
<nav class="tabs hra-steps" style="margin-bottom:12px;flex-wrap:wrap;"><a class="tab<?= $step === 0 ? ' is-on' : '' ?>" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => 0]) ?>">🔏 <?= tt('hra.step_welcome') ?></a>
<?php foreach ($steps as $i => $s): $n = $i + 1; $reach = $f['consent_at'] && $n <= max(1, (int)$f['step']); ?><?= $reach ? '<a class="tab' . ($step === $n ? ' is-on' : '') . '" href="' . url('hra_flow', ['id' => (int)$f['id'], 'step' => $n]) . '">' : '<span class="tab is-off" style="opacity:.5">' ?><?= e($s['icon']) ?> <?= e($s['title'] ?? t('hra.step_' . $s['key'])) ?><?= $reach ? '</a>' : '</span>' ?><?php endforeach; ?></nav>

<?php if ($step === 0): ?>
<div class="card" id="hra-welcome">
  <h2>👋 <?= e(t('hra.welcome_title', ['n' => (string)($u['full_name'] ?? '')])) ?></h2>
  <p><?= tt('hra.welcome_1') ?></p>
  <ol class="hint" style="padding-left:18px;"><?php foreach ($steps as $s): ?><li><?= e($s['icon']) ?> <?= e($s['title'] ?? t('hra.step_' . $s['key'])) ?> — <?= e(t('hra.stepd_' . $s['key'])) ?></li><?php endforeach; ?></ol>
  <h3 class="ac-h">🔏 <?= tt('hra.privacy_title') ?></h3>
  <p class="hint"><?= tt('hra.privacy_1') ?></p><p class="hint"><?= tt('hra.privacy_2') ?></p><p class="hint"><?= tt('hra.privacy_3') ?></p>
  <?php if (!$f['consent_at']): ?>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hra_consent"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <label class="chk" style="margin-top:10px;display:block;"><input type="checkbox" name="consent" value="1" required> <?= tt('hra.consent_label') ?></label>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('hra.begin') ?> ▶</button></div>
  </form>
  <?php else: ?><p class="hint">✅ <?= e(t('hra.consent_given', ['d' => fmt_datetime($f['consent_at'])])) ?></p><div class="btn-row"><a class="btn btn--gold" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => 1]) ?>"><?= tt('btn.next') ?> ▶</a></div><?php endif; ?>
</div>

<?php elseif (!empty($steps[$step - 1]['fields'])): $s = $steps[$step - 1]; $needTotp = !empty($s['stepup']) && !empty($u['totp_enabled']); ?>
<div class="cols">
<div class="card" id="hra-step">
  <h2><?= e($s['icon']) ?> <?= e($s['title'] ?? t('hra.step_' . $s['key'])) ?> <span class="hint" style="font-weight:normal;">· <?= $step ?>/<?= $nSteps ?></span></h2>
  <p class="hint"><?= e(t('hra.stepd_' . $s['key'])) ?> <?= tt('hra.prefilled_hint') ?></p>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock id="hra-form"><?= csrf_field() ?><input type="hidden" name="action" value="hra_step"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="step" value="<?= $step - 1 ?>">
    <div class="grid grid-2">
    <?php foreach ($s['fields'] as $k): $cur = hra_current($p, $k); $v = $val($k); $prop = isset($fields[$k]); $req = in_array($k, $tpl['required'], true); ?>
      <div class="field" id="fw-<?= e($k) ?>"><label for="f-<?= e($k) ?>"><?= e(hra_label($k)) ?><?= $req ? ' <span class="req" title="' . e(t('hra.required')) . '">*</span>' : '' ?><?= $prop ? ' ' . pill($fields[$k]['source'] === 'user' ? 'gold' : 'blue', t('hra.src_' . $fields[$k]['source'])) : '' ?></label>
        <?= $inp($k, $v) ?><input type="hidden" name="src[<?= e($k) ?>]" value="<?= e($prop ? $fields[$k]['source'] : 'user') ?>">
        <?php if (!empty($meta[$k][4])): ?><span class="hint"><?= e(t($meta[$k][4])) ?></span><?php endif; ?>
        <?php if ($prop && $cur !== ''): ?><span class="hint"><?= tt('hra.on_card') ?>: <s><?= e(hra_show($k, $cur)) ?></s></span><?php elseif ($cur === '' && !$prop): ?><span class="hint"><?= tt('hra.empty_on_card') ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if ($needTotp): ?><div class="field" style="max-width:260px;margin-top:8px;"><label for="totp">🔐 <?= tt('hra.totp_label') ?></label><input id="totp" name="totp" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required><span class="hint"><?= tt('hra.totp_hint') ?></span></div><?php endif; ?>
    <div class="btn-row" style="margin-top:12px;"><?php if ($step > 1): ?><button class="btn btn--ghost" type="submit" name="back" value="1" formnovalidate>◀ <?= tt('hra.back') ?></button><?php endif; ?><button class="btn btn--gold" type="submit"><?= tt('hra.save_next') ?> ▶</button></div>
  </form>
</div>
<?php if ($ai): ?>
<div class="card" id="hra-ai">
  <h2>✦ <?= tt('hra.assist_title') ?></h2>
  <p class="hint"><?= tt('hra.assist_lede') ?></p>
  <?php if ($sugg && $sugg['fields']): ?>
  <div class="flash flash--warn" style="margin:8px 0;"><b><?= tt('hra.assist_found') ?></b><?= $sugg['notes'] ? '<br><span class="hint">' . e($sugg['notes']) . '</span>' : '' ?></div>
  <div class="table-scroll"><table class="tbl"><tbody>
  <?php foreach ($sugg['fields'] as $k => $v): ?><tr><td><?= e(hra_label($k)) ?></td><td><b><?= e(hra_show($k, $v)) ?></b></td><td class="row-actions"><button type="button" class="btn btn--ghost btn--sm hra-use" data-f="<?= e($k) ?>" data-v="<?= e($v) ?>" data-src="<?= e($sugg['src']) ?>"><?= tt('hra.use') ?></button></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="btn-row" style="margin:8px 0;"><button type="button" class="btn btn--gold btn--sm" id="hra-use-all"><?= tt('hra.use_all') ?></button></div>
  <p class="hint"><?= tt('hra.assist_confirm') ?></p>
  <?php endif; ?>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hra_assist"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="step" value="<?= $step - 1 ?>">
    <div class="field"><label><?= tt('hra.assist_text') ?></label><textarea name="text" rows="4" maxlength="6000" data-plain placeholder="<?= e(t('hra.assist_ph')) ?>"></textarea></div>
    <?php if (in_array($s['key'], ['identity', 'ids', 'contact'], true)): ?><div class="field"><label><?= tt('hra.assist_image') ?></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"><span class="hint"><?= tt('hra.assist_image_hint') ?></span></div><?php endif; ?>
    <div class="btn-row"><button class="btn btn--ghost" type="submit">✦ <?= tt('hra.assist_go') ?></button></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){var form=document.getElementById('hra-form');function use(b){var k=b.getAttribute('data-f'),v=b.getAttribute('data-v'),src=b.getAttribute('data-src');var el=form.querySelector('[name="f['+k+']"]');if(!el)return;el.value=v;var h=form.querySelector('[name="src['+k+']"]');if(h)h.value=src;var w=document.getElementById('fw-'+k);if(w){w.style.outline='2px solid var(--gold,#c9a44a)';w.style.outlineOffset='4px';}b.disabled=true;b.textContent='✓';}
document.querySelectorAll('.hra-use').forEach(function(b){b.addEventListener('click',function(){use(b);});});var all=document.getElementById('hra-use-all');if(all)all.addEventListener('click',function(){document.querySelectorAll('.hra-use').forEach(use);});})();
</script>
<?php endif; ?>
</div>

<?php elseif (isset($steps[$step - 1]['docs'])): $s = $steps[$step - 1]; ?>
<div class="cols">
<div class="card" id="hra-docs">
  <h2><?= e($s['icon']) ?> <?= e($s['title'] ?? t('hra.step_docs')) ?> <span class="hint" style="font-weight:normal;">· <?= $step ?>/<?= $nSteps ?></span></h2>
  <p class="hint"><?= tt('hra.docs_lede') ?></p>
  <?php if (!$docs): ?><p class="hint"><?= tt('hra.docs_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.doc_kind') ?></th><th><?= tt('hra.doc_name') ?></th><th class="num"><?= tt('hra.size') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($docs as $d): ?><tr><td><?= e(t('hr.dk_' . $d['kind'])) ?></td><td><a href="<?= url('hra_doc', ['id' => (int)$d['id']]) ?>" target="_blank" rel="noopener"><?= e($d['name']) ?></a></td><td class="num hint"><?= number_format((int)$d['size'] / 1024) ?> KB</td><td class="row-actions"><form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock data-confirm="<?= e(t('hra.doc_del_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hra_doc_del"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="doc" value="<?= (int)$d['id'] ?>"><input type="hidden" name="step" value="<?= $step ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" enctype="multipart/form-data" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="hra_doc"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="step" value="<?= $step ?>">
    <div class="grid grid-2"><div class="field"><label><?= tt('hr.doc_kind') ?></label><select name="kind" data-nosearch><?php foreach ($s['docs'] as $k): ?><option value="<?= e($k) ?>"><?= e(t('hr.dk_' . $k)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hra.doc_file') ?></label><input type="file" name="doc[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple required><span class="hint"><?= tt('hra.doc_hint') ?></span></div></div>
    <div class="btn-row"><button class="btn btn--ghost" type="submit">⇪ <?= tt('hra.upload') ?></button></div>
  </form>
  <div class="btn-row" style="margin-top:14px;"><a class="btn btn--ghost" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => $step - 1]) ?>">◀ <?= tt('hra.back') ?></a><a class="btn btn--gold" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => $step + 1]) ?>"><?= tt('btn.next') ?> ▶</a></div>
</div>
</div>
<?php if ($step + 1 <= $nSteps && (int)$f['step'] < $step + 1) q('UPDATE hr_flows SET step = ? WHERE id = ? AND step < ?', [$step + 1, (int)$f['id'], $step + 1]); ?>

<?php else: /* review & submit */ $miss = hra_missing($f, $p, $tpl, $fields); ?>
<div class="cols">
<div class="card" id="hra-review">
  <h2>✅ <?= tt('hra.step_review') ?> <span class="hint" style="font-weight:normal;">· <?= $nSteps ?>/<?= $nSteps ?></span></h2>
  <p class="hint"><?= tt('hra.review_lede') ?></p>
  <?php if (!$fields): ?><p class="hint"><?= tt('hra.no_changes') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hra.field') ?></th><th><?= tt('hra.on_card') ?></th><th><?= tt('hra.proposed') ?></th><th><?= tt('hra.source') ?></th></tr></thead><tbody>
  <?php foreach ($fields as $k => $r): ?><tr><td><?= e(hra_label($k)) ?></td><td class="hint"><?= e(hra_show($k, $r['old_value'])) ?></td><td><b><?= e(hra_show($k, $r['new_value'])) ?></b></td><td><?= pill($r['source'] === 'user' ? 'gold' : 'blue', t('hra.src_' . $r['source'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($docs): ?><p class="hint" style="margin-top:8px;">📎 <?= e(t('hra.docs_attached', ['n' => count($docs)])) ?></p><?php endif; ?>
  <?php if ($miss): ?><div class="flash flash--warn" style="margin-top:10px;"><b><?= tt('hra.missing_title') ?>:</b> <?= e(implode(', ', array_map('hra_label', $miss))) ?></div><?php endif; ?>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="hra_submit"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <label class="chk" style="display:block;"><input type="checkbox" name="declare" value="1" required> <?= tt('hra.declare_label') ?></label>
    <?php if (!empty($u['totp_enabled'])): ?><div class="field" style="max-width:260px;margin-top:8px;"><label for="totp2">🔐 <?= tt('hra.totp_label') ?></label><input id="totp2" name="totp" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required></div><?php endif; ?>
    <div class="btn-row" style="margin-top:12px;"><a class="btn btn--ghost" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => $nSteps - 1]) ?>">◀ <?= tt('hra.back') ?></a><button class="btn btn--gold" type="submit"<?= $miss || (!$fields && !$docs) ? ' disabled' : '' ?>>📨 <?= tt('hra.submit') ?></button></div>
  </form>
</div>
</div>
<?php endif; ?>

<?php else: /* ============================================================ read-only / HR review */ $canDecide = $manage && in_array($f['status'], ['hr', 'submitted'], true); ?>
<?php if ($f['status'] === 'draft'): ?><div class="flash flash--warn"><?= tt($own ? 'hra.draft_own' : 'hra.draft_hr') ?></div><?php if ($own): ?><div class="btn-row" style="margin-bottom:12px;"><a class="btn btn--gold" href="<?= url('hra_flow', ['id' => (int)$f['id'], 'step' => max(0, (int)$f['step'])]) ?>">▶ <?= tt('hra.continue') ?></a></div><?php endif; endif; ?>
<div class="cols">
<div class="card" id="hra-diff">
  <h2>🧾 <?= tt('hra.changes') ?> (<?= count($fields) ?>)</h2>
  <?php if (!$fields): ?><p class="hint"><?= tt('hra.no_changes') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hra.field') ?></th><th><?= tt('hra.on_card') ?></th><th><?= tt('hra.proposed') ?></th><th><?= tt('hra.source') ?></th><th><?= tt('hra.decision') ?></th><?php if ($canDecide): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($fields as $k => $r): $mask = !$manage && !$own; ?><tr id="f-<?= e($k) ?>"<?= $r['decision'] === 'rejected' ? ' class="is-retired"' : '' ?>><td><?= e(hra_label($k)) ?><?= in_array($k, $tpl['required'], true) ? ' <span class="req">*</span>' : '' ?></td><td class="hint"><?= e(hra_show($k, $r['old_value'], $mask)) ?></td><td><b><?= e(hra_show($k, $r['new_value'], $mask)) ?></b></td><td><?= pill($r['source'] === 'user' ? 'gold' : 'blue', t('hra.src_' . $r['source'])) ?></td>
    <td><?= $r['decision'] === 'approved' ? pill('green', t('hra.d_approved')) : ($r['decision'] === 'rejected' ? pill('red', t('hra.d_rejected')) : pill('mute', t('hra.d_pending'))) ?><?= $r['hr_note'] ? '<br><span class="hint">' . e($r['hr_note']) . '</span>' : '' ?></td>
    <?php if ($canDecide): ?><td class="row-actions"><form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;"><?= csrf_field() ?><input type="hidden" name="action" value="hra_field"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="field" value="<?= e($k) ?>"><input name="note" placeholder="<?= e(t('hra.note_ph')) ?>" maxlength="200" style="width:110px;"><button class="btn btn--ghost btn--sm" name="decision" value="approved" title="<?= e(t('hra.d_approved')) ?>">✔</button><button class="btn btn--ghost btn--sm" name="decision" value="rejected" title="<?= e(t('hra.d_rejected')) ?>">✖</button></form></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($canDecide): ?>
  <h3 class="ac-h" style="margin-top:16px;"><?= tt('hra.hr_decision') ?></h3>
  <p class="hint"><?= tt('hra.hr_decision_hint') ?></p>
  <form method="post" action="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>" data-lock id="hra-decide"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="field"><label><?= tt('hra.note') ?></label><textarea name="note" rows="2" maxlength="500" data-plain></textarea></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit" name="action" value="hra_apply" data-confirm="<?= e(t('hra.apply_confirm')) ?>">✅ <?= tt('hra.apply') ?></button><button class="btn btn--ghost" type="submit" name="action" value="hra_return">↩ <?= tt('hra.return') ?></button><button class="btn btn--danger" type="submit" name="action" value="hra_reject" data-confirm="<?= e(t('hra.reject_confirm')) ?>">✖ <?= tt('hra.reject') ?></button></div>
  </form>
  <?php endif; ?>
</div>
<div>
<div class="card" id="hra-docs">
  <h2>📎 <?= tt('hra.docs') ?> (<?= count($docs) ?>)</h2>
  <?php if (!$docs): ?><p class="hint"><?= tt('hra.docs_none') ?></p><?php else: ?><ul style="padding-left:18px;margin:0;"><?php foreach ($docs as $d): ?><li><?= e(t('hr.dk_' . $d['kind'])) ?> · <a href="<?= url('hra_doc', ['id' => (int)$d['id']]) ?>" target="_blank" rel="noopener"><?= e($d['name']) ?></a> <span class="hint"><?= number_format((int)$d['size'] / 1024) ?> KB · <?= e((string)$d['by_name']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($f['status'] === 'applied'): ?><p class="hint" style="margin-top:8px;"><?= tt('hra.docs_filed') ?></p><?php endif; ?>
</div>
<?php if ($manage): ?>
<div class="card" id="hra-log">
  <h2>🕒 <?= tt('hra.log') ?></h2>
  <ul class="hint" style="padding-left:18px;margin:0;"><?php foreach (hra_log_list((int)$f['id']) as $l): ?><li><?= e(fmt_datetime($l['created_at'])) ?> · <b><?= e(t('hra.ev_' . $l['event'])) ?></b><?= $l['full_name'] ? ' · ' . e($l['full_name']) : '' ?><?= $l['detail'] ? ' · ' . e($l['detail']) : '' ?></li><?php endforeach; ?></ul>
  <?php if ($f['consent_at']): ?><p class="hint" style="margin-top:8px;">🔏 <?= e(t('hra.consent_given', ['d' => fmt_datetime($f['consent_at'])])) ?><?= $f['consent_ip'] ? ' · ' . e($f['consent_ip']) : '' ?></p><?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<?php layout_foot();
