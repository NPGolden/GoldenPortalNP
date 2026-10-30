<?php
/**
 * Production orders and production cards.                                                        GAS-CP-R41-001
 *
 * A production order (GAS-PO-YYYY-NNN / GIT-PO-YYYY-NNN) follows the manufacturing of a system from the work package
 * that asked for it to the as-built: the units (serial numbers in the Equipment register — one production card
 * each, with the status and the percentage finished set by the production manager), the drawings from draft to
 * approved-for-production to as-built, the FAT / HAT / SAT records, the photos from the shop floor, the delivery
 * to the vessel and the commissioning. Project managers monitor every order in progress under Fleet › Equipment ›
 * Production. Staff only — owners never see production orders (the units appear on their vessel as equipment).
 */
declare(strict_types=1);

const PD_STATUSES = ['design', 'production', 'fat', 'delivered', 'hat', 'commissioning', 'sat', 'closed', 'cancelled'];
const PD_OPEN     = ['design', 'production', 'fat', 'delivered', 'hat', 'commissioning', 'sat'];
const PD_TONE     = ['design' => 'mute', 'production' => 'gold', 'fat' => 'navy', 'delivered' => 'blue', 'hat' => 'navy', 'commissioning' => 'gold', 'sat' => 'navy', 'closed' => 'green', 'cancelled' => 'bad'];
const PD_USTATUSES = ['pending', 'in_production', 'tested', 'delivered', 'installed', 'commissioned', 'accepted', 'cancelled'];
const PD_UTONE    = ['pending' => 'mute', 'in_production' => 'gold', 'tested' => 'navy', 'delivered' => 'blue', 'installed' => 'navy', 'commissioned' => 'gold', 'accepted' => 'green', 'cancelled' => 'bad'];
/** Suggested % finished when a unit status is chosen without a figure. */
const PD_UPCT     = ['pending' => 0, 'in_production' => 25, 'tested' => 60, 'delivered' => 70, 'installed' => 80, 'commissioned' => 90, 'accepted' => 100, 'cancelled' => 0];
/** Document phases of an order — in the order of the life cycle. [sp_files kind, default visibility] */
const PD_PHASES   = ['draft' => ['drawing', 'internal'], 'approved' => ['drawing', 'external'], 'asbuilt' => ['drawing', 'external'], 'fat' => ['fat', 'external'], 'photo' => ['photo', 'internal'],
                     'delivery' => ['other', 'external'], 'hat' => ['hat', 'external'], 'commissioning' => ['commissioning_report', 'external'], 'sat' => ['sat', 'external'], 'subcontract' => ['other', 'internal'], 'other' => ['other', 'internal']];
/* Subcontracted / external production (R41-003): a scope line per order — the whole equipment of a unit or a part of the work. */
const PS_STATUSES = ['planned', 'ordered', 'in_production', 'delivered', 'inspected', 'accepted', 'rejected', 'cancelled'];
const PS_TONE     = ['planned' => 'mute', 'ordered' => 'navy', 'in_production' => 'gold', 'delivered' => 'blue', 'inspected' => 'navy', 'accepted' => 'green', 'rejected' => 'bad', 'cancelled' => 'mute'];
const PS_PCT      = ['planned' => 0, 'ordered' => 10, 'in_production' => 40, 'delivered' => 70, 'inspected' => 85, 'accepted' => 100, 'rejected' => 70, 'cancelled' => 0];

function pd_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'production_orders'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pd_status_label(string $s): string { return t('pd.s_' . $s); }
function pd_status_pill(string $s): string { return pill(PD_TONE[$s] ?? 'mute', t('pd.s_' . $s)); }
function pd_ustatus_pill(string $s): string { return pill(PD_UTONE[$s] ?? 'mute', t('pd.u_' . $s)); }
function pd_phase_label(string $p): string { return t('pd.ph_' . $p); }
function pd_pct_bar(int $pct): string { $tone = $pct >= 100 ? 'green' : ($pct >= 50 ? 'gold' : 'amber'); return function_exists('bg_bar') ? bg_bar((float)$pct, $tone, null, '7px') : ''; }

/* ------------------------------------------------------------------ permissions */
/** Production managers (Settings › Portal accounts) may edit every order and card. */
function pd_is_production(?int $uid = null): bool {
    static $c = [];
    $uid = $uid ?: (int)(current_user()['id'] ?? 0); if (!$uid) return false;
    if (!isset($c[$uid])) { try { $c[$uid] = (bool) scalar('SELECT is_production FROM customer_users WHERE id = ?', [$uid]); } catch (Throwable $e) { $c[$uid] = false; } }
    return $c[$uid];
}
/** Edit the order / its cards: administrators, production managers, the order's manager, the PM of the project and work-package managers. */
function pd_can_edit(?array $o = null): bool {
    if (!is_staff() || !pd_has()) return false;
    if (is_cp_admin() || pd_is_production()) return true;
    if (!$o) return function_exists('wp_can_manage') ? true : false;
    $me = (int)(current_user()['id'] ?? 0);
    if ($me && (int)($o['manager_user_id'] ?? 0) === $me) return true;
    if ($me && (int)($o['pm_user_id'] ?? 0) === $me) return true;
    return function_exists('wp_can_manage') && wp_can_manage((int)$o['project_id']);
}
/** Create a new order: the same people, for the project in hand. */
function pd_can_create(int $pid): bool { return is_staff() && pd_has() && (is_cp_admin() || pd_is_production() || (function_exists('wp_can_manage') && wp_can_manage($pid)) || (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]) === (int)(current_user()['id'] ?? 0)); }

/* ------------------------------------------------------------------ numbering */
function pd_next_no(int $pid, ?int $sid = null): string {
    $pre = function_exists('br_prefix') ? br_prefix($pid, $sid) : 'GAS'; $base = $pre . '-PO-' . date('Y') . '-';
    $n = (int) scalar('SELECT MAX(CAST(SUBSTRING(po_no, ?) AS UNSIGNED)) FROM production_orders WHERE po_no LIKE ?', [strlen($base) + 1, $base . '%']) + 1;
    return $base . sprintf('%03d', $n);
}

/* ------------------------------------------------------------------ reading */
const PD_SELECT = "SELECT o.*, p.project_code, p.name AS project_name, p.pm_user_id, p.project_manager, s.code AS sub_code, s.name AS sub_name, v.name AS vessel, w.wp_no, w.title AS work_title, w.status AS work_status,
                   cu.full_name AS manager, vs.name AS system_name, vs.abbr AS system_abbr,
                   (SELECT COUNT(*) FROM production_units u WHERE u.order_id = o.id AND u.status <> 'cancelled') AS n_units,
                   (SELECT COUNT(*) FROM production_units u WHERE u.order_id = o.id AND u.status = 'accepted') AS n_accepted,
                   (SELECT COUNT(*) FROM production_files f WHERE f.order_id = o.id) AS n_files,
                   (SELECT MAX(l.created_at) FROM production_log l WHERE l.order_id = o.id) AS last_event
            FROM production_orders o JOIN projects p ON p.id = o.project_id LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN vessels v ON v.id = o.vessel_id
            LEFT JOIN sp_works w ON w.id = o.work_id LEFT JOIN customer_users cu ON cu.id = o.manager_user_id LEFT JOIN vessel_systems vs ON vs.id = o.system_id";

function pd_get(int $id): ?array {
    if (!pd_has() || !is_staff()) return null;
    $o = one(PD_SELECT . ' WHERE o.id = ?', [$id]); if (!$o) return null;
    $o['units'] = all('SELECT u.*, e.serial_no, e.set_code, e.maker, e.model, e.kind, e.family, cu.full_name AS updated_name FROM production_units u JOIN equipment e ON e.id = u.equipment_id LEFT JOIN customer_users cu ON cu.id = u.updated_by WHERE u.order_id = ? ORDER BY e.serial_no', [$id]);
    $o['files'] = all('SELECT pf.*, f.original_name, f.mime_type, f.size_bytes, f.title AS file_title, f.doc_no, f.revision, f.visibility, f.created_at AS uploaded_at, cu.full_name AS by_name, e.serial_no FROM production_files pf JOIN sp_files f ON f.id = pf.file_id LEFT JOIN customer_users cu ON cu.id = pf.created_by LEFT JOIN production_units u ON u.id = pf.unit_id LEFT JOIN equipment e ON e.id = u.equipment_id WHERE pf.order_id = ? ORDER BY pf.id', [$id]);
    $o['subs'] = ps_of_order($id);
    foreach ($o['units'] as &$un) { $un['external'] = null; foreach ($o['subs'] as $ps) if ((int)$ps['unit_id'] === (int)$un['id'] && $ps['whole'] && !in_array($ps['status'], ['cancelled'], true)) $un['external'] = $ps; } unset($un);
    $o['log'] = all('SELECT l.*, e.serial_no FROM production_log l LEFT JOIN production_units u ON u.id = l.unit_id LEFT JOIN equipment e ON e.id = u.equipment_id WHERE l.order_id = ? ORDER BY l.id DESC', [$id]);
    return $o;
}
/** Orders list. $f: status (open|closed|<status>), pm, manager, vessel, project, subproject, work, q */
function pd_list(array $f = []): array {
    if (!pd_has() || !is_staff()) return [];
    $w = ['1 = 1']; $a = [];
    $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $w[] = "o.status IN ('" . implode("','", PD_OPEN) . "')"; elseif ($st === 'closed') $w[] = "o.status IN ('closed','cancelled')"; elseif (in_array($st, PD_STATUSES, true)) { $w[] = 'o.status = ?'; $a[] = $st; }
    foreach (['pm' => 'p.pm_user_id', 'manager' => 'o.manager_user_id', 'vessel' => 'o.vessel_id', 'project' => 'o.project_id', 'subproject' => 'o.subproject_id', 'work' => 'o.work_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $a[] = (int)$f[$k]; }
    if (!empty($f['q'])) { $w[] = '(o.po_no LIKE ? OR o.title LIKE ? OR p.project_code LIKE ? OR v.name LIKE ? OR EXISTS (SELECT 1 FROM production_units u JOIN equipment e ON e.id = u.equipment_id WHERE u.order_id = o.id AND e.serial_no LIKE ?))'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l, $l); }
    if (function_exists('scope_sql')) { [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa); }
    return all(PD_SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(o.status, 'production','fat','delivered','hat','commissioning','sat','design','closed','cancelled'), o.planned_end IS NULL, o.planned_end, o.id DESC", $a);
}
/** The open order of a work package, if any. */
function pd_for_work(int $workId): ?array { return pd_has() && is_staff() ? one(PD_SELECT . " WHERE o.work_id = ? ORDER BY o.status IN ('closed','cancelled'), o.id DESC LIMIT 1", [$workId]) : null; }
/** Orders of a sub-project (Works tab), of a project. */
function pd_for_sub(int $sid): array { return pd_list(['status' => '', 'subproject' => $sid]); }
/** The production card of a unit: the order rows + the unit row (+ its files). Null when the unit was never produced through the portal. */
function pd_card(int $eqId): ?array {
    if (!pd_has() || !is_staff()) return null;
    $u = one('SELECT u.* FROM production_units u JOIN production_orders o ON o.id = u.order_id WHERE u.equipment_id = ? ORDER BY o.id DESC LIMIT 1', [$eqId]); if (!$u) return null;
    $o = pd_get((int)$u['order_id']); if (!$o) return null;
    $o['unit'] = $u;
    $o['unit_files'] = array_values(array_filter($o['files'], fn($f) => (int)$f['unit_id'] === (int)$u['id'] || !$f['unit_id']));
    return $o;
}
/** Overall % of an order = the mean of its live units (or the stored figure when it has no units). */
function pd_recalc(int $id): void {
    $r = one("SELECT COUNT(*) n, AVG(pct) a FROM production_units WHERE order_id = ? AND status <> 'cancelled'", [$id]);
    if ((int)$r['n'] > 0) q('UPDATE production_orders SET pct = ? WHERE id = ?', [(int) round((float)$r['a']), $id]);
    $sid = (int) scalar('SELECT subproject_id FROM production_orders WHERE id = ?', [$id]); if ($sid) pd_feed_subproject($sid);
}
/** R41-002: the Production stage of a sub-project = the mean % of every serial number in production for it (all live orders); null when no order with units exists. */
function pd_sub_pct(int $sid): ?int {
    if (!pd_has() || !$sid) return null;
    $r = one("SELECT COUNT(*) n, AVG(u.pct) a FROM production_units u JOIN production_orders o ON o.id = u.order_id WHERE o.subproject_id = ? AND o.status <> 'cancelled' AND u.status <> 'cancelled'", [$sid]);
    return (int)$r['n'] > 0 ? (int) round((float)$r['a']) : null;
}
/* ------------------------------------------------------------------ R42-001: every stage of the sub-project fed from the production orders (option C) */
const PD_STAGE_SLUGS = ['engineering', 'procurement', 'production', 'fat', 'installation', 'sathat', 'commissioning'];
function pd_stages_has(): bool { static $h = null; if ($h === null) { try { $h = pd_has() && (bool) scalar("SHOW COLUMNS FROM production_orders LIKE 'st_engineering'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Share (0–100) of the live units of an order whose status has reached $min in the unit life cycle. */
function pd_unit_share(array $units, string $min): ?int {
    $rank = array_flip(PD_USTATUSES); $live = array_values(array_filter($units, fn($u) => $u['status'] !== 'cancelled')); if (!$live) return null;
    $n = 0; foreach ($live as $u) if (($rank[$u['status']] ?? 0) >= $rank[$min]) $n++;
    return (int) round($n / count($live) * 100);
}
/** Do the files of a phase cover every live unit (a file without unit = all units)? */
function pd_docs_cover(array $o, string $phase): bool {
    $live = array_values(array_filter($o['units'], fn($u) => $u['status'] !== 'cancelled')); $ids = array_map(fn($u) => (int)$u['id'], $live); $cov = [];
    foreach ($o['files'] as $f) if ($f['phase'] === $phase) { if (!$f['unit_id']) return true; $cov[(int)$f['unit_id']] = true; }
    if (!$ids) return false; foreach ($ids as $i) if (empty($cov[$i])) return false; return true;
}
/** The automatic value of every stage for one order (null = no evidence), the override, and the effective value. */
function pd_order_stages(array $o): array {
    $has = fn(string $ph) => (bool) array_filter($o['files'], fn($f) => $f['phase'] === $ph);
    $phaseRank = array_flip(PD_STATUSES); $pr = $phaseRank[$o['status']] ?? 0;
    $auto = [];
    $eng = $has('asbuilt') ? 100 : ($has('approved') ? 70 : ($has('draft') ? 30 : 0));
    if ($o['status'] === 'closed') $eng = 100; elseif ($pr > $phaseRank['design'] && $o['status'] !== 'cancelled') $eng = max($eng, 70);
    $auto['engineering'] = $eng;
    $subs = array_values(array_filter($o['subs'] ?? [], fn($x) => $x['status'] !== 'cancelled'));
    $auto['procurement'] = $subs ? (int) round(array_sum(array_map(fn($x) => (int)$x['pct'], $subs)) / count($subs)) : null;
    $auto['production'] = pd_sub_pct_of_order($o);
    $auto['fat'] = pd_docs_cover($o, 'fat') ? 100 : pd_unit_share($o['units'], 'tested');
    $auto['installation'] = pd_unit_share($o['units'], 'installed');
    $sh = pd_unit_share($o['units'], 'commissioned'); $auto['sathat'] = pd_docs_cover($o, 'sat') || pd_docs_cover($o, 'hat') ? max(100, (int)$sh) : $sh;
    $ac = pd_unit_share($o['units'], 'accepted'); $auto['commissioning'] = pd_docs_cover($o, 'commissioning') ? 100 : $ac;
    if ($o['status'] === 'closed') foreach (['fat', 'installation', 'sathat', 'commissioning'] as $k) if ($auto[$k] !== null) $auto[$k] = 100;
    $out = [];
    foreach (PD_STAGE_SLUGS as $k) { $ov = $k !== 'production' && pd_stages_has() && isset($o['st_' . $k]) && $o['st_' . $k] !== null ? (int)$o['st_' . $k] : null; $out[$k] = ['auto' => $auto[$k], 'override' => $ov, 'value' => $ov ?? $auto[$k]]; }
    return $out;
}
function pd_sub_pct_of_order(array $o): ?int { $live = array_filter($o['units'], fn($u) => $u['status'] !== 'cancelled'); return $live ? (int) round(array_sum(array_map(fn($u) => (int)$u['pct'], $live)) / count($live)) : null; }
/** The sub-project's stages from all its live orders, weighted by number of units (an order without units counts as one). null = no evidence → the PM's value stands. */
function pd_sub_stages(int $sid): array {
    static $c = []; if (isset($c[$sid])) return $c[$sid];
    $out = []; foreach (PD_STAGE_SLUGS as $k) $out[$k] = ['value' => null, 'orders' => []];
    if (!$sid) return $c[$sid] = $out;
    $ids = pd_has() ? array_map('intval', array_column(all("SELECT id FROM production_orders WHERE subproject_id = ? AND status <> 'cancelled'", [$sid]), 'id')) : [];
    $sum = array_fill_keys(PD_STAGE_SLUGS, 0.0); $w = array_fill_keys(PD_STAGE_SLUGS, 0);
    foreach ($ids as $oid) { $o = pd_get($oid); if (!$o) continue; $st = pd_order_stages($o); $n = max(1, count(array_filter($o['units'], fn($u) => $u['status'] !== 'cancelled')));
        foreach ($st as $k => $v) { if ($v['value'] === null) continue; $sum[$k] += $v['value'] * $n; $w[$k] += $n; $out[$k]['orders'][] = ['id' => $oid, 'po_no' => $o['po_no'], 'value' => (int)$v['value'], 'override' => $v['override'] !== null]; } }
    foreach (PD_STAGE_SLUGS as $k) if ($w[$k] > 0) $out[$k]['value'] = (int) round($sum[$k] / $w[$k]);
    if (function_exists('eng_has') && eng_has()) foreach (eng_stage_pcts($sid) as $st => $ep) if (isset($out[$st])) $out[$st] = ['value' => $ep, 'orders' => $out[$st]['orders'], 'eng' => true];   // R47-001 / R48-001: earned value of the hour-budgeted sections replaces the order evidence for that stage
    return $c[$sid] = $out;
}
/** Stages the PM has unlocked on a sub-project (his value stands). */
function pd_sub_manual(int $sid): array { try { return pd_stages_has() ? array_values(array_filter(explode(',', (string) scalar('SELECT stage_manual FROM subprojects WHERE id = ?', [$sid])))) : []; } catch (Throwable $e) { return []; } }
/** Which stages are fed right now (evidence present and not unlocked) — for the read-only fields and the 🏭 marks. */
function pd_sub_fed(int $sid): array {
    static $with = null; if ($with === null) { $with = []; if (pd_has()) foreach (all("SELECT DISTINCT subproject_id FROM production_orders WHERE subproject_id IS NOT NULL AND status <> 'cancelled'") as $r) $with[(int)$r['subproject_id']] = true; }
    $engAny = function_exists('eng_has') && eng_has() ? eng_stage_pcts($sid) : [];   // R47-001 / R48-001: budgeted sections feed their stages even without production orders
    if (empty($with[$sid]) && !$engAny) return [];
    $man = pd_sub_manual($sid); $fed = []; foreach (pd_sub_stages($sid) as $k => $v) if ($v['value'] !== null && !in_array($k, $man, true)) $fed[] = $k;
    return $fed;
}
/** Write the fed stages into the sub-project (the unlocked ones and the ones without evidence keep the PM's value). */
function pd_feed_subproject(int $sid): void {
    if (!$sid) return; $fed = pd_sub_fed($sid); if (!$fed) return;
    $st = pd_sub_stages($sid); $defs = stage_defs(); $sets = []; $vals = [];
    foreach ($fed as $k) { $sets[] = $defs[$k]['col'] . ' = ?'; $vals[] = max(0, min(100, (int)$st[$k]['value'])); }
    $vals[] = $sid; try { q('UPDATE subprojects SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals); } catch (Throwable $e) {}
}
function pd_feed_order(int $oid): void { $sid = (int) scalar('SELECT subproject_id FROM production_orders WHERE id = ?', [$oid]); if ($sid) pd_feed_subproject($sid); }
/** Per-order overrides of the automatic stage values (blank = automatic). action pd_stages */
function pd_stages_save_from_post(int $oid): void {
    $o = pd_get($oid); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess')); if (!pd_stages_has()) throw new RuntimeException(t('pd.need_db'));
    $sets = []; $vals = []; $log = [];
    foreach (PD_STAGE_SLUGS as $k) { if ($k === 'production') continue; $in = trim((string)($_POST['st'][$k] ?? '')); $v = $in === '' ? null : max(0, min(100, (int)$in)); $sets[] = 'st_' . $k . ' = ?'; $vals[] = $v; if ($v !== (isset($o['st_' . $k]) ? (int)$o['st_' . $k] : null)) $log[] = t('st.' . $k) . ' ' . ($v === null ? t('pd.st_auto') : $v . ' %'); }
    $vals[] = $oid; q('UPDATE production_orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    if ($log) pd_log($oid, 'stage_override', implode(' · ', $log));
    pd_feed_order($oid);
}
/** The PM unlocks (or locks back) a fed stage on the sub-project. action sp_stage_lock */
function pd_stage_lock(int $sid, string $slug, bool $unlock): void {
    if (!in_array($slug, PD_STAGE_SLUGS, true) || !pd_stages_has()) throw new RuntimeException(t('err.notfound'));
    $man = pd_sub_manual($sid); $man = $unlock ? array_values(array_unique(array_merge($man, [$slug]))) : array_values(array_diff($man, [$slug]));
    q('UPDATE subprojects SET stage_manual = ? WHERE id = ?', [$man ? implode(',', $man) : null, $sid]);
    $oid = (int) scalar("SELECT id FROM production_orders WHERE subproject_id = ? AND status <> 'cancelled' ORDER BY id LIMIT 1", [$sid]);
    if ($oid) pd_log($oid, $unlock ? 'stage_unlock' : 'stage_lock', t('st.' . $slug));
    if (!$unlock) { // back to the feed: write the fed value at once
        $st = pd_sub_stages($sid); if ($st[$slug]['value'] !== null) q('UPDATE subprojects SET ' . stage_defs()[$slug]['col'] . ' = ? WHERE id = ?', [(int)$st[$slug]['value'], $sid]);
    }
}
/** The orders of a sub-project that feed its Production stage (for the hint on the Process tab). */
function pd_feed_orders(int $sid): array { return pd_has() && is_staff() ? all("SELECT o.id, o.po_no, o.pct, o.status FROM production_orders o WHERE o.subproject_id = ? AND o.status <> 'cancelled' AND EXISTS (SELECT 1 FROM production_units u WHERE u.order_id = o.id AND u.status <> 'cancelled') ORDER BY o.id", [$sid]) : []; }

/* ------------------------------------------------------------------ subcontracting (R41-003) */
function ps_has(): bool { static $h = null; if ($h === null) { try { $h = pd_has() && (bool) scalar("SHOW TABLES LIKE 'production_subs'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ps_status_pill(string $st): string { return pill(PS_TONE[$st] ?? 'mute', t('ps.s_' . $st)); }
function ps_money(?float $v, string $cur = 'EUR'): string { if ($v === null) return '—'; if (!fin_visible()) return fin_masked(); return number_format($v, 2, ',', '.') . ' ' . $cur; }
const PS_SELECT = "SELECT ps.*, o.po_no AS order_no, o.title AS order_title, o.status AS order_status, o.project_id, o.subproject_id, o.vessel_id, o.manager_user_id, p.project_code, p.pm_user_id, v.name AS vessel, e.serial_no, e.model, cu.full_name AS manager,
                   (SELECT COUNT(*) FROM production_files f WHERE f.order_id = ps.order_id AND f.phase = 'subcontract' AND (f.unit_id IS NULL OR f.unit_id = ps.unit_id)) AS n_files
            FROM production_subs ps JOIN production_orders o ON o.id = ps.order_id JOIN projects p ON p.id = o.project_id LEFT JOIN vessels v ON v.id = o.vessel_id
            LEFT JOIN production_units u ON u.id = ps.unit_id LEFT JOIN equipment e ON e.id = u.equipment_id LEFT JOIN customer_users cu ON cu.id = o.manager_user_id";
/** The subcontract lines of one order. */
function ps_of_order(int $oid): array { return ps_has() ? all(PS_SELECT . ' WHERE ps.order_id = ? ORDER BY ps.id', [$oid]) : []; }
/** Every subcontract line (Production › Subcontracting). $f: status (open|closed|<status>), supplier, pm, vessel, q */
function ps_list(array $f = []): array {
    if (!ps_has() || !is_staff()) return [];
    $w = ['1 = 1']; $a = []; $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $w[] = "ps.status NOT IN ('accepted','cancelled')"; elseif ($st === 'closed') $w[] = "ps.status IN ('accepted','cancelled')"; elseif (in_array($st, PS_STATUSES, true)) { $w[] = 'ps.status = ?'; $a[] = $st; }
    if (!empty($f['supplier'])) { $w[] = 'ps.supplier = ?'; $a[] = (string)$f['supplier']; }
    if (!empty($f['pm'])) { $w[] = 'p.pm_user_id = ?'; $a[] = (int)$f['pm']; }
    if (!empty($f['vessel'])) { $w[] = 'o.vessel_id = ?'; $a[] = (int)$f['vessel']; }
    if (!empty($f['q'])) { $w[] = '(ps.scope LIKE ? OR ps.supplier LIKE ? OR ps.po_no LIKE ? OR o.po_no LIKE ? OR e.serial_no LIKE ?)'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l, $l); }
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    return all(PS_SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(ps.status, 'rejected','delivered','inspected','in_production','ordered','planned','accepted','cancelled'), ps.due_at IS NULL, ps.due_at, ps.id", $a);
}
function ps_suppliers(): array { return ps_has() ? array_column(all('SELECT DISTINCT supplier FROM production_subs ORDER BY supplier'), 'supplier') : []; }
function ps_counts(): array { $c = []; if (ps_has()) foreach (all('SELECT status, COUNT(*) n FROM production_subs GROUP BY status') as $r) $c[$r['status']] = (int)$r['n']; return $c; }
/** Create / update a subcontract line from the order page. */
function ps_save_from_post(): int {
    if (!ps_has()) throw new RuntimeException(t('ps.need_db'));
    $oid = (int)($_POST['id'] ?? 0); $o = pd_get($oid); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    $psId = (int)($_POST['ps_id'] ?? 0); $old = $psId ? one('SELECT * FROM production_subs WHERE id = ? AND order_id = ?', [$psId, $oid]) : null; if ($psId && !$old) throw new RuntimeException(t('err.notfound'));
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $unitId = (int)($_POST['unit_id'] ?? 0) ?: null; if ($unitId && !scalar('SELECT COUNT(*) FROM production_units WHERE id = ? AND order_id = ?', [$unitId, $oid])) $unitId = null;
    $whole = $unitId && !empty($_POST['whole']) ? 1 : 0;
    $supplier = $s('supplier', 120); $coId = (int)($_POST['company_id'] ?? 0) ?: null;
    if ($coId && function_exists('co_has') && co_has()) { $co = one('SELECT id, name FROM companies WHERE id = ?', [$coId]); if ($co) { if ($supplier === '') $supplier = (string)$co['name']; } else $coId = null; }
    if ($supplier === '') throw new RuntimeException(t('ps.need_supplier'));
    $scope = $s('scope', 200) ?: ($whole ? t('ps.whole_equipment') : ''); if ($scope === '') throw new RuntimeException(t('ps.need_scope'));
    $st = in_array($_POST['status'] ?? '', PS_STATUSES, true) ? $_POST['status'] : ($old['status'] ?? 'planned');
    $pctIn = trim((string)($_POST['pct'] ?? '')); $pct = $pctIn === '' ? max((int)($old['pct'] ?? 0) * ($st === ($old['status'] ?? '') ? 1 : 0), PS_PCT[$st]) : max(0, min(100, (int)$pctIn)); if ($st === 'accepted') $pct = 100;
    $amount = fin_visible() ? (trim((string)($_POST['amount'] ?? '')) === '' ? null : (function_exists('of_parse_amount') ? of_parse_amount((string)$_POST['amount']) : (float) str_replace(',', '.', (string)$_POST['amount']))) : ($old['amount'] ?? null);
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? ($old['currency'] ?? 'EUR'))), 0, 3)) ?: 'EUR';
    $ordered = $d('ordered_at') ?? ($st !== 'planned' && !$old ? date('Y-m-d') : ($old['ordered_at'] ?? null)); $due = $d('due_at'); $deliv = $d('delivered_at') ?? (in_array($st, ['delivered', 'inspected', 'accepted'], true) ? ($old['delivered_at'] ?? date('Y-m-d')) : null);
    $insp = $s('inspection_note', 500) ?: null; $note = $s('note', 500) ?: null;
    $vals = [$unitId, $whole, $scope, $supplier, $coId, $s('po_no', 60) ?: null, $ordered, $due, $deliv, $amount, $cur, $st, $pct, $insp, $note];
    if ($old) { q('UPDATE production_subs SET unit_id=?, whole=?, scope=?, supplier=?, company_id=?, po_no=?, ordered_at=?, due_at=?, delivered_at=?, amount=?, currency=?, status=?, pct=?, inspection_note=?, note=? WHERE id=?', [...$vals, $psId]); }
    else { q('INSERT INTO production_subs (order_id, unit_id, whole, scope, supplier, company_id, po_no, ordered_at, due_at, delivered_at, amount, currency, status, pct, inspection_note, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$oid, ...$vals, (int)(current_user()['id'] ?? 0)]); $psId = (int) db()->lastInsertId(); }
    $what = $supplier . ' · ' . $scope . ' · ' . t('ps.s_' . $st) . ' · ' . $pct . ' %';
    pd_log($oid, $old ? 'sub_update' : 'sub_added', $what, $unitId);
    if ($whole && $unitId) ps_feed_unit($psId); else pd_feed_order($oid);
    if ($st !== ($old['status'] ?? '') && in_array($st, ['delivered', 'rejected', 'accepted'], true)) pd_notify($o, 'sub', t('ps.nf_' . $st, ['s' => $supplier, 'w' => $scope]), $insp);
    return $psId;
}
/** A whole-equipment subcontract drives the production card of its unit: % follows the line, status in production until accepted (then tested). */
function ps_feed_unit(int $psId): void {
    $ps = one('SELECT * FROM production_subs WHERE id = ?', [$psId]); if (!$ps || !$ps['unit_id'] || !$ps['whole']) return;
    $un = one('SELECT * FROM production_units WHERE id = ?', [(int)$ps['unit_id']]); if (!$un || in_array($un['status'], ['delivered', 'installed', 'commissioned', 'accepted', 'cancelled'], true)) return;
    $ust = $ps['status'] === 'accepted' ? 'tested' : (in_array($ps['status'], ['planned', 'cancelled'], true) ? $un['status'] : 'in_production');
    q('UPDATE production_units SET status = ?, pct = GREATEST(pct, ?), note = COALESCE(note, ?), updated_by = ? WHERE id = ?', [$ust, (int)$ps['pct'], t('ps.unit_note', ['s' => (string)$ps['supplier']]), (int)(current_user()['id'] ?? 0), (int)$un['id']]);
    pd_recalc((int)$ps['order_id']);
}
function ps_delete(int $psId): int {
    $ps = one('SELECT * FROM production_subs WHERE id = ?', [$psId]); if (!$ps) throw new RuntimeException(t('err.notfound'));
    $o = pd_get((int)$ps['order_id']); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM production_subs WHERE id = ?', [$psId]); pd_log((int)$o['id'], 'sub_removed', $ps['supplier'] . ' · ' . $ps['scope']); pd_feed_order((int)$o['id']);
    return (int)$o['id'];
}
/** Subcontracting report (PDF): the open lines grouped by supplier — what is out, where, when due, what came back and was inspected. */
function ps_doc_no(): string { return br_prefix(null) . '-SC-' . date('Ymd'); }
function ps_render_pdf(array $rows, string $label): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $docNo = ps_doc_no(); $bd = br_doc(null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $logo = $bd['logo']; $fin = fin_visible();
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $docNo, $label, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('ps.report_title')));
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $docNo . ' · ' . $label . ' · ' . date('d.m.Y'));
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y = 31.0;
    };
    $footer = function () use ($pdf, $ink, $L, $W, $docNo, $bd) { $pdf->setColor($ink); $pdf->setFont('R', 7); $pdf->text($L, 291, $bd['footer'] . ' · ' . $docNo . ' · ' . date('d.m.Y H:i')); $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W); };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols); $lh = 3.6; $pdf->setFont('B', 7.5); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap($lab, $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 2.0; $x = $L; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F');
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.4 + $lh * ($k + 1) - 0.8, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh; };
    $drow = function (array $cols, array $vals) use ($pdf, $ink, $line, $L, &$y, $hrow, $newPage) { $cols = rc_fit_cols($cols); $lh = 3.9; $pdf->setFont('R', 7.8); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($vals[$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 1.6; if ($y + $hh > 276) { $newPage(); $hrow($cols); $pdf->setFont('R', 7.8); }
        $x = $L; foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.2 + $lh * ($k + 1) - 0.9, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh; };
    $h2 = function (string $t) use ($pdf, $navy, $gold, $L, &$y, $newPage) { if ($y > 262) $newPage(); $y += 3; $pdf->setColor($navy); $pdf->setFont('B', 10); $pdf->text($L, $y + 3.5, $t); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 5.2, $L + 60, $y + 5.2); $y += 9; };
    $newPage();
    $by = []; foreach ($rows as $r) $by[$r['supplier']][] = $r;
    $cols = [[t('pd.no'), 24, 'L'], [t('ps.scope'), 44, 'L'], [t('eq.sn'), 24, 'L'], [t('ps.po_no'), 22, 'L'], [t('ps.due'), 18, 'L'], [t('th.status'), 20, 'L'], ['%', 10, 'R'], [$fin ? t('ps.amount') : t('ps.inspection'), 18, $fin ? 'R' : 'L']];
    $tot = 0.0;
    foreach ($by as $sup => $rs) { $h2($sup . ' (' . count($rs) . ')'); $hrow($cols);
        foreach ($rs as $r) { $tot += (float)($r['amount'] ?? 0); $drow($cols, [$r['order_no'], $r['scope'] . ($r['whole'] ? ' · ' . t('ps.whole') : ''), (string)($r['serial_no'] ?: t('pd.all_units')), (string)($r['po_no'] ?? ''), $r['due_at'] ? fmt_date($r['due_at']) : '—', t('ps.s_' . $r['status']), (string)(int)$r['pct'], $fin ? ps_money($r['amount'] !== null ? (float)$r['amount'] : null, $r['currency']) : (string)($r['inspection_note'] ?: '')]); } }
    if (!$rows) { $hrow($cols); $drow($cols, [t('ps.none'), '', '', '', '', '', '', '']); }
    if ($fin && $rows) { $y += 2; $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 3, t('ps.total') . ': ' . ps_money($tot)); $y += 6; }
    return $pdf->bytes($docNo);
}
function ps_pdf_download(array $f): void {
    require_staff(); $rows = ps_list($f);
    $bytes = ps_render_pdf($rows, t('ps.f_' . (in_array($f['status'] ?? 'open', ['open', 'closed'], true) ? $f['status'] : 'all')));
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . ps_doc_no() . '.pdf"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}
function pd_log(int $oid, string $event, ?string $note = null, ?int $unitId = null): void {
    $u = current_user();
    q('INSERT INTO production_log (order_id, unit_id, event, note, user_id, user_name) VALUES (?,?,?,?,?,?)', [$oid, $unitId, $event, $note !== null ? mb_substr($note, 0, 500) : null, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
}
function pd_notify(array $o, string $kind, string $title, ?string $body, array $extra = []): void {
    if (!function_exists('nf_push')) return;
    $me = (int)(current_user()['id'] ?? 0);
    $ids = array_filter(array_unique(array_merge([(int)($o['pm_user_id'] ?? 0), (int)($o['manager_user_id'] ?? 0), (int)($o['created_by'] ?? 0)], $extra)), fn($i) => $i && $i !== $me);
    if ($ids) nf_push(array_values($ids), 'pd:' . $kind, 'pd:' . $o['id'], $o['po_no'] . ' · ' . $title, $body, url('production_order', ['id' => (int)$o['id']]), $me ?: null, current_user()['full_name'] ?? null);
}

/* ------------------------------------------------------------------ writing */
/** Create / update an order from the form (production_edit). */
function pd_save_from_post(): int {
    require_staff(); if (!pd_has()) throw new RuntimeException(t('pd.need_db'));
    $u = current_user(); $id = (int)($_POST['id'] ?? 0);
    $old = $id ? pd_get($id) : null; if ($id && !$old) throw new RuntimeException(t('err.notfound'));
    if ($old && !pd_can_edit($old)) throw new RuntimeException(t('err.noaccess'));
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $wid = (int)($_POST['work_id'] ?? 0) ?: null; $w = $wid && function_exists('wp_has') && wp_has() ? one('SELECT * FROM sp_works WHERE id = ?', [$wid]) : null; if ($wid && !$w) $wid = null;
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: ($w ? (int)$w['subproject_id'] : null);
    $sp = $sid ? one('SELECT * FROM subprojects WHERE id = ?', [$sid]) : null; if ($sid && !$sp) $sid = null;
    $pid = $sp ? (int)$sp['project_id'] : (int)($_POST['project_id'] ?? 0);
    $p = $pid ? one('SELECT * FROM projects WHERE id = ?', [$pid]) : null; if (!$p) throw new RuntimeException(t('pd.need_project'));
    if (!$old && !pd_can_create($pid)) throw new RuntimeException(t('err.noaccess'));
    $vid = (int)($p['vessel_id'] ?? 0) ?: ((int)($_POST['vessel_id'] ?? 0) ?: null);   // R41-004: the vessel follows the project; free choice only for a project without a vessel
    if ($vid && !scalar('SELECT COUNT(*) FROM vessels WHERE id = ?', [$vid])) $vid = null;
    $sys = (int)($_POST['system_id'] ?? 0) ?: null; if ($sys && !scalar('SELECT COUNT(*) FROM vessel_systems WHERE id = ?', [$sys])) $sys = null;
    $title = $s('title', 160) ?: ($w ? (string)$w['title'] : ''); if ($title === '') throw new RuntimeException(t('pd.need_title'));
    $desc = $s('description', 4000) ?: null;
    $mgr = (int)($_POST['manager_user_id'] ?? 0) ?: null; if ($mgr && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$mgr])) $mgr = null;
    $ps = $d('planned_start') ?: ($w['start_date'] ?? null); $pe = $d('planned_end') ?: ($w['end_date'] ?? null); if ($ps && $pe && $pe < $ps) $pe = $ps;
    if ($old) {
        q('UPDATE production_orders SET subproject_id=?, work_id=?, vessel_id=?, system_id=?, title=?, description=?, manager_user_id=?, planned_start=?, planned_end=? WHERE id=?', [$sid, $wid, $vid, $sys, $title, $desc, $mgr, $ps, $pe, $id]);
        if (!$old['units']) { $pct = max(0, min(100, (int)($_POST['pct'] ?? $old['pct']))); q('UPDATE production_orders SET pct = ? WHERE id = ?', [$pct, $id]); }
        pd_log($id, 'edited');
        if ($mgr && $mgr !== (int)$old['manager_user_id']) pd_notify($old + ['manager_user_id' => $mgr], 'assigned', $title, t('pd.nf_assigned_body'), [$mgr]);
    } else {
        $no = pd_next_no($pid, $sid);
        q('INSERT INTO production_orders (po_no, project_id, subproject_id, work_id, vessel_id, system_id, title, description, manager_user_id, status, pct, planned_start, planned_end, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$no, $pid, $sid, $wid, $vid, $sys, $title, $desc, $mgr, 'design', 0, $ps, $pe, (int)$u['id']]);
        $id = (int) db()->lastInsertId();
        pd_log($id, 'created', $w ? t('pd.from_work', ['no' => (string)$w['wp_no']]) : null);
        $o = pd_get($id); if ($o) pd_notify($o, 'created', $title, $o['vessel'] ? t('pd.nf_created_body', ['v' => (string)$o['vessel']]) : null);
    }
    pd_units_add_from_post($id);
    return $id;
}
/** Add units to an order: existing equipment ids (eq_ids[]) and/or new serial numbers (one per line) created in the Equipment register. */
function pd_units_add_from_post(int $oid): int {
    $o = pd_get($oid); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    if (!function_exists('eq_has') || !eq_has()) return 0;
    $n = 0; $u = current_user();
    foreach (array_unique(array_map('intval', (array)($_POST['eq_ids'] ?? []))) as $eid) {
        if (!$eid || !scalar('SELECT COUNT(*) FROM equipment WHERE id = ?', [$eid])) continue;
        if (scalar('SELECT COUNT(*) FROM production_units WHERE order_id = ? AND equipment_id = ?', [$oid, $eid])) continue;
        q('INSERT INTO production_units (order_id, equipment_id, status, pct, updated_by) VALUES (?,?,?,?,?)', [$oid, $eid, 'pending', 0, (int)$u['id']]); $n++;
        pd_log($oid, 'unit_added', (string) scalar('SELECT serial_no FROM equipment WHERE id = ?', [$eid]), (int) db()->lastInsertId());
    }
    $serials = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($_POST['new_serials'] ?? '')) ?: [])));
    if ($serials) {
        $maker = mb_substr(trim((string)($_POST['new_maker'] ?? '')), 0, 60) ?: 'Golden A/S'; $model = mb_substr(trim((string)($_POST['new_model'] ?? '')), 0, 120) ?: null;
        $kind = in_array($_POST['new_kind'] ?? '', EQ_KINDS, true) ? $_POST['new_kind'] : 'other'; $set = mb_substr(trim((string)($_POST['new_set'] ?? '')), 0, 80) ?: null;
        foreach (array_unique($serials) as $sn) {
            $sn = mb_substr($sn, 0, 60);
            $eid = (int) scalar('SELECT id FROM equipment WHERE maker = ? AND serial_no = ?', [$maker, $sn]);
            if (!$eid) {
                q('INSERT INTO equipment (serial_no, set_code, maker, family, model, kind, vessel_id, sold_by_us, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [$sn, $set, $maker, $o['system_abbr'] ?: null, $model, $kind, $o['vessel_id'] ?: null, 1, t('pd.eq_note', ['no' => $o['po_no']]), (int)$u['id']]);
                $eid = (int) db()->lastInsertId();
            }
            if (scalar('SELECT COUNT(*) FROM production_units WHERE order_id = ? AND equipment_id = ?', [$oid, $eid])) continue;
            q('INSERT INTO production_units (order_id, equipment_id, status, pct, updated_by) VALUES (?,?,?,?,?)', [$oid, $eid, 'pending', 0, (int)$u['id']]); $n++;
            pd_log($oid, 'unit_added', $sn, (int) db()->lastInsertId());
        }
    }
    if ($n) pd_recalc($oid);
    return $n;
}
/** The production manager sets the status and the % finished of one unit (its production card). */
function pd_unit_update(int $unitId): void {
    $un = one('SELECT u.*, e.serial_no FROM production_units u JOIN equipment e ON e.id = u.equipment_id WHERE u.id = ?', [$unitId]); if (!$un) throw new RuntimeException(t('err.notfound'));
    $o = pd_get((int)$un['order_id']); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    $st = in_array($_POST['status'] ?? '', PD_USTATUSES, true) ? $_POST['status'] : $un['status'];
    $pctIn = trim((string)($_POST['pct'] ?? ''));
    $pct = $pctIn === '' ? ($st !== $un['status'] ? max((int)$un['pct'], PD_UPCT[$st]) : (int)$un['pct']) : max(0, min(100, (int)$pctIn));
    if ($st === 'accepted') $pct = 100; if ($st === 'cancelled') $pct = 0;
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null;
    q('UPDATE production_units SET status = ?, pct = ?, note = ?, updated_by = ? WHERE id = ?', [$st, $pct, $note, (int)(current_user()['id'] ?? 0), $unitId]);
    pd_recalc((int)$o['id']);
    $what = $un['serial_no'] . ' · ' . t('pd.u_' . $st) . ' · ' . $pct . ' %' . ($note ? ' — ' . $note : '');
    pd_log((int)$o['id'], 'unit_update', $what, $unitId);
    if ($st !== $un['status'] && in_array($st, ['tested', 'delivered', 'accepted'], true)) pd_notify($o, 'unit', $what, null);
}
function pd_unit_remove(int $unitId): void {
    $un = one('SELECT u.*, e.serial_no FROM production_units u JOIN equipment e ON e.id = u.equipment_id WHERE u.id = ?', [$unitId]); if (!$un) throw new RuntimeException(t('err.notfound'));
    $o = pd_get((int)$un['order_id']); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    q('UPDATE production_files SET unit_id = NULL WHERE unit_id = ?', [$unitId]); q('DELETE FROM production_units WHERE id = ?', [$unitId]);
    pd_recalc((int)$o['id']); pd_log((int)$o['id'], 'unit_removed', $un['serial_no']);
}
/** Move the order to another phase (status); delivered / closed stamp their dates and the units follow where it makes sense. */
function pd_set_status(int $id, string $st, string $note = ''): void {
    $o = pd_get($id); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    if (!in_array($st, PD_STATUSES, true) || $st === $o['status']) return;
    q('UPDATE production_orders SET status = ?, delivered_at = CASE WHEN ? = "delivered" AND delivered_at IS NULL THEN CURDATE() ELSE delivered_at END, closed_at = CASE WHEN ? IN ("closed","cancelled") THEN NOW() ELSE NULL END WHERE id = ?', [$st, $st, $st, $id]);
    $follow = ['delivered' => 'delivered', 'hat' => 'installed', 'commissioning' => 'commissioned', 'sat' => 'commissioned', 'closed' => 'accepted', 'cancelled' => 'cancelled'][$st] ?? null;
    if ($follow) { $rank = array_flip(PD_USTATUSES); foreach ($o['units'] as $un) if ($un['status'] !== 'cancelled' && ($rank[$un['status']] ?? 0) < $rank[$follow]) q('UPDATE production_units SET status = ?, pct = GREATEST(pct, ?) WHERE id = ?', [$follow, PD_UPCT[$follow], (int)$un['id']]); }
    if ($st === 'closed') q("UPDATE production_units SET pct = 100 WHERE order_id = ? AND status <> 'cancelled'", [$id]);
    pd_recalc($id);
    pd_log($id, 'status', pd_status_label($st) . ($note !== '' ? ' — ' . mb_substr($note, 0, 400) : ''));
    pd_notify($o, 'status', pd_status_label($st), $note !== '' ? $note : null);
    if ($st === 'delivered' && function_exists('eq_has') && eq_has() && $o['vessel_id']) foreach ($o['units'] as $un) q('UPDATE equipment SET vessel_id = COALESCE(vessel_id, ?), date_sold = COALESCE(date_sold, CURDATE()) WHERE id = ?', [(int)$o['vessel_id'], (int)$un['equipment_id']]);
}
/** Attach a file to a phase of the order (optionally to one unit): a drawing (draft / approved / as-built), a FAT / HAT / SAT record, a photo, a delivery note, a commissioning report. */
function pd_file_add(int $id): int {
    $o = pd_get($id); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    $phase = isset(PD_PHASES[$_POST['phase'] ?? '']) ? $_POST['phase'] : 'other'; [$kind, $visDef] = PD_PHASES[$phase];
    if (function_exists('fk_valid') && !fk_valid($kind)) $kind = 'other';
    $vis = in_array($_POST['visibility'] ?? '', ['internal', 'external'], true) ? $_POST['visibility'] : $visDef;
    $unitId = (int)($_POST['unit_id'] ?? 0) ?: null; if ($unitId && !scalar('SELECT COUNT(*) FROM production_units WHERE id = ? AND order_id = ?', [$unitId, $id])) $unitId = null;
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    $docNo = mb_substr(trim((string)($_POST['doc_no'] ?? '')), 0, 80) ?: null; $rev = mb_substr(trim((string)($_POST['revision'] ?? '')), 0, 16) ?: null;
    if (!$rev && in_array($phase, ['draft', 'approved', 'asbuilt'], true)) $rev = ['draft' => 'D1', 'approved' => 'R0', 'asbuilt' => 'AB'][$phase];
    $files = $_FILES['files'] ?? null; if (!$files || !is_array($files['name'])) throw new RuntimeException(t('pd.need_file'));
    $n = 0;
    foreach ($files['name'] as $i => $nm) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $one = ['name' => $nm, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        $title = $note ?: ($o['po_no'] . ' · ' . pd_phase_label($phase));
        $fid = cp_store_file($one, (int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null, $kind, $vis, $title, null, null, ['doc_no' => $docNo, 'revision' => $rev, 'label' => $title]);
        if (!$fid) continue;
        if ($docNo || $rev) q('UPDATE sp_files SET doc_no = COALESCE(?, doc_no), revision = COALESCE(?, revision) WHERE id = ?', [$docNo, $rev, $fid]);
        q('INSERT INTO production_files (order_id, unit_id, file_id, phase, note, created_by) VALUES (?,?,?,?,?,?)', [$id, $unitId, $fid, $phase, $note, (int)(current_user()['id'] ?? 0)]); $n++;
    }
    if (!$n) throw new RuntimeException(t('pd.need_file'));
    pd_log($id, 'file', pd_phase_label($phase) . ' × ' . $n . ($docNo ? ' · ' . $docNo : '') . ($rev ? ' ' . $rev : ''), $unitId);
    pd_feed_order($id);
    if (in_array($phase, ['approved', 'asbuilt', 'fat', 'hat', 'sat', 'commissioning'], true)) pd_notify($o, 'file', pd_phase_label($phase) . ($docNo ? ' · ' . $docNo . ($rev ? ' ' . $rev : '') : ''), $note);
    return $n;
}
/** Link a drawing already on the sub-project's Files tab to a phase (no upload). */
function pd_file_link(int $id): void {
    $o = pd_get($id); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    $phase = isset(PD_PHASES[$_POST['phase'] ?? '']) ? $_POST['phase'] : 'other';
    $fid = (int)($_POST['file_id'] ?? 0); $f = $fid ? one('SELECT id FROM sp_files WHERE id = ? AND project_id = ?', [$fid, (int)$o['project_id']]) : null; if (!$f) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM production_files WHERE order_id = ? AND file_id = ?', [$id, $fid])) return;
    q('INSERT INTO production_files (order_id, unit_id, file_id, phase, note, created_by) VALUES (?,?,?,?,?,?)', [$id, null, $fid, $phase, null, (int)(current_user()['id'] ?? 0)]);
    pd_log($id, 'file', pd_phase_label($phase) . ' · ' . t('pd.linked')); pd_feed_order($id);
}
function pd_file_remove(int $pfId): int {
    $pf = one('SELECT * FROM production_files WHERE id = ?', [$pfId]); if (!$pf) throw new RuntimeException(t('err.notfound'));
    $o = pd_get((int)$pf['order_id']); if (!$o || !pd_can_edit($o)) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM production_files WHERE id = ?', [$pfId]); pd_log((int)$o['id'], 'file_removed', pd_phase_label($pf['phase'])); pd_feed_order((int)$o['id']);
    return (int)$o['id'];
}
function pd_delete(int $id): void {
    require_cp_admin(); $o = pd_get($id); if (!$o) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM production_files WHERE order_id = ?', [$id]); q('DELETE FROM production_units WHERE order_id = ?', [$id]); q('DELETE FROM production_log WHERE order_id = ?', [$id]); q('DELETE FROM production_orders WHERE id = ?', [$id]);
}
/** Candidate drawings of the project to link (Files tab, kind drawing) not yet on the order. */
function pd_project_drawings(array $o): array {
    return all("SELECT f.id, f.original_name, f.title, f.doc_no, f.revision, f.created_at FROM sp_files f WHERE f.project_id = ? AND f.kind IN ('drawing','ga') AND f.id NOT IN (SELECT file_id FROM production_files WHERE order_id = ?) ORDER BY f.doc_no, f.revision, f.id DESC LIMIT 200", [(int)$o['project_id'], (int)$o['id']]);
}
/** Equipment on the vessel (or unassigned, sold by us) that is not on any open order — to add as units. */
function pd_candidate_units(array $o): array {
    if (!function_exists('eq_has') || !eq_has()) return [];
    return all("SELECT e.id, e.serial_no, e.maker, e.model, e.kind FROM equipment e WHERE e.retired = 0 AND (e.vessel_id = ? OR (e.vessel_id IS NULL AND e.sold_by_us = 1)) AND e.id NOT IN (SELECT equipment_id FROM production_units WHERE order_id = ?) ORDER BY e.serial_no LIMIT 300", [(int)($o['vessel_id'] ?? 0), (int)$o['id']]);
}
/** Files of an order grouped by phase, life-cycle order. */
function pd_files_by_phase(array $files): array { $g = []; foreach (array_keys(PD_PHASES) as $ph) $g[$ph] = []; foreach ($files as $f) $g[$f['phase']][] = $f; return $g; }
function pd_is_image(array $f): bool { return str_starts_with((string)$f['mime_type'], 'image/'); }
/** Milestone strip: which phases are reached (status order). */
function pd_milestones(array $o): array {
    $rank = array_flip(PD_OPEN); $cur = $rank[$o['status']] ?? ($o['status'] === 'closed' ? 99 : -1);
    $out = []; foreach (PD_OPEN as $i => $st) $out[$st] = $o['status'] === 'cancelled' ? 'off' : ($i < $cur ? 'done' : ($i === $cur ? 'now' : 'todo'));
    return $out;
}
/** Dashboard / Equipment KPI: counts of open orders by status. */
function pd_counts(): array {
    if (!pd_has() || !is_staff()) return [];
    $c = []; foreach (all("SELECT status, COUNT(*) n FROM production_orders GROUP BY status") as $r) $c[$r['status']] = (int)$r['n'];
    return $c;
}

/* ------------------------------------------------------------------ production card PDF */
function pd_doc_no(array $o): string { return $o['po_no'] . '-PC'; }
function pd_render_pdf(array $o): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $docNo = pd_doc_no($o); $bd = br_doc((int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $logo = $bd['logo'];
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $docNo, $o, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('pd.card')) . ' — ' . $o['po_no']);
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $docNo . ' · ' . rt_plain((string)$o['title']));
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y = 31.0;
    };
    $footer = function () use ($pdf, $ink, $L, $W, $docNo, $bd) {
        $pdf->setColor($ink); $pdf->setFont('R', 7);
        $pdf->text($L, 291, $bd['footer'] . ' · ' . $docNo . ' · ' . date('d.m.Y H:i'));
        $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W);
    };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols);
        $lh = 3.6; $pdf->setFont('B', 7.5); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap($lab, $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 2.0; $x = $L; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F');
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy);
            foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.4 + $lh * ($k + 1) - 0.8, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh;
    };
    $drow = function (array $cols, array $vals) use ($pdf, $ink, $line, $L, &$y, $hrow, $newPage) { $cols = rc_fit_cols($cols);
        $lh = 3.9; $pdf->setFont('R', 7.8); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($vals[$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 1.6;
        if ($y + $hh > 276) { $newPage(); $hrow($cols); $pdf->setFont('R', 7.8); }
        $x = $L;
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink);
            foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.2 + $lh * ($k + 1) - 0.9, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh;
    };
    $h2 = function (string $t) use ($pdf, $navy, $gold, $L, &$y, $newPage) { if ($y > 262) $newPage(); $y += 3; $pdf->setColor($navy); $pdf->setFont('B', 10); $pdf->text($L, $y + 3.5, $t); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 5.2, $L + 60, $y + 5.2); $y += 9; };
    $newPage();
    $kv = [[t('pd.no'), $o['po_no']], [t('pr.one'), trim(($o['project_code'] ?: '') . ' ' . $o['project_name']) . ($o['sub_code'] ? ' › ' . $o['sub_code'] . ' ' . $o['sub_name'] : '')], [t('v.one'), (string)($o['vessel'] ?: '—')],
           [t('pd.system'), (string)($o['system_name'] ?: '—')], [t('wp.work'), $o['wp_no'] ? $o['wp_no'] . ' ' . $o['work_title'] : '—'], [t('pd.manager'), (string)($o['manager'] ?: '—')], [t('wv.pm_says'), (string)($o['project_manager'] ?: '—')],
           [t('th.status'), pd_status_label($o['status']) . ' · ' . (int)$o['pct'] . ' %'], [t('pd.planned'), ($o['planned_start'] ? fmt_date($o['planned_start']) : '—') . ' – ' . ($o['planned_end'] ? fmt_date($o['planned_end']) : '—')], [t('pd.delivered_on'), $o['delivered_at'] ? fmt_date($o['delivered_at']) : '—'],
           [t('rp.issued'), date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? '')]];
    $cols = [[t('pd.field'), 50, 'L'], [t('pd.value'), 130, 'L']]; $hrow($cols); foreach ($kv as $r) $drow($cols, $r);
    if ($o['description']) { $y += 2; $pdf->setColor($ink); $pdf->setFont('R', 8.5); foreach ($pdf->wrap(rt_plain((string)$o['description']), $W) as $ln) { if ($y > 272) $newPage(); $pdf->text($L, $y + 3, $ln); $y += 4.2; } }
    $h2(t('pd.units') . ' (' . count($o['units']) . ')');
    $cols = [[t('eq.sn'), 36, 'L'], [t('eq.model'), 50, 'L'], [t('th.status'), 30, 'L'], ['%', 14, 'R'], [t('pd.unit_note'), 50, 'L']]; $hrow($cols);
    foreach ($o['units'] as $un) $drow($cols, [$un['serial_no'] . ($un['set_code'] ? ' (' . $un['set_code'] . ')' : ''), trim($un['maker'] . ' ' . ($un['model'] ?: '')), t('pd.u_' . $un['status']), (string)(int)$un['pct'], (string)($un['note'] ?: '')]);
    if (!$o['units']) $drow($cols, [t('pd.no_units'), '', '', '', '']);
    if ($o['subs']) { $h2(t('ps.title') . ' (' . count($o['subs']) . ')'); $fin = fin_visible();
        $cols = [[t('ps.supplier'), 36, 'L'], [t('ps.scope'), 50, 'L'], [t('eq.sn'), 26, 'L'], [t('ps.po_no'), 24, 'L'], [t('ps.due'), 20, 'L'], [t('th.status'), 24, 'L'], ['%', 10, 'R']]; $hrow($cols);
        foreach ($o['subs'] as $ps) $drow($cols, [$ps['supplier'], $ps['scope'] . ($ps['whole'] ? ' · ' . t('ps.whole') : '') . ($ps['inspection_note'] ? "\n" . t('ps.inspection') . ': ' . $ps['inspection_note'] : ''), (string)($ps['serial_no'] ?: t('pd.all_units')), (string)($ps['po_no'] ?: '—') . ($fin && $ps['amount'] !== null ? "\n" . ps_money((float)$ps['amount'], $ps['currency']) : ''), $ps['due_at'] ? fmt_date($ps['due_at']) : '—', t('ps.s_' . $ps['status']) . ($ps['delivered_at'] ? "\n" . fmt_date($ps['delivered_at']) : ''), (string)(int)$ps['pct']]); }
    $h2(t('pd.documents') . ' (' . count($o['files']) . ')');
    $cols = [[t('pd.phase'), 32, 'L'], [t('dr.file'), 62, 'L'], [t('pd.doc_no'), 30, 'L'], [t('eq.sn'), 26, 'L'], [t('th.date'), 30, 'L']]; $hrow($cols);
    foreach (pd_files_by_phase($o['files']) as $ph => $fs) foreach ($fs as $f) $drow($cols, [pd_phase_label($ph), (string)($f['note'] ?: $f['original_name']), trim((string)$f['doc_no'] . ' ' . (string)$f['revision']), (string)($f['serial_no'] ?: t('pd.all_units')), fmt_date(substr((string)$f['uploaded_at'], 0, 10))]);
    if (!$o['files']) $drow($cols, [t('pd.no_files'), '', '', '', '']);
    $h2(t('pd.log'));
    $cols = [[t('th.date'), 32, 'L'], [t('pd.event'), 30, 'L'], [t('th.by'), 36, 'L'], [t('pd.unit_note'), 82, 'L']]; $hrow($cols);
    foreach (array_slice($o['log'], 0, 60) as $l) $drow($cols, [fmt_datetime($l['created_at']), t('pd.ev_' . $l['event']), (string)$l['user_name'], (string)($l['note'] ?: '')]);
    return $pdf->bytes($docNo);
}
function pd_pdf_download(int $id): void {
    $o = pd_get($id); if (!$o) { http_response_code(404); exit; }
    $bytes = pd_render_pdf($o);
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . pd_doc_no($o) . '.pdf"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}
