<?php
/** In / Out — the process as a flow (GAS-CP-R97-189).  Included by views/inout.php (tab "flow"), for every member of staff.
 *  One centre line from the notice to the personnel evaluation; the two decisions branch symmetrically left and right.
 *  Orthogonal connectors only; the text of each box is wrapped HTML inside the drawing, so every language fits. */
$P = ['m' => io_diff_min(), 'o' => io_ot_min(), 't' => io_tol()];
$W = 220; $H = 70; $X = ['l' => 20, 'c' => 280, 'r' => 540]; $CX = 390; $Y = fn(int $row) => 10 + ($row - 1) * 100;
$box = function (string $col, int $row, string $key, string $tone, ?int $no = null) use ($W, $H, $X, $Y, $P): string {
    $c = ['navy' => ['#1F3A5F', '#fff'], 'gold' => ['#C9A227', '#1F3A5F'], 'green' => ['#2E7D32', '#fff'], 'line' => ['#fff', '#1F3A5F']][$tone];
    return '<rect x="' . $X[$col] . '" y="' . $Y($row) . '" width="' . $W . '" height="' . $H . '" rx="8" style="fill:' . $c[0] . ';stroke:#1F3A5F;stroke-width:1.5"/>'
         . '<foreignObject x="' . ($X[$col] + 6) . '" y="' . ($Y($row) + 3) . '" width="' . ($W - 12) . '" height="' . ($H - 6) . '"><div xmlns="http://www.w3.org/1999/xhtml" class="iofl-b" style="color:' . $c[1] . ';">'
         . '<b>' . ($no ? $no . ' · ' : '') . e(t('io.fl_' . $key . 't')) . '</b><span>' . e(t('io.fl_' . $key . 's', $P)) . '</span></div></foreignObject>';
};
$down = fn(int $row) => '<path d="M' . $CX . ' ' . ($Y($row) + $H) . ' V' . ($Y($row + 1) - 2) . '" class="iofl-l" marker-end="url(#iofl-a)"/>';
$y3 = $Y(3) + $H / 2; $y6 = $Y(6) + $H / 2; $m3 = $Y(3) + $H + 15;
?>
<style nonce="<?= csp_nonce() ?>">
.iofl{max-width:820px;margin:0 auto;}.iofl svg{width:100%;height:auto;display:block;}
.iofl-b{height:100%;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;font:12px/1.25 Aptos,"Segoe UI",system-ui,sans-serif;}
.iofl-b b{font-size:13px;}.iofl-b span{font-size:11px;opacity:.95;margin-top:2px;}
.iofl-l{fill:none;stroke:#1F3A5F;stroke-width:1.6;}
.iofl-steps li{margin:0 0 8px;text-align:justify;}
</style>
<div class="card"><p class="hint"><?= tt('io.fl_lede') ?></p>
<div class="iofl"><svg viewBox="0 0 780 890" role="img" aria-label="<?= e(t('io.t_flow')) ?>" style="background:#fff;border-radius:10px;">
<defs><marker id="iofl-a" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10 z" style="fill:#1F3A5F"/></marker></defs>
<?= $box('c', 1, '1', 'navy', 1) . $down(1) . $box('c', 2, '2', 'navy', 2) . $down(2) . $box('c', 3, '3', 'line', 3) ?>
<?= $box('l', 3, '3l', 'gold') . $box('r', 3, '3r', 'green') ?>
<path d="M<?= $X['c'] ?> <?= $y3 ?> H<?= $X['l'] + $W + 2 ?>" class="iofl-l" marker-end="url(#iofl-a)"/><path d="M<?= $X['c'] + $W ?> <?= $y3 ?> H<?= $X['r'] - 2 ?>" class="iofl-l" marker-end="url(#iofl-a)"/>
<path d="M<?= $X['l'] + $W / 2 ?> <?= $Y(3) + $H ?> V<?= $m3 ?> H<?= $X['r'] + $W / 2 ?> V<?= $Y(3) + $H ?>" class="iofl-l"/><path d="M<?= $CX ?> <?= $m3 ?> V<?= $Y(4) - 2 ?>" class="iofl-l" marker-end="url(#iofl-a)"/>
<?= $box('c', 4, '4', 'navy', 4) . $down(4) . $box('c', 5, '5', 'navy', 5) . $down(5) . $box('c', 6, '6', 'line', 6) ?>
<?= $box('l', 6, '6l', 'line') . $box('r', 6, '6r', 'line') ?>
<path d="M<?= $X['l'] + $W ?> <?= $y6 ?> H<?= $X['c'] - 2 ?>" class="iofl-l" marker-end="url(#iofl-a)"/><path d="M<?= $X['r'] ?> <?= $y6 ?> H<?= $X['c'] + $W + 2 ?>" class="iofl-l" marker-end="url(#iofl-a)"/>
<?= $down(6) . $box('c', 7, '7', 'navy', 7) . $down(7) . $box('c', 8, '8', 'navy', 8) . $down(8) . $box('c', 9, '9', 'gold', 9) ?>
</svg></div></div>
<div class="card"><h3 class="ac-h"><?= tt('io.fl_steps') ?></h3><ol class="iofl-steps"><?php for ($i = 1; $i <= 9; $i++): ?><li><?= e(t('io.fl_p' . $i, $P)) ?></li><?php endfor; ?></ol></div>
<div class="card"><h3 class="ac-h">⭐ <?= tt('io.fl_ev_h') ?></h3><p style="text-align:justify;"><?= e(t('io.fl_ev_p1')) ?></p><p style="text-align:justify;"><?= e(t('io.fl_ev_p2')) ?></p><p class="hint"><?= e(t('io.fl_ev_p3')) ?></p></div>
