<?php
/** Stock control — typed movement reasons, consumables, low-stock alarms, the shortage console and the Stock project.   GAS-CP-R87-001 */
declare(strict_types=1);

const STK_IN_REASONS  = ['receipt', 'manual', 'return_project', 'return_customer'];
const STK_OUT_REASONS = ['delivery_customer', 'delivery_production', 'consumption', 'issue', 'manual', 'scrap'];
function stc_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) db()->query("SHOW COLUMNS FROM inv_items LIKE 'alert_low'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function stc_reason_label(?string $r): string { $r = (string)$r; if ($r === '') return ''; $k = 'stk.r_' . $r; $v = t($k); return $v === $k ? $r : $v; }
/** The company's Stock project / sub-project (Settings › Procurement). */
function stc_stock_sub(): ?array { $sid = (int) cp_setting('proc_stock_subproject_id', '0'); if (!$sid) return null; return one('SELECT s.id, s.code, s.name, s.project_id, p.project_code, p.name AS project_name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sid]); }
/** Create the Stock project (one sub-project STOCK) for a customer — R87-001 */
function stc_stock_project_create(int $customerId, string $code = 'STOCK'): int {
    require_pr_admin(); $cu = one('SELECT id, name FROM customers WHERE id = ?', [$customerId]); if (!$cu) throw new RuntimeException(t('err.notfound'));
    $code = mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', $code) ?: 'STOCK', 0, 30); if (scalar('SELECT id FROM projects WHERE project_code = ?', [$code])) throw new RuntimeException(t('stk.stock_code_taken'));
    $u = current_user();
    q("INSERT INTO projects (customer_id, name, project_code, source, status, category, pm_user_id, project_manager) VALUES (?,?,?,'manual','active','internal',?,?)", [$customerId, t('stk.stock_project_name'), $code, (int)($u['id'] ?? 0) ?: null, (string)($u['name'] ?? '')]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO subprojects (project_id, code, name, status) VALUES (?,?,?,'active')", [$pid, 'STOCK', t('stk.stock_sub_name')]); $sid = (int) db()->lastInsertId();
    cp_setting_set('proc_stock_project_id', (string)$pid); cp_setting_set('proc_stock_subproject_id', (string)$sid); cache_bump_later('inv'); return $pid;
}
/** Items below their minimum (alarm on): free = on hand − reserved; incoming = open PO qty + open stock requisition qty; suggested = max(reorder, min − free − incoming). */
function stc_shortages(?int $catId = null): array {
    if (!stc_has()) return [];
    $w = ['i.status = \'active\'', 'i.alert_low = 1', 'i.min_stock > 0']; $a = []; if ($catId) { $w[] = 'i.category_id = ?'; $a[] = $catId; }
    $rows = all('SELECT i.id, i.code, i.description, i.unit, i.maker, i.factory_code, i.min_stock, i.reorder_qty, i.is_consumable, i.last_price, i.currency, i.preferred_supplier_id, c.name AS category, sup.name AS preferred_supplier,
                        COALESCE(st.on_hand, 0) AS on_hand, COALESCE(st.reserved, 0) AS reserved,
                        (SELECT COALESCE(SUM(l.qty - l.qty_received), 0) FROM po_lines l JOIN po_orders o ON o.id = l.order_id WHERE l.item_id = i.id AND o.kind = \'po\' AND o.status IN (\'sent\',\'confirmed\',\'partial\') AND l.status IN (\'open\',\'partial\')) AS on_order,
                        (SELECT COALESCE(SUM(pl.qty - pl.qty_ordered - pl.qty_reserved), 0) FROM pr_lines pl JOIN pr_requests pr ON pr.id = pl.request_id WHERE pl.item_id = i.id AND pr.purpose = \'stock\' AND pr.status IN (\'submitted\',\'mgmt\',\'approved\') AND pl.status IN (\'open\',\'to_buy\')) AS requested
                 FROM inv_items i LEFT JOIN inv_categories c ON c.id = i.category_id LEFT JOIN companies sup ON sup.id = i.preferred_supplier_id
                 LEFT JOIN (SELECT item_id, SUM(qty_on_hand) AS on_hand, SUM(qty_reserved) AS reserved FROM inv_stock GROUP BY item_id) st ON st.item_id = i.id
                 WHERE ' . implode(' AND ', $w) . ' HAVING (on_hand - reserved) < i.min_stock ORDER BY (on_hand - reserved) / i.min_stock, i.code', $a);
    foreach ($rows as &$r) { $free = (float)$r['on_hand'] - (float)$r['reserved']; $inc = (float)$r['on_order'] + max(0.0, (float)$r['requested']); $gap = (float)$r['min_stock'] - $free - $inc; $r['free'] = $free; $r['incoming'] = $inc; $r['gap'] = $gap; $r['suggested'] = $gap > 0.0005 ? max((float)($r['reorder_qty'] ?? 0), $gap) : 0.0; $r['covered'] = $gap <= 0.0005; } unset($r);
    return $rows;
}
function stc_shortage_count(): int { static $n = null; if ($n === null) { try { $n = count(array_filter(stc_shortages(), fn($r) => !$r['covered'])); } catch (Throwable $e) { $n = 0; } } return $n; }
/** One stock requisition (purpose stock, Stock project) from the selected shortages; submitted to Procurement. Returns the request id. */
function stc_raise_requisition(array $itemIds, array $qtys, string $note = ''): int {
    require_pr_admin(); $sub = stc_stock_sub(); if (!$sub) throw new RuntimeException(t('stk.need_stock_project'));
    $short = []; foreach (stc_shortages() as $r) $short[(int)$r['id']] = $r; $lines = [];
    foreach ($itemIds as $iid) { $iid = (int)$iid; if (!isset($short[$iid])) continue; $q = (float) str_replace(',', '.', (string)($qtys[$iid] ?? '')); if ($q <= 0) $q = (float)$short[$iid]['suggested']; if ($q > 0) $lines[$iid] = $q; }
    if (!$lines) throw new RuntimeException(t('stk.no_shortage_sel'));
    $keep = $_POST; $_POST = ['request_id' => 0, 'project_id' => $sub['project_id'], 'subproject_id' => $sub['id'], 'purpose' => 'stock', 'title' => t('stk.stock_req_title', ['d' => date('d/m/Y')]), 'needed_by' => date('Y-m-d', strtotime('+14 days')), 'note' => $note];
    $rid = prq_save_from_post();
    foreach ($lines as $iid => $q) { $it = $short[$iid]; $_POST = ['request_id' => $rid, 'line_id' => 0, 'item_id' => $iid, 'description' => $it['description'], 'qty' => $q, 'unit' => $it['unit'], 'est_price' => $it['last_price'], 'note' => t('stk.stock_req_line', ['f' => stk_qty($it['free']), 'm' => stk_qty((float)$it['min_stock'])])]; prq_line_save_from_post(); }
    prq_submit($rid); $_POST = $keep; cache_bump_later('inv'); return $rid;
}
/** After a stock decrease: notify Procurement once a day when the item fell below its minimum. */
function stc_after_out(int $itemId): void {
    if (!stc_has() || !function_exists('nf_push')) return;
    $it = one('SELECT i.code, i.min_stock, i.alert_low, (SELECT COALESCE(SUM(qty_on_hand - qty_reserved), 0) FROM inv_stock WHERE item_id = i.id) AS free FROM inv_items i WHERE i.id = ?', [$itemId]);
    if (!$it || !$it['alert_low'] || (float)$it['min_stock'] <= 0 || (float)$it['free'] >= (float)$it['min_stock']) return;
    try { nf_push(prq_proc_ids(), 'work:stock', 'stk_low:' . $itemId . ':' . date('Ymd'), t('stk.low_title', ['c' => (string)$it['code']]), t('stk.low_body', ['f' => stk_qty((float)$it['free']), 'm' => stk_qty((float)$it['min_stock'])]), url('inventory', ['tab' => 'shortages']), null, null); } catch (Throwable $e) {}
}
