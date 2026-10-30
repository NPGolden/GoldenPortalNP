<?php
/**
 * Sub-project teams — which Golden A/S engineers work on which sub-project (option A).      GAS-CP-R25-001
 *
 * The project manager or an administrator ticks the engineers on the sub-project page (Team box). An engineer
 * then sees, in the timesheet, field service report and inspection report forms, only the sub-projects he is
 * assigned to — and only while their project and the sub-project are active. Administrators and the project
 * manager of a project see all of its active sub-projects. A sub-project without a team is open to everybody
 * (nothing changes until teams are set up).
 */
declare(strict_types=1);

function tm_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'sp_team'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** User ids in the team of a sub-project. */
function tm_members(int $subId): array { return tm_has() ? array_map('intval', array_column(all('SELECT user_id FROM sp_team WHERE subproject_id = ? ORDER BY user_id', [$subId]), 'user_id')) : []; }
/** Team rows with names / disciplines for display. */
function tm_members_full(int $subId): array {
    if (!tm_has()) return [];
    return all("SELECT cu.id, cu.full_name, d.name AS discipline, t.added_at FROM sp_team t JOIN customer_users cu ON cu.id = t.user_id LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE t.subproject_id = ? AND cu.is_active = 1 ORDER BY d.sort_order, d.name, cu.full_name", [$subId]);
}
/** May this user edit the team of a sub-project? Administrators and every project manager (R57-002: not only the PM in charge). */
function tm_can_edit(int $subId, ?array $u = null): bool {
    $u = $u ?? current_user(); if (!$u || !is_staff()) return false;
    if (is_cp_admin()) return true;
    if (function_exists('pm_is_pm') && pm_is_pm((int)$u['id'])) return true;
    return (int) scalar('SELECT p.pm_user_id FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$subId]) === (int)$u['id'];
}
function tm_save(int $subId, array $userIds): void {
    if (!tm_has()) throw new RuntimeException(t('tm.need_db'));
    if (!tm_can_edit($subId)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $ids = array_values(array_unique(array_map('intval', $userIds)));
    $valid = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1"), 'id'));
    $ids = array_values(array_intersect($ids, $valid));
    $have = tm_members($subId);
    foreach (array_diff($have, $ids) as $x) q('DELETE FROM sp_team WHERE subproject_id = ? AND user_id = ?', [$subId, $x]);
    foreach (array_diff($ids, $have) as $x) q('INSERT IGNORE INTO sp_team (subproject_id, user_id, added_by) VALUES (?,?,?)', [$subId, $x, (int)$u['id']]);
}
/** Strict mode (Settings › Portal accounts): every engineer is limited to assigned sub-projects, even one with no assignment yet.
 *  Off: only engineers who have at least one assignment are limited — the open mode while teams are being set up. */
function tm_strict(): bool { return function_exists('cp_setting') && cp_setting('tm_strict', '0') === '1'; }
/** Is the signed-in staff account limited to its assignments? (never administrators) */
function tm_restricted(?array $u = null): bool {
    $u = $u ?? current_user(); if (!$u || !is_staff() || is_cp_admin() || !tm_has()) return false;
    if (tm_strict()) return true;
    static $c = [];
    $k = (int)$u['id'];
    if (!isset($c[$k])) $c[$k] = (bool) scalar('SELECT COUNT(*) FROM sp_team WHERE user_id = ?', [$k]);
    return $c[$k];
}
/** Active sub-projects the signed-in staff account may book / report on: team membership or PM of the project
 *  (administrators and unassigned staff: every active sub-project). Rows: id, project_id, code, name, project_code, project_name, vessel. */
function tm_my_subs(?array $u = null): array {
    $u = $u ?? current_user(); if (!$u || !is_staff()) return [];
    $sql = "SELECT s.id, s.project_id, s.code, s.name, p.project_code, p.name AS project_name, v.name AS vessel
            FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id
            WHERE p.status = 'active' AND s.status <> 'completed'";
    [$bs, $ba] = function_exists('br_scope_sql') ? br_scope_sql('p') : ['1 = 1', []];
    $sql .= " AND {$bs}"; $args = $ba;
    if (tm_restricted($u)) { $sql .= ' AND (p.pm_user_id = ? OR EXISTS (SELECT 1 FROM sp_team t WHERE t.subproject_id = s.id AND t.user_id = ?))'; array_push($args, (int)$u['id'], (int)$u['id']); }
    return all($sql . ' ORDER BY v.name, p.project_code, s.sort_order, s.code', $args);
}
/** Ids of the sub-projects of one project the account may use; null = no restriction. */
function tm_allowed_ids(int $projectId, ?array $u = null): ?array {
    if (!tm_restricted($u)) return null;
    return array_map('intval', array_column(array_filter(tm_my_subs($u), fn($s) => (int)$s['project_id'] === $projectId), 'id'));
}
/** Whole-project entries (FSR / ISR without a sub-project, timesheet project list) the account may use. */
function tm_my_projects(?array $u = null): array {
    $out = []; foreach (tm_my_subs($u) as $s) $out[(int)$s['project_id']] = ['id' => (int)$s['project_id'], 'project_code' => $s['project_code'], 'name' => $s['project_name'], 'vessel' => $s['vessel']];
    return array_values($out);
}
/** Staff accounts grouped by discipline for the team box. */
function tm_staff_by_discipline(): array {
    $g = [];
    foreach (all("SELECT cu.id, cu.full_name, cu.role, COALESCE(d.name, '') AS discipline, d.sort_order AS dso FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 ORDER BY ISNULL(d.sort_order), d.sort_order, d.name, cu.full_name") as $r)
        $g[$r['discipline'] !== '' ? $r['discipline'] : t('tm.no_discipline')][] = $r;
    return $g;
}
/** Sub-project codes an account is assigned to (accounts export). */
function tm_user_codes(int $uid): string {
    if (!tm_has()) return '';
    return implode(', ', array_map(fn($r) => trim($r['project_code'] . ' ' . $r['code']), all('SELECT p.project_code, s.code FROM sp_team t JOIN subprojects s ON s.id = t.subproject_id JOIN projects p ON p.id = s.project_id WHERE t.user_id = ? ORDER BY p.project_code, s.sort_order, s.code', [$uid])));
}
