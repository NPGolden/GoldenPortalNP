<?php
/** Function check — fill the OEM commissioning sheet on line (new / draft).   GAS-CP-R77-001   ?p=fc_edit[&id=|&project=&sub=&form=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('fc_has') || !fc_has()) { http_response_code(404); layout_head(t('fc.title'), 'reports'); echo '<h1>' . tt('fc.need_db') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $r = $id ? fc_load($id) : null;
if ($id && (!$r || $r['status'] !== 'draft')) { http_response_code(404); layout_head(t('fc.title'), 'reports'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$form = $r ? (string)$r['form'] : (string)($_GET['form'] ?? 'marex3'); $tpl = fc_form($form); if (!$tpl) { http_response_code(404); layout_head(t('fc.title'), 'reports'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$pid = $r ? (int)$r['project_id'] : (int)($_GET['project'] ?? 0); if ($r && (int)($_GET['project'] ?? 0)) $pid = (int)$_GET['project'];
$sid = $r ? (int)($r['subproject_id'] ?? 0) : (int)($_GET['sub'] ?? 0);
[$ps, $pa] = scope_sql('p'); $projOpts = all("SELECT p.id, p.project_code, p.name, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.status <> 'completed' AND {$ps} ORDER BY p.project_code DESC, p.name", $pa);
if (!$pid && $projOpts) $pid = (int)$projOpts[0]['id'];
$p = $pid ? one('SELECT p.*, v.name AS vessel, v.imo, v.yard AS vessel_yard, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]) : null;
if (!$p) { layout_head(t('fc.title'), 'reports'); echo '<div class="card"><h2>' . tt('fc.title') . '</h2><p>' . tt('doc.need_project') . '</p></div>'; layout_foot(); return; }
if (!array_filter($projOpts, fn($x) => (int)$x['id'] === $pid)) $projOpts[] = ['id' => $pid, 'project_code' => $p['project_code'], 'name' => $p['name'], 'vessel' => $p['vessel']];
$subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
$D = $r ? $r['data'] : ['head' => fc_prefill($tpl, $p, $u), 'chk' => [], 'free' => [], 'val' => [], 'extra' => [], 'closing' => 0];
if ($r && (int)($_GET['project'] ?? 0)) foreach (['vessel' => 'vessel', 'imo' => 'imo', 'project_no' => 'project_code'] as $k => $col) $D['head'][$k] = (string)($p[$col] ?? '');
$self = url('fc_edit', $r ? ['id' => $id] : ['project' => $pid, 'form' => $form]); $sides = $tpl['sides'];
$tri = function (string $name, string $cur) { $o = ''; foreach (['' => '–', 'ok' => '✓', 'na' => '✗'] as $v => $lab) $o .= '<label class="fc-t fc-t-' . ($v ?: 'x') . '"><input type="radio" name="' . $name . '" value="' . $v . '"' . ($cur === $v ? ' checked' : '') . '><span>' . $lab . '</span></label>'; return '<span class="fc-tri">' . $o . '</span>'; };
layout_head(($r ? $r['report_no'] : t('fc.new')) . ' · ' . $tpl['short'], 'reports');
?>
<p class="eyebrow"><a href="<?= url('reports') ?>"><?= tt('r.title') ?></a> › <?= tt('fc.plural') ?> › <?= $r ? e($r['report_no']) : tt('fc.new') ?></p>
<div class="page-head"><div><h1>🧪 <?= e($tpl['title']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($tpl['maker']) ?> · <?= e($tpl['code']) ?><?= $r ? ' · <span class="mono">' . e($r['report_no']) . '</span> R' . (int)$r['version'] : '' ?></p></div><div class="actions"><?= $r ? fc_status_pill($r['status']) : '' ?></div></div>
<form method="post" action="<?= url('fc', ['id' => $id]) ?>" data-lock id="fcform" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="fc_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="form" value="<?= e($form) ?>">
<div class="card">
  <div class="grid grid-4">
    <div class="field"><label for="project_id"><?= tt('pr.one') ?></label><select name="project_id" id="project_id"><?php foreach ($projOpts as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)$x['id'] === $pid ? ' selected' : '' ?>><?= e(trim(($x['project_code'] ?: '') . ' ' . $x['name']) . ($x['vessel'] ? ' · ' . $x['vessel'] : '')) ?></option><?php endforeach; ?></select>
      <script nonce="<?= csp_nonce() ?>">document.getElementById('project_id').addEventListener('change', function () { var u = new URL(location.href); u.searchParams.set('project', this.value); u.searchParams.delete('sub'); location.href = u.toString(); });</script></div>
    <div class="field"><label><?= tt('sp.one') ?></label><select name="subproject_id" data-nosearch><option value="">— <?= tt('fsr.whole_project') ?></option><?php foreach ($subs as $sp): ?><option value="<?= (int)$sp['id'] ?>"<?= (int)$sp['id'] === $sid ? ' selected' : '' ?>><?= e($sp['code'] . ' ' . $sp['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="report_date" value="<?= e($r ? (string)$r['report_date'] : date('Y-m-d')) ?>"></div>
    <div class="field"><label><?= tt('fc.customer_name') ?></label><input name="customer_name" maxlength="120" value="<?= e((string)($r['customer_name'] ?? '')) ?>" placeholder="<?= tt('fc.customer_name_ph') ?>"></div>
  </div>
  <div class="grid grid-2 fc-head">
    <?php foreach ($tpl['head'] as [$k, $lab, $kind]): ?><div class="field"><label><?= e($lab) ?></label><input type="<?= $kind === 'date' ? 'date' : 'text' ?>" name="head[<?= $k ?>]" maxlength="120" value="<?= e((string)($D['head'][$k] ?? '')) ?>"></div><?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt('fc.checks') ?></h2>
  <p class="hint"><?= e($tpl['intro']) ?> <?= tt('fc.tri_hint') ?></p>
  <div class="fc-grid">
    <?php foreach ($sides as $s => $sName): ?>
    <div class="fc-side"><h3><?= e($sName) ?></h3>
      <?php foreach ($tpl['groups'] as $g): ?>
      <table class="fc-tbl" data-nosort><thead><tr><th colspan="3"><?= e($g['title']) ?></th></tr></thead><tbody>
      <?php foreach ($g['rows'] as $i => $row): ?><tr><td class="fc-n"><?= $i + 1 ?></td>
        <?php foreach ($row as $j => $lab): $k = $g['key'] . '.' . $i . '.' . $j; ?><td><?= $tri("chk[$s][$k]", (string)($D['chk'][$s][$k] ?? '')) ?> <?= $lab !== null ? e($lab) : '<input class="fc-free" name="free[' . $s . '][' . $k . ']" maxlength="40" placeholder="…" value="' . e((string)($D['free'][$s][$k] ?? '')) . '">' ?></td><?php endforeach; ?>
      </tr><?php endforeach; ?>
      </tbody></table>
      <?php endforeach; ?>
      <table class="fc-tbl" data-nosort><thead><tr><th colspan="4"><?= e($tpl['values_title']) ?></th></tr></thead><tbody>
      <?php foreach ($tpl['values'] as [$k, $lab, $u1, $u2]): $v = $D['val'][$s][$k] ?? []; ?><tr><td class="fc-n"><?= e($k) ?></td><td><?= $lab !== null ? e($lab) : '<input class="fc-free" name="val[' . $s . '][' . $k . '][label]" maxlength="40" placeholder="…" value="' . e((string)($v['label'] ?? '')) . '">' ?></td>
        <td class="fc-v"><input name="val[<?= $s ?>][<?= $k ?>][v1]" maxlength="20" value="<?= e((string)($v['v1'] ?? '')) ?>" inputmode="decimal"> <span class="hint"><?= e($u1) ?></span></td><td class="fc-v"><input name="val[<?= $s ?>][<?= $k ?>][v2]" maxlength="20" value="<?= e((string)($v['v2'] ?? '')) ?>" inputmode="decimal"> <span class="hint"><?= e($u2) ?></span></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="btn-row" style="margin-top:10px;align-items:center;gap:16px;flex-wrap:wrap;"><?php foreach ($tpl['extra'] as [$k, $lab, $un]): ?><label class="fc-extra"><?= e($lab) ?> <input name="extra[<?= $k ?>]" maxlength="20" inputmode="decimal" value="<?= e((string)($D['extra'][$k] ?? '')) ?>" style="width:90px;"> <?= e($un) ?></label><?php endforeach; ?></div>
  <label class="chk" style="margin-top:10px;"><input type="checkbox" name="closing" value="1"<?= !empty($D['closing']) ? ' checked' : '' ?>> <b><?= e($tpl['closing']) ?></b></label>
  <div class="field" style="margin-top:10px;"><label><?= tt('fc.remarks') ?></label><textarea name="remarks" rows="3" maxlength="4000"><?= e((string)($r['remarks'] ?? '')) ?></textarea></div>
  <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $r ? url('fc', ['id' => $id]) : url('reports') ?>"><?= tt('btn.cancel') ?></a><span class="hint"><?= tt('fc.save_hint') ?></span></div>
</div>
</form>

<?php if ($r): ?>
<div class="card" id="fc-sign">
  <h2>✍ <?= tt('fc.sign_title') ?></h2>
  <p class="hint"><?= tt('fc.sign_lede') ?></p>
  <form method="post" action="<?= url('fc', ['id' => $id]) ?>" data-lock data-confirm="<?= tt('fc.submit_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fc.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fc_submit"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('fc.sign_engineer') ?> — <?= e($u['full_name']) ?></label><div data-sigpad="signature" data-clear="<?= tt('btn.clear') ?>"></div><input type="hidden" name="signature"></div>
      <div class="field"><label><?= tt('fc.sign_customer') ?> <span class="hint">(<?= tt('fc.sign_customer_opt') ?>)</span></label><input name="customer_name" maxlength="120" value="<?= e((string)($r['customer_name'] ?? '')) ?>" placeholder="<?= tt('fc.customer_name_ph') ?>" style="margin-bottom:6px;"><div data-sigpad="customer_signature" data-clear="<?= tt('btn.clear') ?>"></div><input type="hidden" name="customer_signature"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit">✓ <?= tt('fc.submit') ?></button><span class="hint"><?= tt('fc.submit_hint') ?></span></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>
