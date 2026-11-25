<?php
/**
 * Serial numbers and company assets in the inventory (GAS-CP-R97-252).
 *
 * An inventory item can be marked «serial numbers» (inv_items.track_serial) and «company asset» (inv_items.is_asset — always with serial numbers).
 * Every unit of such an item is one row of inv_serials: where it is (in a warehouse · left · with a person · written off) and its history (inv_serial_log).
 *
 * Quantities stay with the stock movements (stk_move); serial numbers are ATTACHED to a movement — at once in the forms that ask for them
 * (stock movement of the item, goods receipt of a purchase order) or afterwards (the movement list of the item, the page of a delivery note).
 * So no stock action is ever blocked by a missing number, and the item page shows how many units in stock have no number yet.
 */
declare(strict_types=1);

const SN_STATES = ['in_stock', 'out', 'with_person', 'scrapped', 'removed'];
const SN_MAX = 500;      // numbers in one entry

function sn_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) scalar("SHOW TABLES LIKE 'inv_serials'") && (bool) scalar("SHOW COLUMNS FROM inv_items LIKE 'track_serial'"); } catch (Throwable $e) { $h = false; } } return $h; }
function sn_can(): bool { return sn_has() && function_exists('pr_wh') && (pr_wh() || (function_exists('pr_admin') && pr_admin())); }
function sn_tracked($item): bool { if (!sn_has()) return false; if (!is_array($item)) $item = one('SELECT track_serial, is_asset FROM inv_items WHERE id = ?', [(int)$item]) ?: []; return !empty($item['track_serial']) || !empty($item['is_asset']); }
function sn_pill(string $s): string { return pill(['in_stock' => 'green', 'out' => 'mute', 'with_person' => 'gold', 'scrapped' => 'bad', 'removed' => 'mute'][$s] ?? 'mute', t('sn.s_' . (in_array($s, SN_STATES, true) ? $s : 'out'))); }
/** "A1, A2\nA3" → ['A1','A2','A3'] — unique (case-insensitive), at most 80 characters each. */
function sn_parse($raw): array {
    $parts = is_array($raw) ? $raw : (preg_split('/[\r\n,;]+/u', (string)$raw) ?: []); $o = []; $seen = [];
    foreach ($parts as $p) { $p = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$p)), 0, 80); if ($p === '') continue; $k = mb_strtolower($p); if (isset($seen[$k])) continue; $seen[$k] = 1; $o[] = $p; if (count($o) > SN_MAX) throw new RuntimeException(t('sn.too_long', ['n' => SN_MAX])); }
    return $o;
}
function sn_log(int $sid, string $ev, array $x = []): void {
    $u = current_user();
    q('INSERT INTO inv_serial_log (serial_id, event, move_id, dn_id, warehouse_id, person_id, ref, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?)', [$sid, $ev, $x['move_id'] ?? null, $x['dn_id'] ?? null, $x['warehouse_id'] ?? null, $x['person_id'] ?? null, isset($x['ref']) ? mb_substr((string)$x['ref'], 0, 160) : null, $u['id'] ?? null, $u['full_name'] ?? null]);
}
function sn_row(int $itemId, string $serial, bool $lock = false): ?array { return one('SELECT * FROM inv_serials WHERE item_id = ? AND serial_no = ?' . ($lock ? ' FOR UPDATE' : ''), [$itemId, $serial]) ?: null; }
/** What a movement says in one line: "GAS-PO-26-0001-GR1", a delivery note number, or the reason. */
function sn_move_ref(array $mv): string {
    $ref = trim((string)($mv['ref_no'] ?? '')); if ($ref !== '') return $ref;
    if (($mv['ref_kind'] ?? '') === 'dn' && $mv['ref_id']) { try { $n = (string) scalar('SELECT dn_no FROM inv_delivery_notes WHERE id = ?', [(int)$mv['ref_id']]); if ($n !== '') return $n; } catch (Throwable $e) {} }
    $note = trim((string)($mv['note'] ?? '')); if ($note !== '') return mb_substr($note, 0, 120);
    return function_exists('stc_reason_label') ? stc_reason_label((string)($mv['reason'] ?? '')) : (string)($mv['reason'] ?? '');
}
function sn_of_move(int $moveId): array { return sn_has() ? array_column(all('SELECT s.serial_no FROM inv_serial_log l JOIN inv_serials s ON s.id = l.serial_id WHERE l.move_id = ? AND l.event IN (\'in\',\'out\',\'xfer\') ORDER BY l.id', [$moveId]), 'serial_no') : []; }
function sn_of_moves(array $moveIds): array { $o = []; $ids = array_values(array_filter(array_map('intval', $moveIds))); if (!$ids || !sn_has()) return $o; foreach (all('SELECT l.move_id, s.serial_no FROM inv_serial_log l JOIN inv_serials s ON s.id = l.serial_id WHERE l.move_id IN (' . implode(',', $ids) . ") AND l.event IN ('in','out','xfer') ORDER BY l.id") as $r) $o[(int)$r['move_id']][] = $r['serial_no']; return $o; }

/** Attach serial numbers to one stock movement. In: the numbers enter the warehouse of the movement. Out: they must be in that warehouse and leave it.
 *  $x: status (state after an out: out | with_person | scrapped), person_id, eq_item_id, dn_id, ref, exact (bool: as many numbers as units). Returns how many were attached. */
function sn_attach(int $moveId, $serials, array $x = []): int {
    if (!sn_has()) return 0; $list = sn_parse($serials); if (!$list) return 0;
    $mv = one('SELECT * FROM inv_moves WHERE id = ?', [$moveId]); if (!$mv) throw new RuntimeException(t('err.notfound'));
    $itemId = (int)$mv['item_id']; $item = stk_item($itemId); if (!$item || !sn_tracked($item)) throw new RuntimeException(t('sn.not_tracked'));
    $qty = abs((float)$mv['qty']); $have = (int) scalar("SELECT COUNT(*) FROM inv_serial_log WHERE move_id = ? AND event IN ('in','out','xfer')", [$moveId]);
    if (abs($qty - round($qty)) > 0.0005) throw new RuntimeException(t('sn.whole_units'));
    if ($have + count($list) > (int) round($qty)) throw new RuntimeException(t('sn.too_many', ['n' => $have + count($list), 'q' => (int) round($qty)]));
    if (!empty($x['exact']) && $have + count($list) !== (int) round($qty)) throw new RuntimeException(t('sn.need_exact', ['n' => count($list), 'q' => (int) round($qty), 'c' => (string)$item['code']]));
    $in = (float)$mv['qty'] > 0; $wh = (int)$mv['warehouse_id']; $ref = (string)($x['ref'] ?? sn_move_ref($mv)); $dn = $x['dn_id'] ?? (($mv['ref_kind'] ?? '') === 'dn' ? (int)$mv['ref_id'] : null);
    foreach ($list as $sn) {
        $r = sn_row($itemId, $sn, true);
        if ($in) {
            if ($r && $r['status'] === 'in_stock') throw new RuntimeException(t('sn.already_in', ['s' => $sn]));
            if ($r && $r['status'] === 'with_person' && empty($x['from_person'])) throw new RuntimeException(t('sn.with_person', ['s' => $sn]));
            if ($r) { q("UPDATE inv_serials SET status = 'in_stock', warehouse_id = ?, person_id = NULL, eq_item_id = NULL, last_ref = ? WHERE id = ?", [$wh, mb_substr($ref, 0, 160), (int)$r['id']]); $sid = (int)$r['id']; }
            else { q("INSERT INTO inv_serials (item_id, serial_no, status, warehouse_id, last_ref) VALUES (?,?,'in_stock',?,?)", [$itemId, $sn, $wh, mb_substr($ref, 0, 160)]); $sid = (int) db()->lastInsertId(); }
            sn_log($sid, 'in', ['move_id' => $moveId, 'dn_id' => $dn, 'warehouse_id' => $wh, 'ref' => $ref]);
        } else {
            $st = in_array($x['status'] ?? '', ['out', 'with_person', 'scrapped'], true) ? (string)$x['status'] : ((string)($mv['reason'] ?? '') === 'scrap' ? 'scrapped' : 'out');
            if ($r && $r['status'] !== 'in_stock') throw new RuntimeException(t('sn.not_in_stock', ['s' => $sn, 'st' => t('sn.s_' . $r['status'])]));
            if ($r && (int)$r['warehouse_id'] !== $wh) throw new RuntimeException(t('sn.other_wh', ['s' => $sn]));
            if ($r) { q('UPDATE inv_serials SET status = ?, warehouse_id = NULL, person_id = ?, eq_item_id = ?, last_ref = ? WHERE id = ?', [$st, $x['person_id'] ?? null, $x['eq_item_id'] ?? null, mb_substr($ref, 0, 160), (int)$r['id']]); $sid = (int)$r['id']; }
            else { q('INSERT INTO inv_serials (item_id, serial_no, status, person_id, eq_item_id, last_ref) VALUES (?,?,?,?,?,?)', [$itemId, $sn, $st, $x['person_id'] ?? null, $x['eq_item_id'] ?? null, mb_substr($ref, 0, 160)]); $sid = (int) db()->lastInsertId(); }   // a unit that was in stock before numbers were kept
            sn_log($sid, 'out', ['move_id' => $moveId, 'dn_id' => $dn, 'warehouse_id' => $wh, 'person_id' => $x['person_id'] ?? null, 'ref' => $ref]);
        }
    }
    if (function_exists('cache_bump_later')) cache_bump_later('inv');
    return count($list);
}
/** A transfer: the numbers follow the goods to the other warehouse (logged on the outgoing movement). */
function sn_transfer(int $moveOutId, int $toWh, $serials, bool $exact = false): int {
    if (!sn_has()) return 0; $list = sn_parse($serials); if (!$list) return 0; $mv = one('SELECT * FROM inv_moves WHERE id = ?', [$moveOutId]); if (!$mv) return 0; $itemId = (int)$mv['item_id']; $qty = (int) round(abs((float)$mv['qty']));
    if (count($list) > $qty || ($exact && count($list) !== $qty)) throw new RuntimeException(t('sn.need_exact', ['n' => count($list), 'q' => $qty, 'c' => (string)(stk_item($itemId)['code'] ?? '')]));
    foreach ($list as $sn) { $r = sn_row($itemId, $sn, true); if (!$r || $r['status'] !== 'in_stock') throw new RuntimeException(t('sn.not_in_stock', ['s' => $sn, 'st' => t('sn.s_' . ($r['status'] ?? 'out'))])); if ((int)$r['warehouse_id'] !== (int)$mv['warehouse_id']) throw new RuntimeException(t('sn.other_wh', ['s' => $sn]));
        q('UPDATE inv_serials SET warehouse_id = ? WHERE id = ?', [$toWh, (int)$r['id']]); sn_log((int)$r['id'], 'xfer', ['move_id' => $moveOutId, 'warehouse_id' => $toWh, 'ref' => sn_move_ref($mv)]); }
    return count($list);
}
/** A movement is taken back (delivery note cancelled, receipt reversed): its numbers go back to where they were before it. */
function sn_undo_move(int $moveId, string $ref = ''): int {
    if (!sn_has() || !$moveId) return 0; $mv = one('SELECT * FROM inv_moves WHERE id = ?', [$moveId]); if (!$mv) return 0; $n = 0; $wh = (int)$mv['warehouse_id']; $in = (float)$mv['qty'] > 0;
    foreach (all("SELECT l.id AS lid, s.* FROM inv_serial_log l JOIN inv_serials s ON s.id = l.serial_id WHERE l.move_id = ? AND l.event IN ('in','out')", [$moveId]) as $s) {
        if ($in) { if ($s['status'] !== 'in_stock') continue; q("UPDATE inv_serials SET status = 'removed', warehouse_id = NULL, last_ref = ? WHERE id = ?", [mb_substr($ref, 0, 160), (int)$s['id']]); }
        else { if ($s['status'] === 'in_stock') continue; q("UPDATE inv_serials SET status = 'in_stock', warehouse_id = ?, person_id = NULL, eq_item_id = NULL, last_ref = ? WHERE id = ?", [$wh, mb_substr($ref, 0, 160), (int)$s['id']]); }
        q("UPDATE inv_serial_log SET event = 'undone' WHERE id = ?", [(int)$s['lid']]); sn_log((int)$s['id'], 'undo', ['move_id' => $moveId, 'warehouse_id' => $in ? null : $wh, 'ref' => $ref]); $n++;
    }
    return $n;
}
/** The numbers that came with a receipt (found by its number) leave again when the receipt is reversed. */
function sn_undo_ref(int $itemId, string $ref, string $why): int {
    if (!sn_has() || $ref === '') return 0; $n = 0;
    foreach (all("SELECT DISTINCT l.move_id FROM inv_serial_log l JOIN inv_serials s ON s.id = l.serial_id WHERE s.item_id = ? AND l.event = 'in' AND l.ref LIKE ?", [$itemId, $ref . '%']) as $m) if ($m['move_id']) $n += sn_undo_move((int)$m['move_id'], $why);
    return $n;
}
/** The units of an item, with the holder for those with a person. */
function sn_list(int $itemId, int $limit = 1000): array { return sn_has() ? all("SELECT s.*, w.code AS wh_code, p.name_en AS person FROM inv_serials s LEFT JOIN inv_warehouses w ON w.id = s.warehouse_id LEFT JOIN hr_people p ON p.id = s.person_id WHERE s.item_id = ? ORDER BY FIELD(s.status, 'in_stock', 'with_person', 'out', 'scrapped', 'removed'), s.serial_no LIMIT " . (int)$limit, [$itemId]) : []; }
function sn_in_stock(int $itemId, ?int $whId = null): array { return sn_has() ? all("SELECT s.id, s.serial_no, s.asset_tag, s.warehouse_id, w.code AS wh_code FROM inv_serials s LEFT JOIN inv_warehouses w ON w.id = s.warehouse_id WHERE s.item_id = ? AND s.status = 'in_stock'" . ($whId ? ' AND s.warehouse_id = ' . (int)$whId : '') . ' ORDER BY s.serial_no LIMIT 2000', [$itemId]) : []; }
function sn_history(int $serialId): array { return sn_has() ? all('SELECT l.*, w.code AS wh_code, p.name_en AS person, d.dn_no FROM inv_serial_log l LEFT JOIN inv_warehouses w ON w.id = l.warehouse_id LEFT JOIN hr_people p ON p.id = l.person_id LEFT JOIN inv_delivery_notes d ON d.id = l.dn_id WHERE l.serial_id = ? ORDER BY l.id DESC LIMIT 200', [$serialId]) : []; }
/** On hand against numbered units in stock: ['on_hand' => 12, 'numbered' => 9, 'missing' => 3]. */
function sn_balance(int $itemId): array { $oh = (float) scalar('SELECT COALESCE(SUM(qty_on_hand), 0) FROM inv_stock WHERE item_id = ?', [$itemId]); $n = sn_has() ? (int) scalar("SELECT COUNT(*) FROM inv_serials WHERE item_id = ? AND status = 'in_stock'", [$itemId]) : 0; return ['on_hand' => $oh, 'numbered' => $n, 'missing' => (int) round($oh) - $n]; }
/** Numbers of units already in the warehouse, written down without a movement (first set-up, or a movement that had none). */
function sn_register_from_post(): int {
    if (!sn_can()) throw new RuntimeException(t('err.noaccess')); $item = stk_item((int)($_POST['item_id'] ?? 0)); if (!$item || !sn_tracked($item)) throw new RuntimeException(t('sn.not_tracked'));
    $wh = (int)($_POST['warehouse_id'] ?? 0) ?: stk_default_wh(); $list = sn_parse((string)($_POST['serials'] ?? '')); if (!$list) throw new RuntimeException(t('sn.need_some')); $tag = mb_substr(trim((string)($_POST['asset_tag'] ?? '')), 0, 40) ?: null;
    return (int) stk_tx(fn() => sn_register_units((int)$item['id'], $wh, $list, t('sn.ev_register'), $tag));
}
/** Numbers for units the stock figure already counts — never more than on hand in that warehouse. Inside a transaction of the caller. */
function sn_register_units(int $itemId, int $wh, array $list, string $ref, ?string $tag = null): int {
    $oh = (int) round((float) scalar('SELECT COALESCE(qty_on_hand, 0) FROM inv_stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE', [$itemId, $wh])); $have = (int) scalar("SELECT COUNT(*) FROM inv_serials WHERE item_id = ? AND status = 'in_stock' AND warehouse_id = ?", [$itemId, $wh]);
    if ($have + count($list) > $oh) throw new RuntimeException(t('sn.more_than_stock', ['n' => $have + count($list), 'q' => $oh]) . ' (' . (string) scalar('SELECT code FROM inv_items WHERE id = ?', [$itemId]) . ')');
    foreach ($list as $sn) { $r = sn_row($itemId, $sn, true); if ($r && in_array($r['status'], ['in_stock', 'with_person'], true)) throw new RuntimeException(t('sn.already_in', ['s' => $sn]));
        if ($r) { q("UPDATE inv_serials SET status = 'in_stock', warehouse_id = ?, person_id = NULL, eq_item_id = NULL, last_ref = ? WHERE id = ?", [$wh, mb_substr($ref, 0, 160), (int)$r['id']]); $sid = (int)$r['id']; }
        else { q("INSERT INTO inv_serials (item_id, serial_no, asset_tag, status, warehouse_id, last_ref) VALUES (?,?,?,'in_stock',?,?)", [$itemId, $sn, count($list) === 1 ? $tag : null, $wh, mb_substr($ref, 0, 160)]); $sid = (int) db()->lastInsertId(); }
        sn_log($sid, 'register', ['warehouse_id' => $wh, 'ref' => $ref]); }
    return count($list);
}
/** Asset tag / note of one unit; a unit entered by mistake and never moved can be removed. */
function sn_edit_from_post(): void {
    if (!sn_can()) throw new RuntimeException(t('err.noaccess')); $s = one('SELECT * FROM inv_serials WHERE id = ?', [(int)($_POST['serial_id'] ?? 0)]); if (!$s) throw new RuntimeException(t('err.notfound'));
    if (!empty($_POST['remove'])) { if ($s['status'] !== 'in_stock' || (int) scalar("SELECT COUNT(*) FROM inv_serial_log WHERE serial_id = ? AND event <> 'register'", [(int)$s['id']])) throw new RuntimeException(t('sn.cannot_remove')); q('DELETE FROM inv_serial_log WHERE serial_id = ?', [(int)$s['id']]); q('DELETE FROM inv_serials WHERE id = ?', [(int)$s['id']]); return; }
    q('UPDATE inv_serials SET asset_tag = ?, note = ? WHERE id = ?', [mb_substr(trim((string)($_POST['asset_tag'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, (int)$s['id']]);
}
/** POST move_id, serials — numbers written on a movement afterwards (movement list of the item, delivery note). */
function sn_attach_from_post(): int {
    if (!sn_can()) throw new RuntimeException(t('err.noaccess')); $mid = (int)($_POST['move_id'] ?? 0); $pick = array_merge(sn_parse((array)($_POST['pick'] ?? [])), []); $typed = sn_parse((string)($_POST['serials'] ?? ''));
    return (int) stk_tx(fn() => sn_attach($mid, array_merge($pick, $typed)));
}

/* ------------------------------------------------------------------ company assets ↔ equipment of the personnel */
/** Units of company-asset items that are in a warehouse now: [serial id => label, item_id, serial, tag, description]. */
function sn_assets_free(): array {
    if (!sn_has()) return []; $o = [];
    try { foreach (all("SELECT s.id, s.serial_no, s.asset_tag, i.id AS item_id, i.code, i.description, w.code AS wh_code FROM inv_serials s JOIN inv_items i ON i.id = s.item_id LEFT JOIN inv_warehouses w ON w.id = s.warehouse_id WHERE s.status = 'in_stock' AND i.is_asset = 1 AND i.status = 'active' ORDER BY i.description, s.serial_no LIMIT 1500") as $r)
        $o[(int)$r['id']] = ['label' => trim($r['code'] . ' · ' . $r['description'] . ' · S/N ' . $r['serial_no'] . ($r['asset_tag'] ? ' · ' . $r['asset_tag'] : '') . ' · ' . $r['wh_code']), 'item_id' => (int)$r['item_id'], 'serial' => (string)$r['serial_no'], 'tag' => (string)($r['asset_tag'] ?? ''), 'title' => (string)$r['description']]; } catch (Throwable $e) {}
    return $o;
}
/** The unit leaves the warehouse in the name of a person (line of the equipment list). */
function sn_assign_person(int $serialId, int $personId, int $eqItemId, string $personName): void {
    $s = one('SELECT s.*, i.last_price, i.is_asset FROM inv_serials s JOIN inv_items i ON i.id = s.item_id WHERE s.id = ? FOR UPDATE', [$serialId]); if (!$s || empty($s['is_asset'])) throw new RuntimeException(t('err.notfound'));
    if ($s['status'] !== 'in_stock') throw new RuntimeException(t('sn.not_in_stock', ['s' => (string)$s['serial_no'], 'st' => t('sn.s_' . $s['status'])]));
    $ref = t('sn.ref_person', ['n' => $personName]);
    $mid = stk_move((int)$s['item_id'], (int)$s['warehouse_id'], 'out', 1.0, ['unit_price' => $s['last_price'], 'reason' => 'personnel', 'note' => $ref . ' · S/N ' . $s['serial_no']]);
    sn_attach($mid, [(string)$s['serial_no']], ['status' => 'with_person', 'person_id' => $personId, 'eq_item_id' => $eqItemId, 'ref' => $ref]);
    q('UPDATE inv_serial_log SET event = ? WHERE move_id = ? AND serial_id = ?', ['out', $mid, $serialId]);
}
/** The unit comes back from the person: 'back' = into the default warehouse, 'scrapped' = written off (no stock comes back). */
function sn_release_person(int $serialId, string $how, string $ref): void {
    $s = one('SELECT s.*, i.last_price FROM inv_serials s JOIN inv_items i ON i.id = s.item_id WHERE s.id = ? FOR UPDATE', [$serialId]); if (!$s || $s['status'] !== 'with_person') return;
    if ($how === 'scrapped') { q("UPDATE inv_serials SET status = 'scrapped', last_ref = ? WHERE id = ?", [mb_substr($ref, 0, 160), $serialId]); sn_log($serialId, 'scrap', ['person_id' => (int)$s['person_id'], 'ref' => $ref]); return; }
    $wh = stk_default_wh(); $mid = stk_move((int)$s['item_id'], $wh, 'in', 1.0, ['unit_price' => $s['last_price'], 'reason' => 'return_personnel', 'note' => $ref . ' · S/N ' . $s['serial_no']]);
    sn_attach($mid, [(string)$s['serial_no']], ['from_person' => true, 'ref' => $ref]);
}

/* ------------------------------------------------------------------ Excel: company assets, one row per unit (R97-253) */
const SN_IMPORT_COLS = ['code' => ['Item code', 'Code', 'Κωδικός'], 'description' => ['Description', 'Περιγραφή'], 'category' => ['Category', 'Κατηγορία'], 'maker' => ['Maker', 'Manufacturer', 'Κατασκευαστής'], 'part_no' => ['Model / Part No.', 'Model', 'Part No.', 'Factory code'],
                        'unit' => ['Unit', 'Μ.Μ'], 'warehouse' => ['Warehouse', 'Warehouse code', 'Αποθήκη'], 'serial_no' => ['Serial No.', 'Serial', 'Serial number', 'S/N'], 'asset_tag' => ['Company No.', 'Company number', 'Asset tag'], 'value' => ['Value', 'Value (EUR)', 'Price'],
                        'emp_code' => ['Holder code', 'Employee code'], 'holder' => ['Holder', 'Holder name', 'Employee'], 'eq_cat' => ['Equipment category', 'Equipment type'], 'cond' => ['Condition'], 'given_on' => ['Given on', 'Issued on'], 'note' => ['Notes', 'Note', 'Remarks']];
function sn_import_can(): bool { return sn_can(); }
function sn_import_template(): void {
    if (!sn_import_can()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php';
    $rows = [['AST-LAPTOP-01', 'Laptop 15 inch', 'IT equipment', 'Maker', 'Model 15', 'pcs', '', 'SERIAL-0001', 'IT-001', 950, '', '', 'Computer / IT', 'Good', '', 'example row — delete it'],
             ['AST-LAPTOP-01', '', '', '', '', '', '', 'SERIAL-0002', 'IT-002', 950, '', 'Name Surname', 'Computer / IT', 'Used', date('Y') . '-01-15', 'example row — delete it']];
    $whs = implode(' · ', array_map(fn($w) => (string)$w['code'], stk_warehouses()));
    $lists = [['Item code', t('sn.imp_n1')], ['Serial No.', t('sn.imp_n2')], ['Warehouse', t('sn.imp_n3') . ' ' . $whs], ['Holder', t('sn.imp_n4')], ['Equipment category', function_exists('t_in') && defined('HRE_CATS') ? implode(' · ', array_map(fn($c) => t_in('hre.c_' . $c, 'en'), HRE_CATS)) : ''],
              ['Condition', 'New · Good · Used · Worn'], ['Given on', 'yyyy-mm-dd / dd/mm/yyyy'], ['', t('sn.imp_n5')]];
    xlsx_download('GAS-import-company-assets.xlsx', [['name' => 'Assets', 'first' => true, 'header' => array_map(fn($a) => $a[0], array_values(SN_IMPORT_COLS)), 'rows' => $rows, 'widths' => [18, 36, 18, 16, 20, 8, 12, 20, 14, 10, 14, 26, 20, 12, 14, 30]],
                                                     ['name' => 'Lists and rules', 'header' => ['Column', 'Values'], 'rows' => $lists, 'widths' => [22, 130]]]);
}
/** Reads the workbook into a preview: nothing is written. Every row is one unit; 'skip' holds the reason a row will not be imported, 'warn' what will be left out of a row that is imported. */
function sn_import_parse(string $path): array {
    if (!sn_import_can()) throw new RuntimeException(t('err.noaccess'));
    $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($path) || strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx' || (int)$f['size'] > 5 * 1024 * 1024) throw new RuntimeException(t('hre.imp_nofile'));
    return sn_import_read($path);
}
function sn_import_read(string $path): array {
    if (!sn_import_can()) throw new RuntimeException(t('err.noaccess'));
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($path); if (count($rows) < 2) throw new RuntimeException(t('hre.imp_empty'));
    $map = xlsx_header_map($rows[0], SN_IMPORT_COLS); if ($map['code'] === null || ($map['serial_no'] === null && $map['asset_tag'] === null)) throw new RuntimeException(t('sn.imp_cols'));
    $norm = fn(string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))); $get = fn(array $r, string $k) => $map[$k] === null ? '' : trim((string)($r[$map[$k]] ?? ''));
    $admin = function_exists('pr_admin') && pr_admin(); $hr = function_exists('hre_can') && hre_can(); $byCode = []; $byName = []; $pname = [];
    if ($hr) foreach (hr_people(false) as $p) { if (!$p['is_active']) continue; $pname[(int)$p['id']] = (string)$p['name_en']; if ((string)$p['emp_code'] !== '') $byCode[$norm((string)$p['emp_code'])] = (int)$p['id']; foreach (['name_en', 'name_el'] as $k) if ((string)($p[$k] ?? '') !== '') { $nn = $norm((string)$p[$k]); $byName[$nn] = isset($byName[$nn]) && $byName[$nn] !== (int)$p['id'] ? 0 : (int)$p['id']; } }
    $cats = []; $conds = []; if (defined('HRE_CATS')) { foreach (HRE_CATS as $c) { $cats[$norm($c)] = $c; foreach (['en', 'el', 'it'] as $lg) $cats[$norm(t_in('hre.c_' . $c, $lg))] = $c; } foreach (HRE_CONDS as $c) { $conds[$norm($c)] = $c; foreach (['en', 'el', 'it'] as $lg) $conds[$norm(t_in('hre.k_' . $c, $lg))] = $c; } }
    $whs = []; foreach (stk_warehouses() as $w) $whs[$norm((string)$w['code'])] = (int)$w['id'];
    $items = []; $first = []; $seen = []; $out = []; $n = ['ok' => 0, 'skip' => 0, 'new_items' => 0, 'switch' => 0, 'holders' => 0, 'warn' => 0];
    foreach (array_slice($rows, 1, 3000, true) as $ri => $r) {
        $code = mb_substr($get($r, 'code'), 0, 60); $serial = mb_substr(trim(preg_replace('/\s+/u', ' ', $get($r, 'serial_no'))), 0, 80); $tag = mb_substr(trim(preg_replace('/\s+/u', ' ', $get($r, 'asset_tag'))), 0, 40); $desc = mb_substr($get($r, 'description'), 0, 255);
        if ($code === '' && $serial === '' && $tag === '' && $desc === '') continue; if (stripos($get($r, 'note'), 'example row') !== false) continue;
        $x = ['line' => $ri + 1, 'code' => $code, 'description' => $desc, 'category' => mb_substr($get($r, 'category'), 0, 120), 'maker' => mb_substr($get($r, 'maker'), 0, 80), 'part_no' => mb_substr($get($r, 'part_no'), 0, 80), 'unit' => $get($r, 'unit'), 'wh' => 0, 'serial' => $serial !== '' ? $serial : $tag, 'tag' => $tag,
              'value' => null, 'pid' => 0, 'holder' => '', 'eq_cat' => $cats[$norm($get($r, 'eq_cat'))] ?? 'other', 'cond' => $conds[$norm($get($r, 'cond'))] ?? 'used', 'given_on' => xlsx_date($get($r, 'given_on')), 'note' => mb_substr($get($r, 'note'), 0, 300), 'item_id' => 0, 'state' => '', 'skip' => null, 'warn' => []];
        $v = str_replace([' ', ','], ['', '.'], $get($r, 'value')); if ($v !== '' && is_numeric($v) && (float)$v >= 0 && (float)$v < 1e9) $x['value'] = round((float)$v, 2);
        $key = $norm($code);
        if ($code === '') $x['skip'] = t('sn.imp_e_code');
        elseif (!preg_match(STK_CODE_RE, $code)) $x['skip'] = t('stk.bad_code');
        elseif ($x['serial'] === '' || preg_match('/[,;]/', $x['serial'])) $x['skip'] = t('sn.imp_e_serial');
        elseif (isset($seen[$key . '|' . $norm($x['serial'])])) $x['skip'] = t('sn.imp_e_twice', ['r' => $seen[$key . '|' . $norm($x['serial'])]]);
        else {
            if (!array_key_exists($key, $items)) $items[$key] = one('SELECT id, description, is_asset, track_serial FROM inv_items WHERE code = ?', [$code]) ?: null;
            $it = $items[$key];
            if ($it) { $x['item_id'] = (int)$it['id']; if ($x['description'] === '') $x['description'] = (string)$it['description'];
                if (empty($it['is_asset'])) { if (!$admin) $x['skip'] = t('sn.imp_e_switch'); else $x['state'] = 'switch'; } else $x['state'] = 'exists';
                if (!$x['skip'] && ($k = one("SELECT status FROM inv_serials WHERE item_id = ? AND serial_no = ?", [(int)$it['id'], $x['serial']])) && in_array($k['status'], ['in_stock', 'with_person'], true)) $x['skip'] = t('sn.imp_e_known', ['st' => t('sn.s_' . $k['status'])]);
            } else {
                if (!isset($first[$key])) { if (!$admin) $x['skip'] = t('sn.imp_e_newitem'); elseif ($desc === '') $x['skip'] = t('sn.imp_e_desc'); else $first[$key] = $x; }
                if (!$x['skip']) { $x['state'] = 'new'; if ($x['description'] === '') $x['description'] = (string)$first[$key]['description']; }
            }
        }
        if (!$x['skip'] && ($wc = $get($r, 'warehouse')) !== '') { $x['wh'] = $whs[$norm($wc)] ?? 0; if (!$x['wh']) $x['skip'] = t('sn.imp_e_wh', ['w' => $wc]); }
        if (!$x['skip']) { $ec = $get($r, 'emp_code'); $en = $get($r, 'holder');
            if ($ec !== '' || $en !== '') { if (!$hr) $x['warn'][] = t('sn.imp_w_hr'); else { $pid = $ec !== '' ? ($byCode[$norm($ec)] ?? 0) : 0; if (!$pid && $en !== '') $pid = $byName[$norm($en)] ?? 0; if (!$pid) $x['warn'][] = t('hre.imp_noperson', ['n' => $ec !== '' ? $ec : $en]); else { $x['pid'] = $pid; $x['holder'] = $pname[$pid] ?? ''; } } } }
        if ($x['skip']) $n['skip']++; else { $n['ok']++; $seen[$key . '|' . $norm($x['serial'])] = $ri + 1; if ($x['pid']) $n['holders']++; if ($x['warn']) $n['warn']++; }
        $out[] = $x;
    }
    if (!$out) throw new RuntimeException(t('hre.imp_empty'));
    foreach ($items as $k => $it) if ($it && empty($it['is_asset'])) foreach ($out as $x) if (!$x['skip'] && $x['state'] === 'switch' && $norm($x['code']) === $k) { $n['switch']++; break; }
    $n['new_items'] = count($first);
    return ['rows' => $out, 'n' => $n, 'at' => time(), 'by' => (int)(current_user()['id'] ?? 0)];
}
/** Writes the preview: items made or switched to company asset, one unit per row in stock with its number, and — for a row with a holder — a line on the equipment card of the person (to be issued), the unit handed over.
 *  $mode 'add' books every unit into the stock; 'number' only writes numbers for units the stock figure already counts (never more than on hand). Units with a holder are always booked in and out again. All or nothing. */
function sn_import_apply(array $pv, int $whDefault, string $mode): array {
    if (!sn_import_can()) throw new RuntimeException(t('err.noaccess')); if ((int)($pv['by'] ?? 0) !== (int)(current_user()['id'] ?? -1) || time() - (int)($pv['at'] ?? 0) > 3600) throw new RuntimeException(t('sn.imp_stale'));
    $whOk = array_map('intval', array_column(stk_warehouses(), 'id')); if (!in_array($whDefault, $whOk, true)) $whDefault = stk_default_wh();
    $admin = function_exists('pr_admin') && pr_admin(); $hr = function_exists('hre_can') && hre_can(); @set_time_limit(600); $u = current_user(); $ref = t('sn.imp_ref');
    $res = stk_tx(function () use ($pv, $whDefault, $mode, $admin, $hr, $u, $ref, $whOk) {
        $res = ['units' => 0, 'new_items' => 0, 'switched' => 0, 'handed' => 0, 'people' => []]; $ids = []; $groups = []; $made = [];
        foreach ($pv['rows'] as $x) { if ($x['skip']) continue; $key = mb_strtolower($x['code']);
            if (!isset($ids[$key])) { $it = one('SELECT id, is_asset, last_price FROM inv_items WHERE code = ? FOR UPDATE', [$x['code']]);
                if (!$it) { if (!$admin) throw new RuntimeException(t('sn.imp_e_newitem')); $cat = $x['category'] !== '' ? stk_category_find($x['category'], true) : null;
                    q("INSERT INTO inv_items (code, description, category_id, unit, maker, factory_code, status, currency, is_asset, track_serial, last_price, last_price_at, created_by) VALUES (?,?,?,?,?,?,'active','EUR',1,1,?,?,?)", [$x['code'], $x['description'], $cat, stk_unit_norm($x['unit'] !== '' ? $x['unit'] : 'pcs'), $x['maker'] ?: null, $x['part_no'] ?: null, $x['value'], $x['value'] !== null ? date('Y-m-d') : null, (int)($u['id'] ?? 0) ?: null]);
                    $ids[$key] = (int) db()->lastInsertId(); $made[$ids[$key]] = 1; $res['new_items']++; }
                else { if (empty($it['is_asset'])) { if (!$admin) throw new RuntimeException(t('sn.imp_e_switch')); q('UPDATE inv_items SET is_asset = 1, track_serial = 1 WHERE id = ?', [(int)$it['id']]); $res['switched']++; } $ids[$key] = (int)$it['id']; } }
            $wh = $x['wh'] && in_array((int)$x['wh'], $whOk, true) ? (int)$x['wh'] : $whDefault; $groups[$ids[$key] . ':' . $wh . ':' . ($x['pid'] || $mode === 'add' || isset($made[$ids[$key]]) ? 'a' : 'n')][] = $x; }
        $parts = []; foreach ($groups as $gk => $list) foreach (array_chunk($list, max(1, SN_MAX)) as $ch) $parts[] = [$gk, $ch];
        foreach ($parts as [$gk, $list]) { [$itemId, $wh, $how] = explode(':', $gk); $itemId = (int)$itemId; $wh = (int)$wh; $sns = array_column($list, 'serial');
            if ($how === 'a') { $val = null; foreach ($list as $x) if ($x['value'] !== null) { $val = $x['value']; break; }
                $mid = stk_move($itemId, $wh, 'in', (float) count($list), ['unit_price' => $val, 'reason' => 'manual', 'note' => $ref]); sn_attach($mid, $sns, ['ref' => $ref, 'exact' => true]); }
            else sn_register_units($itemId, $wh, $sns, $ref);
            foreach ($list as $x) { $s = sn_row($itemId, $x['serial'], true); if (!$s) throw new RuntimeException(t('err.notfound')); $res['units']++;
                if ($x['tag'] !== '' || $x['note'] !== '') q('UPDATE inv_serials SET asset_tag = COALESCE(?, asset_tag), note = COALESCE(?, note) WHERE id = ?', [$x['tag'] ?: null, $x['note'] !== '' ? mb_substr($x['note'], 0, 255) : null, (int)$s['id']]);
                if ($x['pid'] && $hr) { $p = hr_person((int)$x['pid']); if (!$p || empty($p['is_active'])) continue;
                    q('INSERT INTO hr_eq_items (person_id, category, description, serial_no, asset_tag, qty, cond_out, note, status, issued_on, created_by) VALUES (?,?,?,?,?,1,?,?,?,?,?)', [(int)$x['pid'], $x['eq_cat'], mb_substr($x['description'], 0, 200), $x['serial'], $x['tag'] ?: null, $x['cond'], $x['note'] ?: null, 'draft', $x['given_on'], (int)($u['id'] ?? 0)]);
                    $eq = (int) db()->lastInsertId(); sn_assign_person((int)$s['id'], (int)$x['pid'], $eq, (string)$p['name_en']); q('UPDATE hr_eq_items SET inv_serial_id = ? WHERE id = ?', [(int)$s['id'], $eq]); $res['handed']++; $res['people'][(int)$x['pid']] = 1; } } }
        $res['people'] = count($res['people']); return $res; });
    if (function_exists('cache_bump_later')) cache_bump_later('inv');
    return $res;
}
