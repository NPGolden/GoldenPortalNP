<?php
/** One contact: details, vessels, dates, notes.                 GAS-CP-R17-001   ?p=contact&id=ID */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0);
$c = ct_load($id);
if (!$c) { http_response_code(404); layout_head(t('err.notfound'), 'contacts'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$vessels = all('SELECT v.id, v.name, cu.name AS customer FROM vessels v LEFT JOIN customers cu ON cu.id = v.customer_id ORDER BY cu.name, v.name');
$linked = array_column($c['vessels'], 'vessel_id');
$nb = $c['birthday'] ? ct_next(substr($c['birthday'], 5)) : null; $nn = ct_next($c['nameday_md']); $age = ct_age($c['birthday']);
layout_head($c['full_name'], 'contacts');
?>
<p class="eyebrow"><a href="<?= url('contacts') ?>"><?= tt('ct.title') ?></a><?= $c['customer_name'] ? ' › ' . e($c['customer_name']) : '' ?></p>
<div class="page-head">
  <div style="display:flex;align-items:center;gap:14px;">
    <span class="ava ava--txt" style="width:56px;height:56px;font-size:20px;"><?= e(initials($c['full_name'])) ?></span>
    <div><h1 style="margin:0;"><?= e($c['full_name']) ?> <?= $c['is_active'] ? '' : pill('mute', t('ct.inactive')) ?></h1>
      <p class="lede" style="margin:4px 0 0;"><?= e(implode(' · ', array_filter([$c['position'], $c['company_name'] ?: $c['customer_name']]))) ?> <?= ct_cat_pill($c['category']) ?></p></div>
  </div>
  <div class="btn-row">
    <a class="btn btn--ghost" href="<?= url('contact_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a>
    <?php if (is_cp_admin()): ?><form method="post" action="<?= url('contact', ['id' => $id]) ?>" data-confirm="<?= e(t('ct.delete_confirm', ['name' => $c['full_name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ct_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?>
  </div>
</div>

<div class="cols ct-cols">
<div>
  <div class="card">
    <h2><?= tt('ct.details') ?></h2>
    <div class="oa-facts ct-facts">
      <div><span><?= tt('rp.customer') ?></span><b><?= e($c['customer_name'] ?: '—') ?></b></div>
      <div><span><?= tt('co.one') ?></span><b><?= $c['company_name'] ? e($c['company_name']) . ' ' . ($c['company_kind'] ? co_kind_pill($c['company_kind']) : '') : '—' ?></b></div>
      <div><span><?= tt('ct.position') ?></span><b><?= e($c['position'] ?: '—') ?></b></div>
      <div><span><?= tt('ct.category') ?></span><b><?= ct_cat_pill($c['category']) ?></b></div>
      <div><span><?= tt('au.email') ?></span><b><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></b></div>
      <div><span><?= tt('ct.mobile') ?></span><b><?= $c['mobile'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $c['mobile'])) . '">' . e($c['mobile']) . '</a>' : '—' ?></b></div>
      <div><span><?= tt('ct.phone') ?></span><b><?= $c['phone'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $c['phone'])) . '">' . e($c['phone']) . '</a>' : '—' ?></b></div>
      <div><span><?= tt('ct.portal_account') ?></span><b><?= $c['user_name'] ? e($c['user_name']) . ' <span class="hint">' . e($c['user_email']) . '</span>' : '—' ?></b></div>
      <?php if (co_has()): ?><div><span><?= tt('ct.channels') ?></span><b><?= ct_set_pills($c['channels'] ?? null, ct_channels(), 'mute') ?></b></div>
      <div><span><?= tt('ct.subscriptions') ?></span><b><?= ct_set_pills($c['subscriptions'] ?? null, ct_subscriptions(), 'green') ?></b></div><?php endif; ?>
      <?php if (!empty($c['company_id']) && function_exists('iv_count') && ($nIv = iv_count('company', (int)$c['company_id']))): ?><div><span><?= tt('iv.title') ?></span><b><a href="<?= url('companies', ['edit' => $c['company_id']]) ?>#invoicing"><?= e(t('iv.of_company', ['n' => $nIv, 'c' => $c['company_name']])) ?></a></b></div><?php endif; ?>
    </div>
    <h3 class="ed-h" style="margin-top:18px;"><?= tt('ct.interests') ?></h3>
    <?php if ($c['interests']): ?><p class="ct-text"><?= rt_html($c['interests']) ?></p><?php else: ?><div class="empty"><?= tt('ct.no_interests') ?></div><?php endif; ?>
  </div>

  <div class="card">
    <div class="page-head" style="margin-bottom:8px;"><h2 style="margin:0;"><?= tt('nav.vessels') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($c['vessels']) ?></span></h2></div>
    <?php if (!$c['vessels']): ?><div class="empty"><?= tt('ct.no_vessel') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('v.one') ?></th><th><?= tt('rp.customer') ?></th><th><?= tt('ct.role_on_vessel') ?></th><th></th></tr></thead>
      <tbody><?php foreach ($c['vessels'] as $v): ?>
        <tr><td><a href="<?= url('vessel', ['id' => $v['vessel_id']]) ?>"><b><?= e($v['name']) ?></b></a> <?= $v['is_primary'] ? pill('gold', t('ct.primary')) : '' ?></td><td><?= e($v['customer_name'] ?: '—') ?></td><td><?= e($v['role'] ?: '—') ?></td>
          <td class="row-actions"><form method="post" action="<?= url('contact', ['id' => $id]) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ct_vessel_unlink"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="vessel_id" value="<?= (int)$v['vessel_id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ct.unlink') ?></button></form></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <form method="post" action="<?= url('contact', ['id' => $id]) ?>" class="fltbar" style="grid-template-columns: 2fr 1.4fr auto; margin-top:12px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="ct_vessel_link"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label for="vessel_id"><?= tt('ct.add_vessel') ?></label><select id="vessel_id" name="vessel_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($vessels as $v): if (in_array((int)$v['id'], $linked, true)) continue; ?><option value="<?= $v['id'] ?>"><?= e($v['name'] . ($v['customer'] ? ' · ' . $v['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="role"><?= tt('ct.role_on_vessel') ?></label><input id="role" name="role" maxlength="80" placeholder="<?= tt('ct.role_ph') ?>"></div>
      <div class="fltbar-actions"><button class="btn btn--ghost" type="submit">+ <?= tt('ct.link') ?></button></div>
    </form>
  </div>
</div>

<div>
  <div class="card card--flat">
    <?php if (function_exists('spc_for_contact') && ($spcs = spc_for_contact($id))): ?><h3 class="ed-h"><?= tt('spc.on_contact') ?> <span class="hint"><?= count($spcs) ?></span></h3>
    <ul class="eq-ct"><?php foreach ($spcs as $x): ?><li><a href="<?= url('subproject', ['id' => (int)$x['id'], 'tab' => 'contacts']) ?>"><b><?= e(trim(($x['project_code'] ?: $x['project_name']) . ' › ' . $x['code'] . ' ' . $x['name'])) ?></b></a><?= $x['role'] ? ' · ' . e($x['role']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    <h3 class="ed-h"><?= tt('ct.dates') ?></h3>
    <div class="ct-dates">
      <div><span>🎂 <?= tt('ct.birthday') ?></span><b><?= e(ct_fmt_birthday($c['birthday'])) ?><?= $age !== null ? ' <span class="hint">(' . e(t('ct.age', ['n' => $age])) . ')</span>' : '' ?></b><?= $nb ? '<em>' . ($nb['days'] === 0 ? tt('dash.today') . '!' : e(t('ct.in_days', ['n' => $nb['days']]))) . '</em>' : '' ?></div>
      <div><span>🎉 <?= tt('ct.nameday') ?></span><b><?= e(ct_fmt_md($c['nameday_md'])) ?></b><?= $nn ? '<em>' . ($nn['days'] === 0 ? tt('dash.today') . '!' : e(t('ct.in_days', ['n' => $nn['days']]))) . '</em>' : '' ?></div>
    </div>
  </div>

  <div class="card">
    <h2><?= tt('ct.notes') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($c['notes']) ?></span></h2>
    <form method="post" action="<?= url('contact', ['id' => $id]) ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="ct_note"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><textarea data-mt name="body" rows="3" required placeholder="<?= tt('ct.note_ph') ?>"></textarea></div>
      <button class="btn btn--gold btn--sm" type="submit"><?= tt('ct.add_note') ?></button>
    </form>
    <?php if (!$c['notes']): ?><div class="empty" style="margin-top:12px;"><?= tt('ct.no_notes') ?></div><?php else: ?>
    <div class="ct-notes">
      <?php foreach ($c['notes'] as $n): ?>
      <div class="msg">
        <div class="who"><?= avatar_html((int)$n['author_id'], (string)($n['author'] ?: '?'), 24, 'ava--inline') ?> <b><?= e($n['author'] ?: '—') ?></b> <span class="hint"><?= e(fmt_datetime($n['created_at'])) ?></span>
          <?php if ((int)$n['author_id'] === (int)$u['id'] || is_cp_admin()): ?><form method="post" action="<?= url('contact', ['id' => $id]) ?>" style="display:inline;margin-left:auto;" data-confirm="<?= tt('ct.note_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ct_note_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>"><button class="linkbtn linkbtn--danger" type="submit">✕</button></form><?php endif; ?></div>
        <div class="body"><?= mt_html($n['body']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if (function_exists('iv_section') && (is_cp_admin() || iv_count('contact', $id))) iv_section('contact', $id, url('contact', ['id' => $id]), (int)($_GET['iv'] ?? 0)); ?>
</div>
</div>
<?php layout_foot(); ?>
