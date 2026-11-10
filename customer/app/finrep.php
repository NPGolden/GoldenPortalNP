<?php
/**
 * Financial statement of a project or a sub-project — GAS-CP-R76-002.
 *   One page (and an Excel workbook) with everything money-related that the portal holds: the offers with their payment
 *   terms, every proforma with what is paid and open, the invoice register, the payment register and the totals
 *   (offered, invoiced, collected, outstanding, overdue, still to invoice). For accounting and the PMs who see money.
 */

function fr_can(): bool { return is_staff() && function_exists('pi_has') && pi_has() && (fin_visible() || (function_exists('acc_is_accounting') && acc_is_accounting())); }
/** Accounting sees the amounts even without the financial flag — this report is their register. */
function fr_money(float $v, string $cur = 'EUR'): string { return number_format($v, 2, ',', '.') . ' ' . $cur; }

function fr_data(int $pid, ?int $sid = null): ?array {
    [$sc, $sa] = scope_sql('p'); $p = one("SELECT p.*, v.name AS vessel, c.name AS customer_name, cu.full_name AS pm_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_users cu ON cu.id = p.pm_user_id WHERE p.id = ? AND {$sc}", array_merge([$pid], $sa));
    if (!$p) return null; $s = $sid ? one('SELECT * FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid]) : null; if ($sid && !$s) return null;
    $w = 'o.project_id = ?'; $a = [$pid]; if ($sid) { $w .= ' AND o.subproject_id = ?'; $a[] = $sid; }
    $offers = all("SELECT o.*, s.code AS sub_code, s.name AS sub_name, cu.full_name AS created_name FROM offers o LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN customer_users cu ON cu.id = o.created_by WHERE {$w} AND o.status <> 'draft' ORDER BY o.sent_at, o.id", $a);
    $termsBy = []; if ($offers) foreach (all('SELECT * FROM offer_terms WHERE offer_id IN (' . implode(',', array_map(fn($o) => (int)$o['id'], $offers)) . ') ORDER BY offer_id, n') as $t) $termsBy[(int)$t['offer_id']][] = $t;
    $wp = 'pi.project_id = ?'; $ap = [$pid]; if ($sid) { $wp .= ' AND pi.subproject_id = ?'; $ap[] = $sid; }
    $pis = all(PI_SELECT . " WHERE {$wp} ORDER BY pi.issue_date, pi.id", $ap);
    $ids = array_map(fn($x) => (int)$x['id'], $pis); $inv = []; $pay = [];
    if ($ids) {
        $in = implode(',', $ids);
        // R95-004: invoices are ledger rows — list the invoice rows of the project with the proformas they settle; payments on every row
        foreach ($pis as $x) if ($x['kind'] === 'invoice') $inv[] = ['id' => (int)$x['id'], 'proforma_id' => (int)$x['id'], 'invoice_no' => (string)$x['pi_no'], 'invoice_date' => (string)$x['issue_date'], 'pi_no' => (string)($x['settles_nos'] ?: '—'), 'amount' => (float)$x['gross'], 'settled' => (float)$x['adv_sum'], 'paid_amount' => (float)$x['adv_sum'], 'paid_at' => (string)($x['paid_at'] ?? ''), 'currency' => $x['currency'], 'status' => $x['status']];
        if (function_exists('ppay_has') && ppay_has()) foreach (all("SELECT pp.*, pi.pi_no, pi.currency, pi.kind, NULL AS invoice_no, cu.full_name AS by_name FROM proforma_payments pp JOIN proformas pi ON pi.id = pp.proforma_id LEFT JOIN customer_users cu ON cu.id = pp.created_by WHERE pp.proforma_id IN ({$in}) ORDER BY pp.paid_on, pp.id") as $r) $pay[] = $r;
    }
    $cur = $pis[0]['currency'] ?? ($offers[0]['currency'] ?? 'EUR'); $today = date('Y-m-d');
    $T = ['offered_net' => 0.0, 'offered_gross' => 0.0, 'open_offers' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0, 'to_invoice' => 0.0, 'invoices' => 0.0, 'n_offers' => 0, 'n_pi' => 0, 'n_inv' => count($inv), 'n_pay' => count($pay)];
    foreach ($offers as &$o) { $b = of_status_behaves((string)$o['status']); $o['behaves'] = $b; $o['gross'] = function_exists('ppay_offer_cap') ? ppay_offer_cap($o) : (float)$o['amount'] * (1 + (float)($o['vat_pct'] ?? 0) / 100); $o['terms'] = $termsBy[(int)$o['id']] ?? [];
        if (in_array($b, ['approved', 'closed_won'], true)) { $T['offered_net'] += (float)$o['amount']; $T['offered_gross'] += $o['gross']; $T['n_offers']++; } elseif ($b === 'open') $T['open_offers'] += (float)$o['amount']; } unset($o);
    // R95-004 one ledger: 'invoiced' (requested) = invoices + not-yet-invoiced proforma remainders; collected = payments once (on the invoice, or advances on the proforma)
    foreach ($pis as &$x) { $x['paid'] = (float)$x['paid_sum']; $g = $x['kind'] === 'invoice' ? (float)$x['gross'] : max(0.0, (float)$x['gross'] - (float)$x['inv_sum']); $x['open'] = max(0, (float)$x['open_sum']); $x['overdue'] = in_array($x['status'], ['issued', 'sent', 'partly_paid'], true) && !empty($x['due_date']) && $x['due_date'] < $today && $x['open'] > 0.005;
        if (!in_array($x['status'], ['draft', 'cancelled'], true)) { $T['invoiced'] += $g; $T['collected'] += min($g, (float)$x['adv_sum']); $T['outstanding'] += $x['open']; if ($x['overdue']) $T['overdue'] += $x['open']; if ($x['kind'] !== 'invoice') $T['n_pi']++; } } unset($x);
    foreach ($inv as $r) $T['invoices'] += (float)$r['amount'];
    $T['to_invoice'] = max(0, $T['offered_gross'] - $T['invoiced']);
    // R95-004: labour hours from the accounting register — billed (reported / invoiced), open, and excluded (not billed; NC = marked at source)
    $T['h_billed'] = $T['h_open'] = $T['h_excluded'] = $T['h_nc'] = 0.0;
    if (function_exists('acc_has') && acc_has()) { $wh = 'project_id = ?'; $ah = [$pid]; if ($sid) { $wh .= ' AND subproject_id = ?'; $ah[] = $sid; }
        foreach (all("SELECT state, SUM(hours) h, SUM(IF(exclude_reason LIKE 'NC %', hours, 0)) nc FROM acc_lines WHERE {$wh} GROUP BY state", $ah) as $r) { $h = (float)$r['h']; if (in_array($r['state'], ['reported', 'invoiced'], true)) $T['h_billed'] += $h; elseif (in_array($r['state'], ['open', 'draft'], true)) $T['h_open'] += $h; elseif ($r['state'] === 'excluded') { $T['h_excluded'] += $h; $T['h_nc'] += (float)$r['nc']; } } }
    return ['project' => $p, 'sub' => $s, 'offers' => $offers, 'proformas' => $pis, 'invoices' => $inv, 'payments' => $pay, 'totals' => $T, 'cur' => $cur, 'doc_no' => (function_exists('br_prefix') ? br_prefix($pid) : 'GAS') . '-FS-' . date('Y-m-d') . '-' . ($p['project_code'] ?: $pid) . ($s ? '-' . $s['code'] : '')];
}
/** Excel workbook: Summary · Offers · Proformas · Invoices · Payments. */
function fr_xlsx(array $D): void {
    require_once __DIR__ . '/xlsxw.php'; $T = $D['totals']; $cur = $D['cur'];
    $sum = [[t('fr.offered_net'), round($T['offered_net'], 2)], [t('fr.offered_gross'), round($T['offered_gross'], 2)], [t('fr.open_offers'), round($T['open_offers'], 2)], [t('fs.invoiced'), round($T['invoiced'], 2)], [t('fs.collected'), round($T['collected'], 2)], [t('fs.outstanding'), round($T['outstanding'], 2)], [t('fs.overdue'), round($T['overdue'], 2)], [t('fr.to_invoice'), round($T['to_invoice'], 2)], [t('fr.invoices_total'), round($T['invoices'], 2)]];
    $of = []; foreach ($D['offers'] as $o) $of[] = [$o['offer_no'] . ' ' . $o['revision'], (string)$o['title'], (string)($o['sub_code'] ?? ''), of_status_label($o['status']), (string)($o['sent_at'] ?? ''), round((float)$o['amount'], 2), (float)($o['vat_pct'] ?? 0), round($o['gross'], 2), implode(' | ', array_map(fn($tm) => $tm['n'] . ': ' . ((float)$tm['pct'] > 0 ? $tm['pct'] . ' %' : round((float)$tm['amount'], 2)) . ' ' . $tm['trigger_text'] . ' +' . $tm['due_days'] . 'd', $o['terms']))];
    $pi = []; foreach ($D['proformas'] as $x) $pi[] = [(string)$x['pi_no'], (string)$x['offer_no'], (string)($x['sub_code'] ?? ''), (string)$x['title'], (string)$x['issue_date'], (string)$x['due_date'], t('pi.s_' . $x['status']), round((float)$x['amount_net'], 2), (float)$x['vat_pct'], round((float)$x['gross'], 2), round($x['paid'], 2), round($x['open'], 2), $x['overdue'] ? t('fs.overdue') : ''];
    $in = []; foreach ($D['invoices'] as $r) $in[] = [(string)$r['invoice_no'], (string)$r['invoice_date'], (string)$r['pi_no'], round((float)$r['amount'], 2), (float)$r['settled'] > 0 ? round((float)$r['settled'], 2) : round((float)$r['paid_amount'], 2), (string)($r['paid_at'] ?? ''), (float)$r['settled'] > 0 ? t('pay.via_payments') : '', (string)($r['note'] ?? '')];
    $pa = []; foreach ($D['payments'] as $r) $pa[] = [(string)$r['paid_on'], (string)$r['pi_no'], round((float)$r['amount'], 2), t('pay.m_' . $r['method']), (string)($r['reference'] ?? ''), (string)($r['invoice_no'] ?? ''), (string)($r['note'] ?? ''), (string)($r['by_name'] ?? '')];
    xlsx_download($D['doc_no'] . '.xlsx', [
        ['name' => 'Summary', 'first' => true, 'header' => [t('fr.item'), $cur], 'rows' => $sum],
        ['name' => 'Offers', 'header' => [t('of.no'), t('of.title_f'), t('sp.one'), t('th.status'), t('of.sent'), t('fr.net'), 'VAT %', t('fr.gross'), t('fr.terms')], 'rows' => $of],
        ['name' => 'Proformas', 'header' => [t('pi.no'), t('of.no'), t('sp.one'), t('of.title_f'), t('pi.issue_date'), t('pi.due'), t('th.status'), t('fr.net'), 'VAT %', t('fr.gross'), t('pi.paid_lbl'), t('fr.open'), ''], 'rows' => $pi],
        ['name' => 'Invoices', 'header' => [t('pi.invoice_no'), t('th.date'), t('pi.no'), t('pt.amount'), t('pi.paid_lbl'), t('pi.paid_on'), '', t('pd.note')], 'rows' => $in],
        ['name' => 'Payments', 'header' => [t('th.date'), t('pi.no'), t('pt.amount'), t('pay.method'), t('pay.reference'), t('pay.invoiced'), t('pd.note'), t('th.by')], 'rows' => $pa],
    ]);
}
