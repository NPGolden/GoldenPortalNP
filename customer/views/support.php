<?php
require __DIR__ . '/_layout.php';
$u = require_login(); $cid = (int)$u['customer_id']; $staff = is_staff();
$vessels = $staff ? [] : all("SELECT id, name FROM vessels WHERE customer_id=? AND is_active=1 ORDER BY name", [$cid]);
$tickets = $staff
    ? all("SELECT t.*, v.name AS vessel, c.name AS customer, cu.full_name AS who FROM support_tickets t LEFT JOIN vessels v ON v.id=t.vessel_id LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN customer_users cu ON cu.id=t.customer_user_id ORDER BY t.status='open' DESC, t.id DESC LIMIT 100")
    : all("SELECT t.*, v.name AS vessel FROM support_tickets t LEFT JOIN vessels v ON v.id=t.vessel_id
                WHERE t.customer_id=? ORDER BY t.id DESC LIMIT 20", [$cid]);
layout_head(t('su.title'), 'support');
?>
<h1><?= tt('su.title') ?></h1>
<p class="lede"><?= tt('su.lede') ?></p>
<?php $hq = trim((string)($_GET['hq'] ?? '')); $hits = $hq !== '' ? mn_search($hq) : []; ?>
<div class="card card--tight mn-search" id="helpsearch">
  <form method="get" action="index.php" class="btn-row" style="align-items:center;">
    <input type="hidden" name="p" value="support">
    <input type="search" name="hq" value="<?= e($hq) ?>" placeholder="<?= tt('mn.search_ph') ?>" style="flex:1;min-width:260px;" autofocus>
    <button class="btn btn--sm" type="submit">🔍 <?= tt('mn.search') ?></button><?php if ($hq !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= url('support') ?>"><?= tt('btn.clear') ?></a><?php endif; ?>
  </form>
  <?php if ($hq !== ''): ?>
    <p class="hint" style="margin:8px 0 4px;"><?= e(t('mn.search_n', ['n' => count($hits), 'q' => $hq])) ?></p>
    <?php foreach ($hits as $h): ?><div class="mn-hit"><a href="<?= url('manual', ['for' => $h['edition'], 'hl' => $hq]) ?>#<?= e($h['id']) ?>"><b><?= e($h['title']) ?></b></a> <span class="pill pill--mute"><?= e($h['edition_label']) ?></span><div class="hint"><?= $h['snippet'] ?></div></div><?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="mn-cards">
  <a class="mn-card" href="<?= url('manual', ['for' => 'customer']) ?>"><span class="mn-ico">📘</span><b><?= tt('mn.card_customer') ?></b><span><?= tt('mn.card_customer_sub') ?></span></a>
  <?php if (is_staff()): ?><a class="mn-card" href="<?= url('manual', ['for' => 'golden']) ?>"><span class="mn-ico">📗</span><b><?= tt('mn.card_golden') ?></b><span><?= tt('mn.card_golden_sub') ?></span></a><a class="mn-card" href="<?= url('manual', ['for' => 'pm']) ?>"><span class="mn-ico">📙</span><b><?= tt('mn.card_pm') ?></b><span><?= tt('mn.card_pm_sub') ?></span></a><a class="mn-card" href="<?= url('manual', ['for' => 'staff']) ?>"><span class="mn-ico">📒</span><b><?= tt('mn.card_staff') ?></b><span><?= tt('mn.card_staff_sub') ?></span></a><?php if (function_exists('hr_can') && hr_can()): ?><a class="mn-card" href="<?= url('manual', ['for' => 'hr']) ?>"><span class="mn-ico">📕</span><b><?= tt('mn.card_hr') ?></b><span><?= tt('mn.card_hr_sub') ?></span></a><?php endif; endif; ?>
</div>
<?= function_exists('cr_support_card') ? cr_support_card() : '' ?>
<?= kb_support_card() ?>
<div class="cols su-cols">
  <div class="card card--tight">
    <h3 class="ac-h" style="margin-top:0;"><?= tt('su.topics') ?></h3>
    <?php $topics = is_staff()
      ? [['pm', 'daily', t('mn.t_pm_daily')], ['pm', 'weekly', t('mn.t_pm_weekly')], ['pm', 'forecast', t('mn.t_pm_forecast')], ['pm', 'meeting', t('mn.t_pm_meeting')], ['pm', 'monthly', t('mn.t_pm_monthly')], ['pm', 'checklists', t('mn.t_pm_checklists')], ['golden', 'projects', t('mn.t_projects')], ['golden', 'reports', t('mn.t_reports')], ['golden', 'colours', t('rc.legend')], ['golden', 'listfilters', t('flt.title')], ['golden', 'issues', t('iss.title')], ['golden', 'tasks', t('td.title')], ['golden', 'teams', t('tm.title')], ['golden', 'planning', t('pl.title')], ['golden', 'equipment', t('eq.title')], ['golden', 'timesheets', t('nav.timesheets')], ['golden', 'hours', t('wc.title')], ['golden', 'tickets', t('nav.tickets')], ['golden', 'fd', t('fd.tab')], ['golden', 'fsr', t('nav.reports')], ['golden', 'isr', t('isr.plural')], ['golden', 'branches', t('br.title')], ['golden', 'offers', t('nav.offers')], ['golden', 'contacts', t('nav.contacts')], ['golden', 'vessel_unify', t('vm.title')], ['golden', 'forecast', t('wf.title')], ['golden', 'remote', t('rs.title')], ['golden', 'archive', t('mn.t_archive')], ['golden', 'lists', t('mn.t_lists')], ['golden', 'settings', t('nav.settings')], ['golden', 'licence', t('lic.title')], ['golden', 'kb', t('kb.title')]]
      : [['customer', 'signin', t('mn.t_signin')], ['customer', 'layout', t('mn.t_layout')], ['customer', 'listfilters', t('flt.title')], ['customer', 'colours', t('rc.legend')], ['customer', 'projects', t('nav.projects')], ['customer', 'equipment', t('eq.title')], ['customer', 'issues', t('iss.title')], ['customer', 'approvals', t('nav.approvals')], ['customer', 'tickets', t('nav.tickets')], ['customer', 'tickets_history', t('mn.t_tk_history')], ['customer', 'fsr', t('nav.reports')], ['customer', 'isr', t('isr.plural')], ['customer', 'offers', t('nav.offers')], ['customer', 'forecast', t('wf.title')], ['customer', 'remote', t('rs.title')], ['customer', 'documents', t('nav.docs')], ['customer', 'notifications', t('nf.title')], ['customer', 'kb', t('kb.title')], ['customer', 'faq', t('mn.t_faq')]]; ?>
    <div class="su-topics"><?php foreach ($topics as [$ed, $anchor, $label]): ?><a class="btn btn--ghost btn--sm" href="<?= url('manual', ['for' => $ed]) ?>#<?= e($anchor) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
  </div>
  <div class="card card--tight">
    <h3 class="ac-h" style="margin-top:0;"><?= tt('su.whats_new') ?> <span class="hint">v<?= e(CP_VERSION) ?></span></h3>
    <ul class="su-news"><?php foreach (array_slice(cp_patches(), 0, 6) as $pt): ?><li><b><?= e($pt['version']) ?></b> <span class="hint"><?= e(fmt_date($pt['date'])) ?></span><br><?= e(mb_strimwidth($pt['title'], 0, 170, '…')) ?></li><?php endforeach; ?></ul>
    <p class="hint" style="margin:6px 0 0;"><a href="<?= url('manual', ['for' => is_staff() ? 'golden' : 'customer']) ?>#whatsnew"><?= tt('su.all_news') ?> ›</a></p>
  </div>
</div>

<div class="cols">
  <?php if ($staff): ?>
  <div class="card">
    <h2><?= tt('su.inbox') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count(array_filter($tickets, fn($t) => $t['status'] === 'open')) ?> <?= tt('su.open') ?></span></h2>
    <p class="hint"><?= tt('su.staff_note') ?></p>
    <?php if (!$tickets): ?><div class="empty"><?= tt('su.empty') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('su.subject') ?></th><th><?= tt('rp.customer') ?></th><th><?= tt('v.one') ?></th><th><?= tt('th.date') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
      <tbody><?php foreach ($tickets as $tk): ?>
        <tr><td><b><?= e($tk['subject']) ?></b><br><span class="hint"><?= nl2br(e(mb_substr((string)$tk['body'], 0, 400))) ?><?= mb_strlen((string)$tk['body']) > 400 ? '…' : '' ?></span></td>
          <td><?= e($tk['customer'] ?: '—') ?><br><span class="hint"><?= e($tk['who'] ?: '') ?></span></td><td><?= e($tk['vessel'] ?: '—') ?></td><td class="date"><?= e(fmt_datetime($tk['created_at'])) ?></td>
          <td><?= $tk['status'] === 'open' ? pill('blue', t('su.open')) : pill('mute', t('su.closed')) ?></td>
          <td class="row-actions"><form method="post" action="<?= url('support') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="support_status"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><input type="hidden" name="status" value="<?= $tk['status'] === 'open' ? 'closed' : 'open' ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $tk['status'] === 'open' ? tt('su.close') : tt('su.reopen') ?></button></form></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card">
    <form method="post" action="<?= url('support') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="support_send">
      <div class="field">
        <label for="subject"><?= tt('su.subject') ?></label>
        <input id="subject" name="subject" type="text" required maxlength="200">
      </div>
      <div class="field">
        <label for="vessel_id"><?= tt('su.vessel') ?></label>
        <select id="vessel_id" name="vessel_id">
          <option value="0"><?= tt('su.none') ?></option>
          <?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="body"><?= tt('su.message') ?></label>
        <textarea id="body" name="body" required maxlength="4000"></textarea>
      </div>
      <button class="btn btn--gold" type="submit"><?= tt('su.send') ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= tt('su.history') ?></h2>
    <?php if (!$tickets): ?><div class="empty"><?= tt('su.empty') ?></div>
    <?php else: foreach ($tickets as $tk): ?>
      <div class="rowitem">
        <span class="tx"><b><?= e($tk['subject']) ?></b>
          <span><?= e($tk['vessel'] ?: '—') ?> · <span class="date"><?= e(fmt_datetime($tk['created_at'])) ?></span></span></span>
        <span class="pill pill--<?= $tk['status'] === 'open' ? 'blue' : 'mute' ?>">
          <?= $tk['status'] === 'open' ? tt('su.open') : tt('su.closed') ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
