<?php
/** HR — training (introductory / internal / external) with applications and certificates; annual evaluations; mandatory closure periods.   GAS-CP-R62-001 */
declare(strict_types=1);

const HR_TR_KINDS = ['introductory', 'internal', 'external'];
const HR_TR_STATUS = ['open', 'closed', 'done', 'cancelled'];
const HR_TRM_STATUS = ['applied', 'approved', 'rejected', 'attended', 'absent'];
const HR_EVAL_CRITERIA = ['quality', 'productivity', 'technical', 'teamwork', 'initiative', 'reliability', 'safety', 'communication'];
const HR_EVAL_AUTO = ['training_auto', 'attendance_auto'];   // R64-001: computed by the portal

function hr_tr_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_trainings'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_tr_kind_pill(string $k): string { return pill(['introductory' => 'blue', 'internal' => 'navy', 'external' => 'gold'][$k] ?? 'mute', t('hr.tk_' . $k)); }
function hr_tr_status_pill(string $s): string { return pill(['open' => 'green', 'closed' => 'mute', 'done' => 'navy', 'cancelled' => 'bad'][$s] ?? 'mute', t('hr.ts_' . $s)); }
function hr_trm_pill(string $s): string { return pill(['applied' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'attended' => 'navy', 'absent' => 'mute'][$s] ?? 'mute', t('hr.tm_' . $s)); }

/* ------------------------------------------------------------ trainings (HR) */
function hr_trainings(array $f = []): array {
    if (!hr_tr_has()) return []; $w = ['1=1']; $b = [];
    if (!empty($f['kind']) && in_array($f['kind'], HR_TR_KINDS, true)) { $w[] = 'tr.kind = ?'; $b[] = $f['kind']; }
    if (!empty($f['status']) && in_array($f['status'], HR_TR_STATUS, true)) { $w[] = 'tr.status = ?'; $b[] = $f['status']; }
    if (!empty($f['year'])) { $w[] = 'YEAR(COALESCE(tr.date_from, tr.created_at)) = ?'; $b[] = (int)$f['year']; }
    return all('SELECT tr.*, (SELECT COUNT(*) FROM hr_training_members m WHERE m.training_id = tr.id AND m.status = "applied") AS n_applied, (SELECT COUNT(*) FROM hr_training_members m WHERE m.training_id = tr.id AND m.status IN ("approved","attended")) AS n_in FROM hr_trainings tr WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(tr.status,"open","closed","done","cancelled"), tr.date_from DESC, tr.id DESC', $b);
}
function hr_training(int $id): ?array { return hr_tr_has() ? one('SELECT * FROM hr_trainings WHERE id = ?', [$id]) : null; }
function hr_training_save_from_post(): int {
    if (!hr_can() || !hr_tr_has()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null; $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $title = $s('title', 160); if (!$title) throw new RuntimeException(t('hr.tr_need_title'));
    $kind = in_array($_POST['kind'] ?? '', HR_TR_KINDS, true) ? $_POST['kind'] : 'internal'; $status = in_array($_POST['status'] ?? '', HR_TR_STATUS, true) ? $_POST['status'] : 'open';
    $target = in_array($_POST['target'] ?? '', ['all', 'disciplines', 'people'], true) ? $_POST['target'] : 'all';
    $ids = $target === 'all' ? null : implode(',', array_unique(array_filter(array_map('intval', (array)($_POST[$target === 'disciplines' ? 'discipline_ids' : 'person_ids'] ?? [])))));
    $from = $d('date_from'); $to = $d('date_to') ?: $from; if ($from && $to && $to < $from) throw new RuntimeException(t('pl.bad_dates'));
    $v = [$kind, $title, $s('provider', 120), $s('trainer', 120), $s('location', 120), $from, $to, ($h = trim((string)($_POST['hours'] ?? ''))) === '' ? null : max(0.0, (float) str_replace(',', '.', $h)), $s('description', 8000), $target, $ids, (int)($_POST['capacity'] ?? 0) ?: null, $d('deadline'), ($c = trim((string)($_POST['cost_eur'] ?? ''))) === '' ? null : max(0.0, (float) str_replace(',', '.', $c)), (int)!empty($_POST['certificate']), $status];
    if ($id) { q('UPDATE hr_trainings SET kind = ?, title = ?, provider = ?, trainer = ?, location = ?, date_from = ?, date_to = ?, hours = ?, description = ?, target = ?, target_ids = ?, capacity = ?, deadline = ?, cost_eur = ?, certificate = ?, status = ? WHERE id = ?', [...$v, $id]); return $id; }
    q('INSERT INTO hr_trainings (kind, title, provider, trainer, location, date_from, date_to, hours, description, target, target_ids, capacity, deadline, cost_eur, certificate, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$v, (int)(current_user()['id'] ?? 0) ?: null]);
    $id = (int) db()->lastInsertId();
    // notify the people concerned that a training is open for applications
    if ($status === 'open' && function_exists('nf_push')) { $to = array_values(array_filter(array_map(fn($p) => (int)($p['user_id'] ?? 0), hr_training_people(hr_training($id))))); if ($to) nf_push($to, 'hrt:new', 'hrt:' . $id . ':new', t('hr.nf_tr_new', ['t' => $title]), mb_substr((string)$s('description', 8000), 0, 300), url('my_leave') . '#hr-training', (int)(current_user()['id'] ?? 0) ?: null, (string)(current_user()['full_name'] ?? '')); }
    return $id;
}
function hr_training_delete(int $id): void { if (!hr_can() || !hr_tr_has()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM hr_trainings WHERE id = ?', [$id]); }
/** People a training is offered to. */
function hr_training_people(?array $tr): array {
    if (!$tr) return []; $all = array_values(array_filter(hr_people(false), fn($p) => $p['is_active'])); $ids = array_map('intval', array_filter(explode(',', (string)($tr['target_ids'] ?? ''))));
    if ($tr['target'] === 'disciplines') return array_values(array_filter($all, fn($p) => in_array((int)($p['department_id'] ?? 0), $ids, true)));
    if ($tr['target'] === 'people') return array_values(array_filter($all, fn($p) => in_array((int)$p['id'], $ids, true)));
    return $all;
}
function hr_training_members(int $id): array { return hr_tr_has() ? all('SELECT m.*, p.name_en, p.department_id, p.user_id, d.name AS doc_name FROM hr_training_members m JOIN hr_people p ON p.id = m.person_id LEFT JOIN hr_docs d ON d.id = m.cert_doc_id WHERE m.training_id = ? ORDER BY FIELD(m.status,"applied","approved","attended","absent","rejected"), p.name_en', [$id]) : []; }
/** HR adds a person directly (introductory trainings, or on behalf of someone) — approved at once. */
function hr_training_add_people(int $id, array $pids): int {
    if (!hr_can() || !hr_tr_has()) throw new RuntimeException(t('err.noaccess')); $me = (int)(current_user()['id'] ?? 0); $n = 0;
    foreach (array_unique(array_filter(array_map('intval', $pids))) as $pid) { if (!hr_person($pid)) continue; q('INSERT INTO hr_training_members (training_id, person_id, status, applied_at, decided_by, decided_at) VALUES (?,?,?,NOW(),?,NOW()) ON DUPLICATE KEY UPDATE status = IF(status IN ("rejected","applied"), "approved", status), decided_by = VALUES(decided_by), decided_at = NOW()', [$id, $pid, 'approved', $me]); $n++; hr_audit($pid, 'training', (string)(hr_training($id)['title'] ?? ''), null, t('hr.tm_approved'), null); }
    return $n;
}
/** An employee applies for an open training offered to him. */
function hr_training_apply(array $p, int $id): void {
    $tr = hr_training($id); if (!$tr || $tr['status'] !== 'open') throw new RuntimeException(t('hr.tr_not_open'));
    if ($tr['deadline'] && $tr['deadline'] < date('Y-m-d')) throw new RuntimeException(t('hr.tr_deadline_passed'));
    if (!in_array((int)$p['id'], array_map(fn($x) => (int)$x['id'], hr_training_people($tr)), true)) throw new RuntimeException(t('err.noaccess'));
    if (scalar('SELECT COUNT(*) FROM hr_training_members WHERE training_id = ? AND person_id = ? AND status <> "rejected"', [$id, (int)$p['id']])) throw new RuntimeException(t('hr.tr_already'));
    if ($tr['capacity'] && scalar('SELECT COUNT(*) FROM hr_training_members WHERE training_id = ? AND status IN ("approved","attended")', [$id]) >= (int)$tr['capacity']) throw new RuntimeException(t('hr.tr_full'));
    q('INSERT INTO hr_training_members (training_id, person_id, status, applied_at, note) VALUES (?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE status = "applied", applied_at = NOW(), note = VALUES(note), decided_by = NULL, decided_at = NULL', [$id, (int)$p['id'], 'applied', mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null]);
    if (function_exists('nf_push')) { $to = array_values(array_filter(array_merge(hr_hr_user_ids(), [(int)(hr_manager_of($p) ?? 0)]))); nf_push($to, 'hrt:applied', 'hrt:' . $id . ':' . (int)$p['id'], t('hr.nf_tr_applied', ['u' => $p['name_en'], 't' => $tr['title']]), null, url('hr_training', ['id' => $id]), (int)(current_user()['id'] ?? 0) ?: null, (string)(current_user()['full_name'] ?? '')); }
}
/** HR (or the person's manager) decides an application / records attendance, result and certificate. */
function hr_training_member_save(int $mid): void {
    $m = one('SELECT m.*, p.user_id, p.name_en FROM hr_training_members m JOIN hr_people p ON p.id = m.person_id WHERE m.id = ?', [$mid]); if (!$m) throw new RuntimeException(t('err.notfound'));
    $p = hr_person((int)$m['person_id']); $isMgr = (int)(hr_manager_of($p) ?? 0) === (int)(current_user()['id'] ?? 0);
    if (!hr_can() && !$isMgr) throw new RuntimeException(t('err.noaccess'));
    $status = in_array($_POST['status'] ?? '', HR_TRM_STATUS, true) ? $_POST['status'] : $m['status']; if ($isMgr && !hr_can() && !in_array($status, ['approved', 'rejected', 'applied'], true)) $status = $m['status'];   // a manager only decides applications
    $me = (int)(current_user()['id'] ?? 0); $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $sets = ['status = ?', 'note = ?']; $vals = [$status, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null];
    if ($status !== $m['status'] && in_array($status, ['approved', 'rejected'], true)) { $sets[] = 'decided_by = ?'; $sets[] = 'decided_at = NOW()'; $vals[] = $me; }
    if (hr_can()) { array_push($sets, 'score = ?', 'cert_no = ?', 'cert_issued = ?', 'cert_expiry = ?'); array_push($vals, mb_substr(trim((string)($_POST['score'] ?? '')), 0, 20) ?: null, mb_substr(trim((string)($_POST['cert_no'] ?? '')), 0, 60) ?: null, $d('cert_issued'), $d('cert_expiry')); }
    $vals[] = $mid; q('UPDATE hr_training_members SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    if (hr_can() && !empty($_FILES['doc']['name'][0] ?? $_FILES['doc']['name'] ?? '')) { $before = (int) scalar('SELECT COALESCE(MAX(id),0) FROM hr_docs'); hr_docs_store_from_files((int)$m['person_id'], null, 'training', (string) scalar('SELECT title FROM hr_trainings WHERE id = ?', [(int)$m['training_id']])); $new = (int) scalar('SELECT MAX(id) FROM hr_docs WHERE person_id = ? AND id > ?', [(int)$m['person_id'], $before]); if ($new) q('UPDATE hr_training_members SET cert_doc_id = ? WHERE id = ?', [$new, $mid]); }
    if ($status !== $m['status']) { hr_audit((int)$m['person_id'], 'training', (string) scalar('SELECT title FROM hr_trainings WHERE id = ?', [(int)$m['training_id']]), t('hr.tm_' . $m['status']), t('hr.tm_' . $status), null); if ($m['user_id'] && function_exists('nf_push') && in_array($status, ['approved', 'rejected'], true)) nf_push([(int)$m['user_id']], 'hrt:' . $status, 'hrt:' . $mid . ':' . $status, t('hr.nf_tr_decided', ['t' => (string) scalar('SELECT title FROM hr_trainings WHERE id = ?', [(int)$m['training_id']]), 's' => t('hr.tm_' . $status)]), null, url('my_leave') . '#hr-training', $me ?: null, (string)(current_user()['full_name'] ?? '')); }
}
/** Training record of a person (card tab, My leave). */
function hr_person_trainings(int $pid): array { return hr_tr_has() ? all('SELECT m.*, tr.kind, tr.title, tr.provider, tr.date_from, tr.date_to, tr.hours, tr.certificate, tr.status AS tr_status, d.name AS doc_name FROM hr_training_members m JOIN hr_trainings tr ON tr.id = m.training_id LEFT JOIN hr_docs d ON d.id = m.cert_doc_id WHERE m.person_id = ? ORDER BY tr.date_from DESC, m.id DESC', [$pid]) : []; }
/** Open trainings a person may apply for (not yet applied / approved). */
function hr_trainings_for(array $p): array {
    $out = []; foreach (hr_trainings(['status' => 'open']) as $tr) { if (!in_array((int)$p['id'], array_map(fn($x) => (int)$x['id'], hr_training_people($tr)), true)) continue; $tr['mine'] = one('SELECT status FROM hr_training_members WHERE training_id = ? AND person_id = ?', [(int)$tr['id'], (int)$p['id']]); $tr['full'] = $tr['capacity'] && (int)$tr['n_in'] >= (int)$tr['capacity']; $tr['late'] = $tr['deadline'] && $tr['deadline'] < date('Y-m-d'); $out[] = $tr; }
    return $out;
}
/** Certificates expiring within $days (HR dashboard). */
function hr_cert_alerts(int $days = 90): array { return hr_tr_has() ? all('SELECT m.cert_expiry, m.cert_no, p.id, p.name_en, tr.title, DATEDIFF(m.cert_expiry, CURDATE()) AS left_days FROM hr_training_members m JOIN hr_people p ON p.id = m.person_id JOIN hr_trainings tr ON tr.id = m.training_id WHERE m.cert_expiry IS NOT NULL AND p.is_active = 1 AND m.cert_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY m.cert_expiry', [$days]) : []; }

/* ------------------------------------------------------------ annual evaluations */
function hr_evaluations(int $pid): array { return hr_tr_has() ? all('SELECT ev.*, cu.full_name AS evaluator FROM hr_evaluations ev LEFT JOIN customer_users cu ON cu.id = ev.evaluator_id WHERE ev.person_id = ? ORDER BY ev.year DESC', [$pid]) : []; }
function hr_evaluation(int $pid, int $year): ?array { $e = hr_tr_has() ? one('SELECT * FROM hr_evaluations WHERE person_id = ? AND year = ?', [$pid, $year]) : null; if ($e) $e['sc'] = json_decode((string)$e['scores'], true) ?: []; return $e; }
/** HR or the person's manager writes / finalises the evaluation of a year. */
function hr_evaluation_save_from_post(int $pid): void {
    $p = hr_person($pid); if (!$p || !hr_tr_has()) throw new RuntimeException(t('err.notfound'));
    $isMgr = (int)(hr_manager_of($p) ?? 0) === (int)(current_user()['id'] ?? 0); if (!hr_can() && !$isMgr) throw new RuntimeException(t('err.noaccess'));
    $year = (int)($_POST['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) throw new RuntimeException(t('pl.bad_dates'));
    $cur = hr_evaluation($pid, $year); if ($cur && $cur['status'] !== 'draft' && !hr_can()) throw new RuntimeException(t('hr.ev_locked'));
    $sc = []; foreach (HR_EVAL_CRITERIA as $c) { $v = (int)($_POST['sc'][$c] ?? 0); if ($v >= 1 && $v <= 5) $sc[$c] = $v; }
    if ($sc && function_exists('hr_eval_auto')) foreach (hr_eval_auto($pid, $year) as $k => $a) $sc[$k] = (int)$a['score'];   // R64-001: training participation and attendance consistency, computed
    $overall = $sc ? round(array_sum($sc) / count($sc), 1) : null; $status = in_array($_POST['status'] ?? '', ['draft', 'final'], true) ? $_POST['status'] : 'draft';
    $t = fn(string $k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 8000) ?: null; $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['eval_date'] ?? '')) ? $_POST['eval_date'] : date('Y-m-d');
    $v = [$d, (int)(current_user()['id'] ?? 0) ?: null, json_encode($sc), $overall, $t('strengths'), $t('improvements'), $t('goals'), $t('training_needs'), $t('comments'), $status];
    if ($cur) q('UPDATE hr_evaluations SET eval_date = ?, evaluator_id = ?, scores = ?, overall = ?, strengths = ?, improvements = ?, goals = ?, training_needs = ?, comments = ?, status = IF(status = "acknowledged", status, ?) WHERE id = ?', [...$v, (int)$cur['id']]);
    else q('INSERT INTO hr_evaluations (person_id, year, eval_date, evaluator_id, scores, overall, strengths, improvements, goals, training_needs, comments, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$pid, $year, ...$v]);
    hr_audit($pid, 'evaluation', (string)$year, $cur ? (string)$cur['overall'] . ' · ' . t('hr.es_' . $cur['status']) : null, (string)$overall . ' · ' . t('hr.es_' . $status), null);
    if ($status === 'final' && ($cur['status'] ?? 'draft') === 'draft' && !empty($p['user_id']) && function_exists('nf_push')) nf_push([(int)$p['user_id']], 'hrev:final', 'hrev:' . $pid . ':' . $year, t('hr.nf_ev_final', ['y' => $year]), null, url('my_leave') . '#hr-eval', (int)(current_user()['id'] ?? 0) ?: null, (string)(current_user()['full_name'] ?? ''));
}
/** The employee reads the final evaluation and may add a comment; acknowledging is logged. */
function hr_evaluation_ack(array $p, int $year): void {
    $e = hr_evaluation((int)$p['id'], $year); if (!$e || $e['status'] === 'draft') throw new RuntimeException(t('err.notfound'));
    q('UPDATE hr_evaluations SET employee_comment = ?, status = "acknowledged", acknowledged_at = NOW() WHERE id = ?', [mb_substr(trim((string)($_POST['employee_comment'] ?? '')), 0, 4000) ?: null, (int)$e['id']]);
    hr_audit((int)$p['id'], 'evaluation', (string)$year, t('hr.es_final'), t('hr.es_acknowledged'), null);
}
/** Evolution: overall per year for a person (card chart). */
function hr_eval_series(int $pid): array { $o = []; foreach (array_reverse(hr_evaluations($pid)) as $e) if ($e['overall'] !== null && $e['status'] !== 'draft') $o[(int)$e['year']] = (float)$e['overall']; return $o; }
/** Participation summary for the card: trainings attended, hours, certificates, evaluations. */
function hr_participation(int $pid): array {
    if (!hr_tr_has()) return []; $r = one('SELECT COUNT(*) n, SUM(tr.hours) h, SUM(m.cert_no IS NOT NULL OR m.cert_doc_id IS NOT NULL) c FROM hr_training_members m JOIN hr_trainings tr ON tr.id = m.training_id WHERE m.person_id = ? AND m.status = "attended"', [$pid]) ?: [];
    return ['trainings' => (int)($r['n'] ?? 0), 'hours' => (float)($r['h'] ?? 0), 'certificates' => (int)($r['c'] ?? 0), 'evaluations' => count(hr_evaluations($pid))];
}
