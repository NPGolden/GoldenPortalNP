<?php
/** HR › Job descriptions (GAS-CP-R97-121).
 *  One job description per position of a discipline (optionally per level). HR writes it (by hand, from the company's own set, or with the assistant),
 *  the Discipline Manager, the General Manager and the CEO approve it in that order, and only an approved issue is shown to employees, used on
 *  vacancies and printed. Changing an approved one opens revision R[n+1]; the approved issue stays in force until the new one is approved.
 *  Tables: hr_jds, hr_jd_revs (approved issues), hr_jd_acks (read by the employee); hr_people.jd_id; rc_vacancies.jd_id.
 *  Nothing personal is sent to the assistant: only the title, discipline, level and the brief typed by HR. */
declare(strict_types=1);

const JD_LEVELS   = ['', 'junior', 'mid', 'senior', 'lead'];
const JD_SECTIONS = ['duties', 'qualifications', 'skills', 'personal', 'desirable', 'conditions'];
const JD_STEPS    = ['dm', 'gm', 'ceo'];
const JD_STATES   = ['draft', 'review', 'approved', 'retired'];

function jd_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('hr2_has') && hr2_has() && (bool) scalar("SHOW TABLES LIKE 'hr_jds'"); } catch (Throwable $e) { $h = false; } } return $h; }
function jd_me(): int { return (int)(current_user()['id'] ?? 0); }
/** HR, administrators and Management write and maintain job descriptions. */
function jd_manage(): bool { return jd_has() && is_staff() && ((function_exists('hr_can') && hr_can()) || is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())); }
function jd_is_gm(): bool { return is_staff() && function_exists('cp_role_name') && in_array(cp_role_name(), ['general manager', 'managing director'], true); }
function jd_is_ceo(): bool { return is_staff() && ((function_exists('cp_role_name') && cp_role_name() === 'ceo') || (function_exists('hr_owner') && hr_owner())); }
/** The accounts that hold an approval step of this job description (empty = nobody holds it: the step is skipped). */
function jd_step_users(array $jd, string $step): array {
    static $c = [];
    if ($step === 'dm') return !empty($jd['dm_user_id']) ? [(int)$jd['dm_user_id']] : [];
    if (!isset($c[$step])) {
        try {
            $c[$step] = array_map('intval', array_column(all($step === 'gm'
                ? "SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND LOWER(TRIM(r.name)) IN ('general manager','managing director')"
                : "SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (LOWER(TRIM(r.name)) = 'ceo' OR r.scope = 'owner')"), 'id'));
        } catch (Throwable $e) { $c[$step] = []; }
    }
    return $c[$step];
}
function jd_holds(array $jd, string $step): bool { return in_array(jd_me(), jd_step_users($jd, $step), true); }
function jd_lines(?string $s): array { return array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^\s*(?:[-–—•·*▪■]|\d{1,2}[.)])\s+/u', '', (string)$l)), preg_split('/\r?\n/', (string)$s)), 'strlen')); }
function jd_level_label(string $lv): string { return $lv === '' ? t('jd.lv_any') : t('jd.lv_' . $lv); }
function jd_state_pill(string $st): string { return pill(['draft' => 'mute', 'review' => 'warn', 'approved' => 'green', 'retired' => 'mute'][$st] ?? 'mute', t('jd.st_' . $st)); }
function jd_rev(array $jd): string { return 'R' . (int)$jd['rev']; }

function jd_load(int $id): ?array {
    if (!jd_has() || $id <= 0) return null;
    return one('SELECT j.*, d.name AS discipline, d.manager_user_id AS dm_user_id, cu.full_name AS dm_user_name FROM hr_jds j LEFT JOIN disciplines d ON d.id = j.discipline_id LEFT JOIN customer_users cu ON cu.id = d.manager_user_id WHERE j.id = ?', [$id]) ?: null;
}
/** Who may open a job description: those who maintain it, its approvers, and the employee it is assigned to. */
function jd_can_view(array $jd): bool {
    if (!is_staff() || !jd_has()) return false;
    if (jd_manage() || jd_is_gm() || jd_is_ceo() || jd_holds($jd, 'dm')) return true;
    $p = function_exists('hr_person_of_user') ? hr_person_of_user(jd_me()) : null;
    return $p && (int)($p['jd_id'] ?? 0) === (int)$jd['id'];
}
/** The list a person may see: everything for HR / Management / GM / CEO, the own disciplines for a discipline manager. */
function jd_list(array $f = []): array {
    if (!jd_has() || !is_staff()) return [];
    $w = ['1 = 1']; $a = [];
    if (!(jd_manage() || jd_is_gm() || jd_is_ceo())) { $w[] = 'd.manager_user_id = ?'; $a[] = jd_me(); }
    if (!empty($f['discipline'])) { $w[] = 'j.discipline_id = ?'; $a[] = (int)$f['discipline']; }
    if (!empty($f['status']) && in_array($f['status'], JD_STATES, true)) { $w[] = 'j.status = ?'; $a[] = $f['status']; }
    elseif (($f['status'] ?? '') !== 'all') $w[] = "j.status <> 'retired'";
    if (!empty($f['q'])) { $w[] = '(j.title LIKE ? OR j.code LIKE ? OR j.purpose LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($a, $q, $q, $q); }
    return all('SELECT j.*, d.name AS discipline, d.manager_user_id AS dm_user_id, cu.full_name AS dm_user_name,
                       (SELECT COUNT(*) FROM hr_people p WHERE p.jd_id = j.id AND p.is_active = 1) AS people_n,
                       (SELECT MAX(r.rev) FROM hr_jd_revs r WHERE r.jd_id = j.id) AS issued_rev
                  FROM hr_jds j LEFT JOIN disciplines d ON d.id = j.discipline_id LEFT JOIN customer_users cu ON cu.id = d.manager_user_id
                 WHERE ' . implode(' AND ', $w) . ' ORDER BY d.sort_order, d.name, j.title, j.level', $a);
}
function jd_code(): string { $n = (int) scalar("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)), 0) FROM hr_jds WHERE code LIKE 'JD-%'"); return 'JD-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT); }

/* ------------------------------------------------------------------ write */
function jd_fields_from(array $in): array {
    $v = ['title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 190), 'discipline_id' => (int)($in['discipline_id'] ?? 0) ?: null,
          'level' => in_array((string)($in['level'] ?? ''), JD_LEVELS, true) ? (string)($in['level'] ?? '') : '', 'reports_to' => mb_substr(trim((string)($in['reports_to'] ?? '')), 0, 190) ?: null,
          'purpose' => mb_substr(trim((string)($in['purpose'] ?? '')), 0, 3000) ?: null];
    foreach (JD_SECTIONS as $k) $v[$k] = mb_substr(implode("\n", array_slice(jd_lines(is_array($in[$k] ?? null) ? implode("\n", $in[$k]) : (string)($in[$k] ?? '')), 0, 40)), 0, 8000) ?: null;
    return $v;
}
function jd_save_from_post(int $id): int {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess'));
    $v = jd_fields_from($_POST); if ($v['title'] === '') throw new RuntimeException(t('jd.need_title'));
    if ($v['discipline_id'] && !scalar('SELECT 1 FROM disciplines WHERE id = ?', [$v['discipline_id']])) $v['discipline_id'] = null;
    $u = current_user();
    if ($id) {
        $jd = jd_load($id); if (!$jd || $jd['status'] !== 'draft') throw new RuntimeException(t('jd.locked'));
        q('UPDATE hr_jds SET ' . implode(' = ?, ', array_keys($v)) . ' = ? WHERE id = ?', [...array_values($v), $id]);
    } else {
        $v += ['code' => jd_code(), 'status' => 'draft', 'rev' => 0, 'rev_title' => t('jd.rev_first'), 'created_by' => (int)$u['id'], 'created_name' => (string)$u['full_name']];
        q('INSERT INTO hr_jds (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')', array_values($v)); $id = (int) db()->lastInsertId();
    }
    unset($_SESSION['jd_ai'][$id], $_SESSION['jd_ai'][0], $_SESSION['jd_ai_msg']);
    return $id;
}
function jd_delete(int $id): void {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $jd = jd_load($id); if (!$jd) throw new RuntimeException(t('err.notfound'));
    if ($jd['status'] !== 'draft' || (int)$jd['rev'] > 0 || scalar('SELECT 1 FROM hr_jd_revs WHERE jd_id = ?', [$id])) throw new RuntimeException(t('jd.no_delete'));
    q('UPDATE hr_people SET jd_id = NULL WHERE jd_id = ?', [$id]); try { q('UPDATE rc_vacancies SET jd_id = NULL WHERE jd_id = ?', [$id]); } catch (Throwable $e) {}
    q('DELETE FROM hr_jds WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------ approval: Discipline Manager → General Manager → CEO */
/** The three steps with their state: done (name, date) · wait (who is expected) · skip (nobody holds the step). */
function jd_steps(array $jd): array {
    $out = []; $nextSeen = false;
    foreach (JD_STEPS as $s) {
        $users = jd_step_users($jd, $s); $at = $jd[$s . '_at'] ?? null;
        $st = $at ? 'done' : (!$users ? 'skip' : 'wait'); $isNext = false;
        if ($st === 'wait' && !$nextSeen && $jd['status'] === 'review') { $isNext = true; $nextSeen = true; }
        $out[$s] = ['key' => $s, 'label' => t('jd.step_' . $s), 'state' => $st, 'name' => (string)($jd[$s . '_name'] ?? ''), 'at' => $at, 'users' => $users, 'next' => $isNext, 'mine' => $isNext && in_array(jd_me(), $users, true)];
    }
    return $out;
}
function jd_next_step(array $jd): ?string { foreach (jd_steps($jd) as $s) if ($s['next']) return $s['key']; return null; }
function jd_notify(array $jd, array $uids, string $titleKey, ?string $body = null): void {
    if (!function_exists('nf_push')) return; $u = current_user(); $uids = array_values(array_diff(array_unique(array_filter(array_map('intval', $uids))), [jd_me()])); if (!$uids) return;
    try { nf_push($uids, 'jd', 'jd:' . (int)$jd['id'] . ':' . $titleKey . ':' . (int)$jd['rev'], t($titleKey, ['c' => $jd['code'], 't' => $jd['title'], 'r' => jd_rev($jd)]), $body, url('hr_jd', ['id' => (int)$jd['id']]), (int)$u['id'], (string)$u['full_name']); } catch (Throwable $e) {}
}
function jd_submit(int $id): void {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $jd = jd_load($id); if (!$jd || $jd['status'] !== 'draft') throw new RuntimeException(t('jd.locked'));
    if (trim((string)$jd['purpose']) === '' || !jd_lines($jd['duties'])) throw new RuntimeException(t('jd.need_body'));
    $u = current_user();
    q("UPDATE hr_jds SET status = 'review', submitted_at = NOW(), submitted_name = ?, reject_note = NULL, dm_by = NULL, dm_name = NULL, dm_at = NULL, gm_by = NULL, gm_name = NULL, gm_at = NULL, ceo_by = NULL, ceo_name = NULL, ceo_at = NULL WHERE id = ?", [(string)$u['full_name'], $id]);
    $jd = jd_load($id); $next = jd_next_step($jd);
    if ($next === null) { jd_finish($jd); return; }   // nobody holds any step — issued at once (shown as such on the page)
    jd_notify($jd, jd_step_users($jd, $next), 'jd.nf_review');
}
/** Sign the step that is waiting, and the following ones when the same person holds them too. */
function jd_approve(int $id): int {
    $jd = jd_load($id); if (!$jd || $jd['status'] !== 'review') throw new RuntimeException(t('jd.not_review'));
    $u = current_user(); $n = 0;
    while (($s = jd_next_step($jd)) !== null && jd_holds($jd, $s)) {
        q("UPDATE hr_jds SET {$s}_by = ?, {$s}_name = ?, {$s}_at = NOW() WHERE id = ? AND status = 'review' AND {$s}_at IS NULL", [(int)$u['id'], (string)$u['full_name'], $id]);
        $n++; $jd = jd_load($id);
    }
    if (!$n) throw new RuntimeException(t('jd.not_yours'));
    $next = jd_next_step($jd);
    if ($next === null) jd_finish($jd); else jd_notify($jd, jd_step_users($jd, $next), 'jd.nf_review');
    return $n;
}
/** All steps signed (or skipped): the issue is approved and kept as revision R[n]. */
function jd_finish(array $jd): void {
    q("UPDATE hr_jds SET status = 'approved' WHERE id = ?", [(int)$jd['id']]); $jd = jd_load((int)$jd['id']);
    $snap = jd_content($jd);
    q('REPLACE INTO hr_jd_revs (jd_id, rev, title, body, approved_at) VALUES (?,?,?,?,NOW())', [(int)$jd['id'], (int)$jd['rev'], mb_substr((string)($jd['rev_title'] ?: t('jd.rev_first')), 0, 190), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
    jd_notify($jd, array_merge([(int)$jd['created_by']], array_map('intval', array_column(all('SELECT user_id FROM hr_people WHERE jd_id = ? AND is_active = 1 AND user_id IS NOT NULL', [(int)$jd['id']]), 'user_id'))), 'jd.nf_approved');
}
function jd_reject(int $id, string $note): void {
    $jd = jd_load($id); if (!$jd || $jd['status'] !== 'review') throw new RuntimeException(t('jd.not_review'));
    $s = jd_next_step($jd); if (!($s !== null && jd_holds($jd, $s)) && !jd_is_ceo() && !jd_is_gm()) throw new RuntimeException(t('jd.not_yours'));
    $note = mb_substr(trim($note), 0, 500); if ($note === '') throw new RuntimeException(t('jd.need_note'));
    $u = current_user();
    q("UPDATE hr_jds SET status = 'draft', reject_note = ?, dm_by = NULL, dm_name = NULL, dm_at = NULL, gm_by = NULL, gm_name = NULL, gm_at = NULL, ceo_by = NULL, ceo_name = NULL, ceo_at = NULL WHERE id = ?", [(string)$u['full_name'] . ' — ' . $note, $id]);
    jd_notify($jd, [(int)$jd['created_by']], 'jd.nf_returned', $note);
}
/** Open the next revision of an approved job description (the approved issue stays in force until the new one is approved). */
function jd_revise(int $id, string $title): void {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $jd = jd_load($id); if (!$jd || !in_array($jd['status'], ['approved', 'retired'], true)) throw new RuntimeException(t('jd.locked'));
    $title = mb_substr(trim($title), 0, 190); if ($title === '') throw new RuntimeException(t('jd.need_rev_title'));
    q("UPDATE hr_jds SET status = 'draft', rev = rev + 1, rev_title = ?, submitted_at = NULL, submitted_name = NULL, reject_note = NULL, dm_by = NULL, dm_name = NULL, dm_at = NULL, gm_by = NULL, gm_name = NULL, gm_at = NULL, ceo_by = NULL, ceo_name = NULL, ceo_at = NULL WHERE id = ?", [$title, $id]);
}
function jd_retire(int $id, bool $on): void {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $jd = jd_load($id); if (!$jd) throw new RuntimeException(t('err.notfound'));
    if ($on && $jd['status'] !== 'approved') throw new RuntimeException(t('jd.locked')); if (!$on && $jd['status'] !== 'retired') throw new RuntimeException(t('jd.locked'));
    q('UPDATE hr_jds SET status = ? WHERE id = ?', [$on ? 'retired' : 'approved', $id]);
}

/* ------------------------------------------------------------------ the approved issue */
/** The content of a row as one array (also the format kept in hr_jd_revs.body). */
function jd_content(array $jd): array {
    $c = ['id' => (int)$jd['id'], 'code' => (string)$jd['code'], 'title' => (string)$jd['title'], 'discipline' => (string)($jd['discipline'] ?? ''), 'discipline_id' => (int)($jd['discipline_id'] ?? 0), 'level' => (string)$jd['level'],
          'reports_to' => (string)($jd['reports_to'] ?? ''), 'purpose' => (string)($jd['purpose'] ?? ''), 'rev' => (int)$jd['rev'], 'rev_title' => (string)($jd['rev_title'] ?? ''), 'status' => (string)$jd['status'],
          'prepared' => (string)($jd['submitted_name'] ?: ($jd['created_name'] ?? '')), 'approved_at' => $jd['ceo_at'] ?: ($jd['gm_at'] ?: ($jd['dm_at'] ?: ($jd['submitted_at'] ?? null))), 'approvals' => []];
    foreach (JD_SECTIONS as $k) $c[$k] = jd_lines($jd[$k] ?? '');
    foreach (JD_STEPS as $s) $c['approvals'][$s] = ['name' => (string)($jd[$s . '_name'] ?? ''), 'at' => $jd[$s . '_at'] ?? null];
    return $c;
}
/** What is in force: the row itself when approved, otherwise the last approved issue (null when there has never been one). */
function jd_current(array $jd): ?array {
    if ($jd['status'] === 'approved') return jd_content($jd);
    $r = one('SELECT body, approved_at FROM hr_jd_revs WHERE jd_id = ? ORDER BY rev DESC LIMIT 1', [(int)$jd['id']]); if (!$r) return null;
    $c = json_decode((string)$r['body'], true); if (!is_array($c)) return null; $c['status'] = 'approved'; $c['approved_at'] = $c['approved_at'] ?? $r['approved_at'];
    foreach (JD_SECTIONS as $k) $c[$k] = array_values(array_filter(array_map('strval', (array)($c[$k] ?? [])), 'strlen'));
    return $c;
}
function jd_revs(int $id): array { return all('SELECT rev, title, approved_at FROM hr_jd_revs WHERE jd_id = ? ORDER BY rev', [$id]); }
/** Approved job descriptions to choose from (employee, vacancy). */
function jd_options(): array { return jd_has() ? all("SELECT j.id, j.code, j.title, j.level, j.discipline_id, d.name AS discipline, j.status FROM hr_jds j LEFT JOIN disciplines d ON d.id = j.discipline_id WHERE j.status = 'approved' OR EXISTS (SELECT 1 FROM hr_jd_revs r WHERE r.jd_id = j.id AND j.status <> 'retired') ORDER BY d.sort_order, d.name, j.title, j.level") : []; }
function jd_option_label(array $o): string { return $o['code'] . ' · ' . $o['title'] . ($o['level'] !== '' ? ' (' . jd_level_label((string)$o['level']) . ')' : '') . (!empty($o['discipline']) ? ' — ' . $o['discipline'] : ''); }

/* ------------------------------------------------------------------ employees */
function jd_people(int $jdId): array { return all('SELECT p.id, p.name_en, p.job_title, p.department_id, p.user_id, a.acked_at, a.rev AS ack_rev FROM hr_people p LEFT JOIN hr_jd_acks a ON a.jd_id = p.jd_id AND a.person_id = p.id AND a.rev = (SELECT MAX(a2.rev) FROM hr_jd_acks a2 WHERE a2.jd_id = p.jd_id AND a2.person_id = p.id) WHERE p.jd_id = ? AND p.is_active = 1 ORDER BY p.name_en', [$jdId]); }
/** Tick list on the job description: the ticked employees get it, an employee unticked here loses it. */
function jd_assign_from_post(int $id): int {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $jd = jd_load($id); if (!$jd) throw new RuntimeException(t('err.notfound'));
    $want = array_unique(array_filter(array_map('intval', (array)($_POST['people'] ?? [])))); if (count($want) > 500) throw new RuntimeException(t('err.noaccess'));
    $ok = $want ? array_map('intval', array_column(all('SELECT id FROM hr_people WHERE id IN (' . implode(',', $want) . ')'), 'id')) : [];
    q('UPDATE hr_people SET jd_id = NULL WHERE jd_id = ?' . ($ok ? ' AND id NOT IN (' . implode(',', $ok) . ')' : ''), [$id]);
    if ($ok) q('UPDATE hr_people SET jd_id = ? WHERE id IN (' . implode(',', $ok) . ')', [$id]);
    return count($ok);
}
function jd_person_set(int $personId, int $jdId): void {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); if (!function_exists('hr_person') || !hr_person($personId)) throw new RuntimeException(t('err.notfound'));
    if ($jdId && !jd_load($jdId)) throw new RuntimeException(t('err.notfound'));
    q('UPDATE hr_people SET jd_id = ? WHERE id = ?', [$jdId ?: null, $personId]);
}
/** The job description of the signed-in employee: ['person', 'jd' (row), 'c' (issue in force or null), 'ack' (row or null)]. */
function jd_mine(): ?array {
    if (!jd_has() || !function_exists('hr_person_of_user')) return null; $p = hr_person_of_user(jd_me()); if (!$p) return null;
    $jid = (int) scalar('SELECT jd_id FROM hr_people WHERE id = ?', [(int)$p['id']]); $jd = $jid ? jd_load($jid) : null; $c = $jd ? jd_current($jd) : null;
    $ack = $c ? (one('SELECT * FROM hr_jd_acks WHERE jd_id = ? AND person_id = ? AND rev = ?', [$jid, (int)$p['id'], (int)$c['rev']]) ?: null) : null;
    return ['person' => $p, 'jd' => $jd, 'c' => $c, 'ack' => $ack];
}
function jd_ack(): void {
    $m = jd_mine(); if (!$m || !$m['c']) throw new RuntimeException(t('err.notfound'));
    q('INSERT IGNORE INTO hr_jd_acks (jd_id, person_id, rev, acked_at) VALUES (?,?,?,NOW())', [(int)$m['jd']['id'], (int)$m['person']['id'], (int)$m['c']['rev']]);
}

/* ------------------------------------------------------------------ vacancies */
/** The texts a vacancy takes from the approved issue. */
function jd_vacancy_fields(array $c): array {
    $desc = [];
    if ($c['duties']) { $desc[] = t('jd.s_duties') . ':'; foreach ($c['duties'] as $l) $desc[] = '• ' . $l; }
    if ($c['conditions']) { $desc[] = ''; $desc[] = t('jd.s_conditions') . ':'; foreach ($c['conditions'] as $l) $desc[] = '• ' . $l; }
    $req = array_merge($c['qualifications'], $c['skills'], $c['personal']); if ($c['desirable']) foreach ($c['desirable'] as $l) $req[] = t('jd.desirable_pfx') . ' ' . $l;
    return ['title' => $c['title'], 'summary' => mb_substr((string)$c['purpose'], 0, 600), 'description_html' => implode("\n", $desc), 'requirements' => implode("\n", $req),
            'department_id' => (string)((int)$c['discipline_id'] ?: ''), 'seniority' => in_array($c['level'], ['junior', 'mid', 'senior', 'lead'], true) ? $c['level'] : ''];
}

/* ------------------------------------------------------------------ the company's set */
function jd_seed_rows(): array { static $r = null; if ($r === null) $r = require __DIR__ . '/jd_seed.php'; return $r; }
function jd_seed_missing(): int { if (!jd_has()) return 0; $have = array_column(all('SELECT seed_key FROM hr_jds WHERE seed_key IS NOT NULL'), 'seed_key'); return count(array_filter(jd_seed_rows(), fn($r) => !in_array($r['key'], $have, true))); }
/** Create, as drafts, the job descriptions of the company's set that are not in the portal yet. */
function jd_seed(): int {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $u = current_user(); $n = 0;
    $have = array_column(all('SELECT seed_key FROM hr_jds WHERE seed_key IS NOT NULL'), 'seed_key'); $disc = []; foreach (all('SELECT id, name FROM disciplines') as $d) $disc[mb_strtolower(trim((string)$d['name']))] = (int)$d['id'];
    foreach (jd_seed_rows() as $r) {
        if (in_array($r['key'], $have, true)) continue;
        $v = ['code' => jd_code(), 'seed_key' => $r['key'], 'title' => $r['title'], 'discipline_id' => $disc[mb_strtolower($r['discipline'])] ?? null, 'level' => $r['level'], 'reports_to' => $r['reports_to'], 'purpose' => $r['purpose'],
              'status' => 'draft', 'rev' => 0, 'rev_title' => t('jd.rev_first'), 'created_by' => (int)$u['id'], 'created_name' => (string)$u['full_name']];
        foreach (JD_SECTIONS as $k) $v[$k] = implode("\n", $r[$k]) ?: null;
        q('INSERT INTO hr_jds (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')', array_values($v)); $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ assistant */
function jd_ai_page(): ?string { if (!function_exists('ai_can')) return null; foreach (['hr_jd', 'rcr_vacancy'] as $pg) if (ai_can($pg)) return $pg; return null; }
/** Draft or improve the fields with the assistant. The draft is kept in the session and shown in the form; nothing is saved until Save.
 *  Sent: the brief typed by HR and the current form values (title, discipline, level, texts). No employee data. */
function jd_ai_draft(int $id, string $brief, array $form): array {
    if (!jd_manage()) throw new RuntimeException(t('err.noaccess')); $page = jd_ai_page(); if (!$page) throw new RuntimeException(t('jd.ai_off'));
    $cur = jd_fields_from($form); $fields = [];
    $fields[] = ['name' => 'title', 'label' => 'Job title', 'type' => 'text', 'value' => (string)$cur['title']];
    $fields[] = ['name' => 'discipline_id', 'label' => 'Discipline', 'type' => 'select', 'value' => (string)($cur['discipline_id'] ?? ''), 'options' => array_map(fn($d) => ['value' => (string)$d['id'], 'label' => (string)$d['name']], function_exists('hr_departments') ? hr_departments() : [])];
    $fields[] = ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'value' => (string)$cur['level'], 'options' => [['value' => 'junior', 'label' => 'Junior'], ['value' => 'mid', 'label' => 'Mid-senior'], ['value' => 'senior', 'label' => 'Senior'], ['value' => 'lead', 'label' => 'Lead / Manager']]];
    $fields[] = ['name' => 'reports_to', 'label' => 'Reports to (a position, never a person\'s name)', 'type' => 'text', 'value' => (string)($cur['reports_to'] ?? '')];
    $fields[] = ['name' => 'purpose', 'label' => 'Purpose of the role (one paragraph, 40 to 80 words)', 'type' => 'textarea', 'value' => (string)($cur['purpose'] ?? '')];
    foreach (['duties' => 'Main responsibilities (8 to 14 lines, each starting with a verb)', 'qualifications' => 'Qualifications and experience (education, years, languages; 3 to 6 lines)', 'skills' => 'Technical skills and tools (software, standards, class rules; 3 to 8 lines)', 'personal' => 'Personal skills (4 to 8 lines)', 'desirable' => 'Desirable — not required (0 to 6 lines)', 'conditions' => 'Working conditions (place of work, travel, work on board; 2 to 4 lines)'] as $k => $lbl)
        $fields[] = ['name' => $k, 'label' => $lbl . ' — ONE ITEM PER LINE, no bullets or numbers', 'type' => 'textarea', 'value' => (string)($cur[$k] ?? '')];
    $prompt = ($brief !== '' ? $brief . "\n\n" : '') . "Write this JOB DESCRIPTION for Golden A/S (marine electrical, automation and navigation systems integrator; superyacht newbuilding, refit and conversion, offshore and industrial projects; head office and workshop in Keratsini / Piraeus; engineers work at shipyards across Europe). "
        . "A job description is the internal reference of what the company expects from the holder of the position — not an advertisement: no \"we are seeking\", no benefits, no salary, no company promotion. "
        . "Use the brief and the current form values; keep what is already filled unless the brief changes it, and improve wording, order and consistency. British English, factual, specific to the trade (switchboards, PLC / SCADA, IAS / AMS, power management, navigation and communication equipment, EPLAN, class rules DNV / ABS / LR / BV / RINA) only where it fits the role. "
        . "Scale the expectations to the level: junior = works under guidance; mid = works independently on defined scopes; senior = leads the technical solution and mentors; lead = manages the team, the planning and the budget of the discipline. "
        . "Pick discipline_id and level from the listed options when the brief implies them. Never name a person. Do not invent certificates or legal requirements.";
    $r = ai_run('fill', ['title' => t('jd.title'), 'headline' => t('jd.ai_hint'), 'fields' => $fields, 'page' => 'hr_jd'], $prompt, null, $page);
    if (!empty($r['ok'])) {
        $f = array_map(fn($x) => strip_tags((string)$x), (array)$r['fields']);
        if (isset($f['discipline_id']) && !ctype_digit($f['discipline_id'])) unset($f['discipline_id']); if (isset($f['level']) && !in_array($f['level'], JD_LEVELS, true)) unset($f['level']);
        $_SESSION['jd_ai'][$id] = ['fields' => $f, 'notes' => (string)($r['notes'] ?? ''), 'at' => time()];
    }
    return $r;
}

/* ------------------------------------------------------------------ PDF */
function jd_general_clauses(): array {
    return [t('jd.g_equal_t') => t('jd.g_equal'), t('jd.g_hs_t') => t('jd.g_hs'), t('jd.g_conf_t') => t('jd.g_conf'), t('jd.g_data_t') => t('jd.g_data')];
}
function jd_doc_no(array $c): string { return 'GAS-HR-' . $c['code']; }
/** The issue as a PDF; with $person, the last page carries the employee's name and the date he/she read it. */
function jd_pdf(array $c, array $revs = [], ?array $person = null, ?array $ack = null): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $navy = '#1F3A5F'; $gold = '#C9A227'; $green = '#2E7D32'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $docNo = jd_doc_no($c); $revTxt = 'R' . (int)$c['rev'];
    $bd = function_exists('br_doc') ? br_doc(null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $revTxt) {
        $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], $L, 10, 45, 16);
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($R, 18, mb_strtoupper(t('jd.one')), 'R');
        $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($R, 24, $docNo . ' · ' . $revTxt, 'R');
        $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 37;
    };
    $footer = function () use ($pdf, $L, $R, $bd, $docNo, $revTxt) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo . ' ' . $revTxt); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 278) { $footer(); $header(); } };
    $h2 = function (string $s) use ($pdf, &$y, $navy, $gold, $L, $R, $need) { $need(14); $y += 2; $pdf->setFont('B', 10.5); $pdf->setColor($navy); $pdf->text($L, $y + 4, $s); $y += 6; $pdf->setColor($gold); $pdf->setLineWidth(0.35); $pdf->line($L, $y, $R, $y); $y += 3.5; };
    $para = function (string $s) use ($pdf, &$y, $ink, $L, $W, $need) { $pdf->setFont('R', 9); $pdf->setColor($ink); foreach ($pdf->wrap($s, $W) as $ln) { $need(4.6); $pdf->setFont('R', 9); $pdf->setColor($ink); $pdf->text($L, $y + 3.4, $ln); $y += 4.6; } $y += 1.5; };
    $bullets = function (array $items) use ($pdf, &$y, $ink, $gold, $L, $W, $need) {
        foreach ($items as $it) { $pdf->setFont('R', 9); $lines = $pdf->wrap((string)$it, $W - 6); $need(min(count($lines), 3) * 4.5 + 1);
            $pdf->setColor($gold); $pdf->rect($L + 1.2, $y + 1.5, 1.4, 1.4, 'F'); $pdf->setColor($ink);
            foreach ($lines as $ln) { $need(4.5); $pdf->setFont('R', 9); $pdf->setColor($ink); $pdf->text($L + 6, $y + 3.4, $ln); $y += 4.5; } $y += 1; }
        $y += 1.5;
    };
    $grid = function (array $rows, array $wcol) use ($pdf, &$y, $navy, $ink, $line, $head, $L, $need) {   // rows of [label, value, label, value]
        foreach ($rows as $r) { $pdf->setFont('R', 8.6); $hh = (float)($r['h'] ?? 7.0); unset($r['h']); foreach ([1, 3] as $k) if (isset($r[$k])) $hh = max($hh, count($pdf->wrap((string)$r[$k], $wcol[$k] - 3)) * 4.0 + 3); $need($hh);
            $x = $L; foreach ($r as $k => $cell) { $cw = $wcol[$k]; if ($k % 2 === 0) { $pdf->setColor($head); $pdf->rect($x, $y, $cw, $hh, 'F'); $pdf->setColor($navy); $pdf->setFont('B', 7.6); $pdf->text($x + 1.5, $y + 4.6, (string)$cell); }
                else { $pdf->setColor($ink); $pdf->setFont('R', 8.6); foreach ($pdf->wrap((string)$cell, $cw - 3) as $j => $ln) $pdf->text($x + 1.5, $y + 4.6 + $j * 4.0, $ln); }
                $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->rect($x, $y, $cw, $hh, 'S'); $x += $cw; }
            $y += $hh; }
        $y += 4;
    };
    $d = fn($v) => $v ? fmt_date(substr((string)$v, 0, 10)) : '—';
    $header(); $footer();
    $pdf->setFont('B', 14); $pdf->setColor($navy); foreach ($pdf->wrap((string)$c['title'], $W) as $ln) { $pdf->text($L, $y + 4, $ln); $y += 7; } $y += 2;
    $appr = $c['approvals'] ?? []; $apprNames = array_filter(array_map(fn($s) => (string)($appr[$s]['name'] ?? ''), JD_STEPS), 'strlen');
    $grid([[t('jd.f_docno'), $docNo, t('jd.f_rev'), $revTxt . ' — ' . ((string)$c['rev_title'] ?: t('jd.rev_first'))],
           [t('jd.discipline'), (string)$c['discipline'] ?: '—', t('jd.level'), jd_level_label((string)$c['level'])],
           [t('jd.reports_to'), (string)$c['reports_to'] ?: '—', t('jd.f_date'), $d($c['approved_at'] ?? null)],
           [t('jd.f_prepared'), (string)$c['prepared'] ?: '—', t('jd.f_status'), $c['status'] === 'approved' ? t('jd.st_approved') : t('jd.st_' . $c['status']) . ' — ' . t('jd.not_in_force')],
           [t('jd.f_approved'), $apprNames ? implode(' · ', $apprNames) : '—', t('jd.f_company'), (string)$bd['name']]], [30, 60, 30, 60]);
    if ($revs) { $h2(t('jd.s_revs')); $rr = []; foreach ($revs as $r) $rr[] = ['R' . (int)$r['rev'], $d($r['approved_at']) . ' — ' . (string)$r['title']]; $grid(array_map(fn($x) => [$x[0], $x[1]], $rr), [30, 150]); }
    if (trim((string)$c['purpose']) !== '') { $h2('1  ' . t('jd.s_purpose')); $para((string)$c['purpose']); }
    $n = 2; foreach (JD_SECTIONS as $k) if (!empty($c[$k])) { $h2($n . '  ' . t('jd.s_' . $k)); $bullets($c[$k]); $n++; }
    $h2($n . '  ' . t('jd.s_general')); foreach (jd_general_clauses() as $tt => $body) { $need(12); $pdf->setFont('B', 9); $pdf->setColor($navy); $pdf->text($L, $y + 3.4, $tt); $y += 5; $para($body); } $n++;
    $h2($n . '  ' . t('jd.s_approval'));
    $rows = []; foreach (JD_STEPS as $s) { $a = $appr[$s] ?? []; $rows[] = [t('jd.step_' . $s), ($a['name'] ?? '') !== '' ? (string)$a['name'] : '—', t('jd.f_date'), !empty($a['at']) ? $d($a['at']) : '—']; }
    $grid($rows, [45, 75, 20, 40]);
    if ($person) { $need(34); $h2(t('jd.s_ack')); $para(t('jd.ack_text'));
        $grid([[t('jd.ack_employee'), (string)$person['name_en'], t('jd.f_date'), $ack ? $d($ack['acked_at']) . ' (' . t('jd.ack_portal') . ')' : ''], [t('jd.ack_sign'), '', '', '', 'h' => 16.0]], [45, 75, 20, 40]); }
    return $pdf->bytes($docNo . ' ' . $revTxt);
}

/* ------------------------------------------------------------------ actions (index.php, before pm_handle_action) */
function jd_handle_action(string $action): bool {
    if (!str_starts_with($action, 'jd_')) return false;
    if (!jd_has()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0);
    switch ($action) {
        case 'jd_save':    $id = jd_save_from_post($id); flash('good', t('jd.saved')); redirect('hr_jd', ['id' => $id, '_r' => uniqid()]);
        case 'jd_ai':      { $brief = mb_substr(trim((string)($_POST['brief'] ?? '')), 0, 2000); $r = jd_ai_draft($id, $brief, $_POST); $_SESSION['jd_ai_msg'] = ['brief' => $brief, 'a' => !empty($r['ok']) ? t('jd.ai_done') . (!empty($r['notes']) ? ' ' . $r['notes'] : '') : '', 'err' => empty($r['ok']) ? (string)($r['error'] ?? t('ai.failed')) : '']; redirect('hr_jd', $id ? ['id' => $id, 'edit' => 1, '_r' => uniqid()] : ['new' => 1, '_r' => uniqid()], '#jd-ai'); }
        case 'jd_ai_clear': unset($_SESSION['jd_ai'][$id], $_SESSION['jd_ai_msg']); redirect('hr_jd', $id ? ['id' => $id, 'edit' => 1] : ['new' => 1]);
        case 'jd_submit':  jd_submit($id); $j = jd_load($id); flash('good', t($j && $j['status'] === 'approved' ? 'jd.approved_ok' : 'jd.submitted')); redirect('hr_jd', ['id' => $id, '_r' => uniqid()]);
        case 'jd_approve': jd_approve($id); $j = jd_load($id); flash('good', t($j && $j['status'] === 'approved' ? 'jd.approved_ok' : 'jd.step_ok')); redirect('hr_jd', ['id' => $id, '_r' => uniqid()]);
        case 'jd_reject':  jd_reject($id, (string)($_POST['note'] ?? '')); flash('good', t('jd.returned')); redirect('hr_jd', ['id' => $id, '_r' => uniqid()]);
        case 'jd_revise':  jd_revise($id, (string)($_POST['rev_title'] ?? '')); flash('good', t('jd.revised')); redirect('hr_jd', ['id' => $id, 'edit' => 1, '_r' => uniqid()]);
        case 'jd_retire':  jd_retire($id, empty($_POST['undo'])); flash('good', t('jd.saved')); redirect('hr_jd', ['id' => $id, '_r' => uniqid()]);
        case 'jd_delete':  jd_delete($id); flash('good', t('jd.deleted')); redirect('hr_jds');
        case 'jd_assign':  $n = jd_assign_from_post($id); flash('good', t('jd.assigned', ['n' => $n])); redirect('hr_jd', ['id' => $id, '_r' => uniqid()], '#jd-people');
        case 'jd_person':  $pid = (int)($_POST['person_id'] ?? 0); jd_person_set($pid, (int)($_POST['jd_id'] ?? 0)); flash('good', t('jd.saved')); redirect('hr_person', ['id' => $pid, '_r' => uniqid()], '#hr-jd');
        case 'jd_seed':    $n = jd_seed(); flash('good', t('jd.seeded', ['n' => $n])); redirect('hr_jds', ['status' => 'draft']);
        case 'jd_ack':     jd_ack(); flash('good', t('jd.acked')); redirect('my_jd');
        case 'jd_vacancy': {   // prefill the vacancy form from the approved issue — kept in the session, saved only with the vacancy
            if (!function_exists('rcr_can') || !rcr_can()) throw new RuntimeException(t('err.noaccess'));
            $jid = (int)($_POST['jd_id'] ?? 0); $jd = $jid ? jd_load($jid) : null; $c = $jd ? jd_current($jd) : null; if (!$c) throw new RuntimeException(t('jd.no_issue'));
            $_SESSION['rc_vac_jd'][$id] = ['fields' => jd_vacancy_fields($c), 'jd_id' => $jid, 'label' => $c['code'] . ' ' . $c['title'] . ' R' . (int)$c['rev']];
            redirect('rcr_vacancy', $id ? ['id' => $id, 'edit' => 1, '_r' => uniqid()] : ['new' => 1, '_r' => uniqid()]);
        }
    }
    throw new RuntimeException(t('err.noaccess'));
}
