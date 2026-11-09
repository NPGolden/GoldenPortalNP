<?php
declare(strict_types=1);
/**
 * Work permits per main project — GAS-CP-R93-001.
 * Holder (HR person or free name), permit type, number, issuing authority, country, issue / expiry dates, one document.
 * Staff see them; the project's PM, HR and management edit. Expiry is watched from nf_sweep(): 60 / 30 / 14 / 7 days and
 * the day it lapses, to the PM, HR and administrators (one notification per permit and threshold).
 */

const PMT_TYPES  = ['work_permit', 'residence', 'visa', 'a1', 'yard_pass', 'port_pass', 'other'];
const PMT_STATES = ['active', 'renewal', 'cancelled'];
const PMT_WARN_DAYS = 30;          // "expiring soon" on the dashboards

function pmt_has(): bool { static $v = null; if ($v === null) { try { $v = (bool) scalar("SHOW TABLES LIKE 'work_permits'"); } catch (Throwable $e) { $v = false; } } return $v; }
function pmt_can_view(): bool { return pmt_has() && is_staff(); }
/** Edit rights: administrators, HR, the project's PM (or any PM when no project is given yet). */
function pmt_can_edit(int $projectId = 0): bool {
    if (!pmt_can_view()) return false;
    if (is_cp_admin() || (function_exists('hr_can') && hr_can())) return true;
    $u = current_user();
    if ($projectId) return (int) scalar('SELECT COUNT(*) FROM projects WHERE id = ? AND pm_user_id = ?', [$projectId, (int)$u['id']]) > 0;
    return function_exists('pm_is_pm') ? pm_is_pm() : false;
}
function pmt_types(): array { $o = []; foreach (PMT_TYPES as $t) $o[$t] = t('pmt.t_' . $t); return $o; }

/** Days until expiry (negative = lapsed), measured in calendar days from today. */
function pmt_days(array $p): int { return (int) floor((strtotime((string)$p['expiry_date']) - strtotime(date('Y-m-d'))) / 86400); }
/** expired | soon | valid | renewal | cancelled — what the pills show. */
function pmt_state(array $p): string {
    if ($p['status'] === 'cancelled') return 'cancelled';
    $d = pmt_days($p);
    if ($d < 0) return 'expired';
    if ($p['status'] === 'renewal') return 'renewal';
    return $d <= PMT_WARN_DAYS ? 'soon' : 'valid';
}
function pmt_pill(array $p): string {
    $s = pmt_state($p); $d = pmt_days($p);
    $cls = ['expired' => 'pill--red', 'soon' => 'pill--gold', 'renewal' => 'pill--blue', 'valid' => 'pill--green', 'cancelled' => 'pill--mute'][$s];
    $txt = match ($s) { 'expired' => t('pmt.s_expired_n', ['n' => -$d]), 'soon' => t('pmt.s_soon_n', ['n' => $d]), 'renewal' => t('pmt.s_renewal'), 'cancelled' => t('pmt.s_cancelled'), default => t('pmt.s_valid_n', ['n' => $d]) };
    return '<span class="pill ' . $cls . '">' . e($txt) . '</span>';
}

function pmt_load(int $id): ?array {
    return one('SELECT w.*, p.name AS project_name, p.project_code, p.pm_user_id, hp.name_en AS person_name FROM work_permits w JOIN projects p ON p.id = w.project_id LEFT JOIN hr_people hp ON hp.id = w.person_id WHERE w.id = ?', [$id]);
}
/** List with filters: project, state (expired | soon | valid | renewal | cancelled | open = not cancelled), q. Ordered by expiry. */
function pmt_list(array $f = []): array {
    $w = ['1 = 1']; $a = [];
    if (!empty($f['project'])) { $w[] = 'w.project_id = ?'; $a[] = (int)$f['project']; }
    if (!empty($f['person'])) { $w[] = 'w.person_id = ?'; $a[] = (int)$f['person']; }
    if (!empty($f['q'])) { $w[] = '(w.holder_name LIKE ? OR w.permit_no LIKE ? OR w.authority LIKE ? OR p.name LIKE ? OR p.project_code LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($a, $q, $q, $q, $q, $q); }
    $st = (string)($f['state'] ?? 'open');
    if ($st === 'open') $w[] = "w.status <> 'cancelled'";
    elseif ($st === 'cancelled') $w[] = "w.status = 'cancelled'";
    elseif ($st === 'expired') $w[] = "w.status <> 'cancelled' AND w.expiry_date < CURDATE()";
    elseif ($st === 'soon') { $w[] = "w.status = 'active' AND w.expiry_date >= CURDATE() AND w.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"; $a[] = PMT_WARN_DAYS; }
    elseif ($st === 'valid') { $w[] = "w.status = 'active' AND w.expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY)"; $a[] = PMT_WARN_DAYS; }
    elseif ($st === 'renewal') $w[] = "w.status = 'renewal' AND w.expiry_date >= CURDATE()";
    return all('SELECT w.*, p.name AS project_name, p.project_code, p.pm_user_id, hp.name_en AS person_name FROM work_permits w JOIN projects p ON p.id = w.project_id LEFT JOIN hr_people hp ON hp.id = w.person_id WHERE ' . implode(' AND ', $w) . ' ORDER BY w.expiry_date, p.project_code, w.holder_name', $a);
}
/** Counts per project for the Projects dashboard: [project_id => ['n' => open permits, 'expired' => …, 'soon' => …]]. */
function pmt_project_counts(array $projectIds): array {
    $projectIds = array_values(array_filter(array_map('intval', $projectIds))); if (!$projectIds) return [];
    $in = implode(',', array_fill(0, count($projectIds), '?'));
    $rows = all("SELECT project_id, COUNT(*) AS n, SUM(expiry_date < CURDATE()) AS expired, SUM(status = 'active' AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)) AS soon, MIN(CASE WHEN expiry_date >= CURDATE() THEN expiry_date END) AS next_expiry
                 FROM work_permits WHERE status <> 'cancelled' AND project_id IN ($in) GROUP BY project_id", array_merge([PMT_WARN_DAYS], $projectIds));
    $o = []; foreach ($rows as $r) $o[(int)$r['project_id']] = ['n' => (int)$r['n'], 'expired' => (int)$r['expired'], 'soon' => (int)$r['soon'], 'next' => $r['next_expiry']]; return $o;
}
/** Totals for the permits page head. */
function pmt_summary(): array {
    $r = one("SELECT COUNT(*) AS n, SUM(expiry_date < CURDATE()) AS expired, SUM(status = 'active' AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)) AS soon, SUM(status = 'renewal' AND expiry_date >= CURDATE()) AS renewal FROM work_permits WHERE status <> 'cancelled'", [PMT_WARN_DAYS]) ?: [];
    return ['n' => (int)($r['n'] ?? 0), 'expired' => (int)($r['expired'] ?? 0), 'soon' => (int)($r['soon'] ?? 0), 'renewal' => (int)($r['renewal'] ?? 0)];
}

/** Summary pill for the project row: 🪪 n (· ⚠ expired · ⏳ soon). */
function pmt_project_pill(int $projectId, array $c): string {
    if (empty($c['n'])) return '';
    $tone = $c['expired'] ? 'pill--red' : ($c['soon'] ? 'pill--gold' : 'pill--green');
    $txt = '🪪 ' . $c['n'] . ($c['expired'] ? ' · ' . t('pmt.n_expired', ['n' => $c['expired']]) : '') . ($c['soon'] ? ' · ' . t('pmt.n_soon', ['n' => $c['soon']]) : '');
    return '<a class="pill ' . $tone . '" href="' . url('permits', ['project' => $projectId]) . '" title="' . e(t('pmt.title')) . ($c['next'] ? ' · ' . e(t('pmt.next_expiry', ['d' => fmt_date($c['next'])])) : '') . '">' . e($txt) . '</a>';
}
/** Mini list inside the project tree — only permits that need attention (expired, renewal, ≤ 30 days), newest expiry first. */
function pmt_card_html(int $projectId, ?string $newUrl, int $limit = 6): string {
    if (!pmt_can_view() || !$projectId) return '';
    $rows = array_values(array_filter(pmt_list(['project' => $projectId]), fn($p) => in_array(pmt_state($p), ['expired', 'soon', 'renewal'], true)));
    $n = count($rows); if (!$n) return '';
    $allUrl = url('permits', ['project' => $projectId]);
    $h = '<div class="tk-block pmt-block"><div class="page-head" style="margin-bottom:6px;"><h3 class="ed-h" style="margin:0;"><a href="' . $allUrl . '" style="text-decoration:none;color:inherit;">' . tt('pmt.attention') . '</a> <span class="count">' . $n . '</span></h3>'
       . ($newUrl ? '<a class="btn btn--ghost btn--sm" href="' . $newUrl . '">+ ' . tt('pmt.new') . '</a>' : '') . '</div><ul class="td-mini tk-mini">';
    foreach (array_slice($rows, 0, $limit) as $p) {
        $h .= '<li' . (pmt_state($p) === 'expired' ? ' class="is-wait"' : '') . '><span class="tk-mini-no" style="min-width:0;width:auto;flex:0 0 auto;">🪪</span><span class="td-mini-main"><a class="td-title" href="' . url('permit_edit', ['id' => $p['id']]) . '">' . e($p['holder_name']) . '</a>'
            . '<span class="td-meta">' . pmt_pill($p) . '<span class="td-ctx">' . e(pmt_types()[$p['permit_type']] ?? $p['permit_type']) . ($p['permit_no'] ? ' ' . e($p['permit_no']) : '') . ' · ' . e(fmt_date($p['expiry_date'])) . '</span></span></span></li>';
    }
    $h .= '</ul>' . ($n > $limit ? '<p class="hint" style="margin:6px 0 0;"><a href="' . $allUrl . '">' . e(t('pmt.more', ['n' => $n - $limit])) . ' ›</a></p>' : '') . '</div>';
    return $h;
}

/* ---- save / delete / file ---- */
function pmt_save_from_post(int $id): int {
    $pid = (int)($_POST['project_id'] ?? 0); if (!$pid || !one('SELECT id FROM projects WHERE id = ?', [$pid])) throw new RuntimeException(t('pmt.err_project'));
    if (!pmt_can_edit($pid)) throw new RuntimeException(t('err.noaccess'));
    $old = $id ? pmt_load($id) : null; if ($id && !$old) throw new RuntimeException('Not found');
    if ($old && !pmt_can_edit((int)$old['project_id'])) throw new RuntimeException(t('err.noaccess'));
    $personId = (int)($_POST['person_id'] ?? 0) ?: null; $holder = mb_substr(trim((string)($_POST['holder_name'] ?? '')), 0, 160);
    if ($personId && function_exists('hr_person') && ($hp = hr_person($personId))) { if ($holder === '') $holder = (string)$hp['name_en']; } else $personId = null;
    if ($holder === '') throw new RuntimeException(t('pmt.err_holder'));
    $type = in_array((string)($_POST['permit_type'] ?? ''), PMT_TYPES, true) ? (string)$_POST['permit_type'] : 'work_permit';
    $status = in_array((string)($_POST['status'] ?? ''), PMT_STATES, true) ? (string)$_POST['status'] : 'active';
    $issue = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['issue_date'] ?? '')) ? (string)$_POST['issue_date'] : null;
    $exp = (string)($_POST['expiry_date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) throw new RuntimeException(t('pmt.err_expiry'));
    if ($issue && $issue > $exp) throw new RuntimeException(t('pmt.err_dates'));
    $country = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['country'] ?? ''))); $country = strlen($country) === 2 ? $country : null;
    $vals = [$pid, $personId, $holder, $type, mb_substr(trim((string)($_POST['permit_no'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($_POST['authority'] ?? '')), 0, 160) ?: null, $country, $issue, $exp, $status, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null];
    if ($id) { q('UPDATE work_permits SET project_id=?, person_id=?, holder_name=?, permit_type=?, permit_no=?, authority=?, country=?, issue_date=?, expiry_date=?, status=?, notes=? WHERE id=?', [...$vals, $id]); }
    else { q('INSERT INTO work_permits (project_id, person_id, holder_name, permit_type, permit_no, authority, country, issue_date, expiry_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [...$vals, (int)current_user()['id']]); $id = (int) db()->lastInsertId(); }
    pmt_store_upload($id);
    if ($old && $old['expiry_date'] !== $exp) q("DELETE FROM notifications WHERE kind LIKE 'wkp:%' AND ref_key LIKE ?", ['wkp:' . $id . ':%']);   // new expiry → warnings start over
    return $id;
}
function pmt_delete(int $id): void {
    $p = pmt_load($id); if (!$p) return; if (!pmt_can_edit((int)$p['project_id'])) throw new RuntimeException(t('err.noaccess'));
    if ($p['stored_name']) @unlink(cp_storage() . '/' . $p['stored_name']);
    q('DELETE FROM work_permits WHERE id = ?', [$id]);
    q("DELETE FROM notifications WHERE kind LIKE 'wkp:%' AND ref_key LIKE ?", ['wkp:' . $id . ':%']);
}
function pmt_store_upload(int $id): void {
    $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return;
    if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException(t('pmt.err_file'));
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException(t('pmt.err_file'));
    $mime = (string)(function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '') ?: 'application/octet-stream';
    if (!preg_match('~^(application/pdf|image/(jpeg|png|webp))$~', $mime)) throw new RuntimeException(t('pmt.err_file'));
    $dir = cp_storage() . '/permits/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = 'permits/' . date('Y') . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], cp_storage() . '/' . $stored)) throw new RuntimeException(t('pmt.err_file'));
    $old = one('SELECT stored_name FROM work_permits WHERE id = ?', [$id]); if ($old && $old['stored_name']) @unlink(cp_storage() . '/' . $old['stored_name']);
    q('UPDATE work_permits SET stored_name=?, original_name=?, mime_type=?, size_bytes=? WHERE id=?', [$stored, mb_substr((string)$f['name'], 0, 255), $mime, (int)$f['size'], $id]);
}
function pmt_stream_file(int $id): void {
    $p = pmt_load($id); if (!$p || !pmt_can_view() || !$p['stored_name']) { http_response_code(404); exit('Not found'); }
    $path = cp_storage() . '/' . $p['stored_name']; if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    header('Content-Type: ' . $p['mime_type']); header('Content-Length: ' . (string) filesize($path)); header('Cache-Control: private, max-age=600'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$p['original_name']) . '"'); readfile($path); exit;
}

/* ---- expiry warnings from nf_sweep(): 60 / 30 / 14 / 7 / 0 days; recipients PM + HR + administrators ---- */
function pmt_remind(): int {
    if (!pmt_has() || !function_exists('nf_push')) return 0;
    $rows = all("SELECT w.*, p.name AS project_name, p.project_code, p.pm_user_id FROM work_permits w JOIN projects p ON p.id = w.project_id WHERE w.status <> 'cancelled' AND w.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) AND w.expiry_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if (!$rows) return 0;
    $hr = array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND r.scope IN ('hr','owner')"), 'id'));
    $n = 0;
    foreach ($rows as $p) {
        $d = pmt_days($p); $th = null; foreach ([0, 7, 14, 30, 60] as $t) if ($d <= $t) { $th = $t; break; }
        if ($th === null) continue;
        $to = array_values(array_unique(array_filter(array_merge([(int)$p['pm_user_id']], $hr, nf_admins()))));
        $who = $p['holder_name'] . ' · ' . ($p['project_code'] ?: $p['project_name']);
        $title = $d < 0 ? t('pmt.n_expired_t', ['who' => $who, 'n' => -$d]) : ($d === 0 ? t('pmt.n_today_t', ['who' => $who]) : t('pmt.n_expiring_t', ['who' => $who, 'n' => $d]));
        $body = (pmt_types()[$p['permit_type']] ?? $p['permit_type']) . ($p['permit_no'] ? ' ' . $p['permit_no'] : '') . ' · ' . t('pmt.expiry') . ' ' . fmt_date($p['expiry_date']);
        $ref = 'wkp:' . $p['id'] . ':' . ($d < 0 ? 'x' . date('Ym') : 'e' . $th);          // one warning per threshold; lapsed permits once a month
        if ((int) scalar('SELECT COUNT(*) FROM notifications WHERE ref_key = ?', [$ref])) continue;
        $n += nf_push($to, $d < 0 ? 'wkp:expired' : 'wkp:expiring', $ref, $title, $body, url('permit_edit', ['id' => $p['id']]), null, null);
    }
    return $n;
}
