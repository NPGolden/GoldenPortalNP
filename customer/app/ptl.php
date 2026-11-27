<?php
/**
 * GAS-CP-R97-291 — Company tools on projects. A unit of the register of company tools is sent from a warehouse to a project in the care of a supervisor
 * (state on_project). On the project the supervisor hands it out to a person and takes it back — normally the same day; every hand-out is a line of
 * inv_tool_daily. The warehouse books the unit back in when the project returns it. Reminders: tools not taken back at the end of the day, tools still
 * on a project that is completed or past the date they were expected back.
 */
const PTL_RESULTS = ['ok', 'damaged', 'missing'];
function ptl_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('eqp_has') && eqp_has() && (bool) one("SHOW COLUMNS FROM inv_serials LIKE 'site_user_id'") && (bool) scalar("SHOW TABLES LIKE 'inv_tool_daily'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ptl_ack_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) one("SHOW COLUMNS FROM inv_tool_daily LIKE 'ack_at'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ptl_me(): int { return (int)(current_user()['id'] ?? 0); }
/** Everything on every project: the warehouse and procurement, management. */
function ptl_all(): bool { return ptl_has() && is_staff() && (sn_can() || (function_exists('mgmt_can') && mgmt_can())); }
/** May hand out and take back on this project: the above, the supervisor of the unit, the project manager. */
function ptl_can_site(array $u): bool { if (!ptl_has() || !is_staff()) return false; $me = ptl_me(); return ptl_all() || ($me && ((int)($u['site_user_id'] ?? 0) === $me || (int)($u['pm_user_id'] ?? 0) === $me)); }
/** Does the signed-in person see the page at all? */
function ptl_can_view(): bool { if (!ptl_has() || !is_staff()) return false; if (ptl_all()) return true; static $v = null; if ($v === null) { $me = ptl_me(); try { $v = (bool) scalar("SELECT COUNT(*) FROM inv_serials s JOIN projects p ON p.id = s.project_id WHERE s.status = 'on_project' AND (s.site_user_id = ? OR p.pm_user_id = ?)", [$me, $me]); } catch (Throwable $e) { $v = false; } } return $v; }
function ptl_sql(): string {
    return "SELECT s.id, s.item_id, s.serial_no, s.asset_tag, s.status, s.project_id, s.site_user_id, s.site_since, s.site_due, s.site_wh_id, s.ism_eq_id, i.description, i.maker, i.factory_code, p.project_code, p.name AS project_name, p.status AS project_status, p.pm_user_id,
                   su.full_name AS site_user, d.id AS daily_id, d.person_id AS d_person_id, d.person_name AS d_person, d.given_at AS d_given_at, d.given_name AS d_given_by, d.given_by AS d_given_uid," . (ptl_ack_has() ? ' d.ack_at AS d_ack_at,' : ' NULL AS d_ack_at,') . "
                   (SELECT x.result FROM inv_tool_daily x WHERE x.serial_id = s.id AND x.project_id = s.project_id AND x.returned_at IS NOT NULL ORDER BY x.id DESC LIMIT 1) AS last_result
            FROM inv_serials s JOIN inv_items i ON i.id = s.item_id JOIN projects p ON p.id = s.project_id LEFT JOIN customer_users su ON su.id = s.site_user_id
            LEFT JOIN inv_tool_daily d ON d.serial_id = s.id AND d.returned_at IS NULL";
}
function ptl_unit(int $id): ?array { return ptl_has() && $id ? (one(ptl_sql() . " WHERE s.id = ? AND s.status = 'on_project'", [$id]) ?: null) : null; }
/** 'out' = with somebody now, 'late' = given on an earlier day and not back, 'missing' = reported missing at the last return, 'site' = with the supervisor. */
function ptl_flag(array $u): string { if (!empty($u['daily_id'])) return substr((string)$u['d_given_at'], 0, 10) < date('Y-m-d') ? 'late' : 'out'; return ($u['last_result'] ?? '') === 'missing' ? 'missing' : 'site'; }
/** A tool handed to a member of staff who has not confirmed it yet. */
function ptl_waits(array $u): bool { return ptl_ack_has() && !empty($u['daily_id']) && !empty($u['d_person_id']) && empty($u['d_ack_at']); }
function ptl_flag_pill(array $u): string { $f = ptl_flag($u); return (ptl_waits($u) ? pill('warn', t('ptl.f_wait')) . ' ' : '') . pill(['out' => 'gold', 'late' => 'bad', 'missing' => 'bad', 'site' => 'green'][$f], t('ptl.f_' . $f)); }
/** The tools on projects the signed-in person may see, grouped: [project id => ['p' => project, 'units' => [...], 'n' => counts]]. */
function ptl_projects(?int $only = null, ?int $uid = null): array {
    if (!ptl_has()) return []; $w = ["s.status = 'on_project'"]; $a = [];
    if ($uid !== null || !ptl_all()) { $me = $uid ?? ptl_me(); $w[] = '(s.site_user_id = ? OR p.pm_user_id = ?)'; array_push($a, $me, $me); }
    if ($only) { $w[] = 's.project_id = ?'; $a[] = $only; }
    $o = [];
    foreach (all(ptl_sql() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY p.project_code, COALESCE(s.asset_tag, s.serial_no) LIMIT 5000', $a) as $u) { $pid = (int)$u['project_id'];
        if (!isset($o[$pid])) $o[$pid] = ['p' => ['id' => $pid, 'code' => (string)$u['project_code'], 'name' => (string)$u['project_name'], 'status' => (string)$u['project_status']], 'units' => [], 'n' => ['all' => 0, 'out' => 0, 'late' => 0, 'missing' => 0, 'due' => 0]];
        $f = ptl_flag($u); $o[$pid]['units'][] = $u; $o[$pid]['n']['all']++; if ($f === 'late') { $o[$pid]['n']['late']++; $o[$pid]['n']['out']++; } elseif ($f !== 'site') $o[$pid]['n'][$f]++; if ($u['site_due'] && $u['site_due'] < date('Y-m-d')) $o[$pid]['n']['due']++; }
    return $o;
}
function ptl_count_project(int $pid): int { try { return ptl_has() ? (int) scalar("SELECT COUNT(*) FROM inv_serials WHERE status = 'on_project' AND project_id = ?", [$pid]) : 0; } catch (Throwable $e) { return 0; } }
/** Projects a tool can be sent to: not completed. */
function ptl_open_projects(): array { return all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold') ORDER BY FIELD(status,'active','onhold','quoted'), project_code LIMIT 2000"); }
function ptl_staff(): array { return function_exists('ism_users') ? ism_users() : all("SELECT id, full_name FROM customer_users WHERE is_active = 1 AND role <> 'customer' ORDER BY full_name"); }
function ptl_history(int $projectId, int $limit = 200): array { return ptl_has() ? all('SELECT d.*, s.asset_tag, s.serial_no, i.description FROM inv_tool_daily d JOIN inv_serials s ON s.id = d.serial_id JOIN inv_items i ON i.id = s.item_id WHERE d.project_id = ? ORDER BY d.id DESC LIMIT ' . max(1, min(1000, $limit)), [$projectId]) : []; }

/* ------------------------------------------------------------------ warehouse: to the project and back */
/** The ticked units leave their warehouse for a project, in the care of a supervisor. Returns how many. */
function ptl_send_from_post(): int {
    if (!ptl_has() || !eqp_can_stock()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['units'] ?? []))))); if (!$ids) throw new RuntimeException(t('ptl.e_none')); if (count($ids) > 300) throw new RuntimeException(t('ptl.e_many'));
    $P = one("SELECT id, project_code, name, status FROM projects WHERE id = ?", [(int)($_POST['project_id'] ?? 0)]); if (!$P || $P['status'] === 'completed') throw new RuntimeException(t('ptl.e_project'));
    $sup = (int)($_POST['site_user_id'] ?? 0); $supName = (string) scalar("SELECT full_name FROM customer_users WHERE id = ? AND is_active = 1 AND role <> 'customer'", [$sup]); if ($supName === '') throw new RuntimeException(t('ptl.e_sup'));
    $due = eqp_d($_POST['site_due'] ?? ''); if ($due !== null && $due < date('Y-m-d')) $due = null; $ref = t('ptl.ref_send', ['p' => (string)$P['project_code'], 'n' => $supName]);
    $wantDn = !empty($_POST['with_dn']) && function_exists('dn_can') && dn_can(); $dnTo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['dn_name'] ?? ''))), 0, 120); $dnMail = strtolower(trim((string)($_POST['dn_email'] ?? '')));
    if ($wantDn && $dnMail !== '' && (!filter_var($dnMail, FILTER_VALIDATE_EMAIL) || strlen($dnMail) > 190)) throw new RuntimeException(t('dl.bad_email')); $GLOBALS['ptl_dn_ids'] = [];
    $n = stk_tx(function () use ($ids, $P, $sup, $due, $ref, $wantDn, $dnTo, $supName) { $n = 0; $dnL = [];
        foreach ($ids as $id) { $s = one('SELECT s.*, i.last_price, i.is_asset FROM inv_serials s JOIN inv_items i ON i.id = s.item_id WHERE s.id = ? FOR UPDATE', [$id]); if (!$s || empty($s['is_asset'])) throw new RuntimeException(t('err.notfound'));
            if ($s['status'] !== 'in_stock') throw new RuntimeException(t('sn.not_in_stock', ['s' => (string)($s['asset_tag'] ?: $s['serial_no']), 'st' => t('sn.s_' . $s['status'])]));
            if ($s['ism_eq_id'] && scalar("SELECT status FROM ism_records WHERE id = ?", [(int)$s['ism_eq_id']]) === 'out_of_service') throw new RuntimeException(t('eqp.e_out_of_service', ['c' => (string)($s['asset_tag'] ?: $s['serial_no'])]));
            $wh = (int)$s['warehouse_id']; $mid = stk_move((int)$s['item_id'], $wh, 'out', 1.0, ['unit_price' => $s['last_price'], 'reason' => 'manual', 'note' => $ref . ' · S/N ' . $s['serial_no']]);
            sn_attach($mid, [(string)$s['serial_no']], ['status' => 'on_project', 'ref' => $ref]);
            q('UPDATE inv_serials SET project_id = ?, site_user_id = ?, site_since = CURDATE(), site_due = ?, site_wh_id = ? WHERE id = ?', [(int)$P['id'], $sup, $due, $wh, $id]); $n++;
            $dnL[$wh][] = ['item_id' => (int)$s['item_id'], 'description' => mb_substr(trim((string)($s['asset_tag'] ?: $s['serial_no']) . ' — ' . (string) scalar('SELECT description FROM inv_items WHERE id = ?', [(int)$s['item_id']])), 0, 255), 'unit' => 'pcs', 'qty' => 1, 'note' => (string)$s['serial_no'] !== (string)$s['asset_tag'] ? 'S/N ' . $s['serial_no'] : '', 'skip_move' => true]; }
        /* R97-292: a delivery note for the tools — a document only (the stock was moved above), one per warehouse they left; it is accepted like every delivery note */
        if ($wantDn && function_exists('dn_create_x')) foreach ($dnL as $wh => $L) $GLOBALS['ptl_dn_ids'][] = dn_create_x(['kind' => 'project', 'warehouse_id' => $wh, 'project_id' => (int)$P['id'], 'attention' => $dnTo, 'note' => t('ptl.dn_note', ['n' => $supName])], $L);
        return $n; });
    $dnIds = $GLOBALS['ptl_dn_ids'] ?? []; $GLOBALS['ptl_dn'] = ['ids' => $dnIds, 'mail' => null];
    if ($dnIds && $dnMail !== '' && function_exists('dl_create')) { try { foreach ($dnIds as $did) dl_create('dn', (int)$did, $dnMail, $dnTo, true); $GLOBALS['ptl_dn']['mail'] = true; } catch (Throwable $e) { $GLOBALS['ptl_dn']['mail'] = $e->getMessage(); } }
    if (function_exists('nf_push') && $sup !== ptl_me()) { $u = current_user(); try { nf_push([$sup], 'ptl', 'ptl:send:' . $P['id'] . ':' . date('YmdHis'), t('ptl.nf_send', ['n' => $n, 'p' => (string)$P['project_code']]), t('ptl.nf_send_b'), url('project_tools', ['project' => (int)$P['id']]), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')); } catch (Throwable $e) {} }
    if (function_exists('cache_bump_later')) cache_bump_later('inv');
    return $n;
}
/** The ticked units of a project are back in the warehouse. A tool still with somebody is taken back first (result as stated). Returns how many. */
function ptl_return_from_post(): int {
    if (!ptl_has() || !eqp_can_stock()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['units'] ?? []))))); if (!$ids) throw new RuntimeException(t('ptl.e_none'));
    $whOk = array_map('intval', array_column(stk_warehouses(), 'id')); $toWh = (int)($_POST['warehouse_id'] ?? 0); $u = current_user();
    $n = stk_tx(function () use ($ids, $whOk, $toWh, $u) { $n = 0;
        foreach ($ids as $id) { $s = one('SELECT s.*, i.last_price, p.project_code FROM inv_serials s JOIN inv_items i ON i.id = s.item_id LEFT JOIN projects p ON p.id = s.project_id WHERE s.id = ? FOR UPDATE', [$id]); if (!$s || $s['status'] !== 'on_project') throw new RuntimeException(t('ptl.e_state'));
            $wh = in_array($toWh, $whOk, true) ? $toWh : (in_array((int)$s['site_wh_id'], $whOk, true) ? (int)$s['site_wh_id'] : stk_default_wh()); $ref = t('ptl.ref_back', ['p' => (string)$s['project_code']]);
            q('UPDATE inv_tool_daily SET returned_at = NOW(), returned_by = ?, returned_name = ?, result = ?, note = ? WHERE serial_id = ? AND returned_at IS NULL', [(int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 120), 'ok', mb_substr($ref, 0, 255), $id]);
            $mid = stk_move((int)$s['item_id'], $wh, 'in', 1.0, ['unit_price' => $s['last_price'], 'reason' => 'manual', 'note' => $ref . ' · S/N ' . $s['serial_no']]); sn_attach($mid, [(string)$s['serial_no']], ['ref' => $ref]);
            q('UPDATE inv_serials SET project_id = NULL, site_user_id = NULL, site_since = NULL, site_due = NULL, site_wh_id = NULL WHERE id = ?', [$id]); $n++; }
        return $n; });
    if (function_exists('cache_bump_later')) cache_bump_later('inv');
    return $n;
}
/* ------------------------------------------------------------------ on the project: hand out, take back */
/** The supervisor hands a tool to somebody: a member of staff (person_id) or a name written down (a subcontractor). */
function ptl_give(int $unitId, int $personId, string $name): array {
    $u = ptl_unit($unitId); if (!$u || !ptl_can_site($u)) throw new RuntimeException(t('err.noaccess')); if ($u['daily_id']) throw new RuntimeException(t('ptl.e_out', ['n' => (string)$u['d_person']]));
    if (($u['last_result'] ?? '') === 'missing') throw new RuntimeException(t('ptl.e_missing'));
    if ($personId) { $p = hr_person($personId); if (!$p || empty($p['is_active'])) throw new RuntimeException(t('err.notfound')); $name = (string)$p['name_en']; }
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 120); if ($name === '') throw new RuntimeException(t('ptl.e_person'));
    $me = current_user(); q('INSERT INTO inv_tool_daily (serial_id, project_id, person_id, person_name, given_at, given_by, given_name) VALUES (?,?,?,?,NOW(),?,?)', [$unitId, (int)$u['project_id'], $personId ?: null, $name, (int)($me['id'] ?? 0) ?: null, mb_substr((string)($me['full_name'] ?? ''), 0, 120)]);
    if ($personId && ptl_ack_has() && !empty($p['user_id']) && (int)$p['user_id'] !== ptl_me() && function_exists('nf_push')) { try { nf_push([(int)$p['user_id']], 'ptl', 'ptl:give:' . (int) db()->lastInsertId(), t('ptl.nf_given', ['c' => (string)($u['asset_tag'] ?: $u['serial_no']), 't' => mb_substr((string)$u['description'], 0, 60), 'p' => (string)$u['project_code']]), t('ptl.nf_given_b'), url('my_equipment') . '#eq-handed', (int)($me['id'] ?? 0) ?: null, (string)($me['full_name'] ?? '')); } catch (Throwable $e) {} }
    return ptl_unit($unitId);
}
/** The tool is back with the supervisor: in order, damaged, or missing (it stays on the project marked missing until it is found or written off). */
function ptl_back(int $unitId, string $result, string $note): array {
    $u = ptl_unit($unitId); if (!$u || !ptl_can_site($u)) throw new RuntimeException(t('err.noaccess')); if (!in_array($result, PTL_RESULTS, true)) $result = 'ok'; $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, 255);
    $me = current_user(); $by = [(int)($me['id'] ?? 0) ?: null, mb_substr((string)($me['full_name'] ?? ''), 0, 120)];
    if (!$u['daily_id']) {   // nobody holds it: only a missing tool that was found, or a tool found damaged / missing on the shelf of the site
        if ($result === 'ok' && ($u['last_result'] ?? '') !== 'missing') throw new RuntimeException(t('ptl.e_not_out'));
        q('INSERT INTO inv_tool_daily (serial_id, project_id, person_id, person_name, given_at, given_by, given_name, returned_at, returned_by, returned_name, result, note) VALUES (?,?,NULL,?,NOW(),?,?,NOW(),?,?,?,?)', [$unitId, (int)$u['project_id'], t('ptl.site_check'), $by[0], $by[1], $by[0], $by[1], $result, $note ?: null]);
    } else { if ($result !== 'ok' && $note === '') throw new RuntimeException(t('ptl.e_note')); q('UPDATE inv_tool_daily SET returned_at = NOW(), returned_by = ?, returned_name = ?, result = ?, note = ? WHERE id = ? AND returned_at IS NULL', [$by[0], $by[1], $result, $note ?: null, (int)$u['daily_id']]); }
    if ($result !== 'ok' && function_exists('nf_push') && function_exists('prq_proc_ids')) { try { $to = array_values(array_diff(array_unique(array_merge(prq_proc_ids(), [(int)$u['site_user_id'], (int)$u['pm_user_id']])), [0, ptl_me()])); if ($to) nf_push($to, 'ptl', 'ptl:res:' . $unitId . ':' . date('YmdHi'), t('ptl.nf_' . $result, ['c' => (string)($u['asset_tag'] ?: $u['serial_no']), 't' => mb_substr((string)$u['description'], 0, 60), 'p' => (string)$u['project_code']]), trim((string)$u['d_person'] . ($note !== '' ? ' — ' . $note : '')), url('project_tools', ['project' => (int)$u['project_id']]), $by[0], $by[1]); } catch (Throwable $e) {} }
    return ptl_unit($unitId);
}
/** R97-292 — the person who was given a tool confirms it, or says it was not received (the hand-out is then closed and the supervisor hears). */
function ptl_ack(int $dailyId, bool $ok, string $note, string $via = 'portal'): void {
    if (!ptl_has() || !ptl_ack_has()) throw new RuntimeException(t('err.noaccess')); $P = function_exists('hre_my_person') ? hre_my_person() : null;
    $d = one('SELECT d.*, s.asset_tag, s.serial_no, s.site_user_id, i.description, p.project_code FROM inv_tool_daily d JOIN inv_serials s ON s.id = d.serial_id JOIN inv_items i ON i.id = s.item_id JOIN projects p ON p.id = d.project_id WHERE d.id = ?', [$dailyId]);
    if (!$d || !$P || (int)$d['person_id'] !== (int)$P['id']) throw new RuntimeException(t('err.noaccess')); if ($d['returned_at'] || $d['ack_at']) throw new RuntimeException(t('ptl.e_ack'));
    $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, 200); $via = $via === 'app' ? 'app' : 'portal'; $me = current_user();
    if ($ok) { q("UPDATE inv_tool_daily SET ack_at = NOW(), ack_state = 'ok', ack_via = ? WHERE id = ? AND ack_at IS NULL AND returned_at IS NULL", [$via, $dailyId]); return; }
    q("UPDATE inv_tool_daily SET ack_at = NOW(), ack_state = 'no', ack_via = ?, returned_at = NOW(), returned_by = ?, returned_name = ?, result = 'ok', note = ? WHERE id = ? AND ack_at IS NULL AND returned_at IS NULL", [$via, (int)($me['id'] ?? 0) ?: null, mb_substr((string)($me['full_name'] ?? ''), 0, 120), mb_substr(t('ptl.not_received') . ($note !== '' ? ': ' . $note : ''), 0, 255), $dailyId]);
    if (function_exists('nf_push')) { try { $to = array_values(array_diff(array_unique(array_filter([(int)$d['given_by'], (int)$d['site_user_id']])), [ptl_me()])); if ($to) nf_push($to, 'ptl', 'ptl:noack:' . $dailyId, t('ptl.nf_notrec', ['n' => (string)$d['person_name'], 'c' => (string)($d['asset_tag'] ?: $d['serial_no']), 'p' => (string)$d['project_code']]), $note ?: null, url('project_tools', ['project' => (int)$d['project_id']]), (int)($me['id'] ?? 0) ?: null, (string)($me['full_name'] ?? '')); } catch (Throwable $e) {} }
}
/** Tools handed to a person on a project and not taken back yet (for «My tools»). */
function ptl_with_person(int $personId): array { if (!ptl_has() || !$personId) return []; try { return all(ptl_sql() . " WHERE s.status = 'on_project' AND d.person_id = ?", [$personId]); } catch (Throwable $e) { return []; } }

/** Hourly job. After 18:00 (Athens) once a day: every supervisor hears which tools were handed out and are not back. Mondays: tools still on a completed
 *  project or past the date they were expected back — to the supervisor, the project manager and procurement. */
function ptl_sweep(?DateTime $now = null): int {
    if (!ptl_has() || !function_exists('nf_push')) return 0; $n = 0; $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Athens')); $day = $now->format('Y-m-d');
    if ((int)$now->format('G') >= 18 && (string) cp_setting('ptl_eod_day', '') !== $day) { cp_setting_set('ptl_eod_day', $day); $by = [];
        foreach (all(ptl_sql() . " WHERE s.status = 'on_project' AND d.id IS NOT NULL") as $u) { $to = (int)$u['site_user_id'] ?: (int)$u['pm_user_id']; if ($to) $by[$to][(int)$u['project_id']][] = $u; }
        foreach ($by as $to => $prj) foreach ($prj as $pid => $list) $n += (int) nf_push([$to], 'ptl', 'ptl:eod:' . $pid . ':' . $to . ':' . $day, t('ptl.nf_eod', ['n' => count($list), 'p' => (string)$list[0]['project_code']]), mb_substr(implode("\n", array_map(fn($u) => ($u['asset_tag'] ?: $u['serial_no']) . ' ' . $u['description'] . ' — ' . $u['d_person'], array_slice($list, 0, 12))), 0, 900), url('project_tools', ['project' => $pid]), null, null); }
    if ($now->format('N') === '1' && (string) cp_setting('ptl_week_day', '') !== $day) { cp_setting_set('ptl_week_day', $day); $proc = function_exists('prq_proc_ids') ? prq_proc_ids() : [];
        $grp = []; foreach (all(ptl_sql() . " WHERE s.status = 'on_project' AND (p.status = 'completed' OR (s.site_due IS NOT NULL AND s.site_due < CURDATE()))") as $u) $grp[(int)$u['project_id']][] = $u;
        foreach ($grp as $pid => $list) { $to = array_values(array_unique(array_filter(array_merge($proc, array_map(fn($u) => (int)$u['site_user_id'], $list), [(int)$list[0]['pm_user_id']])))); if (!$to) continue;
            $n += (int) nf_push($to, 'ptl', 'ptl:left:' . $pid . ':' . $day, t($list[0]['project_status'] === 'completed' ? 'ptl.nf_done' : 'ptl.nf_due', ['n' => count($list), 'p' => (string)$list[0]['project_code']]), t('ptl.nf_left_b'), url('project_tools', ['project' => $pid]), null, null); } }
    return $n;
}

function ptl_handle_action(string $action): bool {
    if (!str_starts_with($action, 'ptl_')) return false; if (!ptl_has()) throw new RuntimeException(t('err.noaccess'));
    switch ($action) {
        case 'ptl_send':   { $n = ptl_send_from_post(); $dn = $GLOBALS['ptl_dn'] ?? ['ids' => [], 'mail' => null]; flash('good', t('ptl.sent_ok', ['n' => $n]) . ($dn['ids'] ? ' ' . t('ptl.sent_dn', ['no' => implode(', ', array_map(fn($i) => (string) scalar('SELECT dn_no FROM inv_delivery_notes WHERE id = ?', [(int)$i]), $dn['ids']))]) . ($dn['mail'] === true ? ' ' . t('ptl.sent_dn_mail') : '') : '')); if (is_string($dn['mail'])) flash('warn', t('ptl.dn_mail_fail') . ' ' . $dn['mail']); if (count($dn['ids']) === 1) redirect('delivery_note', ['id' => (int)$dn['ids'][0], '_r' => uniqid()]); redirect('project_tools', ['project' => (int)($_POST['project_id'] ?? 0), '_r' => uniqid()]); }
        case 'ptl_return': { $n = ptl_return_from_post(); flash('good', t('ptl.returned_ok', ['n' => $n])); redirect('project_tools', ['project' => (int)($_POST['project_id'] ?? 0), '_r' => uniqid()]); }
        case 'ptl_give':   { $u = ptl_give((int)($_POST['unit_id'] ?? 0), (int)($_POST['person_id'] ?? 0), (string)($_POST['person_name'] ?? '')); flash('good', t('ptl.given_ok', ['n' => (string)$u['d_person']])); redirect('project_tools', ['project' => (int)$u['project_id'], '_r' => uniqid()], '#u' . (int)$u['id']); }
        case 'ptl_ack':    { ptl_ack((int)($_POST['daily_id'] ?? 0), ($_POST['answer'] ?? '') === 'ok', (string)($_POST['note'] ?? '')); flash('good', t(($_POST['answer'] ?? '') === 'ok' ? 'ptl.ack_ok' : 'ptl.ack_no_ok')); redirect('my_equipment', ['_r' => uniqid()], '#eq-handed'); }   // R97-292
        case 'ptl_back':   { $u = ptl_back((int)($_POST['unit_id'] ?? 0), (string)($_POST['result'] ?? 'ok'), (string)($_POST['note'] ?? '')); flash('good', t('ptl.back_ok')); redirect('project_tools', ['project' => (int)$u['project_id'], '_r' => uniqid()], '#u' . (int)$u['id']); }
    }
    throw new RuntimeException(t('err.noaccess'));
}
