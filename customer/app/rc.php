<?php
/**
 * Report colour code (option B — marker bar per row).                                        GAS-CP-R29-007
 *
 * One meaning everywhere: red = overdue · failed · high risk · rejected / disputed · open too long; amber = due soon
 * (RC_SOON_DAYS) · pending a decision · medium risk · draft / partial; green = done · on time · accepted · closed ·
 * no / low risk; navy = in progress / information; grey = not applicable. The PDF writers draw a thin bar at the left
 * of the row (and print dates already passed in bold red); the Word writer adds a narrow filled cell; every document
 * carries the legend line under its header. A row says what it is with 'tone' => 'red|amber|green|navy|grey'.
 */
declare(strict_types=1);

const RC_SOON_DAYS = 7;
const RC_HEX = ['red' => '9B2C2C', 'amber' => 'C89B3C', 'green' => '2F7D5C', 'navy' => '0B1E2D', 'grey' => 'C8CED6'];
function rc_hex(?string $tone): ?string { return $tone && isset(RC_HEX[$tone]) ? RC_HEX[$tone] : null; }
/** Tone for a due / deadline date: passed → red, within RC_SOON_DAYS → amber, otherwise null (or green when $greenWhenLater). */
function rc_due(?string $date, bool $greenWhenLater = false, ?string $ref = null): ?string {
    if (!$date) return null; $d = substr($date, 0, 10); $ref = $ref ?: date('Y-m-d');
    if ($d < $ref) return 'red';
    if ((strtotime($d) - strtotime($ref)) / 86400 <= RC_SOON_DAYS) return 'amber';
    return $greenWhenLater ? 'green' : null;
}
/** Tone for a signed number of days left (negative = overdue). */
function rc_days(?int $days, bool $greenWhenLater = false): ?string { if ($days === null) return null; return $days < 0 ? 'red' : ($days <= RC_SOON_DAYS ? 'amber' : ($greenWhenLater ? 'green' : null)); }
/** Tone for the common status words of the portal. */
function rc_status(string $s): ?string {
    return match ($s) {
        'rejected', 'disputed', 'failed', 'high', 'cancelled', 'overdue' => 'red',
        'pending', 'submitted', 'checking', 'waiting_customer', 'waiting', 'draft', 'medium', 'sent', 'requested', 'planned', 'open' => 'amber',
        'approved', 'accepted', 'closed', 'resolved', 'done', 'issued', 'completed', 'finished', 'performed', 'received', 'none', 'low', 'approved_comments', 'to_be_invoiced' => 'green',
        'in_progress', 'active', 'started' => 'navy',
        'na', 'n/a' => 'grey',
        default => null,
    };
}
/** Tone for an open-issues register row. */
function rc_issue(array $i): ?string {
    if ($i['status'] === 'closed') return 'green';
    if (!empty($i['due_on']) && $i['due_on'] < date('Y-m-d')) return 'red';
    if (($i['priority'] ?? '') === 'high') return 'red';
    if (!empty($i['due_on']) && (strtotime($i['due_on']) - time()) / 86400 <= RC_SOON_DAYS) return 'amber';
    return $i['status'] === 'in_progress' ? 'navy' : 'amber';
}
/** Tone for a ticket row (open too long = red). */
function rc_ticket(array $t): ?string {
    if (in_array($t['status'], ['resolved', 'closed'], true)) return 'green';
    if ($t['status'] === 'waiting_customer') return 'amber';
    $age = (time() - strtotime((string)$t['created_at'])) / 86400;
    return $age > 30 ? 'red' : ($t['status'] === 'in_progress' ? 'navy' : 'amber');
}
/** Tone for a stage percentage (N/A grey, 0 grey, < 100 amber, 100 green). */
function rc_pct(?int $p): ?string { if ($p === null) return null; if (function_exists('pct_na') && pct_na($p)) return 'grey'; return $p >= 100 ? 'green' : ($p <= 0 ? 'grey' : 'amber'); }
function rc_legend_items(): array { return [['red', t('rc.red')], ['amber', t('rc.amber', ['n' => RC_SOON_DAYS])], ['green', t('rc.green')], ['navy', t('rc.navy')], ['grey', t('rc.grey')]]; }
/** PDF: the legend line (swatch + text per tone) at $y; returns the height used. */
function rc_legend_pdf($pdf, float $L, float $y, float $W): float {
    $pdf->setFont('R', 6.8); $x = $L; $pdf->setColor('#1F2937'); $pdf->text($x, $y + 2.6, t('rc.legend') . ':'); $x += $pdf->width(t('rc.legend') . ':') + 3;
    foreach (rc_legend_items() as [$tone, $label]) {
        $w = $pdf->width($label) + 6.5; if ($x + $w > $L + $W) { $x = $L; $y += 3.6; }
        $pdf->setColor('#' . RC_HEX[$tone]); $pdf->rect($x, $y + 0.3, 2.2, 2.6, 'F'); $pdf->setColor('#1F2937'); $pdf->text($x + 3.2, $y + 2.6, $label); $x += $w;
    }
    return 4.2;
}
/** PDF: the marker bar of a row. */
function rc_bar_pdf($pdf, ?string $tone, float $x, float $y, float $h): void { if (!$tone || !isset(RC_HEX[$tone])) return; $pdf->setColor('#' . RC_HEX[$tone]); $pdf->rect($x + 0.35, $y + 0.35, 1.5, max(0.5, $h - 0.7), 'F'); }
/** Word: the legend paragraph. */
function rc_legend_docx($d): void {
    $cells = [['text' => t('rc.legend') . ':', 'sz' => 7.5, 'b' => true]];
    foreach (rc_legend_items() as [$tone, $label]) { $cells[] = ['text' => '■', 'sz' => 9, 'color' => RC_HEX[$tone], 'align' => 'right']; $cells[] = ['text' => $label, 'sz' => 7.5]; }
    $widths = [1100]; foreach (rc_legend_items() as $_) { $widths[] = 240; $widths[] = 1468; }
    $d->grid([$cells], $widths, ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
}

/** Stretch (or shrink) a column set proportionally so every table spans the full text width — all report tables aligned on the same right edge. */
function rc_fit_cols(array $cols, float $W = 180.0): array {
    $sum = (float) array_sum(array_column($cols, 1));
    if ($sum <= 0 || abs($sum - $W) < 0.05) return $cols;
    $k = $W / $sum; $acc = 0.0; $last = array_key_last($cols);
    foreach ($cols as $i => &$c) { $c[1] = $i === $last ? round($W - $acc, 2) : round($c[1] * $k, 2); $acc += $c[1]; }
    return $cols;
}
