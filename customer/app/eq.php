<?php
/**
 * Equipment register — serial numbers with their service history.                               GAS-CP-R27-001
 *
 * One record per unit (shore converter first; any maker / family): serial number, set, maker, family, model,
 * vessel (or a site), sold-by-us / warranty / retirement, contacts on board. The service history of a unit is one
 * timeline from three sources: the records imported from the maker lists (one dated paragraph "dd/mm/yyyy --- …"
 * each), the field service reports that name the unit (written automatically on submission) and manual records.
 * Owners see the units on their own vessels; internal remarks are never shown to them.
 */
declare(strict_types=1);

const EQ_KINDS = ['shore_converter', 'propulsion', 'ups', 'drive', 'switchboard', 'automation', 'navcom', 'other'];
const EQ_HKINDS = ['commissioning', 'maintenance', 'health_check', 'repair', 'warranty', 'upgrade', 'troubleshooting', 'inspection', 'other'];
const EQ_HTONE = ['commissioning' => 'navy', 'maintenance' => 'green', 'health_check' => 'green', 'repair' => 'bad', 'warranty' => 'gold', 'upgrade' => 'blue', 'troubleshooting' => 'gold', 'inspection' => 'blue', 'other' => 'mute'];

/** R27-003 tables (inspection reports, tasks, contacts) present? */
function eq_links(): bool {
    static $h = null;
    if ($h === null) { try { $h = eq_has() && (bool) scalar("SHOW TABLES LIKE 'equipment_contacts'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function eq_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'equipment'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function eq_kinds(): array { $o = []; foreach (EQ_KINDS as $k) $o[$k] = t('eq.k_' . $k); return $o; }
function eq_hkinds(): array { $o = []; foreach (EQ_HKINDS as $k) $o[$k] = t('eq.h_' . $k); return $o; }
function eq_hpill(string $k): string { return pill(EQ_HTONE[$k] ?? 'mute', t('eq.h_' . $k)); }
function eq_label(array $e): string { return trim($e['serial_no'] . ' · ' . trim($e['maker'] . ' ' . ($e['family'] ?: $e['model'] ?: ''))); }
/** Makers / families already in use (for the pickers). */
function eq_makers(): array { return eq_has() ? array_column(all("SELECT DISTINCT maker FROM equipment WHERE maker <> '' ORDER BY maker"), 'maker') : []; }
function eq_families(): array { return eq_has() ? array_column(all("SELECT DISTINCT family FROM equipment WHERE family IS NOT NULL AND family <> '' ORDER BY family"), 'family') : []; }

/** Customer scope: owners see the units on their vessels only. */
function eq_scope_sql(string $alias = 'e'): array {
    if (is_staff()) return ['1 = 1', []];
    $u = current_user(); return ["{$alias}.vessel_id IN (SELECT id FROM vessels WHERE customer_id = ?)", [(int)$u['customer_id']]];
}
function eq_get(int $id): ?array {
    if (!eq_has() || !$id) return null;
    [$sc, $a] = eq_scope_sql('e'); array_unshift($a, $id);
    return one("SELECT e.*, v.name AS vessel, v.customer_id, c.name AS customer_name, (SELECT COUNT(*) FROM equipment_history h WHERE h.equipment_id = e.id) AS n_hist,
                       (SELECT MAX(h.hist_date) FROM equipment_history h WHERE h.equipment_id = e.id) AS last_service
                FROM equipment e LEFT JOIN vessels v ON v.id = e.vessel_id LEFT JOIN customers c ON c.id = v.customer_id WHERE e.id = ? AND {$sc}", $a);
}
function eq_list(array $f = []): array {
    if (!eq_has()) return [];
    [$sc, $a] = eq_scope_sql('e'); $w = [$sc];
    if (!empty($f['q'])) { $l = '%' . $f['q'] . '%'; $w[] = '(e.serial_no LIKE ? OR e.set_code LIKE ? OR e.model LIKE ? OR e.family LIKE ? OR v.name LIKE ? OR e.site_name LIKE ? OR e.contacts LIKE ?)'; array_push($a, $l, $l, $l, $l, $l, $l, $l); }
    if (!empty($f['maker'])) { $w[] = 'e.maker = ?'; $a[] = $f['maker']; }
    if (!empty($f['family'])) { $w[] = 'e.family = ?'; $a[] = $f['family']; }
    if (!empty($f['vessel'])) { $w[] = 'e.vessel_id = ?'; $a[] = (int)$f['vessel']; }
    if (($f['status'] ?? '') === 'active') $w[] = 'e.retired = 0'; elseif (($f['status'] ?? '') === 'retired') $w[] = 'e.retired = 1'; elseif (($f['status'] ?? '') === 'warranty') $w[] = 'e.warranty_until >= CURDATE()'; elseif (($f['status'] ?? '') === 'sold') $w[] = 'e.sold_by_us = 1';
    return all("SELECT e.*, v.name AS vessel, c.name AS customer_name, (SELECT COUNT(*) FROM equipment_history h WHERE h.equipment_id = e.id) AS n_hist, (SELECT MAX(h.hist_date) FROM equipment_history h WHERE h.equipment_id = e.id) AS last_service
                FROM equipment e LEFT JOIN vessels v ON v.id = e.vessel_id LEFT JOIN customers c ON c.id = v.customer_id WHERE " . implode(' AND ', $w) . ' ORDER BY e.retired, COALESCE(v.name, e.site_name), e.maker, e.serial_no', $a);
}
/** Units installed on a vessel (for the vessel page and the FSR picker). */
function eq_for_vessel(?int $vesselId): array { return $vesselId && eq_has() ? eq_list(['vessel' => $vesselId, 'status' => 'active']) : []; }
/** Full history of a unit, newest first; owners see no internal remarks and only issued reports' entries. */
function eq_history(int $eqId, ?string $source = null): array {
    $w = ['h.equipment_id = ?']; $a = [$eqId];
    if ($source) { $w[] = 'h.source = ?'; $a[] = $source; }
    if (!is_staff()) $w[] = "(h.source IN ('import','manual','ticket') OR (h.source = 'fsr' AND EXISTS (SELECT 1 FROM fsr_reports r WHERE r.id = h.fsr_id AND r.status <> 'draft'))" . (eq_links() ? " OR (h.source = 'isr' AND EXISTS (SELECT 1 FROM isr_reports i WHERE i.id = h.isr_id AND i.status = 'issued'))" : '') . ')';
    $x = eq_links() ? ', i.report_no AS isr_no, i.status AS isr_status, td.title AS task_title' : ', NULL AS isr_no, NULL AS isr_status, NULL AS task_title';
    $j = eq_links() ? ' LEFT JOIN isr_reports i ON i.id = h.isr_id LEFT JOIN tasks td ON td.id = h.task_id' : '';
    return all("SELECT h.*, r.report_no, r.status AS fsr_status, t.ticket_no{$x} FROM equipment_history h LEFT JOIN fsr_reports r ON r.id = h.fsr_id LEFT JOIN tickets t ON t.id = h.ticket_id{$j}
                WHERE " . implode(' AND ', $w) . ' ORDER BY h.hist_date DESC, h.id DESC', $a);
}
/** Guess the record kind from the text. */
/** Default equipment type for a maker list: Aventics / Emerson / Marex = propulsion controls, everything else shore converters. */
function eq_kind_for_maker(string $maker): string { return preg_match('/aventics|emerson|marex|rexroth/i', $maker) ? 'propulsion' : 'shore_converter'; }
function eq_guess_kind(string $s): string {
    $l = mb_strtolower($s);
    foreach ([['commission', 'start up', 'start-up', 'startup', 'deliver', 'new installation', 'installed', 'installation of', 'supply only', 'procurement'], ['health check', 'healthcheck'], ['annual maintenance', 'maintenance', 'service visit', 'preventive'], ['warranty'], ['upgrade', 'retrofit', 'firmware', 'software update'], ['troubleshoot', 'fault', 'alarm', 'tripped', 'failure', 'not working'], ['replac', 'repair', 'burned', 'damaged'], ['inspect', 'survey']] as $i => $words)
        foreach ($words as $w) if (str_contains($l, $w)) return ['commissioning', 'health_check', 'maintenance', 'warranty', 'upgrade', 'troubleshooting', 'repair', 'inspection'][$i];
    return 'other';
}

/* ------------------------------------------------------------ editing */

function eq_save(?int $id): int {
    require_staff(); if (!eq_has()) throw new RuntimeException(t('eq.need_db'));
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n) ?: null;
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $sn = $s('serial_no', 60); if (!$sn) throw new RuntimeException(t('eq.need_sn'));
    $maker = $s('maker', 60) ?? '';
    $vid = (int)($_POST['vessel_id'] ?? 0) ?: null; if ($vid && !scalar('SELECT COUNT(*) FROM vessels WHERE id = ?', [$vid])) $vid = null;
    $kind = in_array($_POST['kind'] ?? '', EQ_KINDS, true) ? $_POST['kind'] : 'shore_converter';
    if (scalar('SELECT COUNT(*) FROM equipment WHERE maker = ? AND serial_no = ? AND id <> ?', [$maker, $sn, (int)$id])) throw new RuntimeException(t('eq.dup', ['v' => $sn]));
    $v = [$sn, $s('set_code', 80), $maker, $s('family', 60), $s('model', 120), $kind, $vid, $vid ? null : $s('site_name', 120), (int)!empty($_POST['sold_by_us']), $d('date_sold'), $d('warranty_until'), $s('warranty_ref', 80), $d('first_attendance'), (int)!empty($_POST['retired']), $d('retired_at'), $s('contacts', 2000), $s('notes', 4000)];
    if ($id) { q('UPDATE equipment SET serial_no=?, set_code=?, maker=?, family=?, model=?, kind=?, vessel_id=?, site_name=?, sold_by_us=?, date_sold=?, warranty_until=?, warranty_ref=?, first_attendance=?, retired=?, retired_at=?, contacts=?, notes=? WHERE id = ?', [...$v, $id]); return $id; }
    q('INSERT INTO equipment (serial_no, set_code, maker, family, model, kind, vessel_id, site_name, sold_by_us, date_sold, warranty_until, warranty_ref, first_attendance, retired, retired_at, contacts, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$v, (int)(current_user()['id'] ?? 0) ?: null]);
    return (int) db()->lastInsertId();
}
function eq_delete(int $id): void { require_cp_admin(); q('DELETE FROM equipment_history WHERE equipment_id = ?', [$id]); q('DELETE FROM fsr_equipment WHERE equipment_id = ?', [$id]); if (eq_links()) { q('DELETE FROM isr_equipment WHERE equipment_id = ?', [$id]); q('DELETE FROM task_equipment WHERE equipment_id = ?', [$id]); q('DELETE FROM equipment_contacts WHERE equipment_id = ?', [$id]); } q('DELETE FROM equipment WHERE id = ?', [$id]); }
function eq_hist_save(int $eqId): int {
    require_staff(); if (!scalar('SELECT COUNT(*) FROM equipment WHERE id = ?', [$eqId])) throw new RuntimeException(t('err.notfound'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000); if ($body === '') throw new RuntimeException(t('eq.need_text'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['hist_date'] ?? '')) ? $_POST['hist_date'] : date('Y-m-d');
    $kind = in_array($_POST['hkind'] ?? '', EQ_HKINDS, true) ? $_POST['hkind'] : eq_guess_kind($body);
    $hid = (int)($_POST['hist_id'] ?? 0); $u = current_user();
    $v = [$date, $kind, mb_substr(trim((string)($_POST['engineer'] ?? '')), 0, 160) ?: null, mb_substr(trim((string)($_POST['parts'] ?? '')), 0, 500) ?: null, $body];
    if ($hid) { q("UPDATE equipment_history SET hist_date=?, kind=?, engineer=?, parts=?, body=? WHERE id = ? AND equipment_id = ? AND source IN ('manual','import')", [...$v, $hid, $eqId]); return $hid; }
    q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, engineer, parts, body, created_by) VALUES (?,?,?,'manual',?,?,?,?)", [$eqId, ...$v, (int)($u['id'] ?? 0) ?: null]);
    return (int) db()->lastInsertId();
}
function eq_hist_delete(int $hid): void { require_staff(); q("DELETE FROM equipment_history WHERE id = ? AND source IN ('manual','import')", [$hid]); }

/* ------------------------------------------------------------ field service reports */

/** Units ticked on an FSR form (+ serial numbers typed as "new"): store the link; history is written on submission. */
function eq_fsr_link(int $reportId, array $ids, string $newSerials, ?int $vesselId, string $maker = ''): void { eq_link('fsr_equipment', 'report_id', $reportId, $ids, $newSerials, $vesselId, $maker); }
/** Link units to an inspection report / a task (R27-003). */
function eq_isr_link(int $reportId, array $ids, string $newSerials, ?int $vesselId): void { if (eq_links()) eq_link('isr_equipment', 'report_id', $reportId, $ids, $newSerials, $vesselId); }
function eq_task_link(int $taskId, array $ids, ?int $vesselId): void { if (eq_links()) eq_link('task_equipment', 'task_id', $taskId, $ids, '', $vesselId); }
function eq_link(string $table, string $col, int $ownerId, array $ids, string $newSerials, ?int $vesselId, string $maker = ''): void {
    if (!eq_has()) return;
    $ids = array_values(array_unique(array_map('intval', $ids)));
    foreach (array_filter(array_map('trim', preg_split('/[,;\n]+/', $newSerials))) as $sn) {
        $sn = mb_substr($sn, 0, 60);
        $x = one('SELECT id FROM equipment WHERE serial_no = ? ' . ($maker !== '' ? 'AND maker = ?' : '') . ' LIMIT 1', $maker !== '' ? [$sn, $maker] : [$sn]);
        if (!$x) { q('INSERT INTO equipment (serial_no, maker, vessel_id, created_by) VALUES (?,?,?,?)', [$sn, $maker, $vesselId, (int)(current_user()['id'] ?? 0) ?: null]); $ids[] = (int) db()->lastInsertId(); }
        else $ids[] = (int)$x['id'];
    }
    q("DELETE FROM {$table} WHERE {$col} = ?", [$ownerId]);
    foreach (array_unique($ids) as $eid) if (scalar('SELECT COUNT(*) FROM equipment WHERE id = ?', [$eid])) q("INSERT IGNORE INTO {$table} ({$col}, equipment_id) VALUES (?,?)", [$ownerId, $eid]);
}
function eq_isr_units(int $reportId): array { return eq_links() ? all('SELECT e.* FROM isr_equipment x JOIN equipment e ON e.id = x.equipment_id WHERE x.report_id = ? ORDER BY e.serial_no', [$reportId]) : []; }
function eq_task_units(int $taskId): array { return eq_links() ? all('SELECT e.* FROM task_equipment x JOIN equipment e ON e.id = x.equipment_id WHERE x.task_id = ? ORDER BY e.serial_no', [$taskId]) : []; }
/** (Re)write the history of an issued inspection report for every linked unit: one inspection record with the findings, risk and corrective actions. */
function eq_isr_sync(int $reportId): void {
    if (!eq_links()) return;
    $r = function_exists('isr_load') ? isr_load($reportId) : null; if (!$r) return;
    q("DELETE FROM equipment_history WHERE isr_id = ? AND source = 'isr'", [$reportId]);
    if ($r['status'] !== 'issued') return;
    $units = eq_isr_units($reportId); if (!$units) return;
    $txt = [];
    foreach ($r['items'] as $it) { $l = trim((string)$it['system_name']) . ': ' . trim((string)$it['condition_found']); if (!empty($it['risk_level']) && $it['risk_level'] !== 'none') $l .= ' [' . t('isr.rk_' . $it['risk_level']) . ']'; if (!empty($it['actions'])) $l .= ' — ' . t('isr.actions') . ': ' . trim((string)$it['actions']); $txt[] = $l; }
    $body = mb_substr(implode("\n", $txt), 0, 8000) ?: (t('isr.title') . ' ' . $r['report_no']);
    foreach ($units as $e) q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, isr_id, engineer, body, created_by) VALUES (?,?,'inspection','isr',?,?,?,?)", [(int)$e['id'], $r['report_date'] ?? substr((string)$r['created_at'], 0, 10), $reportId, mb_substr((string)($r['engineer'] ?? ''), 0, 160) ?: null, $body, (int)$r['created_by'] ?: null]);
}
/** A task marked done writes one record per linked unit; re-opening removes the latest one (recurring tasks keep earlier occurrences). */
function eq_task_done(int $taskId, bool $skipped = false): void {
    if (!eq_links() || $skipped) return;
    $t = function_exists('td_load') ? td_load($taskId) : null; if (!$t) return;
    $units = eq_task_units($taskId); if (!$units) return;
    $body = trim($t['title'] . ($t['notes'] ? "\n" . $t['notes'] : ''));
    $kind = eq_guess_kind($body); if ($kind === 'other') $kind = 'maintenance';
    foreach ($units as $e) q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, task_id, engineer, body, created_by) VALUES (?,CURDATE(),?,'task',?,?,?,?)", [(int)$e['id'], $kind, $taskId, mb_substr((string)($t['assignee'] ?: current_user()['full_name'] ?? ''), 0, 160) ?: null, mb_substr($body, 0, 8000), (int)(current_user()['id'] ?? 0) ?: null]);
}
function eq_task_reopen(int $taskId): void {
    if (!eq_links()) return;
    foreach (all("SELECT equipment_id, MAX(id) AS id FROM equipment_history WHERE task_id = ? AND source = 'task' GROUP BY equipment_id", [$taskId]) as $r) q('DELETE FROM equipment_history WHERE id = ?', [(int)$r['id']]);
}
/** Open tasks that concern a unit. */
function eq_tasks(int $eqId): array { return eq_links() && is_staff() ? all("SELECT t.id, t.title, t.due_at, t.status, t.priority, a.full_name AS assignee FROM task_equipment x JOIN tasks t ON t.id = x.task_id LEFT JOIN customer_users a ON a.id = t.assignee_id WHERE x.equipment_id = ? ORDER BY t.status = 'open' DESC, t.due_at DESC LIMIT 30", [$eqId]) : []; }
/** Inspection reports that concern a unit (customers: issued only). */
function eq_isrs(int $eqId): array { return eq_links() ? all("SELECT r.id, r.report_no, r.status, r.report_date FROM isr_equipment x JOIN isr_reports r ON r.id = x.report_id WHERE x.equipment_id = ?" . (is_staff() ? '' : " AND r.status = 'issued'") . ' ORDER BY r.id DESC LIMIT 30', [$eqId]) : []; }
/* ------------------------------------------------------------ linked contacts (R27-003) */
function eq_contacts(int $eqId): array {
    if (!eq_links()) return [];
    return all('SELECT ct.id, ct.full_name, ct.position, ct.email, ct.phone, ct.mobile, ct.category, x.role, ' . (function_exists('co_has') && co_has() ? 'co.name' : 'ct.company') . ' AS company_name FROM equipment_contacts x JOIN contacts ct ON ct.id = x.contact_id ' . (function_exists('co_has') && co_has() ? 'LEFT JOIN companies co ON co.id = ct.company_id ' : '') . 'WHERE x.equipment_id = ? ORDER BY ct.full_name', [$eqId]);
}
/** Contacts offered in the picker: the contacts of the vessel first, then the rest of the customer, then everybody else (active only). */
function eq_contact_options(array $e): array {
    if (!eq_links()) return [];
    $vid = (int)($e['vessel_id'] ?? 0); $cid = (int)($e['customer_id'] ?? 0);
    return all('SELECT ct.id, ct.full_name, ct.position, ' . (function_exists('co_has') && co_has() ? 'co.name' : 'ct.company') . ' AS company_name,
                       CASE WHEN EXISTS (SELECT 1 FROM contact_vessels cv WHERE cv.contact_id = ct.id AND cv.vessel_id = ?) THEN 0 WHEN ct.customer_id = ? AND ? > 0 THEN 1 ELSE 2 END AS grp
                FROM contacts ct ' . (function_exists('co_has') && co_has() ? 'LEFT JOIN companies co ON co.id = ct.company_id ' : '') . 'WHERE ct.is_active = 1 ORDER BY grp, ct.full_name LIMIT 600', [$vid, $cid, $cid]);
}
function eq_contact_add(int $eqId, int $contactId, string $role): void {
    require_staff(); if (!eq_links() || !$contactId || !scalar('SELECT COUNT(*) FROM contacts WHERE id = ?', [$contactId])) throw new RuntimeException(t('err.notfound'));
    q('INSERT INTO equipment_contacts (equipment_id, contact_id, role, added_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$eqId, $contactId, mb_substr(trim($role), 0, 80) ?: null, (int)(current_user()['id'] ?? 0) ?: null]);
}
/** ?p=eq_contact_search&eq=&q=&customer=&category=&vessel=1 — JSON list for the picker dialog (staff). */
function eq_contact_search(): void {
    require_staff(); header('Content-Type: application/json; charset=utf-8');
    $e = eq_get((int)($_GET['eq'] ?? 0)); if (!$e || !eq_links()) { echo '{"rows":[],"total":0}'; exit; }
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'customer' => (string)($_GET['customer'] ?? ''), 'category' => (string)($_GET['category'] ?? ''), 'status' => 'active', 'page' => (int)($_GET['page'] ?? 1)];
    if (!empty($_GET['vessel']) && $e['vessel_id']) $f['vessel'] = (string)(int)$e['vessel_id'];
    $r = ct_list($f, 40); $linked = array_map('intval', array_column(eq_contacts((int)$e['id']), 'id'));
    echo json_encode(['total' => $r['total'], 'page' => $r['page'], 'pages' => $r['pages'], 'rows' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['full_name'], 'position' => (string)($c['position'] ?? ''), 'company' => (string)($c['company_name'] ?? ''), 'customer' => (string)($c['customer_name'] ?? ''), 'category' => t('ct.cat_' . $c['category']), 'vessels' => (string)($c['vessels'] ?? ''), 'phone' => (string)($c['mobile'] ?: $c['phone'] ?: ''), 'email' => (string)($c['email'] ?? ''), 'linked' => in_array((int)$c['id'], $linked, true)], $r['rows'])], JSON_UNESCAPED_UNICODE); exit;
}
function eq_contact_add_many(int $eqId, array $ids, string $role): int { $n = 0; foreach (array_unique(array_map('intval', $ids)) as $cid) if ($cid) { eq_contact_add($eqId, $cid, $role); $n++; } return $n; }
function eq_contact_remove(int $eqId, int $contactId): void { require_staff(); if (eq_links()) q('DELETE FROM equipment_contacts WHERE equipment_id = ? AND contact_id = ?', [$eqId, $contactId]); }
function eq_fsr_units(int $reportId): array { return eq_has() ? all('SELECT e.* FROM fsr_equipment x JOIN equipment e ON e.id = x.equipment_id WHERE x.report_id = ? ORDER BY e.serial_no', [$reportId]) : []; }
/** (Re)write the history entries of a submitted FSR for every linked unit: tasks, parts, engineers. */
function eq_fsr_sync(int $reportId): void {
    if (!eq_has()) return;
    $r = function_exists('fsr_load') ? fsr_load($reportId) : null; if (!$r) return;
    q("DELETE FROM equipment_history WHERE fsr_id = ? AND source = 'fsr'", [$reportId]);
    if ($r['status'] === 'draft') return;
    $units = eq_fsr_units($reportId); if (!$units) return;
    $parts = []; $text = []; $issues = [];
    foreach ($r['works'] as $w) { if ($w['description']) $text[] = t('fsr.task_n', ['n' => $w['seq']]) . ': ' . trim((string)$w['description']); foreach ($w['parts'] ?? [] as $p) if (!empty($p['d'])) $parts[] = $p['d'] . ' (' . $p['t'] . ')'; if (!empty($w['open_issues'])) $issues[] = $w['open_issues']; }
    $eng = array_values(array_unique(array_filter(array_map(fn($l) => $l['engineer'] ?? '', $r['lines']))));
    $body = implode("\n", $text) . ($issues ? "\n" . t('fsr.issues') . ': ' . implode(' · ', $issues) : '');
    $kind = eq_guess_kind($body);
    foreach ($units as $e) q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, fsr_id, engineer, parts, body, created_by) VALUES (?,?,?,'fsr',?,?,?,?,?)", [(int)$e['id'], $r['report_date'], $kind, $reportId, mb_substr(implode(', ', $eng), 0, 160) ?: null, mb_substr(implode(' · ', $parts), 0, 500) ?: null, $body ?: t('fsr.title') . ' ' . $r['report_no'], (int)$r['submitted_by'] ?: null]);
}

/* ------------------------------------------------------------ Excel import / export (maker lists) */

/** Import a maker list export (SN · Vessel Name · 1st Attendance · Vessel Contacts · Service Records · Model · SoldByUs · Date Sold · Warranty Expiration · Retired · Retirement Date).
 *  $maker names the maker for the whole file. Sets "5433-001/002" become one unit per member; every "dd/mm/yyyy --- …" paragraph a history record. */
function eq_import(string $path, string $maker, bool $dry): array {
    require_staff(); if (!eq_has()) throw new RuntimeException(t('eq.need_db'));
    require_once __DIR__ . '/xlsx.php';
    $rows = xlsx_rows($path);
    $hdr = pm_import_header($rows, ['sn' => ['SN', 'Serial', 'Serial number', 'Serial No', 'S/N']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'SN']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['vessel' => ['Vessel Name', 'Vessel', 'Site'], 'first' => ['1st Attendance', 'First attendance'], 'contacts' => ['Vessel Contacts', 'Contacts'], 'records' => ['Service Records', 'Records', 'History'], 'model' => ['Model', 'Type'], 'sold' => ['SoldByUs', 'Sold by us', 'Sold'], 'date_sold' => ['Date Sold'], 'warranty' => ['Warranty Expiration', 'Warranty'], 'retired' => ['Retired'], 'retired_at' => ['Retirement Date'], 'family' => ['Family'], 'set' => ['Set']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $res = ['rows' => 0, 'units_new' => 0, 'units_upd' => 0, 'records' => 0, 'vessels_new' => 0, 'skipped' => [], 'warnings' => [], 'unmatched_vessels' => []];
    $maker = mb_substr(trim($maker), 0, 60);
    $vessels = []; foreach (all('SELECT id, name FROM vessels') as $v) $vessels[eq_norm($v['name'])] = (int)$v['id'];
    $yes = fn(string $v) => in_array(mb_strtolower($v), ['1', 'true', 'yes', 'y', 'x'], true);
    $me = current_user();
    $kind = eq_kind_for_maker($maker); $usedSn = [];
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $maker, $vessels, $yes, $me, $kind, &$usedSn) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $snRaw = $g($r, 'sn'); $noSn = $snRaw === '';
            if ($noSn) { $base = 'NO-SN ' . mb_substr(eq_norm($g($r, 'vessel')) ?: $maker, 0, 48); $snRaw = $base; for ($k = 2; isset($usedSn[$snRaw]); $k++) $snRaw = $base . ' #' . $k; $usedSn[$snRaw] = true; $res['warnings'][] = [$line, t('eq.im_nosn', ['v' => $g($r, 'vessel'), 'sn' => $snRaw])]; }
            $members = eq_expand_set($snRaw); $set = count($members) > 1 ? mb_substr($snRaw, 0, 80) : null;
            $vName = $g($r, 'vessel'); $vKey = eq_norm($vName); $vid = $vessels[$vKey] ?? null; $site = null;
            if ($vName !== '' && !$vid && function_exists('vm_has') && vm_has()) { $vid = vm_find($vName); if ($vid) $vessels[$vKey] = $vid; }   // R30-001
            if ($vName !== '' && !$vid) { if (preg_match('/^(M\/Y|MY|S\/Y|SY|M\/V|MV|M\/T|MT|GY\d*-?)\s*/iu', $vName)) { q('INSERT INTO vessels (name, is_active) VALUES (?, 1)', [mb_substr($vName, 0, 120)]); $vid = (int) db()->lastInsertId(); $vessels[$vKey] = $vid; $res['vessels_new']++; } else { $site = mb_substr($vName, 0, 120); $res['unmatched_vessels'][$vName] = true; } }
            $model = mb_substr($g($r, 'model'), 0, 120); $family = $g($r, 'family') !== '' ? mb_substr($g($r, 'family'), 0, 60) : eq_family_of($model);
            $first = eq_date($g($r, 'first')); $dateSold = eq_date($g($r, 'date_sold')); $warr = eq_date($g($r, 'warranty')); $retAt = eq_date($g($r, 'retired_at'));
            $sold = (int)$yes($g($r, 'sold')); $retired = (int)$yes($g($r, 'retired'));
            $contacts = mb_substr($g($r, 'contacts'), 0, 2000) ?: null; $records = $g($r, 'records');
            $wref = preg_match('/warranty\s*project\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', $records, $m) ? mb_substr($m[1], 0, 80) : null;
            foreach ($members as $sn) {
                $x = one('SELECT id FROM equipment WHERE maker = ? AND serial_no = ?', [$maker, $sn]);
                $v = [$set, $kind, $family, $model ?: null, $vid, $vid ? null : $site, $sold, $dateSold, $warr, $wref, $first, $retired, $retAt, $contacts, $records ?: null];
                if ($x) { q('UPDATE equipment SET set_code=?, kind=?, family=?, model=?, vessel_id=?, site_name=?, sold_by_us=?, date_sold=?, warranty_until=?, warranty_ref=?, first_attendance=?, retired=?, retired_at=?, contacts=?, raw_records=? WHERE id = ?', [...$v, $x['id']]); $eid = (int)$x['id']; $res['units_upd']++; q("DELETE FROM equipment_history WHERE equipment_id = ? AND source = 'import'", [$eid]); }
                else { q('INSERT INTO equipment (serial_no, maker, set_code, kind, family, model, vessel_id, site_name, sold_by_us, date_sold, warranty_until, warranty_ref, first_attendance, retired, retired_at, contacts, raw_records, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$sn, $maker, ...$v, (int)($me['id'] ?? 0) ?: null]); $eid = (int) db()->lastInsertId(); $res['units_new']++; }
                foreach (eq_parse_records($records) as $rec) { q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, body, created_by) VALUES (?,?,?,'import',?,?)", [$eid, $rec['date'] ?? $first ?? $dateSold, $rec['kind'], $rec['text'], (int)($me['id'] ?? 0) ?: null]); $res['records']++; }
            }
        }
        foreach (array_keys($res['unmatched_vessels']) as $n) $res['warnings'][] = [0, t('eq.im_site', ['v' => $n])];
        return $res;
    });
}
/** "5433-001/002/003/004" → [5433-001, 5433-002, 5433-003, 5433-004]; "1275" → [1275]. */
function eq_expand_set(string $sn): array {
    $sn = trim($sn); if ($sn === '') return [];
    $toks = array_values(array_filter(array_map('trim', explode('/', $sn)), fn($x) => $x !== ''));
    if (count($toks) === 1) {                                                   // "4799-001-002-003", "4652-0001-0002", "21053-1-2"
        $p = explode('-', $toks[0]);
        if (count($p) >= 3 && ctype_alnum($p[0])) {
            $tail = array_slice($p, 1); $w = strlen($tail[0]);
            if ($w <= 4 && count(array_filter($tail, fn($x) => preg_match('/^\d+[A-Za-z]?$/', $x) && strlen($x) === $w)) === count($tail))
                return array_map(fn($x) => $p[0] . '-' . $x, $tail);
        }
        return [mb_substr($toks[0], 0, 60)];
    }
    $out = [$toks[0]]; $base = $toks[0];
    foreach (array_slice($toks, 1) as $t) {
        if (preg_match('/^[A-Za-z]$/', $t) && preg_match('/[A-Za-z]$/', $base)) $out[] = substr($base, 0, -1) . strtoupper($t);          // "4299-001A/B", "20084A/B"
        elseif (ctype_digit($t) && strlen($t) < strlen($base) && !str_contains($t, '-')) $out[] = substr($base, 0, strlen($base) - strlen($t)) . $t;   // "5433-001/002", "98108/107", "22047/48"
        else $out[] = $t;                                                                                                              // "4806-001/4806-002", "20123/21065"
    }
    return array_values(array_unique(array_map(fn($x) => mb_substr($x, 0, 60), $out)));
}
/** Split the free-text history into dated records: paragraphs starting with dd/mm/yyyy or mm/yyyy followed by "---" (or a line break). */
function eq_parse_records(string $txt): array {
    $txt = str_replace("\r", '', trim($txt)); if ($txt === '') return [];
    $parts = preg_split('/(?=(?:^|\n)\s*\d{1,2}(?:[\/.\-]\d{1,2})?[\/.\-]\d{4}\s*-{2,})/u', "\n" . $txt);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p); if ($p === '' || preg_match('/^warranty\s*project/i', $p)) continue;
        if (preg_match('/^(\d{1,2})(?:[\/.\-](\d{1,2}))?[\/.\-](\d{4})\s*-{2,}\s*(.*)$/su', $p, $m)) {
            $date = $m[2] !== '' ? sprintf('%04d-%02d-%02d', $m[3], $m[2], min(31, (int)$m[1])) : sprintf('%04d-%02d-01', $m[3], min(12, (int)$m[1]));   // "12/2024" = month only
            if (!checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) $date = substr($date, 0, 8) . '01';
            $text = trim(preg_replace('/^\s*warranty\s*project[^\n]*\n?/i', '', $m[4]));
            if ($text !== '') $out[] = ['date' => $date, 'kind' => eq_guess_kind($text), 'text' => mb_substr($text, 0, 8000)];
        } else { $text = trim(preg_replace('/^\s*warranty\s*project[^\n]*\n?/i', '', $p)); if ($text !== '') $out[] = ['date' => null, 'kind' => eq_guess_kind($text), 'text' => mb_substr($text, 0, 8000)]; }
    }
    return $out;
}
function eq_family_of(string $model): ?string {
    $m = trim($model); if ($m === '' || strcasecmp($m, 'unknown') === 0 || strcasecmp($m, 'none') === 0) return null;
    $m = preg_replace('/\bclas+ic\b/i', 'Classic', $m);
    $fam = [['/\bSPA\s*II\b/i', 'SPA II'], ['/\bSPA\b|\bSPA\d/i', 'SPA'], ['/\bClassic\s*III\b/i', 'Classic III'], ['/\bClassic\s*II\b/i', 'Classic II'], ['/\bClassic\s*I\b/i', 'Classic I'],
            ['/\bUltra\s*Classic\b/i', 'Ultra Classic'], ['/\bClassic\b/i', 'Classic'], ['/\bUHF/i', 'UHF'], ['/\bSHF/i', 'SHF'], ['/\bSP200\b/i', 'SP200'], ['/\bVSP5\b/i', 'VSP5'], ['/\bVSP\b/i', 'VSP'],
            ['/\bUltra\s*UPS\b/i', 'Ultra UPS'], ['/\bUltra\b/i', 'Ultra'], ['/\bElite\b/i', 'Elite'],
            ['/\bMarex\s*OS\s*III\b/i', 'Marex OS III'], ['/\bMPC\b/i', 'MPC'], ['/\bType\s*244\b/i', 'Type 244'], ['/\bType\s*240\b/i', 'Type 240'], ['/\bType\s*230\b/i', 'Type 230'], ['/\bECS\b/i', 'ECS'], ['/joystick/i', '3D Joystick'], ['/control\s*head/i', 'Control head']];
    foreach ($fam as [$re, $name]) if (preg_match($re, $m)) return $name;
    return mb_substr(preg_replace('/\s+\d.*$/', '', $m), 0, 60) ?: null;
}
function eq_date(string $v): ?string {
    if ($v === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})/', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (is_numeric($v) && $v > 20000 && $v < 60000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    return null;
}
function eq_norm(string $s): string { $s = mb_strtoupper(trim($s)); $s = preg_replace('/^(M\/Y|MY|S\/Y|SY|M\/V|MV|M\/T|MT)\s+/u', '', $s); return preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]/u', '', $s)); }
/** The register as Excel in the same 13-column layout the maker lists use (history flattened back into "dd/mm/yyyy --- …" paragraphs). */
function eq_export(array $f): void {
    require_staff(); require_once __DIR__ . '/xlsxw.php';
    $rows = [];
    foreach (eq_list($f) as $e) {
        $h = array_reverse(eq_history((int)$e['id']));
        $rec = implode("\n", array_map(fn($x) => ($x['hist_date'] ? date('d/m/Y', strtotime($x['hist_date'])) . ' --- ' : '') . $x['body'] . ($x['parts'] ? ' [' . $x['parts'] . ']' : '') . ($x['report_no'] ? ' (FSR ' . $x['report_no'] . ')' : ''), $h));
        $rows[] = [$e['serial_no'], $e['vessel'] ?: ($e['site_name'] ?? ''), $e['first_attendance'] ?? '', $e['contacts'] ?? '', $rec, $e['model'] ?? '', $e['sold_by_us'] ? 'True' : 'False', $e['date_sold'] ?? '', $e['warranty_until'] ?? '', $e['retired'] ? 'True' : 'False', $e['retired_at'] ?? '', $e['maker'], $e['family'] ?? '', $e['set_code'] ?? '', $e['warranty_ref'] ?? ''];
    }
    xlsx_download('GAS-equipment-' . date('Ymd') . '.xlsx', [['name' => 'Equipment', 'first' => true, 'header' => ['SN', 'Vessel Name', '1st Attendance', 'Vessel Contacts', 'Service Records', 'Model', 'SoldByUs', 'Date Sold', 'Warranty Expiration', 'Retired', 'Retirement Date', 'Maker', 'Family', 'Set', 'Warranty ref'], 'rows' => $rows, 'widths' => [16, 24, 12, 30, 90, 26, 9, 12, 12, 8, 12, 10, 12, 20, 12], 'wrap' => [3, 4]]]);
}

/* ------------------------------------------------------------ service-history document per serial number (R27-002) */

function eq_doc_no(array $e): string {
    $pid = $e['vessel_id'] ? (function_exists('ar_vessel_project') ? (int) ar_vessel_project((int)$e['vessel_id']) : 0) : 0;
    return br_prefix($pid ?: null) . '-EH-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $e['serial_no'])) . '-' . date('Ymd');
}
/** Everything the PDF and the Word document print, so both say the same. */
function eq_doc_data(int $id): ?array {
    $e = eq_get($id); if (!$e) return null;
    $hist = eq_history($id, '');
    $parts = []; foreach ($hist as $h) if (!empty($h['parts'])) $parts[] = ['date' => $h['hist_date'], 'parts' => $h['parts'], 'src' => $h['source'], 'ref' => $h['report_no'] ?? ''];
    $set = $e['set_code'] ? all('SELECT serial_no FROM equipment WHERE maker = ? AND set_code = ? AND id <> ? ORDER BY serial_no', [$e['maker'], $e['set_code'], $id]) : [];
    $kv = [[t('eq.sn'), $e['serial_no'] . ($e['set_code'] ? ' (' . $e['set_code'] . ')' : '')], [t('eq.kind'), t('eq.k_' . $e['kind'])], [t('eq.maker') . ' · ' . t('eq.family'), trim($e['maker'] . ' · ' . ($e['family'] ?: '—'))], [t('eq.model'), (string)($e['model'] ?: '—')],
           [t('v.one'), (string)($e['vessel'] ?: $e['site_name'] ?: '—')], [t('eq.sold_by_us'), $e['sold_by_us'] ? t('eq.yes') . ($e['date_sold'] ? ' · ' . fmt_date($e['date_sold']) : '') : t('eq.service_only')],
           [t('eq.warranty'), $e['warranty_until'] ? fmt_date($e['warranty_until']) . ($e['warranty_ref'] ? ' · ' . $e['warranty_ref'] : '') : '—'], [t('eq.first_attendance'), $e['first_attendance'] ? fmt_date($e['first_attendance']) : '—'],
           [t('eq.contacts'), (string)($e['contacts'] ?: '—')], [t('rp.issued'), date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? '')]];
    if ($set) $kv[] = [t('eq.set_units'), implode(', ', array_column($set, 'serial_no'))];
    if ($lc = eq_contacts($id)) $kv[] = [t('eq.linked_contacts'), implode("\n", array_map(fn($c) => trim($c['full_name'] . (($c['role'] ?: $c['position']) ? ' (' . ($c['role'] ?: $c['position']) . ')' : '') . ' · ' . implode(' · ', array_filter([$c['mobile'] ?: $c['phone'], $c['email']]))), $lc))];
    $src = fn($h) => t('eq.src_' . $h['source']) . (!empty($h['report_no']) ? ' ' . $h['report_no'] : (!empty($h['isr_no']) ? ' ' . $h['isr_no'] : ''));
    $T = [];
    $T[] = ['title' => t('eq.parts_replaced'), 'cols' => [[t('th.date'), 24, 'L'], [t('eq.source'), 40, 'L'], [t('eq.parts_replaced'), 116, 'L']],
            'rows' => array_map(fn($p) => ['v' => [fmt_date($p['date']), t('eq.src_' . $p['src']) . ($p['ref'] ? ' ' . $p['ref'] : ''), $p['parts']]], $parts)];
    $T[] = ['title' => t('eq.history'), 'cols' => [[t('th.date'), 22, 'L'], [t('eq.hkind'), 26, 'L'], [t('eq.source'), 30, 'L'], [t('fsr.engineer'), 28, 'L'], [t('eq.record_text'), 74, 'L']],
            'rows' => array_map(fn($h) => ['v' => [$h['hist_date'] ? fmt_date($h['hist_date']) : t('eq.undated'), t('eq.h_' . $h['kind']), $src($h), (string)($h['engineer'] ?: '—'), trim((string)$h['body'] . (!empty($h['parts']) ? "\n" . t('eq.parts_replaced') . ': ' . $h['parts'] : ''))]], $hist)];
    return ['e' => $e, 'docNo' => eq_doc_no($e), 'kv' => $kv, 'tables' => $T, 'n' => count($hist), 'last' => $hist ? ($hist[0]['hist_date'] ?? null) : null];
}
function eq_render_pdf(array $D): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $docNo = $D['docNo']; $bd = br_doc(null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0; $logo = $bd['logo'];
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $docNo, $D, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('eq.doc_title')) . ' — ' . $D['e']['serial_no']);
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $docNo);
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y = 31.0; if ($pdf->pageNo() === 1) $y += rc_legend_pdf($pdf, $L, 27.2, $R - $L);
    };
    $footer = function () use ($pdf, $ink, $L, $W, $docNo, $bd) {
        $pdf->setColor($ink); $pdf->setFont('R', 7);
        $pdf->text($L, 291, $bd['footer'] . ' · ' . $docNo . ' · ' . date('d.m.Y H:i'));
        $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W);
    };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $hh) use (&$y, $newPage) { if ($y + $hh > 276) $newPage(); };
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
    $header(); $footer();
    foreach ($D['kv'] as [$k, $val]) {
        $pdf->setFont('R', 8.5); $ls = $pdf->wrap($val, $W - 48); $hh = max(6, 1.4 + 4.0 * count($ls)); $need($hh);
        $pdf->setColor($line); $pdf->rect($L, $y, $W, $hh); $pdf->setColor($navy); $pdf->setFont('B', 8); $pdf->text($L + 2, $y + 4.1, $k); $pdf->setColor($ink); $pdf->setFont('R', 8.5);
        foreach ($ls as $i => $ln) $pdf->text($L + 45, $y + 4.1 + 4.0 * $i, $ln); $y += $hh;
    }
    $y += 4; $pdf->setColor($ink); $pdf->setFont('R', 7.5); $y += $pdf->paragraph($L, $y, $W, t('eq.doc_note', ['n' => $D['n']]), 3.8) + 3;
    foreach ($D['tables'] as $T) {
        $y += 3; $need(18); $tableNo++;
        $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $T['title']); $y += 6.5;
        $hrow($T['cols']);
        if (!$T['rows']) { $drow($T['cols'], [t('vh.none')]); continue; }
        foreach ($T['rows'] as $r) $drow($T['cols'], $r['v']);
    }
    return $pdf->bytes($docNo);
}
function eq_render_docx(array $D): string {
    require_once __DIR__ . '/docx.php';
    $w = new DocxWriter(); $docNo = $D['docNo']; $bd = br_doc(null); $logo = (string) @file_get_contents($bd['logo']);
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4'; $tableNo = 0;
    $w->grid([[['lines' => [mb_strtoupper(t('eq.doc_title')) . ' — ' . $D['e']['serial_no'], $docNo], 'b' => true, 'sz' => 14, 'color' => $NAVY], ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    $w->grid(array_map(fn($r) => [['lines' => [$r[0]], 'b' => true, 'sz' => 8], ['text' => (string)$r[1], 'sz' => 9]], $D['kv']), [2600, 7040], ['border' => '9AA5B1']);
    $w->para(t('eq.doc_note', ['n' => $D['n']]), ['sz' => 9, 'i' => true]);
    $hdr = fn($x) => ['text' => $x, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center'];
    $c = fn($x) => ['text' => (string)$x, 'sz' => 8.5];
    foreach ($D['tables'] as $T) {
        $tableNo++; $w->para(t('rp.table') . ' ' . $tableNo . ' — ' . $T['title'], ['b' => true, 'align' => 'left', 'color' => $NAVY]);
        $tot = array_sum(array_column($T['cols'], 1)); $widths = array_map(fn($col) => (int) round(9640 * $col[1] / $tot), $T['cols']);
        $out = [array_map(fn($col) => $hdr($col[0]), $T['cols'])];
        if (!$T['rows']) $out[] = array_merge([$c(t('vh.none'))], array_fill(0, count($T['cols']) - 1, ''));
        foreach ($T['rows'] as $r) $out[] = array_map(fn($i) => $c($r['v'][$i] ?? ''), array_keys($T['cols']));
        $w->grid($out, $widths, ['border' => '9AA5B1']);
    }
    return $w->bytes($docNo, $bd['footer'] . ' · ' . $docNo);
}
/** ?p=equipment_doc&id=&fmt=pdf|docx — streams the service-history document (staff and the owner of the vessel). */
function eq_doc_download(int $id, string $fmt): void {
    require_login(); $D = eq_doc_data($id); if (!$D) { http_response_code(404); exit; }
    $docx = $fmt === 'docx'; $bytes = $docx ? eq_render_docx($D) : eq_render_pdf($D);
    header('Content-Type: ' . ($docx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $D['docNo'] . ($docx ? '.docx' : '.pdf') . '"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}
/** Equipment tables for the vessel technical-history report (R27-002). */
function eq_vessel_tables(int $vid): array {
    if (!eq_has()) return [];
    $units = eq_list(['vessel' => $vid, 'status' => 'all']); $T = [];
    $T[] = ['title' => t('eq.vh_units'), 'cols' => [[t('eq.sn'), 28, 'L'], [t('eq.kind'), 24, 'L'], [t('eq.maker') . ' · ' . t('eq.family'), 32, 'L'], [t('eq.model'), 40, 'L'], [t('eq.warranty'), 22, 'L'], [t('eq.last_service'), 20, 'L'], [t('eq.records'), 14, 'R']],
            'rows' => array_map(fn($u) => ['v' => [$u['serial_no'] . ($u['retired'] ? ' (' . t('eq.retired') . ')' : ''), t('eq.k_' . $u['kind']), trim($u['maker'] . ' · ' . ($u['family'] ?: '—')), (string)($u['model'] ?: '—'), $u['warranty_until'] ? fmt_date($u['warranty_until']) : '—', !empty($u['last_service']) ? fmt_date($u['last_service']) : '—', (string)(int)$u['n_hist']]], $units)];
    $rows = [];
    if ($units) {
        $ids = array_map('intval', array_column($units, 'id')); $in = implode(',', $ids);
        $cust = !is_staff() ? " AND (h.source <> 'fsr' OR EXISTS (SELECT 1 FROM fsr_reports fr WHERE fr.id = h.fsr_id AND fr.status <> 'draft'))" : '';
        $cust .= !is_staff() && eq_links() ? " AND (h.source <> 'isr' OR EXISTS (SELECT 1 FROM isr_reports ir WHERE ir.id = h.isr_id AND ir.status = 'issued')) AND h.source <> 'task'" : '';
        $x = eq_links() ? ', ir.report_no AS isr_no' : ', NULL AS isr_no'; $j = eq_links() ? ' LEFT JOIN isr_reports ir ON ir.id = h.isr_id' : '';
        foreach (all("SELECT h.*, e.serial_no, fr.report_no{$x} FROM equipment_history h JOIN equipment e ON e.id = h.equipment_id LEFT JOIN fsr_reports fr ON fr.id = h.fsr_id{$j} WHERE h.equipment_id IN ($in){$cust} ORDER BY h.hist_date DESC, h.id DESC") as $h)
            $rows[] = ['v' => [$h['hist_date'] ? fmt_date($h['hist_date']) : t('eq.undated'), $h['serial_no'], t('eq.h_' . $h['kind']), t('eq.src_' . $h['source']) . ($h['report_no'] ? ' ' . $h['report_no'] : ($h['isr_no'] ?? '' ? ' ' . $h['isr_no'] : '')), trim((string)$h['body'] . (!empty($h['parts']) ? "\n" . t('eq.parts_replaced') . ': ' . $h['parts'] : ''))]];
    }
    $T[] = ['title' => t('eq.vh_history'), 'cols' => [[t('th.date'), 20, 'L'], [t('eq.sn'), 28, 'L'], [t('eq.hkind'), 26, 'L'], [t('eq.source'), 32, 'L'], [t('eq.record_text'), 74, 'L']], 'rows' => $rows];
    return $T;
}
