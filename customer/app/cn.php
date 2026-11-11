<?php
/**
 * Customer credit notes — GAS-CP-R95-006.
 *
 *   A credit note is a ledger row (proformas, kind 'credit') with NEGATIVE amounts, registered by its accounting number like a
 *   direct invoice. It is standalone or raised for an invoice (credit_of_id). What it settles is recorded in pi_credits
 *   (credit note → invoice, amount); what is not applied to an invoice is the REMAINDER, and the accountant chooses what
 *   happens to it (credit_mode): 'refund' — money goes back to the customer (a refund is a negative payment on the credit note) —
 *   or 'carry' — it stays as a credit balance of the billing party until it is applied to a later invoice.
 *
 *   open of an invoice      = gross − payments − credit notes applied
 *   open of a credit note   = −(|gross| − applied − refunded)        (PI_OPEN_SQL in app/pi.php)
 *
 *   cn_has()                    schema present (migrate_cp_r95_006)
 *   cn_remainder($r)            what is neither applied nor refunded (positive figure)
 *   cn_applied($creditId)       invoices this credit note settles
 *   cn_on_invoice($invId)       credit notes applied to an invoice
 *   cn_candidates($cr)          open invoices the remainder may be applied to (same billing party, else same project; same currency)
 *   cn_apply($cr, $inv, $amt)   apply (null amount = as much as possible)        cn_unapply($id)
 *   cn_set_mode($cr, $mode)     refund | carry
 */
declare(strict_types=1);

function cn_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'pi_credits'") && (bool) scalar("SHOW COLUMNS FROM proformas LIKE 'credit_mode'"); } catch (Throwable $e) { $h = false; } } return $h; }
function cn_modes(): array { return ['refund' => t('cn.m_refund'), 'carry' => t('cn.m_carry')]; }
function cn_remainder(array $r): float { return max(0.0, round(-(float)($r['open_sum'] ?? 0), 2)); }

function cn_applied(int $creditId): array {
    return cn_has() ? all("SELECT k.id AS app_id, k.amount, x.id, x.pi_no, x.title, x.issue_date, x.gross, x.status, x.currency FROM pi_credits k JOIN proformas x ON x.id = k.invoice_id WHERE k.credit_id = ? ORDER BY x.issue_date, x.id", [$creditId]) : [];
}
function cn_on_invoice(int $invId): array {
    return cn_has() ? all("SELECT k.id AS app_id, k.amount, x.id, x.pi_no, x.title, x.issue_date, x.gross, x.status, x.currency, x.credit_mode FROM pi_credits k JOIN proformas x ON x.id = k.credit_id WHERE k.invoice_id = ? ORDER BY x.issue_date, x.id", [$invId]) : [];
}
/** Credit notes raised for an invoice (credit_of_id), applied or not — shown on the invoice. */
function cn_for_invoice(int $invId): array {
    return cn_has() ? all("SELECT pi.id, pi.pi_no, pi.title, pi.issue_date, pi.gross, pi.status, pi.currency, pi.credit_mode, " . PI_OPEN_SQL . " AS open_sum, (SELECT COALESCE(SUM(k.amount),0) FROM pi_credits k WHERE k.credit_id = pi.id AND k.invoice_id = ?) AS applied_here
                           FROM proformas pi WHERE pi.kind = 'credit' AND pi.status <> 'cancelled' AND (pi.credit_of_id = ? OR EXISTS (SELECT 1 FROM pi_credits k WHERE k.credit_id = pi.id AND k.invoice_id = ?)) ORDER BY pi.issue_date, pi.id", [$invId, $invId, $invId]) : [];
}
/** Open invoices a credit note's remainder may be applied to. */
function cn_candidates(array $cr): array {
    if (!cn_has()) return [];
    $w = !empty($cr['billing_org_id']) ? 'pi.billing_org_id = ?' : 'pi.project_id = ?'; $a = [!empty($cr['billing_org_id']) ? (int)$cr['billing_org_id'] : (int)$cr['project_id']];
    [$sc, $sa] = scope_sql('p');
    $rows = all("SELECT pi.id, pi.pi_no, pi.title, pi.issue_date, pi.gross, pi.currency, p.project_code, v.name AS vessel, " . PI_OPEN_SQL . " AS open_sum FROM proformas pi JOIN projects p ON p.id = pi.project_id LEFT JOIN vessels v ON v.id = pi.vessel_id
                 WHERE pi.kind = 'invoice' AND pi.status IN ('issued','sent','partly_paid') AND pi.currency = ? AND {$w} AND {$sc} ORDER BY pi.issue_date, pi.id", array_merge([(string)$cr['currency']], $a, $sa));
    return array_values(array_filter($rows, fn($x) => (float)$x['open_sum'] > 0.005));
}
function cn_apply(int $creditId, int $invId, ?float $amount = null): float {
    if (!cn_has() || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    $cr = one("SELECT pi.*, " . PI_OPEN_SQL . " AS open_sum FROM proformas pi WHERE pi.id = ? AND pi.kind = 'credit'", [$creditId]);
    $inv = one("SELECT pi.*, " . PI_OPEN_SQL . " AS open_sum FROM proformas pi WHERE pi.id = ? AND pi.kind = 'invoice'", [$invId]);
    if (!$cr || !$inv || in_array($cr['status'], ['draft', 'cancelled'], true) || in_array($inv['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('cn.bad_invoice'));
    if ((string)$cr['currency'] !== (string)$inv['currency']) throw new RuntimeException(t('cn.currency'));
    // same billing party (or, when none is recorded, the same project) — a credit of one debtor never settles another debtor's invoice
    $same = (int)($cr['billing_org_id'] ?? 0) && (int)($inv['billing_org_id'] ?? 0) ? (int)$cr['billing_org_id'] === (int)$inv['billing_org_id'] : (int)$cr['project_id'] === (int)$inv['project_id'];
    if (!$same) throw new RuntimeException(t('cn.other_party'));
    $room = min(cn_remainder($cr), max(0.0, round((float)$inv['open_sum'], 2)));
    if ($amount === null) $amount = $room; $amount = round($amount, 2);
    if ($amount <= 0.005) { if ($room <= 0.005) return 0.0; throw new RuntimeException(t('pay.need_amount')); }
    if ($amount > $room + 0.005) throw new RuntimeException(t('cn.over', ['v' => pi_money($room, (string)$cr['currency'])]));
    q('INSERT INTO pi_credits (credit_id, invoice_id, amount, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)', [$creditId, $invId, $amount, (int)(current_user()['id'] ?? 0) ?: null]);
    pi_refresh_status($invId); pi_refresh_status($creditId); cache_bump_later('fs');
    return $amount;
}
function cn_unapply(int $appId): int {
    if (!cn_has() || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    $k = one('SELECT * FROM pi_credits WHERE id = ?', [$appId]); if (!$k) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM pi_credits WHERE id = ?', [$appId]);
    pi_refresh_status((int)$k['invoice_id']); pi_refresh_status((int)$k['credit_id']); cache_bump_later('fs');
    return (int)$k['credit_id'];
}
function cn_set_mode(int $creditId, string $mode): void {
    if (!cn_has() || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    if (!isset(cn_modes()[$mode]) || !scalar("SELECT COUNT(*) FROM proformas WHERE id = ? AND kind = 'credit'", [$creditId])) throw new RuntimeException(t('err.notfound'));
    q('UPDATE proformas SET credit_mode = ? WHERE id = ?', [$mode, $creditId]); cache_bump_later('fs');
}
