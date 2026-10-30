<?php
/** Customer contacts — Golden A/S staff only.                 GAS-CP-R17-001
 *  ?p=contacts[&q=&customer=&vessel=&category=&status=&soon=birthday|nameday&page=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!ct_has()) { layout_head(t('ct.title'), 'contacts'); echo '<h1>' . tt('ct.title') . '</h1><div class="flash flash--warn">' . tt('ct.need_r17') . '</div>'; layout_foot(); return; }
$f = ct_filters_from_request($_GET); $co = co_has(); $admin = is_cp_admin();
$companies = $co ? co_all(false) : [];
$l = ct_list($f); $s = ct_summary(); $up = ct_upcoming(30);
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$vessels = all('SELECT v.id, v.name, c.name AS customer FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id ORDER BY c.name, v.name');
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$qs = array_filter($f, fn($v) => $v !== '' && $v !== 1 && $v !== 0); unset($qs['page']);
$emails = array_values(array_unique(array_filter(array_map(fn($r) => $r['email'], $l['rows']))));
$pageUrl = fn(int $n) => url('contacts', $qs + ['page' => $n]);
layout_head(t('ct.title'), 'contacts');
?>
<div class="page-head">
  <div><h1><?= tt('ct.title') ?></h1><p class="lede"><?= tt('ct.lede') ?></p></div>
  <div class="btn-row"><?php if ($co): ?><a class="btn btn--ghost" href="<?= url('companies') ?>">🏢 <?= tt('co.title') ?></a><?php endif; ?><a class="btn btn--ghost" href="<?= url('settings', ['tab' => 'import']) ?>#contacts">⤒ <?= tt('im.title') ?></a><?php if ($admin): ?><a class="btn btn--ghost" href="<?= url('contacts_export', $qs) ?>">⤓ <?= tt('ct.export') ?></a><?php endif; ?><a class="btn btn--gold" href="<?= url('contact_edit') ?>">+ <?= tt('ct.new') ?></a></div>
</div>

<div class="kpi kpi--5">
  <a class="stat" href="<?= url('contacts') ?>"><b><?= (int)$s['n'] ?></b><span class="s"><?= tt('ct.title') ?></span></a>
  <a class="stat" href="<?= url('contacts') ?>"><b><?= (int)$s['companies'] ?></b><span class="s"><?= tt('ct.companies') ?></span></a>
  <a class="stat" href="<?= url('contacts', ['vessel' => '']) ?>"><b><?= (int)$s['vessels'] ?></b><span class="s"><?= tt('ct.vessels_covered') ?></span></a>
  <a class="stat<?= $s['birthdays'] ? ' gold' : '' ?>" href="<?= url('contacts', ['soon' => 'birthday']) ?>"><b><?= (int)$s['birthdays'] ?></b><span class="s"><?= tt('ct.birthdays_30') ?></span></a>
  <a class="stat<?= $s['namedays'] ? ' gold' : '' ?>" href="<?= url('contacts', ['soon' => 'nameday']) ?>"><b><?= (int)$s['namedays'] ?></b><span class="s"><?= tt('ct.namedays_30') ?></span></a>
</div>

<div class="card">
  <form method="get" action="index.php" class="fltbar fltbar--au">
    <input type="hidden" name="p" value="contacts">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('ct.search_ph') ?>"></div>
    <div class="field"><label for="customer"><?= tt('rp.customer') ?></label><select id="customer" name="customer"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['customer']) ?>><?= tt('ct.no_customer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $f['customer']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <?php if ($co): ?><div class="field"><label for="company"><?= tt('co.one') ?></label><select id="company" name="company"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['company']) ?>><?= tt('co.none_sel') ?></option><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $f['company']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label for="vessel"><?= tt('v.one') ?></label><select id="vessel" name="vessel"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['vessel']) ?>><?= tt('ct.no_vessel') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name'] . ($v['customer'] ? ' · ' . $v['customer'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="category"><?= tt('ct.category') ?></label><select id="category" name="category"><option value=""><?= tt('flt.any') ?></option><?php foreach (ct_categories() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $f['category']) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="soon"><?= tt('ct.soon') ?></label><select id="soon" name="soon"><option value=""><?= tt('flt.any') ?></option><option value="birthday"<?= $sel('birthday', $f['soon']) ?>><?= tt('ct.birthdays_30') ?></option><option value="nameday"<?= $sel('nameday', $f['soon']) ?>><?= tt('ct.namedays_30') ?></option></select></div>
    <?php if ($co): ?><div class="field"><label for="channel"><?= tt('ct.channels') ?></label><select id="channel" name="channel"><option value=""><?= tt('flt.any') ?></option><?php foreach (ct_channels() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $f['channel']) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="sub"><?= tt('ct.subscriptions') ?></label><select id="sub" name="sub"><option value=""><?= tt('flt.any') ?></option><?php foreach (ct_subscriptions() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $f['sub']) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label for="status"><?= tt('th.status') ?></label><select id="status" name="status"><option value="active"<?= $sel('active', $f['status']) ?>><?= tt('au.active') ?></option><option value="inactive"<?= $sel('inactive', $f['status']) ?>><?= tt('ct.inactive') ?></option><option value="all"<?= $sel('all', $f['status']) ?>><?= tt('flt.any') ?></option></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('contacts', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="cols ct-cols">
<div class="card">
  <h2><?= tt('ct.list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('au.showing', ['from' => $l['total'] ? ($l['page'] - 1) * $l['per'] + 1 : 0, 'to' => min($l['total'], $l['page'] * $l['per']), 'n' => $l['total']])) ?></span></h2>
  <?php if (!$l['rows']): ?><div class="empty"><?= tt('ct.none') ?></div><?php else: ?>
  <form method="post" action="<?= url('contacts') ?>" id="bulk" data-confirm="<?= tt('ct.bulk_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.apply') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="ct_bulk"><?php foreach ($f as $k => $v) if ($k !== 'page'): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endif; ?>
    <div class="bulkbar">
      <label class="chk"><input type="checkbox" id="chkall"> <?= tt('ct.select_page') ?></label>
      <label class="chk"><input type="checkbox" name="all" value="1"> <?= e(t('ct.select_all', ['n' => $l['total']])) ?></label>
      <select name="op" class="bulk-op">
        <option value=""><?= tt('ct.bulk_choose') ?></option>
        <?php if ($co): foreach (ct_subscriptions() as $k => $lab): ?><option value="sub+<?= $k ?>">+ <?= e($lab) ?></option><?php endforeach; foreach (ct_subscriptions() as $k => $lab): ?><option value="sub-<?= $k ?>">− <?= e($lab) ?></option><?php endforeach; endif; ?>
        <option value="deactivate"><?= tt('ct.bulk_deactivate') ?></option><option value="activate"><?= tt('ct.bulk_activate') ?></option>
        <?php if ($admin): ?><option value="delete">🗑 <?= tt('ct.bulk_delete') ?></option><?php endif; ?>
      </select>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.apply') ?></button>
      <?php if ($emails): ?><a class="btn btn--ghost btn--sm" href="<?= e(function_exists('em_mailto') ? em_mailto($emails, ['vessel' => (string)(($f['vessel'] ?? '') !== '' ? (scalar('SELECT name FROM vessels WHERE id = ?', [(int)$f['vessel']]) ?: '') : ''), 'customer_id' => (int)($f['customer'] ?? 0)]) : 'mailto:' . implode(',', $emails)) ?>">✉ <?= e(t('ct.email_all', ['n' => count($emails)])) ?></a><?php endif; ?>
    </div>
  <div class="table-scroll"><table class="ct-table">
    <thead><tr><th style="width:28px;"></th><th><?= tt('au.name') ?></th><th><?= tt('co.one') ?></th><th><?= tt('ct.category') ?></th><th><?= tt('nav.vessels') ?></th><th><?= tt('ct.reach') ?></th><?php if ($co): ?><th><?= tt('ct.channels') ?></th><th><?= tt('ct.subscriptions') ?></th><?php endif; ?><th><?= tt('ct.birthday') ?></th><th><?= tt('ct.nameday') ?></th><th class="num"><?= tt('ct.notes') ?></th></tr></thead>
    <tbody><?php foreach ($l['rows'] as $c): ?>
      <tr<?= $c['is_active'] ? '' : ' class="is-retired"' ?>>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" class="rowchk"></td>
        <td><a href="<?= url('contact', ['id' => $c['id']]) ?>"><b><?= e($c['full_name']) ?></b></a><?= $c['position'] ? '<br><span class="hint">' . e($c['position']) . '</span>' : '' ?></td>
        <td><?= e($c['company_name'] ?: ($c['customer_name'] ?: '—')) ?><?= $c['customer_name'] && $c['company_name'] && $c['company_name'] !== $c['customer_name'] ? '<br><span class="hint">' . e($c['customer_name']) . '</span>' : '' ?><?= $c['company_kind'] ? '<br>' . co_kind_pill($c['company_kind']) : '' ?></td>
        <td><?= ct_cat_pill($c['category']) ?></td>
        <td class="ct-vs"><?= $c['vessel_list'] ? e($c['vessel_list']) : '<span class="hint">—</span>' ?></td>
        <td class="ct-reach"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a><br>' : '' ?><?= e(implode(' · ', array_filter([$c['mobile'], $c['phone']]))) ?></td>
        <?php if ($co): ?><td class="ct-sets"><?= ct_set_pills($c['channels'] ?? null, ct_channels(), 'mute') ?></td><td class="ct-sets"><?= ct_set_pills($c['subscriptions'] ?? null, ct_subscriptions(), 'green') ?></td><?php endif; ?>
        <td><?= e(ct_fmt_birthday($c['birthday'])) ?></td>
        <td><?= e(ct_fmt_md($c['nameday_md'])) ?></td>
        <td class="num"><?= (int)$c['n_notes'] ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var a=document.getElementById('chkall');if(!a)return;a.addEventListener('change',function(){document.querySelectorAll('.rowchk').forEach(function(c){c.checked=a.checked;});});})();</script>
  <?php endif; ?>
  <?php if ($l['pages'] > 1): ?><nav class="pager" aria-label="pages">
    <?php if ($l['page'] > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($l['page'] - 1) ?>">‹ <?= tt('btn.prev') ?></a><?php endif; ?>
    <?php for ($n = max(1, $l['page'] - 3); $n <= min($l['pages'], $l['page'] + 3); $n++): ?><a class="btn btn--sm <?= $n === $l['page'] ? 'btn--gold' : 'btn--ghost' ?>" href="<?= $pageUrl($n) ?>"><?= $n ?></a><?php endfor; ?>
    <?php if ($l['page'] < $l['pages']): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($l['page'] + 1) ?>"><?= tt('btn.next') ?> ›</a><?php endif; ?>
  </nav><?php endif; ?>
</div>

<div class="card card--flat ct-up">
  <h3 class="ed-h"><?= tt('ct.upcoming') ?> <span><?= tt('ct.next_30') ?></span></h3>
  <?php if (!$up): ?><div class="empty"><?= tt('ct.none_upcoming') ?></div><?php else: foreach (array_slice($up, 0, 25) as $x): $c = $x['contact']; ?>
    <a class="attrow <?= $x['days'] <= 3 ? 'att--gold' : 'att--mute' ?>" href="<?= url('contact', ['id' => $c['id']]) ?>">
      <span class="age"><?= $x['days'] === 0 ? tt('dash.today') : e(t('ct.in_days', ['n' => $x['days']])) ?></span>
      <span><?= $x['kind'] === 'birthday' ? '🎂' : '🎉' ?> <b><?= e($c['full_name']) ?></b> — <?= $x['kind'] === 'birthday' ? tt('ct.birthday') . ($x['age'] ? ' (' . $x['age'] . ')' : '') : tt('ct.nameday') ?> · <?= e(fmt_date($x['date'])) ?><?= ($c['customer_name'] ?: $c['company']) ? '<br><span class="hint">' . e($c['customer_name'] ?: $c['company']) . '</span>' : '' ?></span>
    </a>
  <?php endforeach; endif; ?>
</div>
</div>
<?php layout_foot(); ?>
