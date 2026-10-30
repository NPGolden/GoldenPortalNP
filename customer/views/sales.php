<?php
/** Commercial › Sales KPIs — hit rate, pipeline, decision time, turnaround, forecast, per sales person / customer, targets (GAS-CP-R39-002). ?p=sales[&period=ytd|m|last|q|12][&user=][&customer=][&branch=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('sl_can') || !sl_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'sales'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$per = in_array($_GET['period'] ?? 'ytd', ['m', 'last', 'q', 'ytd', '12'], true) ? (string)($_GET['period'] ?? 'ytd') : 'ytd'; [$from, $to] = sl_period($per);
$f = array_filter(['user' => (int)($_GET['user'] ?? 0), 'customer' => (int)($_GET['customer'] ?? 0), 'branch' => (int)($_GET['branch'] ?? 0)]);
$K = sl_kpis($from, $to, $f); $M = sl_month_vs_target((int)date('Y'), (int)date('m'));
$people = all("SELECT DISTINCT cu.id, cu.full_name FROM offers o JOIN customer_users cu ON cu.id = o.created_by ORDER BY cu.full_name");
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$keep = ['period' => $per] + $f; $pf = fn(?float $v) => $v === null ? '—' : number_format($v * 100, 0) . ' %'; $df = fn(?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.') . ' ' . t('tk.d');
$lk = fn(array $q) => url('offers', $q);
layout_head(t('sl.title'), 'sales');
?>
<div class="page-head"><div><h1>📈 <?= tt('sl.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sl.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('report_sales', $keep) ?>" target="_blank">⤓ PDF</a><a class="btn btn--ghost btn--sm" href="<?= url('report_sales', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a><?php if (is_cp_admin()): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_sales') ?>">⚙ <?= tt('sl.settings') ?></a><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('offers') ?>">€ <?= tt('of.title') ?> ›</a></div></div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="sales">
    <div class="field"><label><?= tt('acc.period') ?></label><select name="period" data-nosearch><?php foreach (['m' => t('kpi.p_m'), 'last' => t('kpi.p_last'), 'q' => t('kpi.p_q'), 'ytd' => t('kpi.p_ytd'), '12' => t('kpi.p_12')] as $k => $l): ?><option value="<?= $k ?>"<?= $per === (string)$k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sl.person') ?></label><select name="user"><option value=""><?= tt('flt.any') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= ($f['user'] ?? 0) === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('v.customer') ?></label><select name="customer"><option value=""><?= tt('flt.any') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= ($f['customer'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('sales') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="kpi kpi--6" style="margin-bottom:14px;">
  <a class="stat" href="<?= $lk(['status' => 'sent']) ?>"><b><?= $K['sent'] ?></b><?= tt('sl.k_sent') ?><span class="s"><?= e(sl_money($K['sent_v'])) ?></span></a>
  <a class="stat green" href="<?= $lk(['status' => 'approved']) ?>"><b><?= $pf($K['hit']) ?></b><?= tt('sl.k_hit') ?><span class="s"><?= e(t('sl.k_hit_s', ['w' => $K['won'], 'l' => $K['lost'], 'v' => $pf($K['hit_v'])])) ?></span></a>
  <a class="stat gold" href="<?= $lk(['status' => 'sent']) ?>"><b><?= e(sl_money($K['pipe']['v'])) ?></b><?= tt('sl.k_pipe') ?><span class="s"><?= e(t('sl.k_pipe_s', ['n' => $K['pipe']['n'], 'old' => sl_money($K['pipe']['a14p'])])) ?></span></a>
  <div class="stat"><b><?= e(sl_money($K['forecast'])) ?></b><?= tt('sl.k_forecast') ?><span class="s"><?= e(t('sl.k_forecast_s', ['n' => $K['forecast_n']])) ?></span></div>
  <div class="stat"><b><?= $df($K['dec_avg']) ?></b><?= tt('sl.k_decision') ?><span class="s"><?= e(t('sl.k_breach', ['n' => $K['breach']])) ?></span></div>
  <div class="stat"><b><?= $df($K['turn_avg']) ?></b><?= tt('sl.k_turn') ?><span class="s"><?= e(t('sl.k_turn_s', ['n' => $K['turn_n']])) ?></span></div>
</div>
<div class="kpi kpi--6" style="margin-bottom:14px;">
  <div class="stat"><b><?= $K['aov'] !== null ? e(sl_money($K['aov'])) : '—' ?></b><?= tt('sl.k_aov') ?></div>
  <div class="stat"><b><?= $K['offered_inv']['offered'] > 0 ? number_format($K['offered_inv']['invoiced'] / $K['offered_inv']['offered'] * 100, 0) . ' %' : '—' ?></b><?= tt('sl.k_offered_inv') ?><span class="s"><?= e(sl_money($K['offered_inv']['invoiced'])) ?> / <?= e(sl_money($K['offered_inv']['offered'])) ?></span></div>
  <div class="stat"><b><?= e(sl_money($K['discount'])) ?></b><?= tt('sl.k_discount') ?></div>
  <div class="stat"><b><?= $K['repeat'] ?></b><?= tt('sl.k_repeat') ?><span class="s"><?= e(t('sl.k_repeat_s', ['n' => $K['customers_won'], 'p' => $K['top5_share'] !== null ? number_format($K['top5_share'] * 100, 0) : '—'])) ?></span></div>
  <a class="stat" href="<?= url('tickets', ['view' => 'all']) ?>"><b><?= $K['conv']['calls'] ? number_format($K['conv']['offers'] / $K['conv']['calls'] * 100, 0) . ' %' : '—' ?></b><?= tt('sl.k_conv') ?><span class="s"><?= e(t('sl.k_conv_s', ['o' => $K['conv']['offers'], 'c' => $K['conv']['calls']])) ?></span></a>
  <div class="stat"><b><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?></b><?= tt('acc.period') ?></div>
</div>

<div class="cols">
  <div class="card">
    <h2><?= tt('sl.by_person') ?></h2>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sl.person') ?></th><th class="num"><?= tt('sl.k_sent') ?></th><th class="num"><?= tt('sl.won') ?></th><th class="num"><?= tt('sl.won_value') ?></th><th class="num"><?= tt('sl.k_hit') ?></th><th class="num"><?= tt('sl.k_decision') ?></th><th class="num"><?= tt('sl.open') ?></th></tr></thead><tbody>
    <?php foreach ($K['by_user'] as $uid => $x): if (!$x['sent'] && !$x['won'] && !$x['open'] && !$x['lost']) continue; ?><tr><td><a href="<?= url('sales', $keep + ['user' => $uid]) ?>"><?= e($x['name']) ?></a></td><td class="num"><?= $x['sent'] ?></td><td class="num"><b><?= $x['won'] ?></b> <span class="hint">/ <?= $x['lost'] ?></span></td><td class="num"><?= e(sl_money($x['won_v'])) ?></td><td class="num"><?= $pf($x['hit']) ?></td><td class="num"><?= $df($x['dec_avg']) ?></td><td class="num"><?= $x['open'] ?> <span class="hint"><?= e(sl_money($x['open_v'])) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <h2><?= tt('sl.month_target') ?> <span class="hint"><?= e(date('M Y')) ?></span></h2>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sl.person') ?></th><th class="num"><?= tt('sl.k_sent') ?></th><th class="num"><?= tt('sl.won_value') ?></th></tr></thead><tbody>
    <?php foreach ($M['rows'] as $r): $ps = sl_pct($r['sent'], $r['sent_t']); $pv = sl_pct($r['won_v'], $r['won_t']); ?><tr><td><?= e($r['name']) ?></td><td class="num"><?= $r['sent'] ?><?= $r['sent_t'] ? ' <span class="hint">/ ' . $r['sent_t'] . '</span> ' . pill($ps >= 100 ? 'green' : ($ps >= 70 ? 'gold' : 'bad'), $ps . ' %') : '' ?></td><td class="num"><?= e(sl_money($r['won_v'])) ?><?= $r['won_t'] ? ' <span class="hint">/ ' . e(sl_money($r['won_t'])) . '</span> ' . pill($pv >= 100 ? 'green' : ($pv >= 70 ? 'gold' : 'bad'), $pv . ' %') : '' ?></td></tr><?php endforeach; ?>
    <?php $c = $M['company']; $ps = sl_pct($c['sent'], $c['sent_t']); $pv = sl_pct($c['won_v'], $c['won_t']); ?><tr><td><b><?= tt('sl.company') ?></b></td><td class="num"><b><?= $c['sent'] ?></b><?= $c['sent_t'] ? ' <span class="hint">/ ' . $c['sent_t'] . '</span> ' . pill($ps >= 100 ? 'green' : ($ps >= 70 ? 'gold' : 'bad'), $ps . ' %') : '' ?></td><td class="num"><b><?= e(sl_money($c['won_v'])) ?></b><?= $c['won_t'] ? ' <span class="hint">/ ' . e(sl_money($c['won_t'])) . '</span> ' . pill($pv >= 100 ? 'green' : ($pv >= 70 ? 'gold' : 'bad'), $pv . ' %') : '' ?></td></tr>
    </tbody></table></div>
    <p class="hint" style="margin:8px 0 0;"><?= tt('sl.target_hint') ?><?php if (is_cp_admin()): ?> <a href="<?= url('admin_sales') ?>"><?= tt('sl.settings') ?> ›</a><?php endif; ?></p>
  </div>
</div>

<div class="cols">
  <div class="card">
    <h2><?= tt('sl.by_customer') ?></h2>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('v.customer') ?></th><th class="num"><?= tt('sl.k_sent') ?></th><th class="num"><?= tt('sl.won') ?></th><th class="num"><?= tt('sl.won_value') ?></th><th class="num"><?= tt('sl.open_value') ?></th><th class="num"><?= tt('sl.won_12') ?></th></tr></thead><tbody>
    <?php $i = 0; foreach ($K['by_customer'] as $cid => $x): if (!$x['sent'] && !$x['won'] && !$x['lost'] && !$x['open_v']) continue; if (++$i > 15) break; ?><tr><td><a href="<?= url('sales', $keep + ['customer' => $cid]) ?>"><?= e($x['name']) ?></a></td><td class="num"><?= $x['sent'] ?></td><td class="num"><b><?= $x['won'] ?></b> <span class="hint">/ <?= $x['lost'] ?></span></td><td class="num"><?= e(sl_money($x['won_v'])) ?></td><td class="num"><?= e(sl_money($x['open_v'])) ?></td><td class="num"><?= $x['won_12'] ?><?= $x['won_12'] >= 2 ? ' ' . pill('green', t('sl.repeat')) : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <h2><?= tt('sl.lost_why') ?></h2>
    <?php if (!$K['lost_why']): ?><p class="hint"><?= tt('sl.no_lost') ?></p><?php else: $tot = array_sum($K['lost_why']); arsort($K['lost_why']); ?>
    <div class="aging"><?php foreach ($K['lost_why'] as $why => $n): ?><div class="aging-row"><span class="aging-l"><?= e(sl_lost_label((string)$why)) ?></span><span class="aging-bar"><i class="aging-bar--bad" style="width:<?= (int) round($n / $tot * 100) ?>%;"></i></span><span class="aging-n"><?= $n ?></span></div><?php endforeach; ?></div>
    <?php endif; ?>
    <h3 class="ed-h" style="margin-top:14px;"><?= tt('sl.pipe_aging') ?></h3>
    <div class="aging"><?php foreach ([['a7', t('sl.a7'), 'green'], ['a14', t('sl.a14'), 'gold'], ['a14p', t('sl.a14p'), 'bad']] as [$k2, $l2, $tone]): $v = (float)$K['pipe'][$k2]; ?><div class="aging-row"><span class="aging-l"><?= e($l2) ?></span><span class="aging-bar"><i class="aging-bar--<?= $tone ?>" style="width:<?= $K['pipe']['v'] > 0 ? (int) round($v / $K['pipe']['v'] * 100) : 0 ?>%;"></i></span><span class="aging-n"><?= e(sl_money($v)) ?></span></div><?php endforeach; ?></div>
  </div>
</div>
<?php layout_foot(); ?>
