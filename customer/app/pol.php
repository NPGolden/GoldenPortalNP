<?php
declare(strict_types=1);
/**
 * GAS-CP-R97-146 — Delivery lots of a purchase order.
 *
 * An order that arrives in several deliveries is divided into lots. A lot has a planned date, its own
 * transport steps (shipped, arrived, delivered), carrier and tracking, and the order lines that travel in it.
 * The lots are made by hand or proposed from the delivery dates of the lines (promised date, else lead time).
 * The planned date of the step "Delivered" of the order follows the latest lot. A lot whose lines are all
 * received counts as delivered. Shown on the order, in the lists, on the pipeline and in the sub-project.
 */

function pol_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('po_has') && po_has() && (bool) scalar("SHOW TABLES LIKE 'po_lots'") && (bool) scalar("SHOW COLUMNS FROM po_lines LIKE 'lot_id'"); } catch (Throwable $e) { $h = false; } } return $h; }

/** The lots of several orders at once: [order_id => [lot + n, n_recv, state, last_receipt]]. States: delivered, arrived, shipped, late, planned. */
function pol_of(array $oids): array {
    $oids = array_values(array_unique(array_filter(array_map('intval', $oids)))); if (!$oids || !pol_has()) return [];
    $in = implode(',', $oids); $out = []; $today = date('Y-m-d');
    $cnt = []; foreach (all("SELECT lot_id, COUNT(*) AS n, SUM(status = 'received') AS r FROM po_lines WHERE order_id IN ($in) AND lot_id IS NOT NULL AND status <> 'cancelled' GROUP BY lot_id") as $c) $cnt[(int)$c['lot_id']] = [(int)$c['n'], (int)$c['r']];
    $rc = []; foreach (all("SELECT l.lot_id, MAX(r.received_at) AS d FROM po_receipt_lines rl JOIN po_receipts r ON r.id = rl.receipt_id JOIN po_lines l ON l.id = rl.po_line_id WHERE l.order_id IN ($in) AND l.lot_id IS NOT NULL GROUP BY l.lot_id") as $c) $rc[(int)$c['lot_id']] = $c['d'];
    foreach (all("SELECT * FROM po_lots WHERE order_id IN ($in) ORDER BY order_id, seq, id") as $l) {
        [$n, $r] = $cnt[(int)$l['id']] ?? [0, 0]; $allIn = $n > 0 && $r >= $n; $l['n'] = $n; $l['n_recv'] = $r; $l['last_receipt'] = $rc[(int)$l['id']] ?? null;
        $l['done_date'] = $l['delivered_date'] ?: ($allIn ? $l['last_receipt'] : null);
        $l['state'] = ($l['delivered_date'] || $allIn) ? 'delivered' : ($l['planned_date'] && $l['planned_date'] < $today ? 'late' : ($l['arrived_date'] ? 'arrived' : ($l['shipped_date'] ? 'shipped' : 'planned')));
        $out[(int)$l['order_id']][] = $l;
    }
    return $out;
}
function pol_can(?array $o): bool { return $o && pol_has() && po_admin() && $o['kind'] === 'po' && !in_array($o['status'], ['cancelled', 'closed'], true); }
function pol_order(int $oid): array { $o = po_load($oid); if (!pol_can($o)) throw new RuntimeException(t('err.noaccess')); return $o; }
function pol_date(string $k): ?string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? (string)$_POST[$k] : null; }

/** After any change: the planned date of the step Delivered = the latest planned lot; the expected date of the order follows. */
function pol_sync(int $oid): void {
    $max = scalar('SELECT MAX(planned_date) FROM po_lots WHERE order_id = ?', [$oid]);
    if ($max && function_exists('po_r4') && po_r4()) { $u = current_user(); q("INSERT INTO po_milestones (order_id, kind, planned_date, user_id, user_name) VALUES (?, 'delivered', ?, ?, ?) ON DUPLICATE KEY UPDATE planned_date = VALUES(planned_date)", [$oid, $max, $u['id'] ?? null, $u['full_name'] ?? null]); }
    if (function_exists('po_expected_update')) po_expected_update($oid); cache_bump_later('eng');
}
function pol_renumber(int $oid): void { $i = 0; foreach (all('SELECT id FROM po_lots WHERE order_id = ? ORDER BY (planned_date IS NULL), planned_date, seq, id', [$oid]) as $l) q('UPDATE po_lots SET seq = ? WHERE id = ?', [++$i, (int)$l['id']]); }

/** Propose the lots from the delivery dates of the lines: one lot per date (promised date, else sent/confirmed + lead time, else the order's date). Lines without any date stay without a lot. */
function pol_auto(int $oid): int {
    $o = pol_order($oid);
    if (scalar('SELECT 1 FROM po_lots WHERE order_id = ? AND (shipped_date IS NOT NULL OR arrived_date IS NOT NULL OR delivered_date IS NOT NULL OR tracking IS NOT NULL) LIMIT 1', [$oid])) throw new RuntimeException(t('pol.auto_blocked'));
    $by = []; foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled'", [$oid]) as $l) { $d = !empty($l['promised_date']) ? $l['promised_date'] : ($l['lead_days'] !== null ? date('Y-m-d', strtotime(substr((string)(($o['confirmed_at'] ?: $o['sent_at']) ?: date('Y-m-d')), 0, 10) . ' +' . (int)$l['lead_days'] . ' days')) : null);   /* a draft counts the lead time from today */ if ($d) $by[$d][] = (int)$l['id']; }
    if (count($by) < 1) throw new RuntimeException(t('pol.auto_none'));
    q('UPDATE po_lines SET lot_id = NULL WHERE order_id = ?', [$oid]); q('DELETE FROM po_lots WHERE order_id = ?', [$oid]); ksort($by); $u = current_user(); $i = 0;
    foreach ($by as $d => $ids) { q('INSERT INTO po_lots (order_id, seq, planned_date, user_name) VALUES (?,?,?,?)', [$oid, ++$i, $d, $u['full_name'] ?? null]); $lid = (int) db()->lastInsertId(); q('UPDATE po_lines SET lot_id = ? WHERE id IN (' . implode(',', $ids) . ')', [$lid]); }
    pol_sync($oid); po_event($oid, 'lots', t('pol.ev_auto', ['n' => $i])); return $i;
}
/** Create or update one lot. */
function pol_save_from_post(): int {
    $oid = (int)($_POST['id'] ?? 0); pol_order($oid); $lid = (int)($_POST['lot_id'] ?? 0); $u = current_user();
    $v = [pol_date('planned_date'), pol_date('shipped_date'), pol_date('arrived_date'), pol_date('delivered_date'), mb_substr(trim((string)($_POST['carrier'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null];
    if ($lid) { if (!scalar('SELECT 1 FROM po_lots WHERE id = ? AND order_id = ?', [$lid, $oid])) throw new RuntimeException(t('err.notfound')); q('UPDATE po_lots SET planned_date = ?, shipped_date = ?, arrived_date = ?, delivered_date = ?, carrier = ?, tracking = ?, note = ? WHERE id = ?', array_merge($v, [$lid])); }
    else { if (!$v[0]) throw new RuntimeException(t('pol.need_date')); if ((int) scalar('SELECT COUNT(*) FROM po_lots WHERE order_id = ?', [$oid]) >= 40) throw new RuntimeException(t('pol.too_many')); q('INSERT INTO po_lots (order_id, seq, planned_date, shipped_date, arrived_date, delivered_date, carrier, tracking, note, user_name) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge([$oid, 99], $v, [$u['full_name'] ?? null])); $lid = (int) db()->lastInsertId(); }
    pol_renumber($oid); pol_sync($oid); $seq = (int) scalar('SELECT seq FROM po_lots WHERE id = ?', [$lid]);
    po_event($oid, 'lots', t('pol.lot_n', ['n' => $seq]) . ($v[3] ? ' · ' . t('pol.s_delivered') . ' ' . fmt_date($v[3]) : ($v[2] ? ' · ' . t('pol.s_arrived') . ' ' . fmt_date($v[2]) : ($v[1] ? ' · ' . t('pol.s_shipped') . ' ' . fmt_date($v[1]) : ($v[0] ? ' · ' . fmt_date($v[0]) : ''))) . ($v[5] ? ' · ' . $v[5] : '')));
    return $lid;
}
/** Put the lines in their lots: lot[<line id>] = <lot id> or 0. */
function pol_lines_from_post(): int {
    $oid = (int)($_POST['id'] ?? 0); pol_order($oid); $ok = array_map('intval', array_column(all('SELECT id FROM po_lots WHERE order_id = ?', [$oid]), 'id')); $n = 0;
    foreach ((array)($_POST['lot'] ?? []) as $lineId => $lot) { $lot = (int)$lot; if ($lot && !in_array($lot, $ok, true)) continue; $st = q('UPDATE po_lines SET lot_id = ? WHERE id = ? AND order_id = ? AND NOT (lot_id <=> ?)', [$lot ?: null, (int)$lineId, $oid, $lot ?: null]); $n += $st->rowCount(); }
    if ($n) { pol_sync($oid); po_event($oid, 'lots', t('pol.ev_lines', ['n' => $n])); } return $n;
}
function pol_delete(int $oid, int $lid): void {
    pol_order($oid); if (!scalar('SELECT 1 FROM po_lots WHERE id = ? AND order_id = ?', [$lid, $oid])) throw new RuntimeException(t('err.notfound'));
    $seq = (int) scalar('SELECT seq FROM po_lots WHERE id = ?', [$lid]); q('UPDATE po_lines SET lot_id = NULL WHERE lot_id = ? AND order_id = ?', [$lid, $oid]); q('DELETE FROM po_lots WHERE id = ?', [$lid]); pol_renumber($oid); pol_sync($oid); po_event($oid, 'lots', t('pol.ev_deleted', ['n' => $seq]));
}

function pol_state_html(array $l): string {
    $tone = ['delivered' => 'green', 'arrived' => 'navy', 'shipped' => 'gold', 'late' => 'bad', 'planned' => 'mute'][$l['state']] ?? 'mute';
    return pill($tone, t('pol.s_' . $l['state']));
}
/** The Delivery cell of a list: one line per lot — "Lot 1 · 25 Nov ✓" / "Lot 2 · 30 Nov · shipped". Without lots: the plain date. */
function pol_cell(?array $lots, ?string $fallback, bool $open, int $unassigned = 0): string {
    if (!$lots) return ($fallback && $open && $fallback < date('Y-m-d') ? '<span style="color:var(--red)">' . fmt_date($fallback) . '</span>' : fmt_date($fallback));
    $h = [];
    foreach ($lots as $l) {
        $d = $l['state'] === 'delivered' ? ($l['done_date'] ?: $l['planned_date']) : $l['planned_date'];
        $txt = e(t('pol.lot_n', ['n' => (int)$l['seq']])) . ' · ' . ($d ? fmt_date($d) : '—');
        $tail = $l['state'] === 'delivered' ? ' <b style="color:var(--ok,#2E7D32);">✓</b>' : ($l['state'] === 'planned' ? '' : ' · ' . e(t('pol.s_' . $l['state'])));
        $h[] = '<span style="white-space:nowrap;' . ($l['state'] === 'late' && $open ? 'color:var(--red);font-weight:600;' : ($l['state'] === 'delivered' ? 'color:var(--muted,#6a7179);' : '')) . '" title="' . e($l['n_recv'] . ' / ' . $l['n'] . ' ' . t('prq.lines') . ($l['carrier'] ? ' · ' . $l['carrier'] : '') . ($l['tracking'] ? ' · ' . $l['tracking'] : '')) . '">' . $txt . $tail . '</span>';
    }
    return implode('<br>', $h);
}
/** One line for a pipeline card: "◐ 3 lots · 1 delivered · next 30 Nov". */
function pol_brief(?array $lots): string {
    if (!$lots) return ''; $done = count(array_filter($lots, fn($l) => $l['state'] === 'delivered')); $next = null; foreach ($lots as $l) if ($l['state'] !== 'delivered' && $l['planned_date'] && (!$next || $l['planned_date'] < $next)) $next = $l['planned_date'];
    return '◐ ' . e(t('pol.brief', ['n' => count($lots), 'd' => $done])) . ($next ? ' · ' . e(t('pol.next')) . ' ' . fmt_date($next) : '');
}

function pol_handle_action(string $action): bool {
    if (!in_array($action, ['pol_auto', 'pol_save', 'pol_lines', 'pol_delete'], true)) return false;
    require_po_admin(); $oid = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'pol_auto') { $n = pol_auto($oid); flash('good', t('pol.auto_ok', ['n' => $n])); }
        elseif ($action === 'pol_save') { pol_save_from_post(); flash('good', t('pol.saved')); }
        elseif ($action === 'pol_lines') { $n = pol_lines_from_post(); flash('good', t('pol.lines_ok', ['n' => $n])); }
        else { pol_delete($oid, (int)($_POST['lot_id'] ?? 0)); flash('good', t('pol.deleted')); }
    } catch (RuntimeException $e) { flash('bad', $e->getMessage()); }
    redirect('purchase_order', ['id' => $oid, '_r' => uniqid()], '#lots');
    return true;
}
