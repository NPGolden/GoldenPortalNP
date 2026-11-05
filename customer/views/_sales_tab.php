<?php /** Sub-project › Sales — the offers and proformas of this sub-project (GAS-CP-R80-001). Expects $id, $pid, $sp. */
$offers = function_exists('of_has') && of_has() ? all("SELECT * FROM offers WHERE subproject_id = ? ORDER BY id DESC", [$id]) : [];
$pis = function_exists('pi_has') && pi_has() ? all("SELECT * FROM proformas WHERE subproject_id = ? OR offer_id IN (SELECT id FROM offers WHERE subproject_id = ?) ORDER BY id DESC", [$id, $id]) : [];
$fin = fin_visible(); $mon = fn(?float $v, string $c = 'EUR') => $fin ? number_format((float)$v, 2, ',', '.') . ' ' . $c : fin_masked();
$sumOff = 0.0; foreach ($offers as $o) if (in_array($o['status'], ['accepted', 'won', 'ordered'], true)) $sumOff += (float)$o['amount'];
$sumPi = 0.0; $sumPaid = 0.0; foreach ($pis as $p) { if ($p['status'] !== 'cancelled') $sumPi += (float)$p['gross']; if ($p['status'] === 'paid') $sumPaid += (float)$p['gross']; }
?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= count($offers) ?></b><?= tt('spsl.offers') ?><span class="s"><?= tt('spsl.offers_hint') ?></span></div>
  <div class="stat gold"><b><?= e($mon($sumOff)) ?></b><?= tt('spsl.won') ?><span class="s"><?= tt('spsl.won_hint') ?></span></div>
  <div class="stat"><b><?= e($mon($sumPi)) ?></b><?= tt('spsl.pis') ?><span class="s"><?= count($pis) ?> <?= tt('pi.title') ?></span></div>
  <div class="stat"><b><?= e($mon($sumPaid)) ?></b><?= tt('spsl.paid') ?><span class="s"><?= tt('spsl.paid_hint') ?></span></div>
</div>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('spsl.offers') ?></h2></div><div class="btn-row"><?php if (function_exists('of_has') && of_has()): ?><a class="btn btn--ghost btn--sm" href="<?= url('offers', ['q' => $sp['code'] ?: '']) ?>"><?= tt('spsl.all_offers') ?></a><?php endif; ?></div></div>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('spsl.no') ?></th><th><?= tt('spsl.title') ?></th><th class="num"><?= tt('spsl.amount') ?></th><th><?= tt('spsl.sent') ?></th><th><?= tt('spsl.valid') ?></th><th><?= tt('spsl.status') ?></th></tr></thead><tbody>
  <?php if (!$offers): ?><tr><td colspan="6" class="hint"><?= tt('spsl.no_offers') ?></td></tr><?php endif; ?>
  <?php foreach ($offers as $o): ?><tr><td><a href="<?= url('offer', ['id' => (int)$o['id']]) ?>" style="font-family:var(--mono);"><?= e($o['sq_no'] ?: $o['offer_no']) ?></a><?= $o['revision'] ? ' <small class="hint">' . e($o['revision']) . '</small>' : '' ?></td><td><?= e($o['title'] ?? '') ?></td><td class="num"><?= e($mon((float)$o['amount'], $o['currency'])) ?></td><td><?= fmt_date($o['sent_at']) ?></td><td><?= fmt_date($o['valid_until']) ?></td><td><?= function_exists('of_status_pill') ? of_status_pill((string)$o['status']) : e($o['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<div class="card">
  <h2><?= tt('pi.title') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('spsl.no') ?></th><th><?= tt('spsl.title') ?></th><th><?= tt('spsl.issued') ?></th><th><?= tt('spsl.due') ?></th><th class="num"><?= tt('spsl.gross') ?></th><th><?= tt('spsl.status') ?></th></tr></thead><tbody>
  <?php if (!$pis): ?><tr><td colspan="6" class="hint"><?= tt('spsl.no_pis') ?></td></tr><?php endif; ?>
  <?php foreach ($pis as $p): ?><tr><td><a href="<?= url('proforma', ['id' => (int)$p['id']]) ?>" style="font-family:var(--mono);"><?= e($p['pi_no']) ?></a></td><td><?= e($p['title'] ?? '') ?></td><td><?= fmt_date($p['issue_date']) ?></td><td><?= fmt_date($p['due_date']) ?></td><td class="num"><?= e($mon((float)$p['gross'], $p['currency'])) ?></td><td><?= function_exists('pi_status_pill') ? pi_status_pill((string)$p['status']) : e($p['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
