<?php
/** Work › Website statistics (GAS-CP-R95-001): goldenas.com.gr visitor statistics, pulled from the website's own store.  ?p=web_stats[&p=30d|today|7d|month|lastmonth|year|lastyear|12m|custom&from=&to=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('ws_has') || !ws_has()) { http_response_code(404); layout_head(t('ws.title'), 'web_stats'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
[$from, $to, $pfrom, $pto, $per] = ws_period((string)($_GET['per'] ?? '30d'), (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
$S = ws_summary($from, $to); $P = ws_summary($pfrom, $pto);
$series = $S['days'] > 62 ? ws_monthly($from, $to) : ws_series($from, $to); $monthly = $S['days'] > 62;
$pages = ws_engagement($from, $to); $countries = ws_top('country', $from, $to, 12); $regions = ws_top('region', $from, $to, 12);
$devices = ws_top('device', $from, $to, 5); $browsers = ws_top('browser', $from, $to, 6); $os = ws_top('os', $from, $to, 6); $langs = ws_top('lang', $from, $to, 4);
$refs = ws_top('ref', $from, $to, 10); $camps = ws_top('campaign', $from, $to, 8); $search = ws_top('search', $from, $to, 20);
$videos = ws_top('video', $from, $to, 6); $outb = ws_top('outbound', $from, $to, 8); $downloads = ws_top('download', $from, $to, 8); $forms = ws_top('form', $from, $to, 8);
$hours = ws_hours($from, $to); $wd = ws_weekdays($from, $to); $inq = ws_inquiries($from, $to);
$last = (string)cp_setting('ws_last_pull', ''); $err = (string)cp_setting('ws_last_error', ''); $live = json_decode((string)cp_setting('ws_live', '{}'), true) ?: [];
$delta = function (int|float $a, int|float $b, bool $pct = true): string { if ($b <= 0) return ''; $d = ($a - $b) / $b * 100; $cls = $d > 2 ? 'green' : ($d < -2 ? 'red' : 'mute'); return '<span class="pill pill--' . $cls . '" title="' . tt('ws.vs_previous') . '">' . ($d >= 0 ? '+' : '') . round($d) . '%</span>'; };
$fmtT = fn(int $s) => sprintf('%d:%02d', intdiv($s, 60), $s % 60);
$presets = ['today', 'yesterday', '7d', '30d', 'month', 'lastmonth', 'year', 'lastyear', '12m'];
/* ---- tiny inline SVG helpers (no JS library; prints cleanly) ---- */
function ws_bars(array $rows, string $kx, string $kv, string $kv2 = '', int $w = 980, int $h = 220): string {
    if (!$rows) return '<p class="hint">' . tt('ws.no_data') . '</p>';
    $max = max(1, max(array_map(fn($r) => (int)$r[$kv], $rows))); $n = count($rows); $gap = 4; $bw = min(56, max(2, ($w - 40) / $n - $gap)); $w = (int)min($w, 40 + $n * ($bw + $gap) + 8); $o = '<svg class="ws-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
    foreach ([0.25, 0.5, 0.75, 1] as $g) { $y = round(($h - 30) - ($h - 40) * $g, 1); $o .= '<line x1="36" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="var(--line)" stroke-width="1"/><text x="32" y="' . ($y + 4) . '" font-size="10" text-anchor="end" fill="var(--mute)">' . round($max * $g) . '</text>'; }
    $step = max(1, (int)ceil($n / 14));
    foreach ($rows as $i => $r) { $x = round(40 + $i * ($bw + $gap), 1); $v = (int)$r[$kv]; $bh = round(($h - 40) * $v / $max, 1);
        $o .= '<rect x="' . $x . '" y="' . round(($h - 30) - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . $bh . '" rx="2" fill="var(--navy)"><title>' . e((string)$r[$kx]) . ': ' . $v . ($kv2 ? ' / ' . (int)$r[$kv2] : '') . '</title></rect>';
        if ($kv2) { $v2 = (int)$r[$kv2]; $bh2 = round(($h - 40) * $v2 / $max, 1); $o .= '<rect x="' . round($x + $bw * 0.3, 1) . '" y="' . round(($h - 30) - $bh2, 1) . '" width="' . round($bw * 0.4, 1) . '" height="' . $bh2 . '" rx="2" fill="var(--gold)"/>'; }
        if ($i % $step === 0) $o .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 14) . '" font-size="10" text-anchor="middle" fill="var(--mute)">' . e(substr((string)$r[$kx], 5)) . '</text>'; }
    return $o . '</svg>';
}
function ws_hbars(array $rows, callable $label, string $kv = 'n', ?callable $extra = null): string {
    if (!$rows) return '<p class="hint">' . tt('ws.no_data') . '</p>';
    $max = max(1, max(array_map(fn($r) => (int)$r[$kv], $rows))); $o = '<table class="ws-h">';
    foreach ($rows as $r) { $v = (int)$r[$kv]; $o .= '<tr><td class="l">' . $label($r) . '</td><td class="b"><div style="width:' . round($v / $max * 100) . '%"></div></td><td class="v">' . number_format($v) . ($extra ? '<small>' . $extra($r) . '</small>' : '') . '</td></tr>'; }
    return $o . '</table>';
}
function ws_heat(array $hours): string { $max = max(1, max(array_map(fn($x) => $x['n'], $hours))); $o = '<div class="ws-heat">'; foreach ($hours as $h => $x) { $a = $x['n'] / $max; $o .= '<div title="' . sprintf('%02d:00 UTC · %d', $h, $x['n']) . '"><i style="opacity:' . max(.08, round($a, 2)) . '"></i><span>' . sprintf('%02d', $h) . '</span></div>'; } return $o . '</div>'; }
layout_head(t('ws.title'), 'web_stats');
?>
<style>
.ws-per{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0}.ws-per a{padding:6px 11px;border-radius:999px;border:1px solid var(--line);font-size:12.5px;font-weight:600;color:var(--navy-text);text-decoration:none}.ws-per a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.ws-per form{display:flex;gap:6px;align-items:center;margin-left:auto}.ws-per input[type=date]{padding:5px 8px;border:1px solid var(--line);border-radius:8px;font:inherit}
.ws-chart{max-width:100%;height:auto;display:block;margin:0 auto}.ws-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ws-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
table.ws-h{width:100%;border-collapse:collapse}.ws-h td{padding:5px 6px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle}.ws-h td.l{width:42%}.ws-h td.b div{height:10px;border-radius:5px;background:var(--gold);min-width:2px}.ws-h td.v{text-align:right;width:90px;font-weight:700;color:var(--navy-text)}.ws-h td.v small{display:block;font-weight:400;color:var(--mute);font-size:11px}
.ws-heat{display:grid;grid-template-columns:repeat(24,1fr);gap:3px}.ws-heat div{text-align:center}.ws-heat i{display:block;height:34px;border-radius:4px;background:var(--navy)}.ws-heat span{font-size:9px;color:var(--mute)}
.ws-wd{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.ws-wd div{background:var(--light);border-radius:8px;padding:8px 4px;text-align:center}.ws-wd b{display:block;font-size:18px;color:var(--navy-text)}.ws-wd span{font-size:11px;color:var(--mute)}
.ws-legend{font-size:11px;color:var(--mute)}.ws-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;vertical-align:-1px;margin:0 4px 0 10px}
@media(max-width:900px){.ws-grid,.ws-grid3{grid-template-columns:1fr}}
@media print{.ws-per,.filters,.btn-row{display:none!important}.card{break-inside:avoid}}
</style>
<p class="eyebrow"><?= tt('nav.g_work') ?></p>
<div class="page-head"><div><h1>📈 <?= tt('ws.title') ?></h1><p class="lede"><?= tt('ws.lede') ?></p></div>
  <div class="btn-row no-print">
    <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ws_pull_now"><button class="btn btn--sm" type="submit">⟳ <?= tt('ws.pull_now') ?></button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ws_export"><input type="hidden" name="p" value="<?= e($per) ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><button class="btn btn--sm" type="submit">⬇ CSV</button></form>
    <button class="btn btn--sm" type="button" onclick="window.print()">🖨 <?= tt('ws.print') ?></button>
  </div></div>
<?php if (!ws_configured()): ?><p class="flash flash--bad"><?= tt('ws.not_configured') ?> <a href="<?= url('settings', ['tab' => 'website']) ?>"><?= tt('web.title') ?> →</a></p><?php endif; ?>
<?php if ($err): ?><p class="flash flash--bad"><?= tt('ws.last_error') ?>: <?= e($err) ?></p><?php endif; ?>
<div class="ws-per no-print">
  <?php foreach ($presets as $k): ?><a class="<?= $per === $k ? 'on' : '' ?>" href="<?= url('web_stats', ['per' => $k]) ?>"><?= tt('ws.p_' . $k) ?></a><?php endforeach; ?>
  <form method="get"><input type="hidden" name="p" value="web_stats"><input type="hidden" name="per" value="custom"><input type="date" name="from" value="<?= e($from) ?>" max="<?= gmdate('Y-m-d') ?>"><span>–</span><input type="date" name="to" value="<?= e($to) ?>" max="<?= gmdate('Y-m-d') ?>"><button class="btn btn--sm" type="submit"><?= tt('ws.apply') ?></button></form>
</div>
<p class="hint"><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?> · <?= tt('ws.compared') ?> <?= e(fmt_date($pfrom)) ?> – <?= e(fmt_date($pto)) ?> · <?= tt('ws.last_pull') ?>: <?= $last ? e(fmt_datetime($last)) : '—' ?><?= isset($live['active_last_5min']) ? ' · <b>' . (int)$live['active_last_5min'] . '</b> ' . tt('ws.active_now') : '' ?> · UTC</p>

<div class="kpi" style="margin:12px 0 14px;">
  <div class="stat"><b><?= number_format($S['visitors']) ?></b><span class="s"><?= tt('ws.visitors') ?> <?= $delta($S['visitors'], $P['visitors']) ?></span></div>
  <div class="stat"><b><?= number_format($S['views']) ?></b><span class="s"><?= tt('ws.views') ?> <?= $delta($S['views'], $P['views']) ?></span></div>
  <div class="stat"><b><?= $S['pages_per_visit'] ?></b><span class="s"><?= tt('ws.pages_per_visit') ?></span></div>
  <div class="stat"><b><?= $fmtT($S['avg_seconds']) ?></b><span class="s"><?= tt('ws.avg_time') ?> (min:s) · <?= $S['avg_scroll'] ?>% <?= tt('ws.scroll') ?></span></div>
  <div class="stat gold"><b><?= $S['forms'] ?></b><span class="s"><?= tt('ws.forms') ?> <?= $delta($S['forms'], $P['forms']) ?></span></div>
  <div class="stat"><b><?= $S['countries'] ?></b><span class="s"><?= tt('ws.countries') ?></span></div>
  <div class="stat"><b><?= $S['portal_clicks'] ?></b><span class="s"><?= tt('ws.portal_clicks') ?></span></div>
  <div class="stat"><b><?= $S['videos'] ?></b><span class="s"><?= tt('ws.videos') ?></span></div>
</div>

<div class="card"><h2>📅 <?= tt($monthly ? 'ws.trend_month' : 'ws.trend_day') ?> <span class="ws-legend"><i style="background:var(--navy)"></i><?= tt('ws.views') ?><i style="background:var(--gold)"></i><?= tt('ws.visitors') ?></span></h2>
  <?= ws_bars($series, $monthly ? 'm' : 'd', 'views', 'visitors') ?>
  <p class="hint"><b><?= tt('ws.fig') ?> 1.</b> <?= tt($monthly ? 'ws.fig1_month' : 'ws.fig1_day') ?></p></div>

<div class="ws-grid">
  <div class="card"><h2>🗺 <?= tt('ws.countries') ?></h2><?= ws_hbars($countries, fn($r) => e(ws_country_name($r['k'])) . ' <small class="hint">' . e($r['k']) . '</small>', 'n', fn($r) => (int)$r['u'] . ' ' . t('ws.visitors_short')) ?></div>
  <div class="card"><h2>📍 <?= tt('ws.regions') ?></h2><?= ws_hbars($regions, fn($r) => e(str_replace('/', ' · ', $r['k'])), 'n', fn($r) => (int)$r['u'] . ' ' . t('ws.visitors_short')) ?></div>
</div>

<div class="card"><h2>📄 <?= tt('ws.pages') ?></h2>
  <table class="list"><thead><tr><th><?= tt('ws.page') ?></th><th class="r"><?= tt('ws.views') ?></th><th class="r"><?= tt('ws.unique') ?></th><th class="r"><?= tt('ws.avg_time') ?></th><th class="r"><?= tt('ws.scroll') ?></th></tr></thead><tbody>
  <?php foreach ($pages as $r): ?><tr><td><?= e(ws_page_label($r['page'])) ?> <small class="hint">/<?= e($r['page']) ?>.html</small></td><td class="r"><b><?= number_format((int)$r['views']) ?></b></td><td class="r"><?= number_format((int)$r['uniq']) ?></td><td class="r"><?= $fmtT((int)$r['secs']) ?></td><td class="r"><?= (int)$r['scroll'] ?>%</td></tr><?php endforeach; ?>
  <?php if (!$pages): ?><tr><td colspan="5" class="hint"><?= tt('ws.no_data') ?></td></tr><?php endif; ?></tbody></table>
  <p class="hint"><b><?= tt('ws.table') ?> 1.</b> <?= tt('ws.tab1') ?></p></div>

<div class="ws-grid">
  <div class="card"><h2>🔎 <?= tt('ws.search_terms') ?></h2><?= ws_hbars($search, fn($r) => e($r['k']) . ((int)$r['x'] > 0 ? ' ' . pill('red', t('ws.no_results', ['n' => (int)$r['x']])) : '')) ?><p class="hint"><?= tt('ws.search_hint') ?></p></div>
  <div class="card"><h2>🔗 <?= tt('ws.sources') ?></h2><?= ws_hbars($refs, fn($r) => e(t('ws.ref_' . $r['k']) !== 'ws.ref_' . $r['k'] ? t('ws.ref_' . $r['k']) : $r['k']), 'n', fn($r) => (int)$r['u'] . ' ' . t('ws.visitors_short')) ?>
    <?php if ($camps): ?><h3 style="margin-top:12px"><?= tt('ws.campaigns') ?></h3><?= ws_hbars($camps, fn($r) => e($r['k'])) ?><?php endif; ?></div>
</div>

<div class="ws-grid3">
  <div class="card"><h2>📱 <?= tt('ws.devices') ?></h2><?= ws_hbars($devices, fn($r) => tt('ws.dev_' . $r['k'])) ?></div>
  <div class="card"><h2>🧭 <?= tt('ws.browsers') ?></h2><?= ws_hbars($browsers, fn($r) => e($r['k'])) ?><h3 style="margin-top:10px"><?= tt('ws.os') ?></h3><?= ws_hbars($os, fn($r) => e($r['k'])) ?></div>
  <div class="card"><h2>🌐 <?= tt('ws.languages') ?></h2><?= ws_hbars($langs, fn($r) => strtoupper(e($r['k']))) ?><h3 style="margin-top:10px">🎬 <?= tt('ws.videos') ?></h3><?= ws_hbars($videos, fn($r) => e($r['k'])) ?></div>
</div>

<div class="ws-grid">
  <div class="card"><h2>🕒 <?= tt('ws.hours') ?></h2><?= ws_heat($hours) ?><p class="hint"><b><?= tt('ws.fig') ?> 2.</b> <?= tt('ws.fig2') ?></p></div>
  <div class="card"><h2>📆 <?= tt('ws.weekdays') ?></h2><div class="ws-wd"><?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $i => $d): ?><div><b><?= number_format($wd[$i]) ?></b><span><?= tt('ws.wd_' . $d) ?></span></div><?php endforeach; ?></div><p class="hint"><b><?= tt('ws.fig') ?> 3.</b> <?= tt('ws.fig3') ?></p></div>
</div>

<div class="ws-grid">
  <div class="card"><h2>✅ <?= tt('ws.conversions') ?></h2>
    <table class="kv">
      <?php foreach ($inq as $r): ?><tr><th><?= tt('web.kind_' . $r['kind']) ?></th><td><b><?= (int)$r['n'] ?></b> <?= tt('ws.received_in_portal') ?></td></tr><?php endforeach; ?>
      <?php foreach ($forms as $r): ?><tr><th><?= tt(ws_form_label($r['k'])) ?></th><td><?= (int)$r['n'] ?> <?= tt('ws.submitted_on_site') ?></td></tr><?php endforeach; ?>
      <?php if (!$inq && !$forms): ?><tr><td class="hint"><?= tt('ws.no_data') ?></td></tr><?php endif; ?>
    </table><p class="hint"><?= tt('ws.conv_hint') ?></p></div>
  <div class="card"><h2>↗ <?= tt('ws.outbound') ?></h2><?= ws_hbars($outb, fn($r) => e($r['k'])) ?><?php if ($downloads): ?><h3 style="margin-top:10px">⬇ <?= tt('ws.downloads') ?></h3><?= ws_hbars($downloads, fn($r) => e($r['k'])) ?><?php endif; ?></div>
</div>
<p class="hint"><?= tt('ws.privacy_note') ?></p>
<?php layout_foot(); ?>
