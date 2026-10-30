<?php
/**
 * Branches — the Greek head office and the Italian branch in one portal.          GAS-CP-R24-001 … R24-003
 * Design: GAS-2026-PORTAL-BRN-001 (OP-1 GIT prefix · OP-2 portal offer series GIT-OF-YYYY-NNN · OP-3 branch scope
 * off by default · OP-4 same charge rates · OP-5 Italian legal identity · OP-6 logo variant with the branch line).
 *
 * A project carries a branch (NULL = the default branch); a sub-project inherits it and may override it. Every
 * record below (reports, FSR / ISR, timesheets, offers, tickets, work orders, tasks, files) derives its branch
 * from the sub-project / project — nothing is assigned individually. Documents issued by the portal carry the
 * identity (legal name, address, VAT, registry, logo variant) and the document prefix of the issuing branch.
 */
declare(strict_types=1);

function br_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'branches'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** All branches keyed by id (active first by sort order); the built-in head office when the table is missing. */
function br_all(bool $activeOnly = false): array {
    static $c = null;
    if ($c === null || !empty($GLOBALS['br_dirty'])) {
        $GLOBALS['br_dirty'] = false; $c = [];
        if (br_has()) foreach (all('SELECT * FROM branches ORDER BY is_default DESC, sort_order, code') as $r) $c[(int)$r['id']] = $r;
        if (!$c) $c[0] = ['id' => 0, 'code' => 'GR', 'name' => 'Greece · head office', 'legal_name' => 'Golden Automation Systems', 'trade_name' => 'Golden Automation Systems', 'address' => null, 'country' => 'GR', 'vat_no' => null, 'registry' => null,
                          'email' => null, 'phone' => null, 'doc_prefix' => 'GAS', 'offer_series' => null, 'work_calendar' => 'GR', 'currency' => 'EUR', 'logo_variant' => 'default', 'is_default' => 1, 'is_active' => 1, 'sort_order' => 10];
    }
    return $activeOnly ? array_filter($c, fn($b) => (int)$b['is_active'] === 1 || (int)$b['is_default'] === 1) : $c;
}
function br_has_cost(): bool { static $h = null; if ($h === null) { try { $h = br_has() && (bool) scalar("SHOW COLUMNS FROM branches LIKE 'manhour_cost'"); } catch (Throwable $e) { $h = false; } } return $h; }
function br_default(): array { foreach (br_all() as $b) if ((int)$b['is_default']) return $b; return array_values(br_all())[0]; }
function br_get(?int $id): array { $all = br_all(); return $id && isset($all[$id]) ? $all[$id] : br_default(); }
function br_multi(): bool { return count(br_all(true)) > 1; }
/** The branch a project / sub-project belongs to (sub-project override, else project, else default). */
function br_of(?int $projectId, ?int $subId = null): array {
    static $cache = [];
    $k = (int)$projectId . ':' . (int)$subId;
    if (!isset($cache[$k])) {
        $bid = null;
        if (br_has()) {
            if ($subId) $bid = (int) scalar('SELECT COALESCE(s.branch_id, p.branch_id, 0) FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$subId]);
            elseif ($projectId) $bid = (int) scalar('SELECT COALESCE(branch_id, 0) FROM projects WHERE id = ?', [$projectId]);
        }
        $cache[$k] = br_get($bid ?: null);
    }
    return $cache[$k];
}
function br_pill(?array $b, bool $always = false): string {
    if (!$b || (!$always && !br_multi())) return '';
    return pill((int)$b['is_default'] ? 'navy' : 'gold', $b['code']);
}
/** Home branch of the signed-in staff account (NULL = default). */
function br_user(?array $u = null): array {
    $u = $u ?? current_user(); return br_get($u && br_has() ? (int)($u['branch_id'] ?? 0) ?: null : null);
}
/** Extra WHERE on projects for a staff account with branch scope on (administrators are always group-wide).   OP-3 */
function br_scope_sql(string $alias = 'p'): array {
    if (!br_has() || !is_staff() || is_cp_admin()) return ['1 = 1', []];
    $u = current_user(); if (empty($u['branch_scope'])) return ['1 = 1', []];
    $b = br_user($u);
    return (int)$b['is_default'] ? ["({$alias}.branch_id IS NULL OR {$alias}.branch_id = ?)", [(int)$b['id']]] : ["{$alias}.branch_id = ?", [(int)$b['id']]];
}
/** Filter for list pages: ?branch=<id> (0 = all). */
function br_filter_sql(string $alias, int $branchId): array {
    if (!$branchId || !br_has()) return ['1 = 1', []];
    $b = br_get($branchId);
    return (int)$b['is_default'] ? ["({$alias}.branch_id IS NULL OR {$alias}.branch_id = ?)", [$branchId]] : ["{$alias}.branch_id = ?", [$branchId]];
}

/* ------------------------------------------------------------ uploaded logos (R24-001b) */

/** Folder of the uploaded branch logos — under storage, so a portal patch never overwrites them. */
function br_logo_dir(): string { return cp_storage() . '/_branding'; }
/** Path of the logo uploaded for a branch, or null (the built-in variant is used then). */
function br_logo_file(int $id): ?string {
    foreach (['png', 'jpg'] as $ext) { $f = br_logo_dir() . '/branch-' . $id . '.' . $ext; if (is_file($f)) return $f; }
    return null;
}
/** Store an uploaded PNG / JPEG as the branch logo (max 2 MB, at least 200 px wide); replaces the previous one. */
function br_logo_upload(int $id, array $file): void {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('br.logo_bad'));
    if ((int)$file['size'] > 2 * 1024 * 1024) throw new RuntimeException(t('br.logo_big'));
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] < 200) throw new RuntimeException(t('br.logo_bad'));
    $dir = br_logo_dir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) throw new RuntimeException(t('br.logo_bad'));
    br_logo_remove($id);
    $dst = $dir . '/branch-' . $id . '.' . ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
    if (!move_uploaded_file($file['tmp_name'], $dst)) throw new RuntimeException(t('br.logo_bad'));
    @chmod($dst, 0644);
}
function br_logo_remove(int $id): void { foreach (['png', 'jpg'] as $ext) { $f = br_logo_dir() . '/branch-' . $id . '.' . $ext; if (is_file($f)) @unlink($f); } }
/** Stream a branch logo for the settings preview (?p=brlogo&id=N): the uploaded one, else the built-in variant. */
function br_logo_stream(int $id): void {
    $b = br_get($id); $f = br_logo_file((int)$b['id']) ?: br_doc(null, null, $b)['logo'];
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: ' . (str_ends_with($f, '.jpg') ? 'image/jpeg' : 'image/png')); header('Cache-Control: private, max-age=60'); header('Content-Length: ' . filesize($f));
    readfile($f); exit;
}

/* ------------------------------------------------------------ documents */

/** Identity block for a document issued under a project / sub-project: prefix, logo path, footer line, header lines.   R24-002 */
function br_doc(?int $projectId, ?int $subId = null, ?array $b = null): array {
    $b = $b ?? br_of($projectId, $subId);
    $root = dirname(__DIR__, 2) . '/assets/';
    $logo = $b['logo_variant'] && $b['logo_variant'] !== 'default' && is_file($root . 'golden-logo-' . $b['logo_variant'] . '.png') ? $root . 'golden-logo-' . $b['logo_variant'] . '.png' : $root . 'golden-logo.png';
    if (!empty($b['id'])) $logo = br_logo_file((int)$b['id']) ?: $logo;                                 // an uploaded logo wins over the built-in variant
    $trade = (string)($b['trade_name'] ?: $b['legal_name']);
    $lines = array_values(array_filter([(string)$b['legal_name'], (string)($b['address'] ?? ''), trim(($b['vat_no'] ? (($b['country'] === 'IT' ? 'P.IVA ' : 'VAT ') . $b['vat_no']) : '') . ($b['registry'] ? ' · ' . $b['registry'] : ''), ' ·'), trim(($b['email'] ?? '') . ($b['phone'] ? ' · ' . $b['phone'] : ''), ' ·')]));
    return ['branch' => $b, 'prefix' => (string)$b['doc_prefix'] ?: 'GAS', 'logo' => $logo, 'name' => $trade, 'legal' => (string)$b['legal_name'], 'lines' => $lines,
            'footer' => $trade . ($b['vat_no'] ? ' · ' . ($b['country'] === 'IT' ? 'P.IVA ' : 'VAT ') . $b['vat_no'] : ''), 'logo_ratio' => 386 / 1024];
}
/** Document number prefix for the portal's issued documents (GAS-WR-… / GIT-WR-…). */
function br_prefix(?int $projectId, ?int $subId = null): string { return br_doc($projectId, $subId)['prefix']; }
/** The offer series of a branch: its own (GIT-OF) or, since R40-001, "<document prefix>-OF" (GAS-OF) — every branch numbers its portal offers. */
function br_offer_series(array $b): string { $s = strtoupper(trim((string)($b['offer_series'] ?? ''))); return $s !== '' ? $s : (((string)($b['doc_prefix'] ?? '') ?: 'GAS') . '-OF'); }
/** Portal-generated offer number (GAS-OF-2026-001 / GIT-OF-2026-001), assigned at creation for every branch (release C, R40-001). */
function br_offer_no(?int $projectId, ?int $subId = null): string {
    $b = br_of($projectId, $subId); $s = br_offer_series($b);
    if (!function_exists('of_has') || !of_has()) return '';
    $base = $s . '-' . date('Y') . '-';
    $n = (int) scalar("SELECT MAX(CAST(SUBSTRING(offer_no, ?) AS UNSIGNED)) FROM offers WHERE offer_no LIKE ?", [strlen($base) + 1, $base . '%']) + 1;
    return $base . sprintf('%03d', $n);
}

/** Mean manhour cost of a branch (EUR/h) — the company's cost of labour when a discipline has no cost rate of its own.   R40-002 */
function br_manhour_cost(?int $projectId = null, ?int $subId = null): float {
    $b = $projectId || $subId ? br_of($projectId, $subId) : br_default();
    return br_manhour_cost_of($b);
}
function br_manhour_cost_of(array $b): float { $c = (float)($b['manhour_cost'] ?? 0); return $c > 0 ? $c : 35.0; }
/** Cost per hour for a discipline row and hour type, with the branch mean as the fallback (R40-002). */
function br_hour_cost(?array $d, string $hourType, ?int $projectId = null, ?int $subId = null): float {
    $ht = in_array($hourType, ['normal', 'overtime', 'holiday'], true) ? $hourType : 'normal';
    $r = $d ? (float)(($d['rate_' . $ht] ?? null) ?: ($d['rate_normal'] ?? 0)) : 0.0;
    return $r > 0 ? $r : br_manhour_cost($projectId, $subId);
}

/* ------------------------------------------------------------ admin */

function br_save(): int {
    require_cp_admin(); if (!br_has()) throw new RuntimeException(t('br.need_db'));
    $id = (int)($_POST['id'] ?? 0);
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s('code', 8))) ?: 'BR';
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s('doc_prefix', 8))) ?: 'GAS';
    $series = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $s('offer_series', 16))) ?: null;
    $cal = $s('work_calendar', 8) ?: null; if ($cal && function_exists('wc_calendars') && !isset(wc_calendars()[$cal])) $cal = null;
    $variant = preg_replace('/[^a-z0-9\-]/', '', strtolower($s('logo_variant', 20))) ?: 'default';
    $name = $s('name', 80); $legal = $s('legal_name', 200); if ($name === '' || $legal === '') throw new RuntimeException(t('br.need_name'));
    if (scalar('SELECT COUNT(*) FROM branches WHERE code = ? AND id <> ?', [$code, $id])) throw new RuntimeException(t('br.dup_code'));
    $vals = [$code, $name, $legal, $s('trade_name', 120) ?: null, $s('address', 300) ?: null, strtoupper($s('country', 2)) ?: 'GR', $s('vat_no', 30) ?: null, $s('registry', 160) ?: null, $s('email', 190) ?: null, $s('phone', 60) ?: null,
             $prefix, $series, $cal, strtoupper($s('currency', 3)) ?: 'EUR', $variant, (int)!empty($_POST['is_active']), (int)($_POST['sort_order'] ?? 10)];
    if ($id) { q('UPDATE branches SET code=?, name=?, legal_name=?, trade_name=?, address=?, country=?, vat_no=?, registry=?, email=?, phone=?, doc_prefix=?, offer_series=?, work_calendar=?, currency=?, logo_variant=?, is_active=?, sort_order=? WHERE id = ?', [...$vals, $id]); }
    else { q('INSERT INTO branches (code, name, legal_name, trade_name, address, country, vat_no, registry, email, phone, doc_prefix, offer_series, work_calendar, currency, logo_variant, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals); $id = (int) db()->lastInsertId(); }
    if (isset($_POST['accounting_email']) && function_exists('acc_has') && acc_has()) { $ae = strtolower(trim((string)$_POST['accounting_email'])); q('UPDATE branches SET accounting_email = ? WHERE id = ?', [filter_var($ae, FILTER_VALIDATE_EMAIL) ? mb_substr($ae, 0, 190) : null, $id]); }   // R38-001
    if (isset($_POST['manhour_cost']) && br_has_cost()) { $mc = (float) str_replace(',', '.', (string)$_POST['manhour_cost']); q('UPDATE branches SET manhour_cost = ? WHERE id = ?', [$mc > 0 && $mc < 10000 ? round($mc, 2) : 35.0, $id]); }   // R40-002
    if (!empty($_POST['is_default'])) { q('UPDATE branches SET is_default = 0'); q('UPDATE branches SET is_default = 1, is_active = 1 WHERE id = ?', [$id]); }
    if (!empty($_POST['logo_remove'])) br_logo_remove($id);
    if (!empty($_FILES['logo_file'])) br_logo_upload($id, $_FILES['logo_file']);
    $GLOBALS['br_dirty'] = true;
    return $id;
}
function br_delete(int $id): void {
    require_cp_admin(); $b = br_get($id);
    if ((int)$b['is_default']) throw new RuntimeException(t('br.no_del_default'));
    $n = (int) scalar('SELECT (SELECT COUNT(*) FROM projects WHERE branch_id = ?) + (SELECT COUNT(*) FROM subprojects WHERE branch_id = ?) + (SELECT COUNT(*) FROM customer_users WHERE branch_id = ?)', [$id, $id, $id]);
    if ($n) throw new RuntimeException(t('br.in_use', ['n' => $n]));
    q('DELETE FROM branches WHERE id = ?', [$id]); $GLOBALS['br_dirty'] = true;
}
/** Branch field of the project / sub-project forms (NULL when not posted or unknown). */
function br_from_post(string $key = 'branch_id'): ?int {
    if (!br_has() || !isset($_POST[$key])) return null;
    $id = (int)$_POST[$key]; return $id && isset(br_all()[$id]) ? $id : null;
}
/** <select> options for a branch picker; $inherit adds the "inherits the project" / "default branch" first option. */
function br_options(?int $selected, ?string $firstLabel = null): string {
    $h = $firstLabel !== null ? '<option value="">' . e($firstLabel) . '</option>' : '';
    foreach (br_all(true) as $b) $h .= '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === (int)$selected ? ' selected' : '') . '>' . e($b['code'] . ' · ' . $b['name']) . '</option>';
    return $h;
}
/** Usage counts for the settings page. */
function br_usage(int $id): array {
    if (!br_has()) return ['projects' => 0, 'subprojects' => 0, 'users' => 0];
    return ['projects' => (int) scalar('SELECT COUNT(*) FROM projects WHERE branch_id = ?', [$id]), 'subprojects' => (int) scalar('SELECT COUNT(*) FROM subprojects WHERE branch_id = ?', [$id]), 'users' => (int) scalar('SELECT COUNT(*) FROM customer_users WHERE branch_id = ?', [$id])];
}
