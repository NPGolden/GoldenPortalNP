<?php
/**
 * GAS-CP-R97-290 — Inventory › Equipment: the register of the company equipment, one line per unit (company number, serial number, place or holder,
 * purchase, warranty) — linked to the measuring equipment register of ISO 9001 (7.1.5) and its calibrations, and to the equipment list of the person who holds it.
 * A unit is a unit of a company-asset item (inv_serials). Giving it to a person makes a line on the equipment list of that person (HR issues it).
 */
function eqp_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('sn_has') && sn_has() && (bool) one("SHOW COLUMNS FROM inv_serials LIKE 'ism_eq_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function eqp_qh(): bool { return function_exists('qh_can') && function_exists('ism_has') && ism_has() && qh_can(); }
/** The register is read by the warehouse and procurement, by QHSE and by HR; it is changed by the warehouse and procurement. */
function eqp_it(): bool { return function_exists('hre_it_member') && hre_it_member(); }
/** R97-292: HR has no part in the tools — the register is read by the warehouse and procurement, QHSE and the IT team. */
const EQP_DIRECT = ['tool', 'instrument'];   // lines of these categories go to the person without HR
function eqp_can_view(): bool { return eqp_has() && is_staff() && (sn_can() || eqp_qh() || eqp_it()); }
/** Details of a unit: the warehouse and procurement, and the IT team (it corrects the entry of a unit that a person did not accept). Stock is moved by the warehouse only. */
function eqp_can_edit(): bool { return eqp_has() && (sn_can() || eqp_it()); }
function eqp_can_stock(): bool { return eqp_has() && sn_can(); }
function eqp_can_give(): bool { return eqp_has() && function_exists('hre_prep_cats') && (sn_can() || eqp_qh() || eqp_it()) && (bool) hre_prep_cats(); }
const EQP_STATES = ['wh', 'person', 'project', 'retired', 'out'];
const EQP_CALS = ['measuring', 'valid', 'soon', 'expired', 'none', 'out'];

function eqp_sql(): string {
    return "SELECT s.*, i.code AS item_code, i.description, i.maker, i.factory_code, i.last_price, c.name AS category, w.code AS wh_code, w.name AS wh_name, p.name_en AS person, p.user_id AS person_user,
                   h.status AS eq_status, h.category AS eq_cat, pj.project_code, su.full_name AS site_user, r.code AS ism_code, r.title AS ism_title, r.status AS ism_status, r.review_date AS ism_until, r.data AS ism_data
            FROM inv_serials s JOIN inv_items i ON i.id = s.item_id LEFT JOIN inv_categories c ON c.id = i.category_id LEFT JOIN inv_warehouses w ON w.id = s.warehouse_id
            LEFT JOIN hr_people p ON p.id = s.person_id LEFT JOIN hr_eq_items h ON h.id = s.eq_item_id LEFT JOIN ism_records r ON r.id = s.ism_eq_id AND r.reg = 'qequip'" . (function_exists('ptl_has') && ptl_has() ? ' LEFT JOIN projects pj ON pj.id = s.project_id LEFT JOIN customer_users su ON su.id = s.site_user_id' : ' LEFT JOIN projects pj ON 1 = 0 LEFT JOIN customer_users su ON 1 = 0');
}
/** State of the calibration of a unit: null when it is not measuring equipment, else [state, tone] of the register. */
function eqp_cal(array $u): ?array { if (empty($u['ism_eq_id']) || $u['ism_code'] === null) return null; return ism_cal_state(['status' => (string)$u['ism_status'], 'review_date' => $u['ism_until'], 'data' => (string)$u['ism_data']]); }
function eqp_state(array $u): string { return match ((string)$u['status']) { 'in_stock' => 'wh', 'with_person' => 'person', 'on_project' => 'project', 'scrapped', 'removed' => 'retired', default => 'out' }; }
function eqp_state_pill(array $u): string { $s = eqp_state($u); return pill(['wh' => 'green', 'person' => 'gold', 'project' => 'navy', 'retired' => 'mute', 'out' => 'mute'][$s], t('eqp.s_' . $s)); }
function eqp_unit(int $id): ?array { return eqp_has() && $id ? (one(eqp_sql() . ' WHERE s.id = ? AND i.is_asset = 1', [$id]) ?: null) : null; }
/** The units of the register. $f: q, state, person, cal, cat. */
function eqp_rows(array $f = [], int $limit = 4000): array {
    if (!eqp_can_view()) return []; $w = ['i.is_asset = 1']; $a = [];
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $l = '%' . $q . '%'; $w[] = '(s.serial_no LIKE ? OR s.asset_tag LIKE ? OR i.code LIKE ? OR i.description LIKE ? OR i.maker LIKE ? OR i.factory_code LIKE ? OR s.location LIKE ? OR p.name_en LIKE ?)'; array_push($a, $l, $l, $l, $l, $l, $l, $l, $l); }
    $st = (string)($f['state'] ?? ''); if ($st === 'wh') $w[] = "s.status = 'in_stock'"; elseif ($st === 'person') $w[] = "s.status = 'with_person'"; elseif ($st === 'project') $w[] = "s.status = 'on_project'"; elseif ($st === 'retired') $w[] = "s.status IN ('scrapped','removed')"; elseif ($st === 'out') $w[] = "s.status = 'out'"; elseif ($st !== 'all') $w[] = "s.status <> 'removed'" . ($st === '' && empty($f['cal']) && $q === '' ? " AND s.status <> 'scrapped'" : '');
    if (!empty($f['person'])) { $w[] = 's.person_id = ?'; $a[] = (int)$f['person']; }
    if (!empty($f['project'])) { $w[] = "s.project_id = ? AND s.status = 'on_project'"; $a[] = (int)$f['project']; }
    if (!empty($f['cat'])) { $w[] = 'i.category_id = ?'; $a[] = (int)$f['cat']; }
    $cal = (string)($f['cal'] ?? ''); if ($cal !== '') $w[] = 's.ism_eq_id IS NOT NULL';
    $rows = all(eqp_sql() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(s.asset_tag, i.code), s.serial_no LIMIT ' . max(1, min(8000, $limit)), $a);
    if ($cal !== '' && $cal !== 'measuring') $rows = array_values(array_filter($rows, function ($u) use ($cal) { $c = eqp_cal($u); return $c && ($c[0] === $cal || ($cal === 'expired' && $c[0] === 'none')); }));
    return $rows;
}
/** Figures of the head of the page. */
function eqp_stats(): array {
    $o = ['all' => 0, 'wh' => 0, 'person' => 0, 'project' => 0, 'retired' => 0, 'measuring' => 0, 'soon' => 0, 'expired' => 0]; if (!eqp_can_view()) return $o;
    foreach (all("SELECT s.status, s.ism_eq_id, r.status AS ism_status, r.review_date AS ism_until, r.data AS ism_data, r.code AS ism_code FROM inv_serials s JOIN inv_items i ON i.id = s.item_id LEFT JOIN ism_records r ON r.id = s.ism_eq_id AND r.reg = 'qequip' WHERE i.is_asset = 1 AND s.status <> 'removed'") as $u) {
        $st = eqp_state($u); if ($st === 'retired') { $o['retired']++; continue; } $o['all']++; if (isset($o[$st])) $o[$st]++;
        if ($c = eqp_cal($u)) { $o['measuring']++; if ($c[0] === 'soon') $o['soon']++; if (in_array($c[0], ['expired', 'none', 'out'], true)) $o['expired']++; }
    }
    return $o;
}
function eqp_people(): array { try { return all("SELECT DISTINCT p.id, p.name_en FROM inv_serials s JOIN hr_people p ON p.id = s.person_id WHERE s.status = 'with_person' ORDER BY p.name_en"); } catch (Throwable $e) { return []; } }
function eqp_cats(): array { try { return all('SELECT DISTINCT c.id, c.name FROM inv_items i JOIN inv_categories c ON c.id = i.category_id WHERE i.is_asset = 1 ORDER BY c.name'); } catch (Throwable $e) { return []; } }
function eqp_d($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; }

/* ------------------------------------------------------------------ one unit */
function eqp_save_from_post(): int {
    if (!eqp_can_edit()) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['unit_id'] ?? 0); $u = eqp_unit($id); if (!$u) throw new RuntimeException(t('err.notfound'));
    $S = fn(string $k, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $n) ?: null; $tag = $S('asset_tag', 40);
    if ($tag !== null && scalar('SELECT COUNT(*) FROM inv_serials WHERE asset_tag = ? AND id <> ?', [$tag, $id])) throw new RuntimeException(t('eqp.e_tag', ['t' => $tag]));
    q('UPDATE inv_serials SET asset_tag = ?, location = ?, supplier = ?, purchased_on = ?, warranty_until = ?, note = ? WHERE id = ?', [$tag, $S('location', 80), $S('supplier', 160), eqp_d($_POST['purchased_on'] ?? ''), eqp_d($_POST['warranty_until'] ?? ''), $S('note', 255), $id]);
    sn_log($id, 'edit', ['ref' => t('eqp.log_edit')]);
    if ($u['ism_eq_id'] && eqp_qh()) eqp_ism_sync($id);
    return $id;
}
/** The place and the serial number of the unit are written on its record of the measuring equipment register. */
function eqp_ism_sync(int $unitId): void {
    $u = eqp_unit($unitId); if (!$u || !$u['ism_eq_id'] || $u['ism_code'] === null) return; $d = json_decode((string)$u['ism_data'], true) ?: [];
    $loc = $u['status'] === 'with_person' ? (string)$u['person'] : trim((string)$u['wh_code'] . ((string)$u['location'] !== '' ? ' · ' . $u['location'] : '')); if ($loc === ($d['location'] ?? '') || $loc === '') return;
    $d['location'] = mb_substr($loc, 0, 255); q('UPDATE ism_records SET data = ?, updated_at = NOW() WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), (int)$u['ism_eq_id']]);
}
/** Measuring equipment: a new record of the ISO 9001 register for the unit, an existing record linked to it, or the link taken away (the record stays). QHSE only. */
function eqp_measuring_from_post(): int {
    if (!eqp_has() || !eqp_qh() || !ism_can_reg('qequip')) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['unit_id'] ?? 0); $u = eqp_unit($id); if (!$u) throw new RuntimeException(t('err.notfound'));
    $how = (string)($_POST['how'] ?? 'new');
    if ($how === 'unlink') { if ($u['ism_eq_id']) { q('UPDATE inv_serials SET ism_eq_id = NULL WHERE id = ?', [$id]); sn_log($id, 'edit', ['ref' => t('eqp.log_unlink', ['c' => (string)$u['ism_code']])]); ism_event((int)$u['ism_eq_id'], 'update', t('eqp.ev_unlink', ['c' => eqp_no($u)])); } return $id; }
    if ($u['ism_eq_id'] && $u['ism_code'] !== null) throw new RuntimeException(t('eqp.e_linked', ['c' => (string)$u['ism_code']]));
    if ($how === 'link') { $rid = (int)($_POST['record_id'] ?? 0); $r = ism_get($rid); if (!$r || $r['reg'] !== 'qequip') throw new RuntimeException(t('err.notfound')); if (scalar('SELECT COUNT(*) FROM inv_serials WHERE ism_eq_id = ?', [$rid])) throw new RuntimeException(t('eqp.e_rec_used', ['c' => (string)$r['code']])); }
    else { $rid = eqp_ism_make($u, max(0, min(120, (int)($_POST['cal_interval'] ?? 12))) ?: 12, null, null); }
    q('UPDATE inv_serials SET ism_eq_id = ? WHERE id = ?', [$rid, $id]); $r = ism_get($rid); sn_log($id, 'edit', ['ref' => t('eqp.log_link', ['c' => (string)$r['code']])]); ism_event($rid, 'update', t('eqp.ev_link', ['c' => eqp_no($u)])); eqp_ism_sync($id);
    return $id;
}
function eqp_no(array $u): string { return (string)($u['asset_tag'] ?: $u['serial_no']); }
/** Makes the record of the measuring equipment register of a unit; its number is the company number when that is free. Returns the record id. */
function eqp_ism_make(array $u, int $interval, ?string $lastCal, ?string $until): int {
    $keep = $_POST; $code = (string)($u['asset_tag'] ?? ''); if ($code === '' || scalar("SELECT COUNT(*) FROM ism_records WHERE reg = 'qequip' AND code = ?", [$code])) $code = '';
    try { $_POST = ['reg' => 'qequip', 'title' => mb_substr((string)$u['description'], 0, 255), 'code' => $code, 'status' => 'in_use', 'review_date' => (string)$until,
                    'd' => array_filter(['eq_type' => mb_substr((string)($u['category'] ?? ''), 0, 255), 'maker_model' => mb_substr(trim((string)$u['maker'] . ' ' . (string)$u['factory_code']), 0, 255), 'serial' => (string)$u['serial_no'] === (string)$u['asset_tag'] ? '' : (string)$u['serial_no'], 'cal_needed' => 'yes', 'cal_interval' => (string)$interval], fn($v) => $v !== '')];
        $rid = ism_save_from_post(); } finally { $_POST = $keep; }
    if ($lastCal) { $r = ism_get($rid); $d = ism_data($r); $d['last_cal'] = $lastCal; q('UPDATE ism_records SET data = ? WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $rid]); ism_event($rid, 'update', t('eqp.ev_lastcal', ['d' => fmt_date($lastCal)])); }
    return $rid;
}
/** Records of the measuring equipment register that no unit of the inventory is linked to: [id => label]. */
function eqp_ism_free(): array { $o = []; try { foreach (all("SELECT r.id, r.code, r.title FROM ism_records r WHERE r.reg = 'qequip' AND r.status <> 'retired' AND NOT EXISTS (SELECT 1 FROM inv_serials s WHERE s.ism_eq_id = r.id) ORDER BY r.code LIMIT 800") as $r) $o[(int)$r['id']] = $r['code'] . ' · ' . $r['title']; } catch (Throwable $e) {} return $o; }
/** For the pages of the ISO register: [record id => unit (with place / holder)] of the given records (all when empty). */
function eqp_of_ism(array $ids = []): array {
    if (!eqp_has()) return []; $ids = array_values(array_filter(array_map('intval', $ids))); $o = [];
    try { foreach (all(eqp_sql() . ' WHERE s.ism_eq_id IS NOT NULL' . ($ids ? ' AND s.ism_eq_id IN (' . implode(',', $ids) . ')' : '')) as $u) $o[(int)$u['ism_eq_id']] = $u; } catch (Throwable $e) {}
    return $o;
}
/** What a person holds: the units of the register in the name of the person, with the state of the list line and of the calibration. */
function eqp_of_person(int $pid): array { if (!eqp_has() || !$pid) return []; try { return all(eqp_sql() . " WHERE s.person_id = ? AND s.status = 'with_person' AND i.is_asset = 1 ORDER BY (s.ism_eq_id IS NULL), COALESCE(s.asset_tag, i.code)", [$pid]); } catch (Throwable $e) { return []; } }
/** Where a unit is, in a few words: the warehouse and place, or the person. */
function eqp_where(array $u): string { return match (eqp_state($u)) { 'person' => '👤 ' . (string)$u['person'], 'project' => '📍 ' . (string)($u['project_code'] ?? '') . ((string)($u['site_user'] ?? '') !== '' ? ' · ' . $u['site_user'] : ''), 'wh' => '🏬 ' . (string)$u['wh_code'] . ((string)($u['location'] ?? '') !== '' ? ' · ' . $u['location'] : ''), 'retired' => t('eqp.s_retired') . ($u['retired_on'] ? ' ' . fmt_date($u['retired_on']) : ''), default => t('eqp.s_out') }; }

/** The unit is given to a person: a line on the equipment list of the person (a draft that HR issues), the unit out of the warehouse in that name. */
function eqp_give_from_post(): int {
    if (!eqp_can_give()) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['unit_id'] ?? 0); $u = eqp_unit($id); if (!$u) throw new RuntimeException(t('err.notfound'));
    if ($u['status'] !== 'in_stock') throw new RuntimeException(t('sn.not_in_stock', ['s' => (string)$u['serial_no'], 'st' => t('sn.s_' . $u['status'])]));
    $cal = eqp_cal($u); if ($cal && $cal[0] === 'out') throw new RuntimeException(t('eqp.e_out_of_service', ['c' => (string)$u['ism_code']]));
    $cat = $cal ? 'instrument' : (string)($_POST['category'] ?? 'tool'); $keep = $_POST;
    try { $_POST = ['person_id' => (int)($keep['person_id'] ?? 0), 'category' => $cat, 'inv_sn' => $id, 'src_id' => $cal ? (int)$u['ism_eq_id'] : 0, 'description' => trim((string)$u['description'] . ((string)$u['maker'] !== '' ? ' — ' . trim($u['maker'] . ' ' . $u['factory_code']) : '')), 'asset_tag' => (string)($u['asset_tag'] ?? ''), 'cond_out' => (string)($keep['cond_out'] ?? 'good'), 'note' => (string)($keep['note'] ?? ''), 'qty' => '1'];
        $GLOBALS['hre_no_hr'] = in_array($cat, EQP_DIRECT, true); $pid = hre_add_from_post(); } finally { $_POST = $keep; $GLOBALS['hre_no_hr'] = false; }
    if ($cal) eqp_ism_sync($id);
    if (in_array($cat, EQP_DIRECT, true)) { $line = (int) scalar("SELECT id FROM hr_eq_items WHERE inv_serial_id = ? AND person_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$id, $pid]); if ($line) hre_issue_direct($pid, [$line]); }   // R97-292: a tool goes to the person at once — the list waits for the person, not for HR
    return $pid;
}
/** R97-292 — the warehouse takes a tool or an instrument back from the person who holds it: the line is closed as returned, the unit is in its warehouse again. */
function eqp_take_back_from_post(): int {
    if (!eqp_can_stock()) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['unit_id'] ?? 0); $u = eqp_unit($id); if (!$u || $u['status'] !== 'with_person' || !$u['eq_item_id']) throw new RuntimeException(t('eqp.e_takeback'));
    if (!in_array((string)$u['eq_cat'], EQP_DIRECT, true)) throw new RuntimeException(t('eqp.e_takeback_hr'));
    $res = ($_POST['result'] ?? 'ok') === 'damaged' ? 'damaged' : 'ok'; $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['note'] ?? ''))), 0, 300); if ($res === 'damaged' && $note === '') throw new RuntimeException(t('ptl.e_note'));
    stk_tx(function () use ($u, $id, $res, $note) { $ref = t('eqp.ref_takeback', ['n' => (string)$u['person']]);
        q("UPDATE hr_eq_items SET status = 'returned', returned_on = CURDATE(), cond_in = ?, return_note = ? WHERE id = ? AND status IN ('draft','pending','held','missing')", [$res, $note ?: null, (int)$u['eq_item_id']]);
        $doc = (int) scalar('SELECT issue_doc_id FROM hr_eq_items WHERE id = ?', [(int)$u['eq_item_id']]); if ($doc && !scalar("SELECT COUNT(*) FROM hr_eq_items WHERE issue_doc_id = ? AND status = 'pending'", [$doc])) q("UPDATE hr_eq_docs SET status = 'cancelled' WHERE id = ? AND status IN ('sent','disputed')", [$doc]);
        sn_release_person($id, 'back', $ref . ($note !== '' ? ' — ' . $note : '')); });
    return $id;
}
/** The unit is taken out of use: it leaves the stock as written off; its record of the measuring equipment register is retired with it. */
function eqp_retire_from_post(): int {
    if (!eqp_can_stock()) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['unit_id'] ?? 0); $why = mb_substr(trim((string)($_POST['why'] ?? '')), 0, 120); if ($why === '') throw new RuntimeException(t('eqp.e_why'));
    stk_tx(function () use ($id, $why) {
        $s = one('SELECT s.*, i.last_price, i.is_asset FROM inv_serials s JOIN inv_items i ON i.id = s.item_id WHERE s.id = ? FOR UPDATE', [$id]); if (!$s || empty($s['is_asset'])) throw new RuntimeException(t('err.notfound'));
        $ref = t('eqp.ref_retire') . ' — ' . $why;
        if ($s['status'] === 'on_project' && function_exists('ptl_has') && ptl_has()) {   // R97-291: a tool lost or broken on a project — it left the stock when it was sent, so nothing moves
            q('UPDATE inv_tool_daily SET returned_at = NOW(), result = ?, note = ? WHERE serial_id = ? AND returned_at IS NULL', ['missing', mb_substr($ref, 0, 255), $id]);
            q("UPDATE inv_serials SET status = 'scrapped', retired_on = CURDATE(), last_ref = ?, project_id = NULL, site_user_id = NULL, site_since = NULL, site_due = NULL, site_wh_id = NULL WHERE id = ?", [mb_substr($ref, 0, 160), $id]); sn_log($id, 'scrap', ['ref' => $ref]);
        } else {
        if ($s['status'] !== 'in_stock') throw new RuntimeException(t('eqp.e_retire_state'));
        $mid = stk_move((int)$s['item_id'], (int)$s['warehouse_id'], 'out', 1.0, ['unit_price' => $s['last_price'], 'reason' => 'scrap', 'note' => $ref . ' · S/N ' . $s['serial_no']]);
        sn_attach($mid, [(string)$s['serial_no']], ['status' => 'scrapped', 'ref' => $ref]); q('UPDATE inv_serials SET retired_on = CURDATE() WHERE id = ?', [$id]);
        }
        if ($s['ism_eq_id']) { $r = one("SELECT id, status FROM ism_records WHERE id = ? AND reg = 'qequip'", [(int)$s['ism_eq_id']]); if ($r && $r['status'] !== 'retired') { q("UPDATE ism_records SET status = 'retired', updated_at = NOW() WHERE id = ?", [(int)$r['id']]); ism_event((int)$r['id'], 'status', t('ism.s_' . $r['status']) . ' → ' . t('ism.s_retired') . ' · ' . $ref); } }
    });
    return $id;
}
/** Hourly, with the reminders of the registers: the person who holds an instrument hears once when its calibration is due within 30 days or has passed. */
function eqp_cal_sweep(): int {
    if (!eqp_has() || !function_exists('nf_push') || !function_exists('ism_has') || !ism_has()) return 0; $n = 0; $lim = date('Y-m-d', strtotime('+30 days'));
    foreach (all(eqp_sql() . " WHERE s.status = 'with_person' AND s.ism_eq_id IS NOT NULL AND r.review_date IS NOT NULL AND r.review_date <= ? AND r.status = 'in_use' AND p.user_id IS NOT NULL", [$lim]) as $u) {
        if ((json_decode((string)$u['ism_data'], true)['cal_needed'] ?? 'yes') === 'no') continue;
        if (function_exists('nf_told') && nf_told((int)$u['person_user'], 'eqp:cal:' . $u['id'] . ':' . $u['ism_until'])) continue;   // R97-294: once per calibration date
        $n += (int) nf_push([(int)$u['person_user']], 'eqp:cal', 'eqp:cal:' . $u['id'] . ':' . $u['ism_until'], t('eqp.nf_cal', ['c' => eqp_no($u), 't' => mb_substr((string)$u['description'], 0, 80), 'd' => fmt_date($u['ism_until'])]), t('eqp.nf_cal_b'), url('my_equipment'), null, null);
    }
    return $n;
}
/** The register as an Excel file — what the filter shows. */
function eqp_export(array $rows): void {
    if (!eqp_can_view()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php'; $out = [];
    foreach ($rows as $u) { $c = eqp_cal($u); $d = json_decode((string)($u['ism_data'] ?? ''), true) ?: [];
        $out[] = [(string)$u['asset_tag'], (string)$u['serial_no'], (string)$u['description'], (string)$u['maker'], (string)$u['factory_code'], (string)$u['category'], t('eqp.s_' . eqp_state($u)), (string)$u['wh_code'], (string)$u['location'], (string)$u['person'], (string)$u['supplier'], (string)$u['purchased_on'], (string)$u['warranty_until'], (string)$u['ism_code'], $c ? t('qh.cal_' . $c[0]) : '', (string)($d['last_cal'] ?? ''), $c ? (string)$u['ism_until'] : '', (string)$u['retired_on'], (string)$u['note']]; }
    xlsx_download('GAS-equipment-register-' . date('Ymd') . '.xlsx', [['name' => 'Equipment', 'first' => true, 'header' => [t('eqp.c_no'), t('eqp.c_sn'), t('stk.desc'), t('eqp.c_maker'), t('eqp.c_model'), t('stk.category'), t('th.status'), t('stk.warehouse'), t('eqp.c_area'), t('eqp.c_holder'), t('eqp.c_supplier'), t('eqp.c_purchased'), t('eqp.c_warranty'), t('eqp.c_iso'), t('qh.cal'), t('ism.f_last_cal'), t('ism.f_valid_until'), t('eqp.c_retired_on'), t('eqp.c_note')], 'rows' => $out, 'widths' => [12, 20, 40, 16, 20, 18, 14, 10, 14, 26, 24, 12, 12, 10, 12, 12, 12, 12, 30]]]);
}

/* ------------------------------------------------------------------ import of an equipment list (.csv / .xlsx): one row = one unit with its own number */
const EQP_IMPORT_COLS = ['code' => ['Code', 'Company No.', 'Company number', 'Equipment code', 'Asset tag'], 'serial' => ['S/N', 'Serial No.', 'Serial', 'Serial number'], 'category' => ['Category'], 'description' => ['Description', 'Title'], 'maker' => ['Maker', 'Manufacturer'], 'model' => ['Model', 'Model / Part No.', 'Part No.'],
                         'area' => ['Area', 'Location', 'Place'], 'retired' => ['Retired'], 'supplier' => ['Supplier'], 'warranty' => ['Warranty Expiry', 'Warranty until', 'Warranty'], 'last_cal' => ['Last Calibration', 'Last Calibrartion', 'Last calibration date'], 'cal_due' => ['Calibration Due Date', 'Calibration due', 'Valid until'],
                         'retired_on' => ['Retired Date', 'Retired on'], 'purchased_on' => ['Purchase Date', 'Purchased on'], 'value' => ['Value', 'Value (EUR)', 'Price'], 'note' => ['Notes', 'Note', 'Remarks']];
function eqp_import_can(): bool { return eqp_can_stock() && function_exists('pr_admin') && pr_admin(); }   // it makes inventory items
function eqp_import_template(): void {
    if (!eqp_import_can()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php'; $y = (int) date('Y');
    xlsx_download('GAS-import-equipment-register.xlsx', [['name' => 'Equipment', 'first' => true, 'header' => ['Code', 'S/N', 'Category', 'Description', 'Maker', 'Model', 'Area', 'Retired', 'Supplier', 'Warranty Expiry', 'Last Calibration', 'Calibration Due Date', 'Retired Date', 'Purchase Date', 'Value', 'Notes'],
        'rows' => [['EQ-0001', 'SERIAL-0001', 'Measuring instruments', 'Multimeter', 'Maker', 'Model 1', 'A1', '', 'Supplier', ($y + 1) . '-06-30', $y . '-03-01', ($y + 1) . '-02-28', '', ($y - 1) . '-06-30', 250, 'example row — delete it'], ['EQ-0002', '', 'Tools', 'Cordless drill', 'Maker', 'Model 2', 'B2', '', '', '', '', '', '', '', '', 'example row — delete it']], 'widths' => [12, 18, 22, 34, 16, 18, 10, 9, 22, 14, 14, 16, 12, 14, 10, 30]],
        ['name' => 'Lists and rules', 'header' => ['Column', 'Rule'], 'rows' => [['Code', t('eqp.imp_n1')], ['S/N', t('eqp.imp_n2')], ['Retired', t('eqp.imp_n3')], ['Last Calibration', t('eqp.imp_n4')], ['', t('eqp.imp_n5')]], 'widths' => [22, 130]]]);
}
/** Rows of the uploaded file: .xlsx (first sheet) or .csv (comma or semicolon, UTF-8 or Windows Greek). */
function eqp_import_file_rows(array $f): array {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? '')) || (int)$f['size'] > 5 * 1024 * 1024) throw new RuntimeException(t('hre.imp_nofile'));
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') { require_once __DIR__ . '/xlsx.php'; return array_values(xlsx_rows((string)$f['tmp_name'])); }
    if ($ext !== 'csv') throw new RuntimeException(t('eqp.imp_e_type'));
    return eqp_csv_rows((string) file_get_contents((string)$f['tmp_name']));
}
function eqp_csv_rows(string $raw): array {
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3); if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string) @iconv('Windows-1253', 'UTF-8//IGNORE', $raw);
    $first = (string) strtok($raw, "\n"); $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ','; $rows = []; $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) { if ($r === [null]) continue; $rows[] = array_map('strval', $r); if (count($rows) > 5001) break; } fclose($fh); return $rows;
}
/** A date of the file as yyyy-mm-dd. $order 'dmy' or 'mdy' for 3/4/2026; a time after the date is ignored. */
function eqp_date(string $v, string $order): ?string {
    $v = trim($v); if ($v === '') return null; if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:\D.*)?$#', $v, $m)) { [$d, $mo] = $order === 'mdy' ? [(int)$m[2], (int)$m[1]] : [(int)$m[1], (int)$m[2]]; return checkdate($mo, $d, (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $mo, $d) : null; }
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    return null;
}
/** Reads the list into a preview — nothing is written. $order: 'auto' looks at every date of the file to tell day/month from month/day. */
function eqp_import_read(array $rows, string $order = 'auto'): array {
    if (!eqp_import_can()) throw new RuntimeException(t('err.noaccess')); if (count($rows) < 2) throw new RuntimeException(t('hre.imp_empty'));
    require_once __DIR__ . '/xlsx.php'; $map = xlsx_header_map($rows[0], EQP_IMPORT_COLS); if ($map['code'] === null || $map['description'] === null) throw new RuntimeException(t('eqp.imp_e_cols'));
    $get = fn(array $r, string $k) => $map[$k] === null ? '' : trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($r[$map[$k]] ?? ''))); $norm = fn(string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    $dcols = ['warranty', 'last_cal', 'cal_due', 'retired_on', 'purchased_on']; $data = array_slice($rows, 1, 5000, true);
    if (!in_array($order, ['dmy', 'mdy'], true)) { $a = 0; $b = 0; foreach ($data as $r) foreach ($dcols as $k) if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-]\d{4}#', $get($r, $k), $m)) { if ((int)$m[1] > 12) $a++; if ((int)$m[2] > 12) $b++; }
        if ($a && $b) throw new RuntimeException(t('eqp.imp_e_dates')); $order = $b ? 'mdy' : 'dmy'; $sure = (bool)($a || $b); } else $sure = true;
    $qh = eqp_qh() && ism_can_reg('qequip'); $seen = []; $out = []; $n = ['ok' => 0, 'skip' => 0, 'retired' => 0, 'cal' => 0, 'warn' => 0];
    foreach ($data as $ri => $r) {
        $code = mb_substr($get($r, 'code'), 0, 40); $desc = mb_substr($get($r, 'description'), 0, 255); $serial = mb_substr(preg_replace('/\s+/u', ' ', $get($r, 'serial')), 0, 80); if (preg_match('/^(-+|—|n\/?a|none|no|\?+|0)$/iu', $serial)) $serial = '';
        if ($code === '' && $desc === '' && $serial === '') continue; if (stripos($get($r, 'note'), 'example row') !== false) continue;
        $lat = strtr($code, ['Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X']); $greek = $lat !== $code; $code = $lat;   // a Greek capital typed for the Latin letter that looks the same
        $ret = in_array($norm($get($r, 'retired')), ['yes', 'y', '1', 'true', 'ναι', 'x'], true); $x = ['line' => $ri + 1, 'code' => $code, 'serial' => str_replace([',', ';'], ' ', $serial), 'description' => $desc, 'category' => mb_substr($get($r, 'category'), 0, 120), 'maker' => mb_substr($get($r, 'maker'), 0, 80), 'model' => mb_substr($get($r, 'model'), 0, 80), 'area' => mb_substr($get($r, 'area'), 0, 80), 'supplier' => mb_substr($get($r, 'supplier'), 0, 160),
              'note' => mb_substr($get($r, 'note'), 0, 255), 'value' => null, 'retired' => $ret, 'skip' => null, 'warn' => []];
        foreach ($dcols as $k) { $raw = $get($r, $k); $x[$k] = eqp_date($raw, $order); if ($raw !== '' && $x[$k] === null) $x['warn'][] = t('eqp.imp_w_date', ['v' => mb_substr($raw, 0, 30)]); }
        if ($x['retired_on']) $x['retired'] = $ret = true;
        if ($greek) $x['warn'][] = t('eqp.imp_w_greek');
        $v = str_replace([' ', ','], ['', '.'], $get($r, 'value')); if ($v !== '' && is_numeric($v) && (float)$v >= 0 && (float)$v < 1e9) $x['value'] = round((float)$v, 2);
        $x['cal'] = !$ret && ($x['last_cal'] || $x['cal_due']); if ($x['cal'] && !$qh) { $x['cal'] = false; $x['warn'][] = t('eqp.imp_w_qh'); }
        if ($x['cal'] && $x['last_cal'] && $x['cal_due'] && $x['cal_due'] < $x['last_cal']) { $x['cal'] = false; $x['warn'][] = t('eqp.imp_w_calorder'); }
        $key = $norm($code);
        if ($code === '') $x['skip'] = t('eqp.imp_e_code');
        elseif (!preg_match(STK_CODE_RE, $code)) $x['skip'] = t('stk.bad_code');
        elseif ($desc === '') $x['skip'] = t('sn.imp_e_desc');
        elseif (isset($seen[$key])) $x['skip'] = t('sn.imp_e_twice', ['r' => $seen[$key]]);
        elseif (scalar('SELECT COUNT(*) FROM inv_serials WHERE asset_tag = ?', [$code])) $x['skip'] = t('eqp.imp_e_known');
        elseif (scalar('SELECT COUNT(*) FROM inv_items WHERE code = ?', [$code])) $x['skip'] = t('eqp.imp_e_item');
        if ($x['skip']) $n['skip']++; else { $seen[$key] = $ri + 1; $n['ok']++; if ($ret) $n['retired']++; if ($x['cal']) $n['cal']++; if ($x['warn']) $n['warn']++; }
        $out[] = $x;
    }
    if (!$out) throw new RuntimeException(t('hre.imp_empty'));
    return ['rows' => $out, 'n' => $n, 'order' => $order, 'sure' => $sure, 'at' => time(), 'by' => (int)(current_user()['id'] ?? 0)];
}
/** Writes the preview, all or nothing: per row an inventory item (company asset) with one unit — in the chosen warehouse, or written off when the list says retired —
 *  and, for a row with calibration dates, its record of the measuring equipment register with the last calibration and the date it is valid until. */
function eqp_import_apply(array $pv, int $wh): array {
    if (!eqp_import_can()) throw new RuntimeException(t('err.noaccess')); if ((int)($pv['by'] ?? 0) !== (int)(current_user()['id'] ?? -1) || time() - (int)($pv['at'] ?? 0) > 3600) throw new RuntimeException(t('sn.imp_stale'));
    $whOk = array_map('intval', array_column(stk_warehouses(), 'id')); if (!in_array($wh, $whOk, true)) $wh = stk_default_wh(); $qh = eqp_qh() && ism_can_reg('qequip'); @set_time_limit(600); $u = current_user(); $ref = t('eqp.imp_ref');
    $res = stk_tx(function () use ($pv, $wh, $qh, $u, $ref) {
        $res = ['units' => 0, 'retired' => 0, 'cal' => 0]; $cats = [];
        foreach ($pv['rows'] as $x) { if ($x['skip']) continue;
            if (scalar('SELECT COUNT(*) FROM inv_items WHERE code = ? FOR UPDATE', [$x['code']]) || scalar('SELECT COUNT(*) FROM inv_serials WHERE asset_tag = ?', [$x['code']])) throw new RuntimeException(t('eqp.imp_e_known') . ' (' . $x['code'] . ')');
            $ck = mb_strtolower($x['category']); if ($ck !== '' && !array_key_exists($ck, $cats)) $cats[$ck] = stk_category_find($x['category'], true);
            q("INSERT INTO inv_items (code, description, category_id, unit, maker, factory_code, status, currency, is_asset, track_serial, last_price, last_price_at, created_by) VALUES (?,?,?,?,?,?,'active','EUR',1,1,?,?,?)", [$x['code'], $x['description'], $ck !== '' ? $cats[$ck] : null, stk_unit_norm('pcs'), $x['maker'] ?: null, $x['model'] ?: null, $x['value'], $x['value'] !== null ? date('Y-m-d') : null, (int)($u['id'] ?? 0) ?: null]);
            $item = (int) db()->lastInsertId(); $sn = $x['serial'] !== '' ? $x['serial'] : $x['code'];
            if ($x['retired']) { q("INSERT INTO inv_serials (item_id, serial_no, status, last_ref) VALUES (?,?,'scrapped',?)", [$item, $sn, mb_substr($ref, 0, 160)]); $sid = (int) db()->lastInsertId(); sn_log($sid, 'register', ['ref' => $ref . ' — ' . t('eqp.s_retired')]); $res['retired']++; }
            else { $mid = stk_move($item, $wh, 'in', 1.0, ['unit_price' => $x['value'], 'reason' => 'manual', 'note' => $ref]); sn_attach($mid, [$sn], ['ref' => $ref, 'exact' => true]); $sid = (int) sn_row($item, $sn)['id']; }
            q('UPDATE inv_serials SET asset_tag = ?, location = ?, supplier = ?, purchased_on = ?, warranty_until = ?, retired_on = ?, note = ? WHERE id = ?', [$x['code'], $x['area'] ?: null, $x['supplier'] ?: null, $x['purchased_on'], $x['warranty'], $x['retired'] ? ($x['retired_on'] ?: null) : null, $x['note'] ?: null, $sid]);
            if ($x['cal'] && $qh) { $iv = 12; if ($x['last_cal'] && $x['cal_due']) { $iv = (int) round((strtotime($x['cal_due']) - strtotime($x['last_cal'])) / 86400 / 30.44); $iv = max(1, min(120, $iv)); }
                $rid = eqp_ism_make(['asset_tag' => $x['code'], 'serial_no' => $sn, 'description' => $x['description'], 'category' => $x['category'], 'maker' => $x['maker'], 'factory_code' => $x['model']], $iv, $x['last_cal'], $x['cal_due']);
                q('UPDATE inv_serials SET ism_eq_id = ? WHERE id = ?', [$rid, $sid]); ism_event($rid, 'update', t('eqp.ev_link', ['c' => $x['code']])); eqp_ism_sync($sid); $res['cal']++; }
            $res['units']++; }
        return $res; });
    $res['skipped'] = (int)($pv['n']['skip'] ?? 0);
    try { $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
        if (function_exists('sn_import_log_has') && sn_import_log_has()) q('INSERT INTO inv_asset_imports (user_id, user_name, created_at, mode, warehouse_id, units, new_items, switched, handed, people, skipped, ip) VALUES (?,?,NOW(),?,?,?,?,0,0,0,?,?)', [(int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 120), 'register', $wh, (int)$res['units'], (int)$res['units'], (int)$res['skipped'], mb_substr($ip, 0, 45) ?: null]);
    } catch (Throwable $e) {}
    if (function_exists('cache_bump_later')) cache_bump_later('inv');
    return $res;
}

function eqp_handle_action(string $action): bool {
    if (!str_starts_with($action, 'eqp_')) return false; if (!eqp_has()) throw new RuntimeException(t('err.noaccess'));
    $one = fn(int $id, string $hash = '') => redirect('inv_equipment_unit', ['id' => $id, '_r' => uniqid()], $hash);
    switch ($action) {
        case 'eqp_save':      $id = eqp_save_from_post(); flash('good', t('sys.saved')); $one($id);
        case 'eqp_measuring': $id = eqp_measuring_from_post(); flash('good', t('sys.saved')); $one($id, '#cal');
        case 'eqp_give':      { $id = (int)($_POST['unit_id'] ?? 0); eqp_give_from_post(); $uu = eqp_unit($id); flash('good', t($uu && in_array((string)$uu['eq_cat'], EQP_DIRECT, true) ? 'eqp.given_t' : 'eqp.given')); $one($id, '#holder'); }
        case 'eqp_take_back': $id = eqp_take_back_from_post(); flash('good', t('eqp.takeback_ok')); $one($id, '#holder');
        case 'eqp_retire':    $id = eqp_retire_from_post(); flash('good', t('eqp.retired_ok')); $one($id);
        case 'eqp_import_parse': { $_SESSION['eqp_import'] = eqp_import_read(eqp_import_file_rows($_FILES['file'] ?? []), (string)($_POST['order'] ?? 'auto')); redirect('inv_equipment_import', ['_r' => uniqid()]); }
        case 'eqp_import_apply': { $pv = $_SESSION['eqp_import'] ?? null; if (!$pv) throw new RuntimeException(t('sn.imp_stale')); $r = eqp_import_apply($pv, (int)($_POST['warehouse_id'] ?? 0)); unset($_SESSION['eqp_import']); $_SESSION['eqp_import_result'] = $r; redirect('inv_equipment_import', ['_r' => uniqid()]); }
    }
    throw new RuntimeException(t('err.noaccess'));
}
