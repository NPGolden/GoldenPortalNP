<?php
/**
 * HR › Assessments — questionnaires that measure qualities (reliability, consistency, willingness to learn …).   GAS-CP-R97-063
 *
 * HR or the CEO imports the Excel template GAS-2026-PORTAL-HRA-001 (sheets Campaign, Dimensions, Scale, Questions, Options, Bands),
 * chooses the people and the period, and starts the campaign. Each person answers the SELF form; the head of the department
 * answers the MANAGER form. Scores are 0–100 per dimension (see hrs_score); the results are kept per year in hrs_scores and are
 * shown in the annual evaluation.
 *
 * Privacy: answers and scores stay in the portal. Nothing in this file calls the assistant, and nothing here may be added to
 * hr_eval_ai_context(). The employee sees only the own result; a rater never sees the self answers or the result.
 */

const HRS_LANGS = ['en', 'el', 'it', 'bg', 'uk'];
const HRS_TYPES = ['LIKERT', 'SITUATION', 'CHOICE', 'CHECK'];
const HRS_MAX_FILE = 3145728;   // 3 MB

function hrs_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hrs_campaigns'") && function_exists('hr2_has') && hr2_has(); } catch (Throwable $e) { $h = false; } } return $h; }
function hrs_can(): bool { return function_exists('hr_can') && hr_can() && hrs_has(); }
function hrs_me(): int { return (int) (current_user()['id'] ?? 0); }
/** Text of a {lang: text} map in the portal language, English when not translated. */
function hrs_tx($m, ?string $loc = null): string { if (!is_array($m)) return (string) $m; $l = $loc ?: locale(); return (string) ((($m[$l] ?? '') !== '') ? $m[$l] : ($m['en'] ?? '')); }
function hrs_status_pill(string $s): string { return pill(['draft' => 'mute', 'scheduled' => 'gold', 'running' => 'good', 'closed' => 'mute'][$s] ?? 'mute', t('hrs.st_' . $s)); }
function hrs_template_path(): string { return __DIR__ . '/tpl/GAS-2026-PORTAL-HRA-001_R0_HR-assessment-campaign-template.xlsx'; }

/* ------------------------------------------------------------ import of the Excel template */

/** Names of the sheets; refuses archives that are not a plain workbook (too many parts, too large when unpacked). */
function hrs_sheet_names(string $path): array {
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException(t('hrs.e_file'));
    $tot = 0; for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); $tot += (int) ($st['size'] ?? 0); }
    if ($z->numFiles > 300 || $tot > 40 * 1048576) { $z->close(); throw new RuntimeException(t('hrs.e_file')); }
    $wb = (string) $z->getFromName('xl/workbook.xml'); $z->close();
    preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"/', $wb, $m);
    return array_map(fn($s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
}
/** Rows of one sheet as [header => value] (headers in lower case): from the row after the one whose first cell is $first to the first empty row. */
function hrs_sheet(string $path, string $name, string $first): array {
    $rows = xlsx_rows($path, $name); $hdr = null; $out = [];
    foreach ($rows as $i => $r) {
        if ($hdr === null) {
            if (mb_strtolower(trim((string) ($r[0] ?? ''))) === mb_strtolower($first)) { $hdr = []; foreach ($r as $c => $h) if (trim((string) $h) !== '') $hdr[$c] = mb_strtolower(trim((string) $h)); }
            continue;
        }
        if (mb_strtolower(trim((string) ($r[0] ?? ''))) === 'note') break;   // the note under a table of the template
        $row = ['_row' => $i + 1]; $any = false;
        foreach ($hdr as $c => $h) { $v = trim((string) ($r[$c] ?? '')); $row[$h] = mb_substr($v, 0, 2000); if ($v !== '') $any = true; }
        if ($any) $out[] = $row; elseif ($out) break;   // the table ends at the first empty row; notes may follow
    }
    if ($hdr === null) throw new RuntimeException(t('hrs.e_header', ['s' => $name, 'h' => $first]));
    return $out;
}
/** Read and check the workbook. Returns ['form' => array|null, 'errors' => string[], 'warnings' => string[]]. */
function hrs_parse(string $path): array {
    $err = []; $warn = [];
    $E = function (string $sheet, $row, string $msg) use (&$err) { if (count($err) < 60) $err[] = $sheet . ($row ? ' · ' . t('hrs.row') . ' ' . $row : '') . ': ' . $msg; };
    $langs = fn(array $r, string $p) => array_filter(array_combine(HRS_LANGS, array_map(fn($l) => (string) ($r[$p . ' ' . $l] ?? ''), HRS_LANGS)), fn($v) => $v !== '');
    $yn = fn(string $v, bool $def) => $v === '' ? $def : in_array(mb_strtoupper($v), ['Y', 'YES', '1', 'TRUE', 'ΝΑΙ'], true);
    $num = fn(string $v, float $def) => is_numeric(str_replace(',', '.', $v)) ? (float) str_replace(',', '.', $v) : $def;
    try { $names = hrs_sheet_names($path); } catch (Throwable $e) { return ['form' => null, 'errors' => [$e->getMessage()], 'warnings' => []]; }
    $S = [];
    foreach (['Campaign' => 'Key', 'Dimensions' => 'Code', 'Scale' => 'Value', 'Questions' => 'No', 'Options' => 'Question', 'Bands' => 'From'] as $sh => $first) {
        if (!in_array($sh, $names, true)) { $E($sh, 0, t('hrs.e_sheet')); $S[$sh] = []; continue; }
        try { $S[$sh] = hrs_sheet($path, $sh, $first); } catch (Throwable $e) { $E($sh, 0, $e->getMessage()); $S[$sh] = []; }
    }
    if ($err) return ['form' => null, 'errors' => $err, 'warnings' => []];

    /* settings */
    $kv = []; foreach ($S['Campaign'] as $r) $kv[mb_strtolower($r['key'])] = (string) ($r['value'] ?? '');
    $fam = mb_strtoupper(trim($kv['family_code'] ?? '')); if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,39}$/', $fam)) $E('Campaign', 0, t('hrs.e_family'));
    $title = []; $intro = []; foreach (HRS_LANGS as $l) { if (($kv['title_' . $l] ?? '') !== '') $title[$l] = mb_substr($kv['title_' . $l], 0, 180); if (($kv['intro_' . $l] ?? '') !== '') $intro[$l] = $kv['intro_' . $l]; }
    if (empty($title['en'])) $E('Campaign', 0, t('hrs.e_title'));
    $set = ['family_code' => $fam, 'family_version' => max(1, (int) $num($kv['family_version'] ?? '', 1)), 'time_limit_min' => max(0, min(600, (int) $num($kv['time_limit_min'] ?? '', 0))),
            'manager_form' => $yn($kv['manager_form'] ?? '', true), 'self_weight' => max(0.0, $num($kv['self_weight'] ?? '', 0.5)), 'manager_weight' => max(0.0, $num($kv['manager_weight'] ?? '', 0.5)),
            'employee_sees_result' => $yn($kv['employee_sees_result'] ?? '', true), 'min_group' => max(3, (int) $num($kv['min_group'] ?? '', 5)),
            'fast_seconds' => max(0.0, $num($kv['fast_seconds'] ?? '', 3)), 'pair_tolerance' => max(0, (int) $num($kv['pair_tolerance'] ?? '', 1))];
    if ($set['self_weight'] + $set['manager_weight'] <= 0) $E('Campaign', 0, t('hrs.e_weights'));
    if ((int) $num($kv['attempts'] ?? '', 1) !== 1) $warn[] = t('hrs.w_attempts');

    /* dimensions */
    $dims = [];
    foreach ($S['Dimensions'] as $r) {
        $c = mb_strtoupper($r['code'] ?? ''); if (!preg_match('/^[A-Z][A-Z0-9_]{1,11}$/', $c)) { $E('Dimensions', $r['_row'], t('hrs.e_dim_code')); continue; }
        if (isset($dims[$c])) { $E('Dimensions', $r['_row'], t('hrs.e_dup', ['v' => $c])); continue; }
        $n = $langs($r, 'name'); if (empty($n['en'])) $E('Dimensions', $r['_row'], t('hrs.e_text_en'));
        $dims[$c] = ['name' => $n, 'desc' => (string) ($r['description'] ?? ''), 'w' => max(0.0, $num($r['weight'] ?? '', 1)) ?: 1.0, 'o' => (int) $num($r['order'] ?? '', count($dims) + 1)];
    }
    if (!$dims) $E('Dimensions', 0, t('hrs.e_empty')); if (count($dims) > 12) $E('Dimensions', 0, t('hrs.e_too_many'));
    uasort($dims, fn($a, $b) => $a['o'] <=> $b['o']);

    /* scale: exactly 1–5, the formula of the template depends on it */
    $scale = [];
    foreach ($S['Scale'] as $r) { $v = (int) ($r['value'] ?? 0); $lab = $langs($r, 'label'); if ($v < 1 || $v > 5 || empty($lab['en'])) { $E('Scale', $r['_row'], t('hrs.e_scale')); continue; } $scale[$v] = $lab; }
    ksort($scale); if (array_keys($scale) !== [1, 2, 3, 4, 5]) $E('Scale', 0, t('hrs.e_scale'));

    /* questions */
    $Q = [];
    foreach ($S['Questions'] as $r) {
        $no = (int) ($r['no'] ?? 0); if ($no < 1 || $no > 9999 || (string) $no !== (string) ($r['no'] ?? '')) { $E('Questions', $r['_row'], t('hrs.e_no')); continue; }
        if (isset($Q[$no])) { $E('Questions', $r['_row'], t('hrs.e_dup', ['v' => $no])); continue; }
        $f = mb_strtoupper($r['form'] ?? ''); $ty = mb_strtoupper($r['type'] ?? ''); $d = mb_strtoupper($r['dimension'] ?? '');
        if (!in_array($f, ['SELF', 'MANAGER'], true)) { $E('Questions', $r['_row'], t('hrs.e_form')); continue; }
        if (!in_array($ty, HRS_TYPES, true)) { $E('Questions', $r['_row'], t('hrs.e_type')); continue; }
        if ($f === 'MANAGER' && $ty !== 'LIKERT') { $E('Questions', $r['_row'], t('hrs.e_mgr_type')); continue; }
        if (in_array($ty, ['LIKERT', 'SITUATION'], true) && !isset($dims[$d])) { $E('Questions', $r['_row'], t('hrs.e_dim', ['v' => $d])); continue; }
        $tx = $langs($r, 'text'); if (empty($tx['en'])) { $E('Questions', $r['_row'], t('hrs.e_text_en')); continue; }
        $Q[$no] = ['f' => $f === 'SELF' ? 'S' : 'M', 'd' => in_array($ty, ['LIKERT', 'SITUATION'], true) ? $d : '', 't' => $ty, 'r' => $yn($r['reverse'] ?? '', false) ? 1 : 0,
                   'w' => $ty === 'CHECK' ? 0.0 : max(0.0, $num($r['weight'] ?? '', 1)), 'p' => (int) ($r['pair'] ?? 0), 'req' => $yn($r['required'] ?? '', true) ? 1 : 0, 'x' => 0, 'tx' => $tx, 'o' => [], '_row' => $r['_row']];
    }
    if (count($Q) > 300) $E('Questions', 0, t('hrs.e_too_many'));
    foreach ($Q as $no => &$q) {
        if ($q['t'] !== 'CHECK') { $q['p'] = 0; continue; }
        if ($q['p']) { $pq = $Q[$q['p']] ?? null; if (!$pq || $pq['f'] !== 'S' || $pq['t'] !== 'LIKERT') $E('Questions', $q['_row'], t('hrs.e_pair', ['v' => $q['p']])); continue; }
        if (preg_match_all('/[“"«‘\']([^”"»’\']{2,60})[”"»’\']/u', (string) $q['tx']['en'], $m)) foreach ($m[1] as $quoted) foreach ($scale as $v => $lab) if (mb_strtolower(trim($quoted)) === mb_strtolower((string) $lab['en'])) $q['x'] = $v;
        if (!$q['x']) $E('Questions', $q['_row'], t('hrs.e_check'));
    }
    unset($q);

    /* options */
    foreach ($S['Options'] as $r) {
        $no = (int) ($r['question'] ?? 0); $l = mb_strtoupper(mb_substr($r['option'] ?? '', 0, 2)); $q = $Q[$no] ?? null;
        if (!$q || !in_array($q['t'], ['SITUATION', 'CHOICE'], true)) { $E('Options', $r['_row'], t('hrs.e_opt_q', ['v' => $no])); continue; }
        if (!preg_match('/^[A-Z]{1,2}$/', $l) || isset($q['o'][$l])) { $E('Options', $r['_row'], t('hrs.e_opt_letter')); continue; }
        $tx = $langs($r, 'text'); if (empty($tx['en'])) { $E('Options', $r['_row'], t('hrs.e_text_en')); continue; }
        $d = mb_strtoupper($r['dimension'] ?? ''); if ($q['t'] === 'CHOICE' && !isset($dims[$d])) { $E('Options', $r['_row'], t('hrs.e_dim', ['v' => $d])); continue; }
        $Q[$no]['o'][$l] = ['pt' => max(0.0, $num($r['points'] ?? '', 0)), 'd' => $q['t'] === 'CHOICE' ? $d : $q['d'], 'tx' => $tx];
    }
    $selfDims = []; $mgrDims = []; $nSelf = 0; $nMgr = 0;
    foreach ($Q as $no => $q) {
        if ($q['t'] === 'SITUATION' && (count($q['o']) < 2 || max(array_column($q['o'], 'pt')) <= 0)) $E('Questions', $q['_row'], t('hrs.e_sit_opts', ['v' => $no]));
        if ($q['t'] === 'CHOICE' && (count($q['o']) !== 2 || count(array_unique(array_column($q['o'], 'd'))) !== 2)) $E('Questions', $q['_row'], t('hrs.e_choice_opts', ['v' => $no]));
        if ($q['f'] === 'S') { $nSelf++; if ($q['w'] > 0) { if ($q['d'] !== '') $selfDims[$q['d']] = 1; foreach ($q['o'] as $o) if ($q['t'] === 'CHOICE') $selfDims[$o['d']] = 1; } }
        else { $nMgr++; if ($q['w'] > 0) $mgrDims[$q['d']] = 1; }
        unset($Q[$no]['_row']);
    }
    if (!$nSelf) $E('Questions', 0, t('hrs.e_empty'));
    foreach ($dims as $c => $_) if (!isset($selfDims[$c])) $E('Dimensions', 0, t('hrs.e_dim_unused', ['v' => $c]));
    if ($set['manager_form'] && !$nMgr) { $set['manager_form'] = false; $warn[] = t('hrs.w_no_mgr'); }
    if (!$set['manager_form']) foreach ($Q as $no => $q) if ($q['f'] === 'M') unset($Q[$no]);
    if ($set['manager_form']) foreach ($dims as $c => $_) if (!isset($mgrDims[$c])) $warn[] = t('hrs.w_dim_no_mgr', ['v' => $c]);
    foreach (array_diff(HRS_LANGS, ['en']) as $l) { $miss = 0; foreach ($Q as $q) if (empty($q['tx'][$l])) $miss++; if ($miss) $warn[] = t('hrs.w_lang', ['l' => mb_strtoupper($l), 'n' => $miss]); }

    /* bands: 0–100 without gaps */
    $bands = [];
    foreach ($S['Bands'] as $r) { $a = (int) ($r['from'] ?? -1); $b = (int) ($r['to'] ?? -1); $lab = $langs($r, 'label'); if ($a < 0 || $b > 100 || $b < $a || empty($lab['en'])) { $E('Bands', $r['_row'], t('hrs.e_band')); continue; } $bands[] = ['a' => $a, 'b' => $b, 'l' => $lab, 'g' => $langs($r, 'guidance')]; }
    usort($bands, fn($x, $y) => $x['a'] <=> $y['a']); $next = 0; foreach ($bands as $b) { if ($b['a'] !== $next) { $E('Bands', 0, t('hrs.e_band_gap')); break; } $next = $b['b'] + 1; }
    if ($bands && !$err && $next !== 101) $E('Bands', 0, t('hrs.e_band_gap'));
    if (!$bands) $E('Bands', 0, t('hrs.e_empty'));

    if ($err) return ['form' => null, 'errors' => $err, 'warnings' => $warn];
    ksort($Q);
    return ['form' => ['set' => $set, 'title' => $title, 'intro' => $intro, 'dims' => $dims, 'scale' => $scale, 'q' => $Q, 'bands' => $bands], 'errors' => [], 'warnings' => $warn];
}
/** Import of an uploaded workbook: a draft campaign, or the list of problems. */
function hrs_import_upload(?array $up): array {
    if (!hrs_can()) throw new RuntimeException(t('err.noaccess'));
    if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $up['tmp_name'])) throw new RuntimeException(t('hrs.e_file'));
    if ((int) $up['size'] > HRS_MAX_FILE || strtolower(pathinfo((string) $up['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException(t('hrs.e_file'));
    require_once __DIR__ . '/xlsx.php';
    $res = hrs_parse((string) $up['tmp_name']); if ($res['errors']) return $res;
    $F = $res['form']; $u = current_user();
    q('INSERT INTO hrs_campaigns (family_code, family_version, title, form, file_name, created_by, created_name) VALUES (?,?,?,?,?,?,?)',
      [$F['set']['family_code'], $F['set']['family_version'], mb_substr((string) $F['title']['en'], 0, 200), json_encode($F, JSON_UNESCAPED_UNICODE), mb_substr(basename((string) $up['name']), 0, 190), (int) ($u['id'] ?? 0) ?: null, mb_substr((string) ($u['full_name'] ?? ''), 0, 120)]);
    $res['id'] = (int) db()->lastInsertId(); return $res;
}

/* ------------------------------------------------------------ campaigns and people */

function hrs_campaign(int $id): ?array { if (!hrs_has() || $id < 1) return null; $c = one('SELECT * FROM hrs_campaigns WHERE id = ?', [$id]); if ($c) $c['F'] = json_decode((string) $c['form'], true) ?: []; return $c ?: null; }
function hrs_campaigns(): array {
    return hrs_has() ? all("SELECT c.id, c.family_code, c.family_version, c.title, c.status, c.starts_on, c.ends_on, c.year, c.created_name, c.created_at, c.closed_at,
        (SELECT COUNT(*) FROM hrs_targets t WHERE t.campaign_id = c.id) AS n, (SELECT COUNT(*) FROM hrs_targets t WHERE t.campaign_id = c.id AND t.self_status = 'done') AS n_done
        FROM hrs_campaigns c ORDER BY FIELD(c.status, 'running', 'scheduled', 'draft', 'closed'), COALESCE(c.ends_on, c.created_at) DESC, c.id DESC") : [];
}
function hrs_targets(int $cid): array {
    return all('SELECT t.*, p.name_en, p.name_el, p.is_active AS person_active, d.name AS department, ru.full_name AS rater_name FROM hrs_targets t JOIN hr_people p ON p.id = t.person_id
                LEFT JOIN disciplines d ON d.id = t.department_id LEFT JOIN customer_users ru ON ru.id = t.rater_user_id WHERE t.campaign_id = ? ORDER BY d.sort_order, p.name_en', [$cid]);
}
function hrs_target(int $cid, int $pid): ?array { return one('SELECT * FROM hrs_targets WHERE campaign_id = ? AND person_id = ?', [$cid, $pid]) ?: null; }
/** Who rates the person: the head of the department; when that is the person or nobody, the person's own manager; else nobody. */
function hrs_rater_for(array $p): ?int {
    $own = (int) ($p['user_id'] ?? 0);
    foreach ([(int) ($p['head_user_id'] ?? 0), (int) (hr_manager_of($p) ?? 0)] as $r) if ($r > 0 && $r !== $own) return $r;
    return null;
}
/** The chosen people of a campaign that has not closed. People who already answered are never removed. */
function hrs_targets_set(array $c, array $pids): void {
    if ($c['status'] === 'closed') throw new RuntimeException(t('hrs.e_closed'));
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids)))); $cur = []; foreach (hrs_targets((int) $c['id']) as $t) $cur[(int) $t['person_id']] = $t;
    $new = [];
    foreach ($pids as $pid) {
        if (isset($cur[$pid])) continue; $p = hr_person($pid); if (!$p || empty($p['user_id']) || empty($p['is_active'])) continue;
        q('INSERT IGNORE INTO hrs_targets (campaign_id, person_id, user_id, rater_user_id, department_id) VALUES (?,?,?,?,?)', [(int) $c['id'], $pid, (int) $p['user_id'], hrs_rater_for($p), (int) ($p['department_id'] ?? 0) ?: null]);
        $new[] = $pid;
    }
    foreach ($cur as $pid => $t) if (!in_array($pid, $pids, true) && $t['self_status'] === 'pending' && $t['mgr_status'] === 'pending' && $t['answers'] === null) q('DELETE FROM hrs_targets WHERE id = ?', [(int) $t['id']]);
    if ($c['status'] === 'running' && $new) hrs_notify_start($c, $new);
}
function hrs_notify_start(array $c, ?array $onlyPids = null): void {
    if (!function_exists('nf_push')) return; $me = null; $by = null; $F = $c['F']; $raters = [];   // no actor: the person who starts the campaign may be one of the people or a rater and must be told too
    foreach (hrs_targets((int) $c['id']) as $t) {
        if ($onlyPids !== null && !in_array((int) $t['person_id'], $onlyPids, true)) continue;
        if ($t['user_id'] && $t['self_status'] !== 'done') nf_push([(int) $t['user_id']], 'hrs:start', 'hrs:' . $c['id'] . ':' . $t['person_id'], t('hrs.nf_start', ['t' => hrs_tx($F['title']), 'd' => fmt_date($c['ends_on'])]), null, url('hr_test_take', ['id' => (int) $c['id']]), $me, $by);
        if (!empty($F['set']['manager_form']) && $t['rater_user_id'] && $t['mgr_status'] !== 'done') $raters[(int) $t['rater_user_id']] = 1;
    }
    foreach (array_keys($raters) as $r) nf_push([$r], 'hrs:rate', 'hrs:' . $c['id'] . ':r' . $r . ':' . ($onlyPids === null ? 0 : time()), t('hrs.nf_rate', ['t' => hrs_tx($F['title']), 'd' => fmt_date($c['ends_on'])]), null, url('hr_test_take', ['id' => (int) $c['id'], 'form' => 'mgr']), $me, $by);
}
/** Dates, then start now or on the first day. */
function hrs_start(array $c, string $from, string $to): string {
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) throw new RuntimeException(t('hrs.e_closed'));
    $ok = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; $today = date('Y-m-d');
    if (!$ok($from) || !$ok($to) || $to < $from || $to < $today || $to > date('Y-m-d', strtotime('+2 years'))) throw new RuntimeException(t('pl.bad_dates'));
    if (!(int) scalar('SELECT COUNT(*) FROM hrs_targets WHERE campaign_id = ?', [(int) $c['id']])) throw new RuntimeException(t('hrs.e_no_people'));
    $st = $from > $today ? 'scheduled' : 'running';
    q('UPDATE hrs_campaigns SET starts_on = ?, ends_on = ?, year = ?, status = ?, started_at = ' . ($st === 'running' ? 'NOW()' : 'NULL') . ', reminded = 0 WHERE id = ?', [$from, $to, (int) substr($to, 0, 4), $st, (int) $c['id']]);
    if ($st === 'running') hrs_begin((int) $c['id']);
    return $st;
}
/** The campaign opens: the rater of each person is fixed now, everybody is told. */
function hrs_begin(int $cid): void {
    $c = hrs_campaign($cid); if (!$c) return;
    foreach (hrs_targets($cid) as $t) { $p = hr_person((int) $t['person_id']); if ($p && $t['mgr_status'] === 'pending') q('UPDATE hrs_targets SET rater_user_id = ?, department_id = ?, user_id = ? WHERE id = ?', [hrs_rater_for($p), (int) ($p['department_id'] ?? 0) ?: null, (int) ($p['user_id'] ?? 0) ?: null, (int) $t['id']]); }
    hrs_notify_start($c);
}
function hrs_remind(array $c): int {
    if ($c['status'] !== 'running' || !function_exists('nf_push')) return 0; $F = $c['F']; $n = 0; $raters = []; $k = date('Ymd');
    foreach (hrs_targets((int) $c['id']) as $t) {
        if ($t['user_id'] && $t['self_status'] !== 'done') $n += nf_push([(int) $t['user_id']], 'hrs:remind', 'hrs:' . $c['id'] . ':' . $t['person_id'] . ':' . $k, t('hrs.nf_remind', ['t' => hrs_tx($F['title']), 'd' => fmt_date($c['ends_on'])]), null, url('hr_test_take', ['id' => (int) $c['id']]), null, null);
        if (!empty($F['set']['manager_form']) && $t['rater_user_id'] && $t['mgr_status'] !== 'done') $raters[(int) $t['rater_user_id']] = 1;
    }
    foreach (array_keys($raters) as $r) $n += nf_push([$r], 'hrs:remind', 'hrs:' . $c['id'] . ':r' . $r . ':' . $k, t('hrs.nf_rate', ['t' => hrs_tx($F['title']), 'd' => fmt_date($c['ends_on'])]), null, url('hr_test_take', ['id' => (int) $c['id'], 'form' => 'mgr']), null, null);
    return $n;
}

/* ------------------------------------------------------------ answering */

/** Order of the questions of a form: statements mixed (the same order every time for the same person), then situations, then choices. */
function hrs_order(array $F, string $form, int $seed): array {
    $a = []; $s = []; $c = [];
    foreach ($F['q'] as $no => $q) { if ($q['f'] !== $form) continue; if ($q['t'] === 'SITUATION') $s[] = (int) $no; elseif ($q['t'] === 'CHOICE') $c[] = (int) $no; else $a[] = (int) $no; }
    if ($form === 'S') usort($a, fn($x, $y) => crc32($seed . ':' . $x) <=> crc32($seed . ':' . $y));
    return array_merge($a, $s, $c);
}
/** Keep only answers that exist in the form. */
function hrs_clean_answers(array $F, string $form, $in): array {
    $out = []; if (!is_array($in)) return $out;
    foreach ($F['q'] as $no => $q) {
        if ($q['f'] !== $form || !isset($in[$no]) || is_array($in[$no])) continue; $v = (string) $in[$no];
        if (in_array($q['t'], ['LIKERT', 'CHECK'], true)) { if (in_array($v, ['1', '2', '3', '4', '5'], true)) $out[$no] = (int) $v; }
        elseif (isset($q['o'][$v])) $out[$no] = $v;
    }
    return $out;
}
function hrs_missing(array $F, string $form, array $ans): array { $m = []; foreach ($F['q'] as $no => $q) if ($q['f'] === $form && $q['req'] && !isset($ans[$no])) $m[] = (int) $no; return $m; }
/** Save (and optionally submit) the answers of the person ($form = 'S') or of the rater ($form = 'M'). */
function hrs_answer(int $cid, int $pid, string $form, $in, bool $submit): array {
    $c = hrs_campaign($cid); $t = $c ? hrs_target($cid, $pid) : null; $me = hrs_me();
    if (!$c || !$t || $c['status'] !== 'running') throw new RuntimeException(t('hrs.e_not_open'));
    $F = $c['F'];
    if ($form === 'S') { if ((int) $t['user_id'] !== $me) throw new RuntimeException(t('err.noaccess')); if ($t['self_status'] === 'done') throw new RuntimeException(t('hrs.e_done')); }
    else { if (empty($F['set']['manager_form']) || (int) $t['rater_user_id'] !== $me) throw new RuntimeException(t('err.noaccess')); if ($t['mgr_status'] === 'done') throw new RuntimeException(t('hrs.e_done')); }
    $ans = hrs_clean_answers($F, $form, $in); $miss = hrs_missing($F, $form, $ans); $done = $submit && !$miss;
    if ($form === 'S') {
        $k = 'hrs_t' . (int) $t['id']; $sec = isset($_SESSION[$k]) ? max(0, min(2700, time() - (int) $_SESSION[$k])) : 0; $_SESSION[$k] = time();
        q('UPDATE hrs_targets SET answers = ?, self_seconds = self_seconds + ?, self_started_at = COALESCE(self_started_at, NOW()), self_status = ?, self_submitted_at = ' . ($done ? 'NOW()' : 'NULL') . ' WHERE id = ?', [json_encode($ans), $sec, $done ? 'done' : 'started', (int) $t['id']]);
    } else q('UPDATE hrs_targets SET mgr_answers = ?, mgr_status = ?, mgr_submitted_at = ' . ($done ? 'NOW()' : 'NULL') . ' WHERE id = ?', [json_encode($ans), $done ? 'done' : 'started', (int) $t['id']]);
    if ($done) hrs_rescore($c, (int) $t['id']);
    return ['done' => $done, 'missing' => $miss];
}

/* ------------------------------------------------------------ scoring (the method of the sheet “Methodology”) */

/**
 * LIKERT (answer−1)/4, reversed (5−answer)/4 · SITUATION points / highest points of the question · CHOICE 1 for the dimension of the chosen
 * statement, 0 for the other · CHECK is not scored. Dimension = round(100 × Σ(value × weight) / Σ(weight)). Combined = self × self_weight +
 * manager × manager_weight (self alone when there is no manager rating).
 */
function hrs_score(array $F, array $ans, array $mans, int $seconds = 0, ?string $started = null, ?string $submitted = null): array {
    $acc = []; foreach ($F['dims'] as $d => $_) $acc[$d] = ['sn' => 0.0, 'sd' => 0.0, 'mn' => 0.0, 'md' => 0.0];
    $contra = 0; $lik = []; $answered = 0; $tol = (int) ($F['set']['pair_tolerance'] ?? 1);
    foreach ($F['q'] as $no => $q) {
        $isM = $q['f'] === 'M'; $a = $isM ? ($mans[$no] ?? null) : ($ans[$no] ?? null); if ($a === null || $a === '') continue;
        if (!$isM) $answered++; $w = (float) $q['w']; $n = $isM ? 'mn' : 'sn'; $dn = $isM ? 'md' : 'sd';
        if ($q['t'] === 'LIKERT') {
            $v = (int) $a; if ($v < 1 || $v > 5) continue; if (!$isM) $lik[] = $v; if ($w <= 0 || !isset($acc[$q['d']])) continue;
            $acc[$q['d']][$n] += ($q['r'] ? (5 - $v) / 4 : ($v - 1) / 4) * $w; $acc[$q['d']][$dn] += $w;
        } elseif ($q['t'] === 'SITUATION') {
            $o = $q['o'][(string) $a] ?? null; $mx = $q['o'] ? max(array_column($q['o'], 'pt')) : 0; if (!$o || $w <= 0 || $mx <= 0 || !isset($acc[$q['d']])) continue;
            $acc[$q['d']][$n] += ((float) $o['pt'] / $mx) * $w; $acc[$q['d']][$dn] += $w;
        } elseif ($q['t'] === 'CHOICE') {
            if (!isset($q['o'][(string) $a]) || $w <= 0) continue;
            foreach ($q['o'] as $l => $o) { if (!isset($acc[$o['d']])) continue; $acc[$o['d']][$dn] += $w; if ((string) $l === (string) $a) $acc[$o['d']][$n] += $w; }
        } elseif (!$isM) {   // CHECK
            $v = (int) $a;
            if ($q['p']) { $pq = $F['q'][$q['p']] ?? null; $b = (int) ($ans[$q['p']] ?? 0); if ($pq && $b && abs(($q['r'] ? 6 - $v : $v) - ($pq['r'] ? 6 - $b : $b)) > $tol) $contra++; }
            elseif ($q['x'] && $v !== (int) $q['x']) $contra++;
        }
    }
    $sw = (float) ($F['set']['self_weight'] ?? 0.5); $mw = (float) ($F['set']['manager_weight'] ?? 0.5); $dims = []; $on = 0.0; $od = 0.0;
    foreach ($acc as $d => $x) {
        $s = $x['sd'] > 0 ? (int) round(100 * $x['sn'] / $x['sd']) : null; $m = $x['md'] > 0 ? (int) round(100 * $x['mn'] / $x['md']) : null;
        $cmb = $s === null ? null : (($m === null || $sw + $mw <= 0) ? $s : (int) round(($s * $sw + $m * $mw) / ($sw + $mw)));
        $dims[$d] = ['self' => $s, 'mgr' => $m, 'comb' => $cmb, 'gap' => ($s !== null && $m !== null) ? $s - $m : null];
        if ($cmb !== null) { $w = (float) ($F['dims'][$d]['w'] ?? 1); $on += $cmb * $w; $od += $w; }
    }
    $fs = (float) ($F['set']['fast_seconds'] ?? 0); $lim = (int) ($F['set']['time_limit_min'] ?? 0);
    $flags = ['contra' => $contra, 'fast' => $fs > 0 && $seconds > 0 && $answered > 0 && $seconds / $answered < $fs,
              'straight' => count($lik) >= 10 && max(array_count_values($lik)) / count($lik) >= 0.9,
              'overtime' => $lim > 0 && $started && $submitted && strtotime($submitted) - strtotime($started) > $lim * 60 + 120];
    return ['dims' => $dims, 'overall' => $od > 0 ? round($on / $od, 1) : null, 'flags' => $flags, 'valid' => !($contra >= 2 || $flags['fast'] || $flags['straight'])];
}
function hrs_band(array $F, ?int $score): ?array { if ($score === null) return null; foreach ($F['bands'] as $i => $b) if ($score >= $b['a'] && $score <= $b['b']) return $b + ['i' => $i, 'tone' => $i === 0 ? 'warn' : ($i === count($F['bands']) - 1 ? 'good' : 'gold')]; return null; }
function hrs_band_pill(array $F, ?int $score): string { $b = hrs_band($F, $score); return $score === null ? '<span class="hint">—</span>' : pill($b['tone'] ?? 'mute', $score . ($b ? ' · ' . hrs_tx($b['l']) : '')); }
/** Compute and store the scores of one person. */
function hrs_rescore(array $c, int $tid): void {
    $t = one('SELECT * FROM hrs_targets WHERE id = ?', [$tid]); if (!$t) return;
    $ans = $t['self_status'] === 'done' ? (json_decode((string) $t['answers'], true) ?: []) : []; $mans = $t['mgr_status'] === 'done' ? (json_decode((string) $t['mgr_answers'], true) ?: []) : [];
    if (!$ans && !$mans) { q('UPDATE hrs_targets SET scores = NULL, flags = NULL, valid = 1, overall = NULL WHERE id = ?', [$tid]); return; }
    $r = hrs_score($c['F'], $ans, $mans, (int) $t['self_seconds'], $t['self_started_at'], $t['self_submitted_at']);
    q('UPDATE hrs_targets SET scores = ?, flags = ?, valid = ?, overall = ? WHERE id = ?', [json_encode($r['dims']), json_encode($r['flags']), $r['valid'] ? 1 : 0, $r['overall'], $tid]);
}
/** The campaign closes: final scores, the yearly record, the open evaluations of the year, a message to the people. */
function hrs_close(array $c, ?int $by = null): int {
    if ($c['status'] !== 'running') throw new RuntimeException(t('hrs.e_not_open'));
    $cid = (int) $c['id']; $F = $c['F']; $year = (int) ($c['year'] ?: date('Y')); $n = 0; $tell = [];
    q("UPDATE hrs_campaigns SET status = 'closed', closed_at = NOW(), closed_by = ?, year = ? WHERE id = ?", [$by, $year, $cid]);
    q("UPDATE hrs_targets SET self_status = 'missed' WHERE campaign_id = ? AND self_status <> 'done'", [$cid]);
    q("UPDATE hrs_targets SET mgr_status = 'missed' WHERE campaign_id = ? AND mgr_status <> 'done'", [$cid]);
    foreach (all('SELECT id FROM hrs_targets WHERE campaign_id = ?', [$cid]) as $r) hrs_rescore($c, (int) $r['id']);
    foreach (all('SELECT * FROM hrs_targets WHERE campaign_id = ? AND scores IS NOT NULL', [$cid]) as $t) {
        foreach ((json_decode((string) $t['scores'], true) ?: []) as $d => $s) {
            if ($s['self'] === null && $s['mgr'] === null) continue;
            q('INSERT INTO hrs_scores (campaign_id, person_id, family_code, family_version, year, dim, self_score, mgr_score, combined, valid, department_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE self_score = VALUES(self_score), mgr_score = VALUES(mgr_score), combined = VALUES(combined), valid = VALUES(valid)',
              [$cid, (int) $t['person_id'], $c['family_code'], (int) $c['family_version'], $year, mb_substr((string) $d, 0, 12), $s['self'], $s['mgr'], $s['comb'], (int) $t['valid'], $t['department_id']]);
        }
        $n++; hrs_eval_record((int) $t['person_id'], $year);
        if ($t['self_status'] === 'done' && $t['user_id'] && !empty($F['set']['employee_sees_result'])) $tell[] = [(int) $t['user_id'], (int) $t['person_id']];
    }
    if (function_exists('nf_push')) foreach ($tell as [$uid, $pid]) nf_push([$uid], 'hrs:result', 'hrs:' . $cid . ':' . $pid . ':res', t('hrs.nf_result', ['t' => hrs_tx($F['title'])]), null, url('hr_test_result', ['id' => $cid, 'pid' => $pid]), null, null);
    return $n;
}
function hrs_delete(array $c): void { if ($c['status'] !== 'draft') throw new RuntimeException(t('hrs.e_delete')); q('DELETE FROM hrs_targets WHERE campaign_id = ?', [(int) $c['id']]); q('DELETE FROM hrs_campaigns WHERE id = ?', [(int) $c['id']]); }

/** Hourly: scheduled campaigns start on their first day, reminders at half time and two days before the end, closing the day after the last day. */
function hrs_sweep(): int {
    if (!hrs_has() || time() - (int) cp_setting('hrs_sweep_at', '0') < 3600) return 0; cp_setting_set('hrs_sweep_at', (string) time());
    $today = date('Y-m-d'); $n = 0;
    foreach (all("SELECT id FROM hrs_campaigns WHERE status = 'scheduled' AND starts_on <= ?", [$today]) as $r) { q("UPDATE hrs_campaigns SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'scheduled'", [(int) $r['id']]); hrs_begin((int) $r['id']); $n++; }
    foreach (all("SELECT id FROM hrs_campaigns WHERE status = 'running'") as $r) {
        $c = hrs_campaign((int) $r['id']); if (!$c || !$c['ends_on']) continue;
        if ($today > $c['ends_on']) { hrs_close($c); $n++; continue; }
        $half = date('Y-m-d', (int) ((strtotime((string) $c['starts_on']) + strtotime((string) $c['ends_on'])) / 2)); $late = date('Y-m-d', strtotime($c['ends_on'] . ' -2 days'));
        $step = $today >= $late ? 2 : ($today >= $half && $half > $c['starts_on'] ? 1 : 0);
        if ($step > (int) $c['reminded']) { q('UPDATE hrs_campaigns SET reminded = ? WHERE id = ?', [$step, (int) $c['id']]); hrs_remind($c); $n++; }
    }
    return $n;
}

/* ------------------------------------------------------------ what a user has to do, results, history */

/** Campaigns the user takes part in: ['self' => rows to answer, 'rate' => [campaign => n people to rate], 'results' => closed with a result to read]. */
function hrs_mine(int $uid): array {
    $out = ['self' => [], 'rate' => [], 'results' => []]; if (!hrs_has() || $uid < 1) return $out;
    foreach (all("SELECT t.campaign_id, t.person_id, t.self_status, t.mgr_status, t.user_id, t.rater_user_id, c.title, c.status, c.ends_on, c.form FROM hrs_targets t JOIN hrs_campaigns c ON c.id = t.campaign_id
                  WHERE (t.user_id = ? OR t.rater_user_id = ?) AND c.status IN ('running', 'closed') ORDER BY c.ends_on DESC, c.id DESC", [$uid, $uid]) as $r) {
        $F = json_decode((string) $r['form'], true) ?: []; $ti = hrs_tx($F['title'] ?? $r['title']); $cid = (int) $r['campaign_id'];
        if ((int) $r['user_id'] === $uid) {
            if ($r['status'] === 'running' && $r['self_status'] !== 'done') $out['self'][] = ['id' => $cid, 'title' => $ti, 'ends_on' => $r['ends_on'], 'started' => $r['self_status'] === 'started'];
            if ($r['status'] === 'closed' && $r['self_status'] === 'done' && !empty($F['set']['employee_sees_result'])) $out['results'][] = ['id' => $cid, 'pid' => (int) $r['person_id'], 'title' => $ti, 'ends_on' => $r['ends_on']];
        }
        if ((int) $r['rater_user_id'] === $uid && $r['status'] === 'running' && $r['mgr_status'] !== 'done' && !empty($F['set']['manager_form'])) { $out['rate'][$cid] ??= ['id' => $cid, 'title' => $ti, 'ends_on' => $r['ends_on'], 'n' => 0]; $out['rate'][$cid]['n']++; }
    }
    $out['rate'] = array_values($out['rate']); return $out;
}
/** May the current user read the result of this person? HR and the CEO always; the person after the campaign closed, when the campaign allows it. */
function hrs_may_see(array $c, array $t): bool {
    if (hrs_can()) return true;
    return $c['status'] === 'closed' && (int) $t['user_id'] === hrs_me() && $t['self_status'] === 'done' && !empty($c['F']['set']['employee_sees_result']);
}
/** Earlier results of a person in campaigns of the same family: [year => [dim => row]]. */
function hrs_history(int $pid, string $family): array {
    $out = []; foreach (all('SELECT s.*, c.title FROM hrs_scores s JOIN hrs_campaigns c ON c.id = s.campaign_id WHERE s.person_id = ? AND s.family_code = ? ORDER BY s.year, s.campaign_id', [$pid, $family]) as $r) $out[(int) $r['year']][$r['dim']] = $r;
    return $out;
}
/** Averages of a family per year and dimension — only where at least $min people have a score. [year => [dim => ['avg','n']]] */
function hrs_family_years(string $family, int $min = 5): array {
    $out = []; $min = max(3, $min);
    foreach (all('SELECT year, dim, COUNT(*) AS n, ROUND(AVG(combined)) AS avg, MAX(family_version) AS v FROM hrs_scores WHERE family_code = ? AND combined IS NOT NULL GROUP BY year, dim HAVING COUNT(*) >= ? ORDER BY year', [$family, $min]) as $r) $out[(int) $r['year']][$r['dim']] = ['avg' => (int) $r['avg'], 'n' => (int) $r['n'], 'v' => (int) $r['v']];
    return $out;
}
/** Averages of one closed campaign per dimension (and per department), only for groups of at least min_group. */
function hrs_group_avgs(array $c): array {
    $min = max(3, (int) ($c['F']['set']['min_group'] ?? 5)); $out = ['all' => [], 'dept' => []];
    foreach (all('SELECT dim, COUNT(*) AS n, ROUND(AVG(combined)) AS avg FROM hrs_scores WHERE campaign_id = ? AND combined IS NOT NULL GROUP BY dim HAVING COUNT(*) >= ?', [(int) $c['id'], $min]) as $r) $out['all'][$r['dim']] = ['avg' => (int) $r['avg'], 'n' => (int) $r['n']];
    foreach (all('SELECT s.department_id, d.name, s.dim, COUNT(*) AS n, ROUND(AVG(s.combined)) AS avg FROM hrs_scores s LEFT JOIN disciplines d ON d.id = s.department_id WHERE s.campaign_id = ? AND s.combined IS NOT NULL AND s.department_id IS NOT NULL GROUP BY s.department_id, d.name, s.dim HAVING COUNT(*) >= ?', [(int) $c['id'], $min]) as $r) $out['dept'][(string) $r['name']][$r['dim']] = ['avg' => (int) $r['avg'], 'n' => (int) $r['n']];
    return $out;
}

/* ------------------------------------------------------------ annual evaluation */

/** 0–100 → the 1–5 scale of the evaluation: 0–12 → 1, 13–37 → 2, 38–62 → 3, 63–87 → 4, 88–100 → 5. */
function hrs_to5(int $score): int { return max(1, min(5, 1 + (int) round($score / 25))); }
/** Assessment results of a person that belong to the evaluation of a year: the last closed campaign of each family in that year, one row per dimension.
 *  $own = true: the rows the person may read (campaigns with employee_sees_result). */
function hrs_eval_rows(int $pid, int $year, bool $own = false): array {
    static $memo = []; $k = $pid . ':' . $year . ':' . (int) $own; if (isset($memo[$k])) return $memo[$k]; $rows = [];
    if (!hrs_has()) return $memo[$k] = $rows;
    $last = []; foreach (all('SELECT family_code, MAX(campaign_id) AS cid FROM hrs_scores WHERE person_id = ? AND year = ? AND combined IS NOT NULL GROUP BY family_code', [$pid, $year]) as $r) $last[] = (int) $r['cid'];
    foreach ($last as $cid) {
        $c = hrs_campaign($cid); if (!$c) continue; $F = $c['F']; if ($own && empty($F['set']['employee_sees_result'])) continue;   // the person reads only what the campaign lets them read
        foreach (all('SELECT * FROM hrs_scores WHERE campaign_id = ? AND person_id = ? AND combined IS NOT NULL', [$cid, $pid]) as $s) {
            if (!isset($F['dims'][$s['dim']])) continue; $b = hrs_band($F, (int) $s['combined']);
            $rows['as_' . $s['dim']] = ['key' => 'as_' . $s['dim'], 'label' => hrs_tx($F['dims'][$s['dim']]['name']), 'score' => hrs_to5((int) $s['combined']), 'pct' => (int) $s['combined'], 'self' => $s['self_score'] !== null ? (int) $s['self_score'] : null,
                'mgr' => $s['mgr_score'] !== null ? (int) $s['mgr_score'] : null, 'band' => $b ? hrs_tx($b['l']) : '', 'valid' => (bool) $s['valid'], 'campaign' => hrs_tx($F['title']), 'cid' => $cid, 'o' => (int) ($F['dims'][$s['dim']]['o'] ?? 0)];
        }
    }
    uasort($rows, fn($a, $b) => [$a['cid'], $a['o']] <=> [$b['cid'], $b['o']]);
    return $memo[$k] = $rows;
}
function hrs_eval_text(array $r): string {
    $p = [t('hrs.ev_comb', ['n' => $r['pct']])]; if ($r['self'] !== null) $p[] = t('hrs.ev_self', ['n' => $r['self']]); if ($r['mgr'] !== null) $p[] = t('hrs.ev_mgr', ['n' => $r['mgr']]);
    return implode(' · ', $p) . ($r['band'] !== '' ? ' — ' . $r['band'] : '') . ' — ' . $r['campaign'] . ($r['valid'] ? '' : ' — ⚠ ' . t('hrs.low_conf'));
}
/** The assessment values as they are stored with an evaluation: ['as_REL' => 4, …]. Not part of the overall mark. */
function hrs_eval_scores(int $pid, int $year): array { $o = []; foreach (hrs_eval_rows($pid, $year) as $k => $r) $o[$k] = (int) $r['score']; return $o; }
/** Write the values into the evaluation of the year when one exists and the employee has not acknowledged it yet. */
function hrs_eval_record(int $pid, int $year): void {
    if (!function_exists('hr_tr_has') || !hr_tr_has()) return;
    $ev = one("SELECT id, scores FROM hr_evaluations WHERE person_id = ? AND year = ? AND status <> 'acknowledged'", [$pid, $year]); if (!$ev) return;
    $sc = json_decode((string) $ev['scores'], true) ?: []; foreach ($sc as $k => $_) if (str_starts_with((string) $k, 'as_')) unset($sc[$k]);
    $as = []; foreach (all('SELECT s.dim, s.combined FROM hrs_scores s WHERE s.person_id = ? AND s.year = ? AND s.combined IS NOT NULL ORDER BY s.campaign_id', [$pid, $year]) as $r) $as['as_' . $r['dim']] = hrs_to5((int) $r['combined']);
    q('UPDATE hr_evaluations SET scores = ? WHERE id = ?', [json_encode($sc + $as), (int) $ev['id']]);
}

/* ------------------------------------------------------------ actions */

function hrs_handle_action(string $action): bool {
    if (!str_starts_with($action, 'hrs_')) return false;
    if (!hrs_has() || !is_staff()) throw new RuntimeException(t('err.noaccess'));
    $cid = (int) ($_POST['id'] ?? 0);
    if ($action === 'hrs_answer') {
        $form = ($_POST['form'] ?? '') === 'mgr' ? 'M' : 'S'; $pid = (int) ($_POST['pid'] ?? 0); $submit = isset($_POST['submit']);
        $r = hrs_answer($cid, $pid, $form, $_POST['a'] ?? [], $submit);
        if ($r['done']) { flash('good', t($form === 'S' ? 'hrs.f_thanks' : 'hrs.f_thanks_mgr')); if ($form === 'M') redirect('hr_test_take', ['id' => $cid, 'form' => 'mgr']); redirect(function_exists('hr_person_of_user') && hr_person_of_user(hrs_me()) ? 'my_leave' : 'dashboard'); }
        flash($submit ? 'warn' : 'good', $submit ? t('hrs.f_missing', ['n' => count($r['missing'])]) : t('hrs.f_saved'));
        redirect('hr_test_take', ['id' => $cid] + ($form === 'M' ? ['form' => 'mgr', 'pid' => $pid] : []) + ($submit ? ['miss' => 1] : []));
    }
    if (!hrs_can()) throw new RuntimeException(t('err.noaccess'));
    if ($action === 'hrs_import') {
        $res = hrs_import_upload($_FILES['file'] ?? null);
        if ($res['errors']) { $_SESSION['hrs_import'] = ['errors' => $res['errors'], 'file' => mb_substr(basename((string) ($_FILES['file']['name'] ?? '')), 0, 120)]; flash('bad', t('hrs.imp_failed', ['n' => count($res['errors'])])); redirect('hr_tests'); }
        if ($res['warnings']) $_SESSION['hrs_warn'][$res['id']] = $res['warnings'];
        flash('good', t('hrs.imp_ok')); redirect('hr_test', ['id' => $res['id']]);
    }
    $c = hrs_campaign($cid); if (!$c) throw new RuntimeException(t('err.notfound'));
    switch ($action) {
        case 'hrs_people': hrs_targets_set($c, (array) ($_POST['pids'] ?? [])); flash('good', t('hr.saved')); break;
        case 'hrs_start':
            if (isset($_POST['pids'])) hrs_targets_set($c, (array) $_POST['pids']);
            $st = hrs_start($c, (string) ($_POST['starts_on'] ?? ''), (string) ($_POST['ends_on'] ?? '')); flash('good', t($st === 'running' ? 'hrs.started' : 'hrs.scheduled')); break;
        case 'hrs_back': if ($c['status'] !== 'scheduled') throw new RuntimeException(t('hrs.e_closed')); q("UPDATE hrs_campaigns SET status = 'draft' WHERE id = ?", [$cid]); flash('good', t('hr.saved')); break;
        case 'hrs_end':
            if ($c['status'] !== 'running') throw new RuntimeException(t('hrs.e_not_open')); $d = (string) ($_POST['ends_on'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < date('Y-m-d') || $d > date('Y-m-d', strtotime('+2 years'))) throw new RuntimeException(t('pl.bad_dates'));
            q('UPDATE hrs_campaigns SET ends_on = ?, year = ? WHERE id = ?', [$d, (int) substr($d, 0, 4), $cid]); flash('good', t('hr.saved')); break;
        case 'hrs_rater':
            if ($c['status'] === 'closed') throw new RuntimeException(t('hrs.e_closed')); $t = hrs_target($cid, (int) ($_POST['pid'] ?? 0)); $r = (int) ($_POST['rater'] ?? 0);
            if (!$t || $t['mgr_status'] === 'done') throw new RuntimeException(t('hrs.e_done'));
            if ($r && (!scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND is_active = 1 AND role <> 'customer'", [$r]) || $r === (int) $t['user_id'])) throw new RuntimeException(t('err.notfound'));
            q("UPDATE hrs_targets SET rater_user_id = ?, mgr_answers = NULL, mgr_status = 'pending' WHERE id = ?", [$r ?: null, (int) $t['id']]);
            if ($r && $c['status'] === 'running' && function_exists('nf_push')) nf_push([$r], 'hrs:rate', 'hrs:' . $cid . ':r' . $r . ':' . time(), t('hrs.nf_rate', ['t' => hrs_tx($c['F']['title']), 'd' => fmt_date($c['ends_on'])]), null, url('hr_test_take', ['id' => $cid, 'form' => 'mgr']), hrs_me() ?: null, (string) (current_user()['full_name'] ?? ''));
            flash('good', t('hr.saved')); break;
        case 'hrs_remind': flash('good', t('hrs.reminded', ['n' => hrs_remind($c)])); break;
        case 'hrs_close': $n = hrs_close($c, hrs_me() ?: null); flash('good', t('hrs.closed_ok', ['n' => $n])); break;
        case 'hrs_delete': hrs_delete($c); flash('good', t('hrs.deleted')); redirect('hr_tests');
        default: throw new RuntimeException(t('err.notfound'));
    }
    redirect('hr_test', ['id' => $cid]);
}
