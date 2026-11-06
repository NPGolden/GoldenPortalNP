<?php
/** Request for Offer to several suppliers — GAS-CP-R88-001
 *  An RFQ (po_orders.kind = rfq) keeps one set of lines but is now sent to N invited suppliers (po_rfq_suppliers); each supplier's
 *  prices and lead times are typed per line (po_rfq_quotes), a comparison table marks the lowest price per line and the cheapest /
 *  fastest offer, and the award creates the purchase order for the chosen supplier with that supplier's prices. The header
 *  supplier (po_orders.supplier_id) is the first invited one and becomes the awarded one. Older RFQs were migrated as one-supplier
 *  requests, so nothing changes for them.
 */
declare(strict_types=1);

const RFO_STATES = ['invited', 'sent', 'quoted', 'declined', 'awarded', 'lost'];
const RFO_TONE = ['invited' => 'mute', 'sent' => 'gold', 'quoted' => 'navy', 'declined' => 'bad', 'awarded' => 'ok', 'lost' => 'mute'];

function rfo_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('po_has') && po_has() && (bool) db()->query("SHOW TABLES LIKE 'po_rfq_suppliers'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function rfo_pill(string $s): string { return pill(RFO_TONE[$s] ?? 'mute', t('rfo.s_' . $s)); }

/** Invited suppliers of an RFQ with their quote totals (over the non-cancelled lines, qty × unit price) and the number of lines quoted. */
function rfo_suppliers(int $rfqId): array {
    if (!rfo_has()) return [];
    return all("SELECT rs.*, co.name AS supplier, co.email AS supplier_co_email, sd.currency AS sup_currency, sd.lead_days AS sup_lead,
                       (SELECT COUNT(*) FROM po_rfq_quotes q JOIN po_lines l ON l.id = q.po_line_id WHERE q.rfq_supplier_id = rs.id AND q.unit_price IS NOT NULL AND l.status <> 'cancelled') AS n_quoted,
                       (SELECT COALESCE(SUM(l.qty * q.unit_price), 0) FROM po_rfq_quotes q JOIN po_lines l ON l.id = q.po_line_id WHERE q.rfq_supplier_id = rs.id AND q.unit_price IS NOT NULL AND l.status <> 'cancelled') AS quote_total,
                       (SELECT MAX(q.lead_days) FROM po_rfq_quotes q JOIN po_lines l ON l.id = q.po_line_id WHERE q.rfq_supplier_id = rs.id AND l.status <> 'cancelled') AS lead_max
                FROM po_rfq_suppliers rs JOIN companies co ON co.id = rs.supplier_id LEFT JOIN supplier_details sd ON sd.company_id = co.id WHERE rs.rfq_id = ? ORDER BY rs.id", [$rfqId]);
}
function rfo_supplier(int $rfqId, int $rsId): ?array { foreach (rfo_suppliers($rfqId) as $r) if ((int)$r['id'] === $rsId) return $r; return null; }
function rfo_supplier_by_company(int $rfqId, int $supplierId): ?array { foreach (rfo_suppliers($rfqId) as $r) if ((int)$r['supplier_id'] === $supplierId) return $r; return null; }
/** Quotes of an RFQ: [rfq_supplier_id][po_line_id] => ['unit_price', 'lead_days', 'note'] */
function rfo_quotes(int $rfqId): array { $g = []; if (rfo_has()) foreach (all('SELECT q.* FROM po_rfq_quotes q JOIN po_rfq_suppliers rs ON rs.id = q.rfq_supplier_id WHERE rs.rfq_id = ?', [$rfqId]) as $q) $g[(int)$q['rfq_supplier_id']][(int)$q['po_line_id']] = $q; return $g; }

/** Keep the invited-supplier list in step with the header supplier (older RFQs, or an RFQ created before the tables existed). */
function rfo_ensure(array $o): void { if (!rfo_has() || $o['kind'] !== 'rfq') return; q('INSERT IGNORE INTO po_rfq_suppliers (rfq_id, supplier_id, status, sent_at, sent_to, supplier_ref, delivery_date, currency) VALUES (?,?,?,?,?,?,?,?)', [(int)$o['id'], (int)$o['supplier_id'], match ($o['status']) { 'sent' => 'sent', 'quoted' => 'quoted', 'awarded' => 'awarded', default => 'invited' }, $o['sent_at'], $o['sent_to'], $o['supplier_ref'], $o['delivery_date'], $o['currency']]); }

function rfo_add_supplier(int $rfqId, int $supplierId): int {
    require_po_admin(); $o = po_load($rfqId); if (!$o || $o['kind'] !== 'rfq' || !in_array($o['status'], ['draft', 'sent', 'quoted'], true)) throw new RuntimeException(t('err.noaccess'));
    $S = sup_load($supplierId); if (!$S || !($S['kind'] === 'supplier' || !empty($S['is_supplier']))) throw new RuntimeException(t('po.need_supplier'));
    rfo_ensure($o); if (rfo_supplier_by_company($rfqId, $supplierId)) throw new RuntimeException(t('rfo.already_invited', ['n' => $S['name']]));
    q('INSERT INTO po_rfq_suppliers (rfq_id, supplier_id, currency) VALUES (?,?,?)', [$rfqId, $supplierId, ($S['sup_currency'] ?? '') ?: $o['currency']]); $id = (int) db()->lastInsertId();
    po_event($rfqId, 'invite', $S['name']); return $id;
}
function rfo_remove(int $rfqId, int $rsId): void {
    require_po_admin(); $o = po_load($rfqId); $rs = rfo_supplier($rfqId, $rsId); if (!$o || !$rs || !in_array($o['status'], ['draft', 'sent', 'quoted'], true)) throw new RuntimeException(t('err.noaccess'));
    if ((int)$rs['supplier_id'] === (int)$o['supplier_id'] && count(rfo_suppliers($rfqId)) > 1) {   // the header supplier moves to the next invited one
        $next = null; foreach (rfo_suppliers($rfqId) as $r) if ((int)$r['id'] !== $rsId) { $next = $r; break; }
        if ($next) q('UPDATE po_orders SET supplier_id = ?, supplier_email = NULL, supplier_contact = NULL WHERE id = ?', [(int)$next['supplier_id'], $rfqId]);
    } elseif ((int)$rs['supplier_id'] === (int)$o['supplier_id']) throw new RuntimeException(t('rfo.keep_one'));
    if (!in_array($rs['status'], ['invited', 'declined'], true)) throw new RuntimeException(t('rfo.cannot_remove'));
    q('DELETE FROM po_rfq_quotes WHERE rfq_supplier_id = ?', [$rsId]); q('DELETE FROM po_rfq_suppliers WHERE id = ?', [$rsId]); po_event($rfqId, 'uninvite', $rs['supplier']);
}
/** The RFQ went to this supplier (✉ card or "mark sent"): the row and the RFQ itself count as sent. */
function rfo_mark_sent(int $rfqId, int $supplierId, array $to = []): void {
    $o = po_load($rfqId); if (!$o || $o['kind'] !== 'rfq' || !po_admin()) throw new RuntimeException(t('err.noaccess')); rfo_ensure($o);
    $rs = rfo_supplier_by_company($rfqId, $supplierId); if (!$rs) { rfo_add_supplier($rfqId, $supplierId); $rs = rfo_supplier_by_company($rfqId, $supplierId); }
    if (in_array($o['status'], ['draft', 'sent', 'quoted'], true)) po_mark_sent($rfqId, $to);   // overall state + log (draft → sent once)
    q("UPDATE po_rfq_suppliers SET status = IF(status IN ('invited','sent'), 'sent', status), sent_at = COALESCE(sent_at, NOW()), sent_to = ? WHERE id = ?", [$to ? mb_substr(implode(', ', $to), 0, 400) : $rs['sent_to'], (int)$rs['id']]);
    po_event($rfqId, 'send_sup', $rs['supplier'] . ($to ? ' · ' . implode(', ', $to) : ''));
}
/** Prices and lead times of one supplier, typed per line. */
function rfo_quote_from_post(): int {
    require_po_admin(); $rfqId = (int)($_POST['id'] ?? 0); $rsId = (int)($_POST['rs_id'] ?? 0); $o = po_load($rfqId); $rs = rfo_supplier($rfqId, $rsId);
    if (!$o || !$rs || $o['kind'] !== 'rfq' || !in_array($o['status'], ['draft', 'sent', 'quoted'], true) || in_array($rs['status'], ['awarded', 'lost'], true)) throw new RuntimeException(t('err.noaccess'));
    $n = 0;
    foreach (all("SELECT id FROM po_lines WHERE order_id = ? AND status <> 'cancelled'", [$rfqId]) as $l) {
        $lid = (int)$l['id']; $pv = trim((string)($_POST['price'][$lid] ?? '')); $lv = trim((string)($_POST['lead'][$lid] ?? '')); $nv = mb_substr(trim((string)($_POST['qnote'][$lid] ?? '')), 0, 160) ?: null;
        $price = $pv !== '' ? max(0.0, round((float) str_replace(',', '.', $pv), 4)) : null; $lead = $lv !== '' ? max(0, min(999, (int)$lv)) : null;
        if ($price === null && $lead === null && $nv === null) { q('DELETE FROM po_rfq_quotes WHERE rfq_supplier_id = ? AND po_line_id = ?', [$rsId, $lid]); continue; }
        q('INSERT INTO po_rfq_quotes (rfq_supplier_id, po_line_id, unit_price, lead_days, note) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price), lead_days = VALUES(lead_days), note = VALUES(note)', [$rsId, $lid, $price, $lead, $nv]); if ($price !== null) $n++;
    }
    $cur = preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : ($rs['currency'] ?: $o['currency']);
    $total = (float) scalar("SELECT COALESCE(SUM(l.qty * q.unit_price), 0) FROM po_rfq_quotes q JOIN po_lines l ON l.id = q.po_line_id WHERE q.rfq_supplier_id = ? AND q.unit_price IS NOT NULL AND l.status <> 'cancelled'", [$rsId]);
    q("UPDATE po_rfq_suppliers SET status = IF(? > 0, 'quoted', IF(status = 'quoted', 'sent', status)), quoted_at = IF(? > 0, COALESCE(quoted_at, NOW()), quoted_at), supplier_ref = ?, delivery_date = ?, currency = ?, total = ?, note = ? WHERE id = ?",
      [$n, $n, mb_substr(trim((string)($_POST['supplier_ref'] ?? '')), 0, 80) ?: $rs['supplier_ref'], preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['delivery_date'] ?? '')) ? $_POST['delivery_date'] : $rs['delivery_date'], $cur, $n ? $total : null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 400) ?: $rs['note'], $rsId]);
    $anyQuoted = (int) scalar("SELECT COUNT(*) FROM po_rfq_suppliers WHERE rfq_id = ? AND status = 'quoted'", [$rfqId]);
    q('UPDATE po_orders SET status = ? WHERE id = ? AND status IN (\'sent\', \'quoted\', \'draft\')', [$anyQuoted ? 'quoted' : ($o['status'] === 'draft' ? 'draft' : 'sent'), $rfqId]);
    po_event($rfqId, 'quote', $rs['supplier'] . ' · ' . $n . ' ' . t('prq.lines') . ($n ? ' · ' . po_money($total, $cur) : '')); cache_bump_later('inv'); return $rsId;
}
function rfo_decline(int $rfqId, int $rsId, string $note): void {
    require_po_admin(); $o = po_load($rfqId); $rs = rfo_supplier($rfqId, $rsId); if (!$o || !$rs || in_array($rs['status'], ['awarded', 'lost'], true)) throw new RuntimeException(t('err.noaccess'));
    q("UPDATE po_rfq_suppliers SET status = 'declined', note = ? WHERE id = ?", [mb_substr(trim($note), 0, 400) ?: $rs['note'], $rsId]); po_event($rfqId, 'decline', $rs['supplier'] . ($note !== '' ? ' · ' . $note : ''));
}
/** The RFQ header as it would be addressed to one invited supplier (PDF, ✉ card, people). */
function rfo_order_for(array $o, int $supplierId): array {
    if ((int)$o['supplier_id'] === $supplierId) return $o; $S = sup_load($supplierId); if (!$S) return $o;
    return ['supplier_id' => $supplierId, 'supplier' => $S['name'], 'supplier_co_email' => $S['email'] ?? null, 'supplier_email' => null, 'supplier_contact' => null, 'supplier_ref' => null, 'currency' => ($S['sup_currency'] ?? '') ?: $o['currency']] + $o;
}
/** Comparison: per line the lowest price among the quoted suppliers; per supplier total, missing lines, longest lead. */
function rfo_compare(int $rfqId, array $lines): array {
    $sups = array_values(array_filter(rfo_suppliers($rfqId), fn($r) => !in_array($r['status'], ['declined'], true))); $Q = rfo_quotes($rfqId); $act = array_values(array_filter($lines, fn($l) => $l['status'] !== 'cancelled'));
    $best = []; foreach ($act as $l) { $lid = (int)$l['id']; $min = null; foreach ($sups as $s) { $p = $Q[(int)$s['id']][$lid]['unit_price'] ?? null; if ($p !== null && ($min === null || (float)$p < $min)) $min = (float)$p; } $best[$lid] = $min; }
    $tot = []; foreach ($sups as $s) { $rsId = (int)$s['id']; $sum = 0.0; $missing = 0; $lead = null; $wins = 0;
        foreach ($act as $l) { $lid = (int)$l['id']; $q = $Q[$rsId][$lid] ?? null; if ($q === null || $q['unit_price'] === null) { $missing++; continue; } $sum += (float)$l['qty'] * (float)$q['unit_price']; if ($q['lead_days'] !== null) $lead = max($lead ?? 0, (int)$q['lead_days']); if ($best[$lid] !== null && abs((float)$q['unit_price'] - $best[$lid]) < 0.00005) $wins++; }
        $tot[$rsId] = ['total' => $sum, 'missing' => $missing, 'lead' => $lead, 'wins' => $wins, 'complete' => $missing === 0 && count($act) > 0]; }
    $cheapest = null; $fastest = null; foreach ($tot as $rsId => $t) { if (!$t['complete']) continue; if ($cheapest === null || $t['total'] < $tot[$cheapest]['total']) $cheapest = $rsId; if ($t['lead'] !== null && ($fastest === null || $t['lead'] < $tot[$fastest]['lead'])) $fastest = $rsId; }
    return ['suppliers' => $sups, 'quotes' => $Q, 'lines' => $act, 'best' => $best, 'totals' => $tot, 'cheapest' => $cheapest, 'fastest' => $fastest];
}
/** Award to one invited supplier: the PO is created from the lines with that supplier's prices and lead times; the others are marked lost. */
function rfo_award(int $rfqId, int $rsId): int {
    require_po_admin(); $r = po_load($rfqId); $rs = rfo_supplier($rfqId, $rsId);
    if (!$r || !$rs || $r['kind'] !== 'rfq' || !in_array($r['status'], ['quoted', 'sent'], true) || $rs['status'] !== 'quoted') throw new RuntimeException(t('err.noaccess'));
    $Q = rfo_quotes($rfqId)[$rsId] ?? []; $u = current_user(); $sid = (int)$rs['supplier_id']; $S = sup_load($sid) ?: [];
    foreach (all("SELECT id, seq FROM po_lines WHERE order_id = ? AND status <> 'cancelled'", [$rfqId]) as $l) if (($Q[(int)$l['id']]['unit_price'] ?? null) === null) throw new RuntimeException(t('rfo.incomplete', ['n' => (int)$l['seq']]));
    $cur = $rs['currency'] ?: $r['currency']; $no = po_number('po', $r['branch_id'] ? (int)$r['branch_id'] : null);
    q('INSERT INTO po_orders (kind, po_no, branch_id, supplier_id, project_id, subproject_id, rfq_id, title, currency, incoterms, terms_days, advance_pct, delivery_date, warehouse_id, supplier_ref, supplier_contact, supplier_email, vat_pct, note, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      ['po', $no, $r['branch_id'], $sid, $r['project_id'], $r['subproject_id'], $rfqId, $r['title'], $cur, $S['incoterms'] ?? $r['incoterms'], $S['terms_days'] ?? $r['terms_days'], $S['advance_pct'] ?? $r['advance_pct'], $rs['delivery_date'] ?: $r['delivery_date'], $r['warehouse_id'], $rs['supplier_ref'], $sid === (int)$r['supplier_id'] ? $r['supplier_contact'] : null, $sid === (int)$r['supplier_id'] ? $r['supplier_email'] : ($S['email'] ?? null), $r['vat_pct'], $r['note'], (int)$u['id'], $u['full_name']]);
    $oid = (int) db()->lastInsertId();
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled' ORDER BY seq", [$rfqId]) as $l) { $q = $Q[(int)$l['id']];
        q('INSERT INTO po_lines (order_id, seq, item_id, alt_of_item_id, description, maker, part_no, unit, qty, unit_price, price_origin, discount_pct, lead_days, need_date, note, pr_line_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$oid, $l['seq'], $l['item_id'], $l['alt_of_item_id'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], $l['qty'], $q['unit_price'], t('rfo.origin', ['no' => $r['po_no']]), 0, $q['lead_days'] ?? $l['lead_days'], $l['need_date'], trim((string)$l['note'] . ($q['note'] ? ' · ' . $q['note'] : '')) ?: null, $l['pr_line_id']]);
        q('UPDATE po_lines SET unit_price = ?, lead_days = ? WHERE id = ?', [$q['unit_price'], $q['lead_days'] ?? $l['lead_days'], (int)$l['id']]);   // the RFQ keeps the awarded prices on its own lines
    }
    po_recalc($oid); po_recalc($rfqId);
    q("UPDATE po_rfq_suppliers SET status = IF(id = ?, 'awarded', IF(status IN ('quoted','sent','invited'), 'lost', status)) WHERE rfq_id = ?", [$rsId, $rfqId]);
    q('UPDATE po_orders SET status = ?, supplier_id = ?, supplier_ref = COALESCE(?, supplier_ref), currency = ? WHERE id = ?', ['awarded', $sid, $rs['supplier_ref'], $cur, $rfqId]);
    po_event($rfqId, 'award', $no . ' · ' . $rs['supplier']); po_event($oid, 'create', t('po.from_rfq', ['no' => $r['po_no']]));
    cache_bump_later('inv'); return $oid;
}
