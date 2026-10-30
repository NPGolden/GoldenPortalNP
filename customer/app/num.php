<?php
/**
 * Release D1 (GAS-CP-R43): automatic numbers, invoicing details per vessel, contacts per sub-project.
 *
 *  R43-001 numbering — the portal creates numbers in the ERP's own shape:
 *     project      PRJ.000496                (prefix + zero-padded sequence; next = max of the existing codes + 1, never below Settings › Numbering)
 *     sub-project  Y.001                     (prefix + sequence per project; the width follows the project's existing sub-projects)
 *     SQ           PRJ.000496.Y.001.SQ.0012  (project . sub-project . SQ . portal-wide unique id — the commercial reference of an offer)
 *     PI           PRJ.000496.Y.001.PI.0007.n (same, one unique id per proforma, n = the instalment of the payment terms — used by release D2)
 *  R43-002 invoicing details per vessel — owner type "vessel" on invoicing_details; a sub-project picks one (subprojects.invoicing_id).
 *  R43-003 contacts per sub-project — sp_contacts: the vessel contacts in relation with the sub-project, with a role; the e-mail cards pre-tick them.
 */
declare(strict_types=1);

function num_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM offers LIKE 'sq_no'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Settings (Settings › Numbering). */
function num_cfg(): array {
    $g = fn(string $k, string $d) => function_exists('cp_setting') ? (string)(cp_setting('num_' . $k, $d) ?? $d) : $d;
    return ['p_prefix' => $g('p_prefix', 'PRJ.'), 'p_digits' => max(3, min(8, (int)$g('p_digits', '6'))), 'p_next' => max(1, (int)$g('p_next', '1')),
            's_prefix' => $g('s_prefix', 'Y.'), 's_digits' => max(2, min(4, (int)$g('s_digits', '3'))), 'sq_digits' => max(3, min(6, (int)$g('sq_digits', '4'))), 'pi_digits' => max(3, min(6, (int)$g('pi_digits', '4'))), 'auto' => $g('auto', '1') === '1'];
}
function num_cfg_save_from_post(): void {
    require_cp_admin();
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    cp_setting_set('num_p_prefix', $s('p_prefix', 12) ?: 'PRJ.'); cp_setting_set('num_p_digits', (string) max(3, min(8, (int)($_POST['p_digits'] ?? 6))));
    cp_setting_set('num_p_next', (string) max(1, (int) preg_replace('/\D/', '', (string)($_POST['p_next'] ?? '1'))));
    cp_setting_set('num_s_prefix', $s('s_prefix', 12) ?: 'Y.'); cp_setting_set('num_s_digits', (string) max(2, min(4, (int)($_POST['s_digits'] ?? 3))));
    cp_setting_set('num_sq_digits', (string) max(3, min(6, (int)($_POST['sq_digits'] ?? 4)))); cp_setting_set('num_pi_digits', (string) max(3, min(6, (int)($_POST['pi_digits'] ?? 4))));
    cp_setting_set('num_auto', !empty($_POST['auto']) ? '1' : '0');
    foreach (['tz_app', 'tz_db'] as $k) if (isset($_POST[$k])) { $v = trim((string)$_POST[$k]); cp_setting_set($k, in_array($v, timezone_identifiers_list(), true) ? $v : null); }   // R46-002
}
/** The next project number: max of the existing project / ERP codes with the prefix + 1, never below the configured "next". */
function num_next_project(): string {
    $c = num_cfg(); $max = 0; $pre = $c['p_prefix'];
    foreach (all('SELECT project_code, erp_code FROM projects') as $r) foreach ([$r['project_code'], $r['erp_code']] as $code) {
        if ($code && str_starts_with((string)$code, $pre) && preg_match('/^(\d+)/', substr((string)$code, strlen($pre)), $m)) $max = max($max, (int)$m[1]);
    }
    return $pre . str_pad((string) max($max + 1, $c['p_next']), $c['p_digits'], '0', STR_PAD_LEFT);
}
/** The next sub-project code of a project (Y.001 …); the width follows the sub-projects already there (Y.01 → Y.02). */
function num_next_sub(int $pid): string {
    $c = num_cfg(); $pre = $c['s_prefix']; $max = 0; $width = $c['s_digits'];
    foreach (all('SELECT code FROM subprojects WHERE project_id = ?', [$pid]) as $r) if ($r['code'] && str_starts_with((string)$r['code'], $pre) && preg_match('/^(\d+)/', substr((string)$r['code'], strlen($pre)), $m)) { $max = max($max, (int)$m[1]); $width = strlen($m[1]); }
    return $pre . str_pad((string)($max + 1), $width, '0', STR_PAD_LEFT);
}
/** Portal-wide unique id for SQ / PI numbers (doc_sequences, scope "portal"). */
function num_unique(string $family): string {
    $c = num_cfg(); $d = $family === 'PI' ? $c['pi_digits'] : $c['sq_digits'];
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['portal', $family]);
    return str_pad((string)(int) db()->lastInsertId(), $d, '0', STR_PAD_LEFT);
}
/** "<project>.<sub>." — the stem of SQ / PI numbers; the sub-project part is left out for a project-level document. */
function num_stem(int $pid, ?int $sid): string {
    $p = one('SELECT project_code, name FROM projects WHERE id = ?', [$pid]); if (!$p) return '';
    $code = (string)($p['project_code'] ?: $p['name']); if (str_starts_with($code, 'GEN-')) return '';
    $sub = $sid ? (string) scalar('SELECT code FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid]) : '';
    return $code . '.' . ($sub !== '' ? $sub . '.' : '');
}
/** SQ number for a new offer (null for vessel-in-general offers). */
function num_sq(int $pid, ?int $sid): ?string { if (!num_has()) return null; $stem = num_stem($pid, $sid); return $stem === '' ? null : $stem . 'SQ.' . num_unique('SQ'); }
/** PI number — one unique id per proforma, the instalment n appended (release D2). */
function num_pi(int $pid, ?int $sid, int $n): ?string { $stem = num_stem($pid, $sid); return $stem === '' ? null : $stem . 'PI.' . num_unique('PI') . '.' . $n; }
/** Is this code one the portal would have generated (for the "portal number" pill)? */
function num_is_portal(?string $code): bool { $c = num_cfg(); return $code !== null && $code !== '' && preg_match('/^' . preg_quote($c['p_prefix'], '/') . '\d{' . $c['p_digits'] . ',}$/', $code) === 1; }

/* ------------------------------------------------------------------ R43-002 invoicing details per vessel */
function iv_vessel_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('iv_has') && iv_has() && (bool) scalar("SHOW COLUMNS FROM subprojects LIKE 'invoicing_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** The invoicing details a sub-project may use: the vessel's (first) then the customer's; [id => row]. */
function iv_choices_for_sub(array $sp): array {
    if (!iv_vessel_has()) return [];
    $out = [];
    if (!empty($sp['vessel_id'])) foreach (iv_list('vessel', (int)$sp['vessel_id'], true) as $r) $out[(int)$r['id']] = $r + ['src' => 'vessel'];
    if (!empty($sp['customer_id'])) foreach (iv_list('customer', (int)$sp['customer_id'], true) as $r) $out[(int)$r['id']] = $r + ['src' => 'customer'];
    return $out;
}
/** The invoicing details of a sub-project: the chosen one, else the vessel's default, else the customer's default. */
function iv_for_sub(array $sp): ?array {
    if (!iv_vessel_has()) return null;
    if (!empty($sp['invoicing_id'])) { $r = iv_load((int)$sp['invoicing_id']); if ($r && $r['is_active']) return $r; }
    if (!empty($sp['vessel_id']) && ($r = iv_default('vessel', (int)$sp['vessel_id']))) return $r;
    return !empty($sp['customer_id']) ? iv_default('customer', (int)$sp['customer_id']) : null;
}
function iv_sub_save(int $sid): void {
    if (!iv_vessel_has() || !array_key_exists('invoicing_id', $_POST)) return;
    $id = (int)$_POST['invoicing_id'] ?: null; $sp = load_subproject($sid); if (!$sp) return;
    if ($id && !isset(iv_choices_for_sub($sp)[$id])) $id = null;
    q('UPDATE subprojects SET invoicing_id = ? WHERE id = ?', [$id, $sid]);
}
function iv_label(array $r): string { return trim((string)($r['label'] ?: $r['legal_name'])) . ($r['vat_no'] ? ' · ' . $r['vat_no'] : ''); }

/* ------------------------------------------------------------------ R43-003 contacts per sub-project */
function spc_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('ct_has') && ct_has() && (bool) scalar("SHOW TABLES LIKE 'sp_contacts'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** [contact_id => role] of a sub-project. */
function spc_of(int $sid): array { $o = []; if (spc_has()) foreach (all('SELECT contact_id, role FROM sp_contacts WHERE subproject_id = ?', [$sid]) as $r) $o[(int)$r['contact_id']] = (string)($r['role'] ?? ''); return $o; }
/** The contact rows (vessel contacts) in relation with the sub-project, with their sub-project role. */
function spc_rows(int $sid): array {
    if (!spc_has()) return [];
    return all('SELECT c.*, x.role AS sp_role, cv.role AS vessel_role, co.name AS company_name FROM sp_contacts x JOIN contacts c ON c.id = x.contact_id LEFT JOIN subprojects s ON s.id = x.subproject_id LEFT JOIN projects p ON p.id = s.project_id LEFT JOIN contact_vessels cv ON cv.contact_id = c.id AND cv.vessel_id = p.vessel_id LEFT JOIN companies co ON co.id = c.company_id WHERE x.subproject_id = ? ORDER BY c.full_name', [$sid]);
}
/** E-mails of the sub-project contacts (for pre-ticking the e-mail cards). */
function spc_emails(int $sid): array { return array_values(array_filter(array_map(fn($c) => strtolower(trim((string)$c['email'])), spc_rows($sid)))); }
function spc_save_from_post(int $sid): int {
    require_staff(); if (!spc_has()) throw new RuntimeException(t('spc.need_db'));
    $sp = load_subproject($sid); if (!$sp) throw new RuntimeException(t('err.notfound'));
    $allowed = []; if (!empty($sp['vessel_id'])) foreach (ct_for_vessel((int)$sp['vessel_id']) as $c) $allowed[(int)$c['id']] = true;
    $ids = array_values(array_filter(array_unique(array_map('intval', (array)($_POST['contact_ids'] ?? []))), fn($i) => isset($allowed[$i])));
    $roles = (array)($_POST['role'] ?? []); $u = current_user();
    q('DELETE FROM sp_contacts WHERE subproject_id = ?' . ($ids ? ' AND contact_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : ''), array_merge([$sid], $ids));
    foreach ($ids as $cid) q('INSERT INTO sp_contacts (subproject_id, contact_id, role, added_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$sid, $cid, mb_substr(trim((string)($roles[$cid] ?? '')), 0, 80) ?: null, (int)$u['id']]);
    return count($ids);
}
/** Sub-projects a contact is in relation with (for the contact page). */
function spc_for_contact(int $cid): array { return spc_has() ? all('SELECT s.id, s.code, s.name, x.role, p.project_code, p.name AS project_name FROM sp_contacts x JOIN subprojects s ON s.id = x.subproject_id JOIN projects p ON p.id = s.project_id WHERE x.contact_id = ? ORDER BY p.project_code, s.code', [$cid]) : []; }
