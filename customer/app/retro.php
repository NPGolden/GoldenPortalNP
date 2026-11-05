<?php
/** Project retrospectives and similar projects.   GAS-CP-R63-002
 *  When a project is completed the management team grades how it went (eight criteria 1–5), writes what went well,
 *  what to improve and the lessons for similar projects; a project is correlated with past projects by customer,
 *  vessel, yard, systems, category, branch, size and title so their lessons show up next to it. */
declare(strict_types=1);

const RETRO_CRITERIA = ['schedule', 'budget', 'quality', 'customer', 'team', 'communication', 'safety', 'documentation'];

function retro_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'pm_retro_reviews'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Management team: administrators, owners, PMs, engineering management, discipline managers. */
function retro_can(): bool { static $v = null; if ($v === null) $v = is_staff() && (is_cp_admin() || (function_exists('hr_owner') && hr_owner()) || (function_exists('pm_is_pm') && pm_is_pm()) || (function_exists('eng_is_management') && eng_is_management()) || (function_exists('hr_is_manager') && hr_is_manager())); return $v; }
function retro_project(int $pid): ?array { return one('SELECT p.*, v.name AS vessel, v.yard, c.name AS customer, b.name AS branch FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN branches b ON b.id = p.branch_id WHERE p.id = ?', [$pid]); }

/* ------------------------------------------------------------------ facts used for similarity and shown on the review page */
function retro_facts(int $pid): array {
    static $c = []; if (isset($c[$pid])) return $c[$pid];
    $p = retro_project($pid); if (!$p) return $c[$pid] = [];
    $subs = all('SELECT id, system_id, name, code, status, completed_at, activated_at, deadline FROM subprojects WHERE project_id = ?', [$pid]);
    $sys = array_values(array_unique(array_filter(array_map(fn($s) => (int)$s['system_id'], $subs))));
    $offer = 0.0; try { $offer = (float) scalar('SELECT COALESCE(SUM(b.offer_value),0) FROM sp_budgets b JOIN (SELECT subproject_id, MAX(revision) r FROM sp_budgets GROUP BY subproject_id) x ON x.subproject_id = b.subproject_id AND x.r = b.revision JOIN subprojects s ON s.id = b.subproject_id WHERE s.project_id = ?', [$pid]); } catch (Throwable $e) {}
    $hours = 0.0; try { $hours = (float) scalar('SELECT COALESCE(SUM(l.hours),0) FROM timesheet_lines l JOIN subprojects s ON s.id = l.subproject_id WHERE s.project_id = ?', [$pid]); } catch (Throwable $e) {}
    $tickets = (int) scalar('SELECT COUNT(*) FROM tickets WHERE project_id = ?', [$pid]);
    $start = (string)($p['activated_at'] ?: $p['created_at']); $end = (string) (scalar('SELECT MAX(completed_at) FROM subprojects WHERE project_id = ?', [$pid]) ?: ($p['status'] === 'completed' ? $p['updated_at'] : ''));
    $words = retro_words((string)$p['name']);
    return $c[$pid] = ['p' => $p, 'subs' => $subs, 'systems' => $sys, 'offer' => $offer, 'hours' => $hours, 'tickets' => $tickets, 'start' => substr($start, 0, 10), 'end' => substr($end, 0, 10), 'words' => $words, 'nsubs' => count($subs)];
}
function retro_words(string $s): array { $stop = ['the', 'and', 'for', 'with', 'of', 'to', 'on', 'in', 'my', 'm/y', 'project', 'new', 'system', 'systems', 'yacht', 'vessel', 'refit', 'works', 'work', 'supply', 'installation']; $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: []; return array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) >= 3 && !in_array($x, $stop, true)))); }
/** Similar past projects: [['pid','score','why' => [reasons],'f' => facts], …] best first. */
function retro_similar(int $pid, int $n = 6): array {
    $a = retro_facts($pid); if (!$a) return []; $out = [];
    foreach (all('SELECT id FROM projects WHERE id <> ? AND project_code NOT LIKE "GEN-%"', [$pid]) as $r) {
        $b = retro_facts((int)$r['id']); if (!$b) continue; $s = 0; $why = [];
        if ($a['p']['customer_id'] && (int)$a['p']['customer_id'] === (int)$b['p']['customer_id']) { $s += 3; $why[] = 'customer'; }
        if ($a['p']['vessel_id'] && (int)$a['p']['vessel_id'] === (int)$b['p']['vessel_id']) { $s += 4; $why[] = 'vessel'; }
        elseif ($a['p']['yard'] && $a['p']['yard'] === $b['p']['yard']) { $s += 2; $why[] = 'yard'; }
        $shared = array_intersect($a['systems'], $b['systems']); if ($shared) { $s += min(6, 2 * count($shared)); $why[] = 'systems:' . count($shared); }
        if ($a['p']['category'] && $a['p']['category'] === $b['p']['category']) { $s += 1; $why[] = 'category'; }
        if ($a['p']['branch_id'] && (int)$a['p']['branch_id'] === (int)$b['p']['branch_id']) { $s += 1; $why[] = 'branch'; }
        if ($a['offer'] > 0 && $b['offer'] > 0 && max($a['offer'], $b['offer']) / min($a['offer'], $b['offer']) <= 1.5) { $s += 1; $why[] = 'size'; }
        $sw = array_intersect($a['words'], $b['words']); if ($sw) { $s += min(3, count($sw)); $why[] = 'title:' . implode(' ', array_slice($sw, 0, 3)); }
        if ($s >= 3) $out[] = ['pid' => (int)$r['id'], 'score' => $s, 'why' => $why, 'f' => $b];
    }
    usort($out, fn($x, $y) => [$y['score'], $y['f']['end']] <=> [$x['score'], $x['f']['end']]);
    return array_slice($out, 0, $n);
}

/* ------------------------------------------------------------------ reviews */
function retro_reviews(int $pid): array { if (!retro_has()) return []; $r = all('SELECT r.*, cu.full_name FROM pm_retro_reviews r JOIN customer_users cu ON cu.id = r.user_id WHERE r.project_id = ? ORDER BY r.updated_at DESC', [$pid]); foreach ($r as &$x) $x['sc'] = json_decode((string)$x['scores'], true) ?: []; return $r; }
function retro_summary(int $pid): array {
    $rv = retro_reviews($pid); $avg = []; foreach (RETRO_CRITERIA as $c) { $v = array_filter(array_map(fn($r) => $r['sc'][$c] ?? null, $rv)); $avg[$c] = $v ? round(array_sum($v) / count($v), 1) : null; }
    $ov = array_filter(array_map(fn($r) => $r['overall'], $rv), fn($v) => $v !== null); return ['n' => count($rv), 'overall' => $ov ? round(array_sum($ov) / count($ov), 1) : null, 'avg' => $avg, 'reviews' => $rv];
}
function retro_my(int $pid): ?array { if (!retro_has()) return null; $r = one('SELECT * FROM pm_retro_reviews WHERE project_id = ? AND user_id = ?', [$pid, (int)(current_user()['id'] ?? 0)]); if ($r) $r['sc'] = json_decode((string)$r['scores'], true) ?: []; return $r; }
function retro_review_save(int $pid): void {
    if (!retro_has() || !retro_can()) throw new RuntimeException(t('err.noaccess')); $p = retro_project($pid); if (!$p) throw new RuntimeException(t('err.notfound'));
    if ($p['status'] !== 'completed' && !is_cp_admin()) throw new RuntimeException(t('retro.not_completed'));
    $sc = []; foreach (RETRO_CRITERIA as $c) { $v = (int)($_POST['sc'][$c] ?? 0); if ($v >= 1 && $v <= 5) $sc[$c] = $v; } if (!$sc) throw new RuntimeException(t('retro.need_scores'));
    $ov = round(array_sum($sc) / count($sc), 1); $t = fn(string $k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 8000) ?: null; $me = (int)(current_user()['id'] ?? 0);
    q('INSERT INTO pm_retro_reviews (project_id, user_id, scores, overall, went_well, improve, lessons) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE scores = VALUES(scores), overall = VALUES(overall), went_well = VALUES(went_well), improve = VALUES(improve), lessons = VALUES(lessons), updated_at = NOW()', [$pid, $me, json_encode($sc), $ov, $t('went_well'), $t('improve'), $t('lessons')]);
    if (function_exists('nf_push')) { $to = array_values(array_unique(array_filter([(int)($p['pm_user_id'] ?? 0), ...array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"), 'id'))]))); nf_push($to, 'retro:review', 'retro:' . $pid . ':' . $me, '⟲ ' . t('retro.nf_review', ['u' => (string)(current_user()['full_name'] ?? ''), 'p' => (string)($p['project_code'] ?: $p['name']), 's' => (string)$ov]), $t('lessons'), url('retro', ['id' => $pid]), $me ?: null, (string)(current_user()['full_name'] ?? '')); }
}
function retro_close_save(int $pid): void {
    if (!retro_has() || !retro_can()) throw new RuntimeException(t('err.noaccess')); $t = fn(string $k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 8000) ?: null; $tags = mb_substr(trim((string)($_POST['tags'] ?? '')), 0, 200) ?: null; $close = !empty($_POST['close']);
    q('INSERT INTO pm_retros (project_id, summary, actions, tags, closed_by, closed_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE summary = VALUES(summary), actions = VALUES(actions), tags = VALUES(tags), closed_by = VALUES(closed_by), closed_at = VALUES(closed_at)', [$pid, $t('summary'), $t('actions'), $tags, $close ? (int)(current_user()['id'] ?? 0) : null, $close ? date('Y-m-d H:i:s') : null]);
}
function retro_get(int $pid): ?array { return retro_has() ? one('SELECT r.*, cu.full_name AS closed_name FROM pm_retros r LEFT JOIN customer_users cu ON cu.id = r.closed_by WHERE r.project_id = ?', [$pid]) : null; }
/** Completed projects waiting for my grade (dashboard, last 18 months). */
function retro_pending_mine(): array { if (!retro_has() || !retro_can()) return []; return all('SELECT p.id, p.project_code, p.name, p.updated_at, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.status = "completed" AND p.project_code NOT LIKE "GEN-%" AND p.updated_at > DATE_SUB(NOW(), INTERVAL 18 MONTH) AND NOT EXISTS (SELECT 1 FROM pm_retro_reviews r WHERE r.project_id = p.id AND r.user_id = ?) AND NOT EXISTS (SELECT 1 FROM pm_retros x WHERE x.project_id = p.id AND x.closed_at IS NOT NULL) ORDER BY p.updated_at DESC LIMIT 8', [(int)(current_user()['id'] ?? 0)]); }
/** Lessons library: completed projects with their grade and lessons, filterable by text / customer / system. */
function retro_list(array $f = []): array {
    if (!retro_has()) return []; $w = ['p.project_code NOT LIKE "GEN-%"']; $b = [];
    if (!empty($f['q'])) { $w[] = '(p.name LIKE ? OR p.project_code LIKE ? OR v.name LIKE ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM pm_retro_reviews r2 WHERE r2.project_id = p.id AND (r2.lessons LIKE ? OR r2.improve LIKE ? OR r2.went_well LIKE ?)) OR EXISTS (SELECT 1 FROM pm_retros x2 WHERE x2.project_id = p.id AND (x2.summary LIKE ? OR x2.actions LIKE ? OR x2.tags LIKE ?)))'; $like = '%' . $f['q'] . '%'; array_push($b, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like); }
    if (!empty($f['customer'])) { $w[] = 'p.customer_id = ?'; $b[] = (int)$f['customer']; }
    if (!empty($f['system'])) { $w[] = 'EXISTS (SELECT 1 FROM subprojects s WHERE s.project_id = p.id AND s.system_id = ?)'; $b[] = (int)$f['system']; }
    if (($f['status'] ?? 'completed') === 'completed') $w[] = 'p.status = "completed"';
    $rows = all('SELECT p.id, p.project_code, p.name, p.status, p.updated_at, p.customer_id, v.name AS vessel, c.name AS customer, (SELECT COUNT(*) FROM pm_retro_reviews r WHERE r.project_id = p.id) n_rev, (SELECT ROUND(AVG(r.overall),1) FROM pm_retro_reviews r WHERE r.project_id = p.id) overall, x.closed_at, x.tags, x.summary FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN pm_retros x ON x.project_id = p.id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.updated_at DESC LIMIT 200', $b);
    return $rows;
}
function retro_stars(?float $v): string { if ($v === null) return '<span class="hint">—</span>'; $n = (int) round($v); return '<span title="' . $v . '">' . str_repeat('★', $n) . '<span class="hint">' . str_repeat('☆', 5 - $n) . '</span></span> <b>' . rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '</b>'; }
