<?php
/**
 * Field Service Reports — form SF-GLD-014.                    GAS-CP-R7-001
 *
 * Engineers fill the report in the portal; on submit the system renders the
 * PDF and the Word file and stores both; the customer accepts electronically
 * and the acceptance (who, when, from where) is logged and printed on the
 * report. Everything hangs off the project / sub-project.
 */
declare(strict_types=1);

const FSR_FORM_CODE  = 'SF-GLD-014';
const FSR_MAX_LINES  = 3;            // time lines per task
const FSR_MAX_WORKS  = 20;           // tasks per report (added one by one on the form)
const FSR_MAX_PARTS  = 3;
const FSR_MAX_PHOTOS = 9;

/** Legacy free-text specialties (reports before R94-002 carry these). */
function fsr_specialties_legacy(): array {
    return ['Service Engineer', 'Automation Engineer', 'Electrical Engineer', 'Electrician', 'Navigation Engineer',
            'Commissioning Engineer', 'Project Manager', 'Technician', 'Supervisor'];
}
/** R94-002: the technical disciplines ticked in Settings › Disciplines › Service reports (nothing ticked = every active discipline);
 *  the specialty on a time line is a discipline name, so accounting prices the hours with that discipline's rate card. */
function fsr_tech_disc_setting(): array { $v = function_exists('cp_setting') ? (string) cp_setting('fsr_disciplines', '') : ''; return $v === '' ? [] : array_values(array_unique(array_map('intval', array_filter(explode(',', $v), fn($x) => $x !== '')))); }
function fsr_tech_disc_save(array $ids): void { if (!is_cp_admin()) throw new RuntimeException(t('err.noaccess')); cp_setting_set('fsr_disciplines', implode(',', array_values(array_unique(array_map('intval', $ids))))); }
/** Names of the technical disciplines offered (see fsr_specialties() for the full list incl. rate-card-only specialties). */
function fsr_disc_names(): array {
    static $c = null; if ($c !== null) return $c;
    try { $rows = all('SELECT id, name, sort_order FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name'); } catch (Throwable $e) { $rows = []; }
    $sel = fsr_tech_disc_setting();
    // ticked ones; nothing ticked = the technical disciplines (the HR departments — Administration, Accounting, Sales — sit at sort order 900+ since R56-002 and are left out)
    $rows = array_values(array_filter($rows, fn($d) => $sel ? in_array((int)$d['id'], $sel, true) : (int)$d['sort_order'] < 900));
    $c = $rows ? array_values(array_unique(array_map(fn($d) => (string)$d['name'], $rows))) : fsr_specialties_legacy();
    return $c;
}
/** R97-224: what the engineer picks on a time line is the SERVICE CARD of the work (A — Service Engineer, C — Atlas Marine Systems Engineer …),
 *  one choice per hourly card of the current rate list, in card order. No discipline and no price on the form: the rate — normal hours, overtime,
 *  Saturday / Sunday / holiday, zone — is set by Accounting when the hours are processed. The value stored on the line is the name of the card.
 *  Without a rate list the technical disciplines are offered as before.
 *  [['name', 'id', 'card', 'card_name', 'rate' (always null), 'cur']] */
function fsr_specialty_options(?int $projectId = null, bool $withRates = false): array {
    static $memo = []; $mk = (int)$projectId; if (isset($memo[$mk])) return $memo[$mk];
    $out = []; $seen = [];
    $list = null; if (function_exists('rl_current')) { $bid = $projectId && function_exists('br_of') ? (int)(br_of($projectId)['id'] ?? 0) : 0; $list = rl_current($bid ?: null); }
    $cards = $list && function_exists('rl_cards') ? rl_cards((int)$list['id']) : []; $cur = (string)($list['currency'] ?? 'EUR');
    foreach ($cards as $c) { $nm = trim((string)$c['name']); if ($c['section'] !== 'hourly' || $nm === '' || isset($seen[mb_strtolower($nm)])) continue; $seen[mb_strtolower($nm)] = true;
        $out[] = ['name' => $nm, 'id' => 0, 'card' => (string)$c['card'], 'card_name' => $nm, 'rate' => null, 'cur' => $cur]; }
    usort($out, fn($a, $b) => strnatcasecmp($a['card'], $b['card']));
    if (!$out) foreach (fsr_disc_names() as $n) {   // no rate list yet
        $d = one('SELECT id FROM disciplines WHERE name = ?', [$n]);
        $out[] = ['name' => $n, 'id' => (int)($d['id'] ?? 0), 'card' => $d && function_exists('acc_card_of') ? acc_card_of((int)$d['id']) : '', 'card_name' => '', 'rate' => null, 'cur' => $cur];
    }
    return $memo[$mk] = $out;
}
/** Every choice an engineer may pick (names of the service cards). */
function fsr_specialties(): array { return array_values(array_unique(array_column(fsr_specialty_options(), 'name'))); }
/** The hourly rate card a line's specialty names, or '' when unknown: a service card name, or — on reports written before R97-224 — a discipline name with its rate card. */
function fsr_specialty_card(string $specialty, ?int $projectId = null): string {
    $s = mb_strtolower(trim($specialty)); if ($s === '') return '';
    foreach (fsr_specialty_options($projectId) as $o) if (mb_strtolower($o['name']) === $s) return (string)$o['card'];
    try { $d = one('SELECT id FROM disciplines WHERE LOWER(name) = ? LIMIT 1', [$s]); } catch (Throwable $e) { $d = null; }
    return $d && function_exists('acc_card_of') ? acc_card_of((int)$d['id']) : '';
}
/** Label of a choice: "A — Service Engineer". Never a price. */
function fsr_specialty_label(array $o): string { return $o['card'] !== '' && $o['card_name'] !== '' ? $o['card'] . ' — ' . $o['name'] : $o['name']; }
/** R94-008: engineers offered on the time lines — active staff of a technical discipline (HR departments at sort order 900+ left out), the current user first. */
function fsr_engineer_choices(): array {
    return all("SELECT cu.id, cu.full_name, cu.discipline_id FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id LEFT JOIN staff_roles sr ON sr.id = cu.staff_role_id
                WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 AND (cu.discipline_id IS NULL OR d.sort_order < 900) AND (sr.scope IS NULL OR sr.scope <> 'hr')
                ORDER BY (cu.id = ?) DESC, cu.full_name", [(int)(current_user()['id'] ?? 0)]);
}
/** Whether the signed-in user is technical staff (then their own name pre-fills the first time line; office staff preparing a report on behalf of an engineer get an empty field). */
function fsr_user_is_technical(): bool { $u = current_user(); if (!$u || !is_staff()) return false; $d = $u['discipline_id'] ?? null; if (!$d) return true; return (int) scalar('SELECT sort_order FROM disciplines WHERE id = ?', [(int)$d]) < 900; }
/** Default specialty of a line: the engineer's own discipline when known, else the first offered. */
function fsr_specialty_default(?int $userId = null): string {   // the service card of the engineer's own discipline when it is offered, else the first card
    $opts = fsr_specialty_options(); if (!$opts) return 'Service Engineer';
    $did = $userId ? (int) scalar('SELECT discipline_id FROM customer_users WHERE id = ?', [$userId]) : 0; $card = $did && function_exists('acc_card_of') ? acc_card_of($did) : '';
    foreach ($opts as $o) if ($card !== '' && $o['card'] === $card) return $o['name'];
    return $opts[0]['name'];
}
function fsr_time_types(): array { return ['WT' => 'WT', 'TT' => 'TT', 'NC' => 'NC']; }   // NC = not chargeable (R46-005)
/** R95-004: why a line is not chargeable — decided by the engineer on the service report; accounting only confirms. */
function fsr_nc_reasons(): array { return ['warranty' => t('fsr.ncr_warranty'), 'goodwill' => t('fsr.ncr_goodwill'), 'internal' => t('fsr.ncr_internal'), 'training' => t('fsr.ncr_training'), 'rework' => t('fsr.ncr_rework'), 'other' => t('fsr.ncr_other')]; }
function fsr_nc_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM fsr_lines LIKE 'nc_reason'"); } catch (Throwable $e) { $h = false; } } return $h; }
function fsr_time_type_label(string $k): string { return ['WT' => t('fsr.tt_wt'), 'TT' => t('fsr.tt_tt'), 'NC' => t('fsr.tt_nc')][$k] ?? $k; }
function fsr_part_types(): array { return ['W' => 'W', 'G' => 'G', 'I' => 'I']; }

/* ------------------------------------------------------------- load */

/** A report the current person may see (customers: own customer, non-draft). */
function fsr_load(int $id): ?array {
    [$scope, $args] = scope_sql('p');
    array_unshift($args, $id);
    $r = one("SELECT f.*, p.name AS project_name, p.project_code, p.customer_id, c.name AS customer_db_name,
                     s.code AS sub_code, s.name AS sub_name
              FROM fsr_reports f JOIN projects p ON p.id = f.project_id
              LEFT JOIN customers c ON c.id = p.customer_id
              LEFT JOIN subprojects s ON s.id = f.subproject_id
              WHERE f.id = ? AND {$scope}", $args);
    if (!$r) return null;
    if (!is_staff() && $r['status'] === 'draft') return null;
    if (!is_staff() && fsr_ext_has() && ($r['kind'] ?? 'internal') === 'external' && !(int)$r['owner_visible']) return null;   // R38-003
    $r['lines']  = all('SELECT * FROM fsr_lines WHERE report_id = ? ORDER BY seq, id', [$id]);
    if (fsr_ext_has()) { $r['source'] = !empty($r['source_report_id']) ? one('SELECT id, report_no, ext_file_id FROM fsr_reports WHERE id = ?', [(int)$r['source_report_id']]) : null; $r['converted'] = !empty($r['converted_id']) ? one('SELECT id, report_no, status FROM fsr_reports WHERE id = ?', [(int)$r['converted_id']]) : null; }
    $r['works']  = all('SELECT * FROM fsr_works WHERE report_id = ? ORDER BY seq', [$id]);
    foreach ($r['works'] as &$w) {
        $w['parts'] = $w['parts'] ? (json_decode((string)$w['parts'], true) ?: []) : [];
        $w['lines'] = array_values(array_filter($r['lines'], fn($l) => (int)($l['work_id'] ?? 0) === (int)$w['id']));
    }
    unset($w);
    $r['jo'] = !empty($r['job_order_id']) ? one('SELECT id, jo_no, title, status FROM job_orders WHERE id = ?', [$r['job_order_id']]) : null;
    $r['summary'] = fsr_summary($r['lines']);
    $r['photos'] = all("SELECT * FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo' ORDER BY fsr_task, id", [$id]);
    $fig = 0;
    foreach ($r['photos'] as &$ph) $ph['figure'] = ++$fig;             // Figure numbers run through the report
    unset($ph);
    foreach ($r['works'] as &$w) $w['photos'] = array_values(array_filter($r['photos'], fn($ph) => (int)($ph['fsr_task'] ?? 0) === (int)$w['seq']));
    unset($w);
    $r['events'] = all('SELECT * FROM fsr_events WHERE report_id = ? ORDER BY id', [$id]);
    return $r;
}

/** Reports of a project / sub-project visible to the current person. */
function fsr_list(?int $projectId = null, ?int $subId = null, ?int $customerId = null): array {
    [$scope, $args] = scope_sql('p');
    $w = [$scope];
    if ($projectId) { $w[] = 'f.project_id = ?'; $args[] = $projectId; }
    if ($subId)     { $w[] = 'f.subproject_id = ?'; $args[] = $subId; }
    if ($customerId){ $w[] = 'p.customer_id = ?'; $args[] = $customerId; }
    if (!is_staff()) { $w[] = "f.status <> 'draft'"; if (fsr_ext_has()) $w[] = "(f.kind = 'internal' OR f.owner_visible = 1)"; }
    return all("SELECT f.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, v.name AS vessel
                FROM fsr_reports f JOIN projects p ON p.id = f.project_id
                LEFT JOIN subprojects s ON s.id = f.subproject_id LEFT JOIN vessels v ON v.id = p.vessel_id
                WHERE " . implode(' AND ', $w) . ' ORDER BY f.report_date DESC, f.id DESC', $args);
}

/** R97-219: the equipment (serial numbers) a report concerns can be set at any time by staff — also after the customer accepted. It is a link to the equipment register, not part of the signed report: text, hours and PDF stay as they are. The service history of each unit is written again from the report. */
function fsr_equipment_set(int $id, array $ids, string $newSerials): array {
    require_staff(); if (!function_exists('eq_has') || !eq_has()) throw new RuntimeException(t('err.notfound'));
    $r = fsr_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [(int)$r['project_id']]) ?: null;
    $ok = $vid ? array_map('intval', array_column(eq_for_vessel($vid), 'id')) : []; $was = array_map('intval', array_column(eq_fsr_units($id), 'id'));
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), fn($x) => in_array($x, $ok, true) || in_array($x, $was, true)));   // only units of this vessel, or already on the report
    $newSerials = mb_substr(trim($newSerials), 0, 300); $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        eq_fsr_link($id, $ids, $newSerials, $vid, ''); eq_fsr_sync($id);
        $now = eq_fsr_units($id); $sn = implode(', ', array_column($now, 'serial_no'));
        fsr_event($id, 'saved', mb_substr(t('eq.fsr_ev', ['sn' => $sn !== '' ? $sn : '—']), 0, 250));
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own) $pdo->rollBack(); throw $e; }
    return $now;
}
/* R97-225: service reports that name a maker whose units carry serial numbers (ATLAS, MAGNUS, AVENTICS, EMERSON …) and have no unit of the equipment register linked. */
const FSR_SN_WORDS = 'ATLAS, MAGNUS, AVENTICS, EMERSON';
/** The words looked for — setting fsr_sn_words, else the four makers. */
function fsr_sn_words(): array {
    $s = function_exists('cp_setting') ? trim((string) cp_setting('fsr_sn_words', '')) : '';
    $w = array_map(fn($x) => mb_strtoupper(trim((string)$x)), preg_split('/[,;\r\n]+/u', $s !== '' ? $s : FSR_SN_WORDS) ?: []);
    return array_slice(array_values(array_unique(array_filter($w, fn($x) => mb_strlen($x) >= 3 && mb_strlen($x) <= 40 && !preg_match('/[%_\\\\<>"\']/', $x)))), 0, 20);
}
function fsr_sn_words_set(string $raw): array {
    if (!function_exists('mgmt_can') || !(mgmt_can() || is_cp_admin())) throw new RuntimeException(t('err.noaccess'));
    cp_setting_set('fsr_sn_words', mb_substr(trim($raw), 0, 600)); $w = fsr_sn_words(); cp_setting_set('fsr_sn_words', implode(', ', $w)); return fsr_sn_words();
}
/** Reports (submitted, accepted, disputed) whose tasks, parts, open points, service cards or attached summary name one of the words, with no serial number linked. $scoped: only what the current person may see; the clock job passes false. $after: only reports submitted after this moment. Each row carries 'words' (those found). */
function fsr_sn_missing(bool $scoped = true, ?string $after = null): array {
    if (!function_exists('eq_has') || !eq_has()) return []; $words = fsr_sn_words(); if (!$words) return [];
    $ext = fsr_ext_has(); $w = ["f.status <> 'draft'", 'NOT EXISTS (SELECT 1 FROM fsr_equipment x WHERE x.report_id = f.id)']; $args = [];
    if ($scoped) { require_staff(); [$scope, $sa] = scope_sql('p'); $w[] = $scope; $args = $sa; }
    if ($ext) $w[] = "(f.kind = 'internal' OR f.converted_id IS NULL)";   // an attached report that became an internal one is followed on the internal one
    if ($after) { $w[] = 'f.submitted_at > ?'; $args[] = $after; }
    $lw = []; $ll = []; $le = []; $aw = []; $al = []; $ae = [];
    foreach ($words as $x) { $k = '%' . mb_strtolower($x) . '%'; $lw[] = "LOWER(CONCAT_WS(' ', wk.description, wk.parts, wk.open_issues)) LIKE ?"; $aw[] = $k; $ll[] = "LOWER(CONCAT_WS(' ', ln.specialty, ln.remarks)) LIKE ?"; $al[] = $k; $le[] = 'LOWER(f.ext_summary) LIKE ?'; $ae[] = $k; }
    $any = ['EXISTS (SELECT 1 FROM fsr_works wk WHERE wk.report_id = f.id AND (' . implode(' OR ', $lw) . '))', 'EXISTS (SELECT 1 FROM fsr_lines ln WHERE ln.report_id = f.id AND (' . implode(' OR ', $ll) . '))']; $args = array_merge($args, $aw, $al);
    if ($ext) { $any[] = '(' . implode(' OR ', $le) . ')'; $args = array_merge($args, $ae); }
    $w[] = '(' . implode(' OR ', $any) . ')';
    $rows = all('SELECT f.id, f.report_no, f.report_date, f.status, f.submitted_name, f.submitted_at, f.project_id, f.vessel_name' . ($ext ? ', f.kind, f.ext_summary' : '') . ', p.project_code, p.name AS project_name, p.pm_user_id, v.name AS vessel
                 FROM fsr_reports f JOIN projects p ON p.id = f.project_id LEFT JOIN vessels v ON v.id = p.vessel_id
                 WHERE ' . implode(' AND ', $w) . ' ORDER BY f.report_date DESC, f.id DESC LIMIT 2000', $args);
    if (!$rows) return [];
    $ids = array_map('intval', array_column($rows, 'id')); $text = array_fill_keys($ids, ''); $in = implode(',', $ids);
    foreach (all("SELECT report_id, description, parts, open_issues FROM fsr_works WHERE report_id IN ({$in})") as $x) { $pt = ''; foreach ((json_decode((string)($x['parts'] ?? ''), true) ?: []) as $pp) $pt .= ' ' . (is_array($pp) ? implode(' ', array_map('strval', array_filter($pp, 'is_scalar'))) : (string)$pp); $text[(int)$x['report_id']] .= ' ' . $x['description'] . ' ' . $pt . ' ' . $x['open_issues']; }
    foreach (all("SELECT report_id, specialty, remarks FROM fsr_lines WHERE report_id IN ({$in})") as $x) $text[(int)$x['report_id']] .= ' ' . $x['specialty'] . ' ' . $x['remarks'];
    $out = [];
    foreach ($rows as $r) {
        $t = $text[(int)$r['id']] . ' ' . (string)($r['ext_summary'] ?? ''); $found = [];
        foreach ($words as $x) if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($x, '/') . '/iu', $t)) $found[] = $x;   // the word, or the word as the start of a longer one (AVENTICS, Aventics-valve) — not inside another word
        if (!$found) continue; unset($r['ext_summary']); $r['words'] = $found; $out[] = $r;
    }
    return $out;
}
/** Once a day from 07:00 (clock job): the reports submitted since the last run that are on the list — notification to the project manager and to management; on Monday a reminder of everything still open. Reads only. Returns the number of reports on the list, or null when it is not time. */
function fsr_sn_daily(): ?int {
    if (!function_exists('cp_setting') || !function_exists('eq_has') || !eq_has()) return null; $today = date('Y-m-d');
    $last = explode('|', (string) cp_setting('fsr_sn_last', ''));
    if ((int) date('G') < 7 || str_starts_with($last[0], $today)) return null;
    $since = preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $last[0]) ? $last[0] . ':00' : date('Y-m-d 00:00:00', strtotime('-1 day'));
    $all = fsr_sn_missing(false); $new = array_values(array_filter($all, fn($r) => (string)($r['submitted_at'] ?? '') > $since));
    cp_setting_set('fsr_sn_last', $today . ' ' . date('H:i') . '|' . count($all) . '|' . count($new));
    $send = $new ?: ((int) date('N') === 1 ? $all : []);
    if ($send && function_exists('nf_push')) {
        $mg = function_exists('prq_mgmt_ids') ? prq_mgmt_ids() : []; $by = [];
        foreach ($send as $r) { foreach (array_unique(array_merge($mg, [(int)($r['pm_user_id'] ?? 0)])) as $uid) if ($uid) $by[$uid][] = (string)$r['report_no']; }
        foreach ($by as $uid => $nos) { try { nf_push([$uid], 'fsr:sn', 'fsr:sn:' . $today, t($new ? 'fsr.sn_nf' : 'fsr.sn_nf_open', ['n' => count($nos)]), implode(', ', array_slice($nos, 0, 8)) . (count($nos) > 8 ? ' …' : ''), url('fsr_sn'), null, null); } catch (Throwable $e) {} }
    }
    return count($all);
}
function fsr_event(int $reportId, string $event, ?string $note = null): void {
    $u = current_user();
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    q('INSERT INTO fsr_events (report_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)',
      [$reportId, $event, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $ip ?: null, $note]);
}

/** Report number: YYYYMMDD + engineer initials + HHMM, made unique. */
function fsr_number(string $engineer, string $date): string {
    $ini = '';
    foreach (preg_split('/\s+/', trim($engineer)) as $part) if ($part !== '') $ini .= mb_strtoupper(mb_substr($part, 0, 1));
    $ini = preg_replace('/[^A-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $ini)) ?: 'GS';
    $base = str_replace('-', '', substr($date, 0, 10)) . $ini . date('Hi');
    $no = $base; $n = 1;
    while (scalar('SELECT COUNT(*) FROM fsr_reports WHERE report_no = ?', [$no])) $no = $base . chr(64 + ++$n);
    return $no;
}

function fsr_hours(?string $start, ?string $end): float {
    if (!$start || !$end) return 0.0;
    $a = strtotime('2000-01-01 ' . $start); $b = strtotime('2000-01-01 ' . $end);
    if ($a === false || $b === false) return 0.0;
    if ($b < $a) $b += 86400;
    return round(($b - $a) / 3600, 2);
}
function fsr_fmt_hours(float $h): string { $m = (int) round($h * 60); return sprintf('%d:%02d', intdiv($m, 60), $m % 60); }
function fsr_fmt_time(?string $t): string { return $t ? substr($t, 0, 5) : ''; }

/** Hours per engineer and discipline: rows [engineer, specialty, wt, tt, total], plus totals. */
function fsr_summary(array $lines): array {
    $rows = []; $tot = ['wt' => 0.0, 'tt' => 0.0, 'nc' => 0.0];
    foreach ($lines as $l) {
        $k = mb_strtolower(trim((string)($l['engineer'] ?? $l['remarks'] ?? ''))) . '|' . mb_strtolower(trim((string)$l['specialty']));
        $rows[$k] ??= ['engineer' => (string)($l['engineer'] ?: ($l['remarks'] ?: '—')), 'specialty' => (string)$l['specialty'], 'wt' => 0.0, 'tt' => 0.0, 'nc' => 0.0];
        $key = $l['time_type'] === 'TT' ? 'tt' : ($l['time_type'] === 'NC' ? 'nc' : 'wt');   // NC = not chargeable (R46-005)
        $rows[$k][$key] += (float)$l['hours']; $tot[$key] += (float)$l['hours'];
    }
    foreach ($rows as &$row) $row['total'] = $row['wt'] + $row['tt'] + $row['nc'];
    unset($row);
    usort($rows, fn($a, $b) => [$a['engineer'], $a['specialty']] <=> [$b['engineer'], $b['specialty']]);
    return ['rows' => array_values($rows), 'wt' => $tot['wt'], 'tt' => $tot['tt'], 'nc' => $tot['nc'], 'total' => $tot['wt'] + $tot['tt'] + $tot['nc'], 'chargeable' => $tot['wt'] + $tot['tt']];
}

/* ------------------------------------------------------------- save */

/** Create or update a draft from the posted form. Returns the report id. */
function fsr_save_from_post(?int $id): int {
    $u = current_user();
    $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $pid = (int)($_POST['project_id'] ?? 0);
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    $p = one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id
              LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
    if (!$p) throw new RuntimeException(t('err.notfound'));
    if ($sid && !one('SELECT id FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    if (!$id && function_exists('tm_allowed_ids') && ($tmIds = tm_allowed_ids($pid)) !== null && ($sid ? !in_array($sid, $tmIds, true) : !$tmIds)) throw new RuntimeException(t('tm.not_allowed'));   // R25-001 · R77-003: whole-project report allowed when the engineer is on a sub-project of it
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['report_date'] ?? '')) ? $_POST['report_date'] : date('Y-m-d');
    $joId = (int)($_POST['job_order_id'] ?? 0) ?: null;
    if ($joId && !one('SELECT id FROM job_orders WHERE id = ? AND project_id = ?', [$joId, $pid])) $joId = null;
    $fields = [$pid, $sid, $joId, $date, $str('customer_name', 160) ?? $p['customer_name'], $str('vessel_name', 120) ?? $p['vessel'],
               $str('location', 160), $str('pic_name', 120), $str('pic_position', 120), $str('pic_mobile', 40), $str('pic_email', 190)];
    if ($id) {
        $r = one('SELECT * FROM fsr_reports WHERE id = ?', [$id]);
        if (!$r) throw new RuntimeException(t('err.notfound'));
        if (!in_array($r['status'], ['draft', 'disputed'], true)) throw new RuntimeException(t('fsr.locked'));
        q('UPDATE fsr_reports SET project_id=?, subproject_id=?, job_order_id=?, report_date=?, customer_name=?, vessel_name=?, location=?, pic_name=?, pic_position=?, pic_mobile=?, pic_email=? WHERE id = ?',
          [...$fields, $id]);
        q('DELETE FROM fsr_lines WHERE report_id = ?', [$id]);
        q('DELETE FROM fsr_works WHERE report_id = ?', [$id]);
        fsr_event($id, 'saved');
    } else {
        $no = fsr_number((string)($u['full_name'] ?? ''), $date);
        q('INSERT INTO fsr_reports (project_id, subproject_id, job_order_id, report_date, customer_name, vessel_name, location, pic_name, pic_position, pic_mobile, pic_email, report_no, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$fields, $no, (int)$u['id']]);
        $id = (int) db()->lastInsertId();
        fsr_event($id, 'created');
        $src = (int)($_POST['source_report_id'] ?? 0);                                     // R38-003: created from an external report
        if ($src && fsr_ext_has()) { $x = one("SELECT id, report_no FROM fsr_reports WHERE id = ? AND kind = 'external' AND converted_id IS NULL", [$src]); if ($x) { q('UPDATE fsr_reports SET source_report_id = ? WHERE id = ?', [$src, $id]); q('UPDATE fsr_reports SET converted_id = ? WHERE id = ?', [$id, $src]); fsr_event($id, 'created', t('fsr.ev_from_ext', ['no' => $x['report_no']])); fsr_event($src, 'saved', t('fsr.ev_converted', ['no' => $no])); } }
    }
    if (isset($_POST['ticket_id'])) tk_link('fsr_reports', $id, (int)$_POST['ticket_id']);
    // tasks, each with its spare parts, open issues and time lines — in the
    // order of the form; the form index is arbitrary (blocks come and go)
    $seq = 0; $lineSeq = 0; $keep = [];                       // $keep[old task no] = new task no (photos follow)
    $idx = array_slice(array_keys((array)($_POST['wk_desc'] ?? [])), 0, FSR_MAX_WORKS);
    foreach ($idx as $i) {
        $i = (int)$i;
        $desc = trim((string)($_POST['wk_desc'][$i] ?? ''));
        $issues = trim((string)($_POST['wk_issues'][$i] ?? ''));
        $parts = [];
        for ($j = 0; $j < FSR_MAX_PARTS; $j++) {
            $pd = trim((string)($_POST['wk_part'][$i][$j] ?? ''));
            if ($pd === '') continue;
            $pt = in_array($_POST['wk_ptype'][$i][$j] ?? '', ['W', 'G', 'I'], true) ? $_POST['wk_ptype'][$i][$j] : 'I';
            $part = ['d' => mb_substr($pd, 0, 120), 't' => $pt];   // R82-001: inventory code + quantity (+ the stock movement once issued)
            $pc = mb_substr(trim((string)($_POST['wk_pcode'][$i][$j] ?? '')), 0, 60); $pq = (float) str_replace(',', '.', (string)($_POST['wk_pqty'][$i][$j] ?? '1'));
            if ($pc !== '') { $part['c'] = $pc; $part['q'] = $pq > 0 ? round($pq, 3) : 1.0; $prev = $_POST['wk_pmove'][$i][$j] ?? ''; if ($prev !== '' && ctype_digit((string)$prev)) $part['m'] = (int)$prev; }
            $parts[] = $part;
        }
        $lines = [];
        for ($k = 0; $k < FSR_MAX_LINES; $k++) {
            $eng = trim((string)($_POST['wk_eng'][$i][$k] ?? ''));
            $st  = (string)($_POST['wk_start'][$i][$k] ?? ''); $en = (string)($_POST['wk_end'][$i][$k] ?? '');
            if ($eng === '' && $st === '' && $en === '') continue;
            $st = preg_match('/^\d{1,2}:\d{2}$/', $st) ? $st . ':00' : null;
            $en = preg_match('/^\d{1,2}:\d{2}$/', $en) ? $en . ':00' : null;
            $lines[] = ['engineer' => mb_substr($eng, 0, 120) ?: null, 'specialty' => mb_substr(trim((string)($_POST['wk_spec'][$i][$k] ?? '')), 0, 80) ?: fsr_specialty_default(),
                        'start' => $st, 'end' => $en, 'type' => in_array($_POST['wk_type'][$i][$k] ?? '', ['TT', 'WT', 'NC'], true) ? $_POST['wk_type'][$i][$k] : 'WT',
                        'ncr' => ($_POST['wk_type'][$i][$k] ?? '') === 'NC' && isset(fsr_nc_reasons()[$_POST['wk_ncr'][$i][$k] ?? '']) ? $_POST['wk_ncr'][$i][$k] : null];   // R95-004
        }
        $orig = (int)($_POST['wk_orig'][$i] ?? 0);
        $hasPhotos = $orig && scalar("SELECT COUNT(*) FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo' AND fsr_task = ?", [$id, $orig]);
        if ($desc === '' && $issues === '' && !$parts && !$lines && !$hasPhotos && !array_filter(fsr_task_files('photos', $i), fn($f) => ($f['error'] ?? 1) === UPLOAD_ERR_OK)) continue;
        q('INSERT INTO fsr_works (report_id, seq, description, parts, open_issues) VALUES (?,?,?,?,?)',
          [$id, ++$seq, mb_substr($desc, 0, 4000) ?: null, $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null, mb_substr($issues, 0, 2000) ?: null]);
        $wid = (int) db()->lastInsertId();
        $taskNo[$i] = $seq;
        if ($orig) $keep[$orig] = $seq;
        foreach ($lines as $ln) {
            q('INSERT INTO fsr_lines (report_id, work_id, seq, specialty, engineer, start_time, end_time, time_type, hours, remarks' . (fsr_nc_has() ? ', nc_reason' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?' . (fsr_nc_has() ? ',?' : '') . ')',
              array_merge([$id, $wid, ++$lineSeq, $ln['specialty'], $ln['engineer'], $ln['start'], $ln['end'], $ln['type'], fsr_hours($ln['start'], $ln['end']), $ln['engineer']], fsr_nc_has() ? [$ln['type'] === 'NC' ? ($ln['ncr'] ?? 'other') : null] : []));
        }
    }
    if (function_exists('fsr_parts_issue')) fsr_parts_issue($id);   // R82-001
    // stored photos follow their task: removed tasks lose theirs, the rest are renumbered
    foreach (all("SELECT id, fsr_task FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo'", [$id]) as $ph) {
        if (!isset($keep[(int)$ph['fsr_task']])) cp_trash_file((int)$ph['id']);   // R97-190
    }
    foreach ($keep as $old => $new) q("UPDATE sp_files SET fsr_task = ? WHERE fsr_id = ? AND kind = 'fsr_photo' AND fsr_task = ?", [100 + $new, $id, $old]);   // two passes: no collisions
    q("UPDATE sp_files SET fsr_task = fsr_task - 100 WHERE fsr_id = ? AND kind = 'fsr_photo' AND fsr_task > 100", [$id]);
    // photo captions of the photos already stored
    foreach ((array)($_POST['photo_caption'] ?? []) as $fid => $cap) {
        q("UPDATE sp_files SET title = ? WHERE id = ? AND fsr_id = ? AND kind = 'fsr_photo'", [mb_substr(trim((string)$cap), 0, 160) ?: null, (int)$fid, $id]);
    }
    // new photos, per task, with their captions (up to the maximum per report)
    $have = (int) scalar("SELECT COUNT(*) FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo'", [$id]);
    foreach ($taskNo ?? [] as $i => $no) {
        $caps = (array)($_POST['new_caption'][$i] ?? []);
        foreach (fsr_task_files('photos', $i) as $n => $f) {
            if ($have >= FSR_MAX_PHOTOS) break;
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
            $fid = cp_store_file($f, $pid, $sid, 'fsr_photo', 'external', mb_substr(trim((string)($caps[$n] ?? '')), 0, 160) ?: null, null, $id);
            if ($fid) { q('UPDATE sp_files SET fsr_task = ? WHERE id = ?', [$no, $fid]); $have++; }
        }
    }
    if (function_exists('eq_has') && eq_has() && (isset($_POST['eq_ids']) || ($_POST['eq_new'] ?? '') !== '' || $id)) eq_fsr_link($id, (array)($_POST['eq_ids'] ?? []), (string)($_POST['eq_new'] ?? ''), (int)($p['vessel_id'] ?? 0) ?: null, '');   // R27-001
    return $id;
}

/** $_FILES['photos'][i][] -> list of file arrays for task $i. */
function fsr_task_files(string $field, int $i): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'][$i]) || !is_array($f['name'][$i])) return [];
    $out = [];
    foreach ($f['name'][$i] as $n => $name) {
        $out[$n] = ['name' => $name, 'type' => $f['type'][$i][$n], 'tmp_name' => $f['tmp_name'][$i][$n], 'error' => $f['error'][$i][$n], 'size' => $f['size'][$i][$n]];
    }
    return $out;
}

/** Store a signature drawn on the canvas (PNG data URL) as an sp_files row. */
function fsr_store_signature(string $dataUrl, array $r, ?string $side = null): ?int {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
    $png = base64_decode($m[1], true);
    if ($png === false || strlen($png) < 200 || strlen($png) > 400000) return null;
    if (!@getimagesizefromstring($png)) return null;
    return cp_store_bytes($png, 'signature.png', 'image/png', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'fsr_sign', 'external', (int)$r['id'], null, ['sign' => $side ?? 'signature']);
}

/** The customer's decision on a submitted report (web page and mobile app): accepted (with the drawn signature kept as
 *  an sp_files row, kind fsr_sign) or disputed with a remark. Records who / when / IP, the event, and regenerates the documents.   R68-001 */
function fsr_customer_decide(int $id, string $decision, string $remark, ?string $signatureDataUrl = null, ?string $via = null): void {
    $au = current_user(); if (!$au) throw new RuntimeException(t('err.noaccess'));
    $r = fsr_load($id);
    if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('fsr.not_pending'));
    $isCustomer = !is_staff() && (int)$au['customer_id'] === (int)$r['customer_id'];
    if (!$isCustomer && !is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    $remark = mb_substr(trim($remark), 0, 2000);
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    if ($decision === 'disputed') {
        if ($remark === '') throw new RuntimeException(t('fsr.remark_needed'));
        q('UPDATE fsr_reports SET status = "disputed", customer_remark = ? WHERE id = ?', [$remark, $id]);
        fsr_event($id, 'disputed', $remark . ($via ? " ($via)" : ''));
        fsr_generate_documents($id);
        return;
    }
    if ($decision !== 'accepted') throw new RuntimeException(t('err.notfound'));
    $signId = $signatureDataUrl ? fsr_store_signature($signatureDataUrl, $r, 'customer') : null;
    $name = $au['full_name'] . (!$isCustomer ? ' (' . t('fsr.on_behalf') . ')' : '');
    q('UPDATE fsr_reports SET status = "accepted", accepted_by = ?, accepted_name = ?, accepted_at = NOW(), accepted_ip = ?, customer_remark = ?, customer_sign_id = COALESCE(?, customer_sign_id) WHERE id = ?',
      [(int)$au['id'], $name, $ip ?: null, $remark ?: null, $signId, $id]);
    fsr_event($id, 'accepted', trim(($remark ?: '') . ($via ? " ($via)" : '')) ?: null);
    fsr_generate_documents($id);
}

/* ------------------------------------------------------- documents */

/** Render both files and store them (replacing the previous version). */
function fsr_generate_documents(int $id): void {
    if (function_exists('cp_doc_budget')) cp_doc_budget();   // R77-010
    $r = fsr_load($id);
    if (!$r) return;
    cp_delete_file($r['docx_file_id'] ? (int)$r['docx_file_id'] : null);
    cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null);
    $base = 'FSR-' . $r['report_no'] . '-R' . (int)$r['version'];
    $pdfId  = cp_store_bytes(fsr_render_pdf($r), $base . '.pdf', 'application/pdf', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'fsr_doc', 'external', $id, $base . '.pdf');
    $docxId = cp_store_bytes(fsr_render_docx($r), $base . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'fsr_doc', 'external', $id, $base . '.docx');
    q('UPDATE fsr_reports SET pdf_file_id = ?, docx_file_id = ? WHERE id = ?', [$pdfId, $docxId, $id]);
}

function fsr_legend(): array { return ['TT: Travelling Time | WT: Working Time', 'W: Warranty | G: Goodwill | I: Invoice']; }

/** The acceptance boxes as separate lines, so each one can be kept inside its box (R97-057): ['g' => [name, capacity, detail], 'c' => [name, capacity, detail], 'sub' => text]. */
function fsr_sign_parts(array $r): array {
    $g = $r['submitted_at'] ? [(string)($r['submitted_name'] ?: ''), '', fmt_dt_doc($r['submitted_at'])] : ['', '', ''];
    $c = ['', '', '']; $sub = 'Pending acceptance / Εκκρεμεί αποδοχή';
    if ($r['status'] === 'accepted' && $r['accepted_at']) {
        $c = [(string)($r['accepted_name'] ?: ''), (string)($r['accepted_role'] ?? ''), fmt_dt_doc($r['accepted_at']) . ($r['accepted_ip'] ? ' · IP ' . $r['accepted_ip'] : '')];
        $sub = function_exists('sgn_via_label') ? sgn_via_label($r['accepted_via'] ?? null) : 'Accepted electronically / Αποδοχή ηλεκτρονικά';
    } elseif ($r['status'] === 'disputed') { $c = ['NOT ACCEPTED — see notes', '', '']; $sub = 'Not accepted / Δεν έγινε αποδοχή'; }
    return ['g' => $g, 'c' => $c, 'sub' => $sub];
}
/** Texts for the two acceptance boxes: [golden, customer, customerSub]. */
function fsr_sign_texts(array $r): array {
    $g = $r['submitted_at'] ? sprintf('%s · %s', $r['submitted_name'] ?: '', fmt_dt_doc($r['submitted_at'])) : '';
    $c = ''; $sub = 'Pending acceptance / Εκκρεμεί αποδοχή';
    if ($r['status'] === 'accepted' && $r['accepted_at']) {
        $c = sprintf('%s · %s', $r['accepted_name'] ?: '', fmt_dt_doc($r['accepted_at']));
        if ($r['accepted_ip']) $c .= ' · IP ' . $r['accepted_ip'];
        if (!empty($r['accepted_role'])) $c = sprintf('%s — %s · %s', $r['accepted_name'] ?: '', $r['accepted_role'], fmt_dt_doc($r['accepted_at'])) . ($r['accepted_ip'] ? ' · IP ' . $r['accepted_ip'] : '');
        $sub = function_exists('sgn_via_label') ? sgn_via_label($r['accepted_via'] ?? null) : 'Accepted electronically / Αποδοχή ηλεκτρονικά';
    } elseif ($r['status'] === 'disputed') { $c = 'NOT ACCEPTED — see notes'; $sub = 'Not accepted / Δεν έγινε αποδοχή'; }
    return [$g, $c, $sub];
}

/* ---- PDF: the SF-GLD-014 layout, one block per task --------------- */
function fsr_render_pdf(array $r): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter();
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4';
    $L = 15.0; $R = 195.0; $W = $R - $L;
    $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null); $logo = $bd['logo'];
    $y = 0.0;

    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, 'FIELD SERVICE REPORT');
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, FSR_FORM_CODE);
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $y = 30.0;
    };
    $footer = function () use ($pdf, $ink, $L, $W, $r, $bd) {
        $pdf->setColor($ink); $pdf->setFont('R', 7.5);
        [$a, $b] = fsr_legend(); $pdf->text($L, 283, $a); $pdf->text($L, 287, $b);
        $pdf->setFont('R', 7); $pdf->text($L, 291, $bd['footer'] . ' · ' . FSR_FORM_CODE . ' · ' . $r['report_no']);
        $pdf->text($L, 291, 'Page ' . $pdf->pageNo(), 'R', $W);
    };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 272) $newPage(); };
    $cell = function (float $x, float $yy, float $w, float $h, string $label, string $value) use ($pdf, $navy, $ink, $line) {   // R59-002: the value wraps; $h is the row height agreed for both cells
        $pdf->kvCell($x, $yy, $w, 38, $label, $value, ['h' => $h, 'labelSize' => 7.5, 'line' => $line, 'navy' => $navy, 'ink' => $ink]);
    };
    $hrow = function (float $x, float $yy, array $cols, float $h = 5.5) use ($pdf, $navy, $line, $head) {
        $pdf->setColor($head); $pdf->rect($x, $yy, array_sum(array_column($cols, 1)), $h, 'F');
        foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $yy, $cw, $h); $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($x + 1.5, $yy + $h / 2 + 1.1, $lab, $al, $cw - 3); $x += $cw; }
    };
    $drow = function (float $x, float $yy, array $cols, array $vals, float $h = 5.3, bool $bold = false) use ($pdf, $ink, $line) {
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $yy, $cw, $h); $pdf->setColor($ink); $pdf->setFont($bold ? 'B' : 'R', 8); $pdf->text($x + 1.5, $yy + $h / 2 + 1.1, (string)($vals[$i] ?? ''), $al, $cw - 3); $x += $cw; }
    };

    /* ---- page 1: header block */
    $header(); $footer();
    $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, 'Report No:');
    $pdf->setFont('R', 9); $pdf->text($L + 38, $y + 4, $r['report_no'] . ($r['version'] > 1 ? '   (R' . ((int)$r['version'] - 1) . ')' : '') . (!empty($r['source']['report_no']) ? '   (' . t('fsr.from_ext', ['no' => $r['source']['report_no']]) . ')' : ''));
    if (!empty($r['jo'])) { $pdf->setFont('B', 8.5); $pdf->text($L + $W / 2, $y + 4, 'Work Order:'); $pdf->setFont('R', 9); $pdf->text($L + $W / 2 + 24, $y + 4, $r['jo']['jo_no']); }
    $y += 8;
    $rows = [['Date / Ημερομηνία:', date('Y-m-d', strtotime($r['report_date'])), 'PIC / Υπεύθυνος:', (string)$r['pic_name']],
             ['Customer / Πελάτης:', (string)($r['customer_name'] ?: $r['customer_db_name']), 'Position / Θέση:', (string)$r['pic_position']],
             ['Vessel / Πλοίο:', (string)$r['vessel_name'], 'Mobile / Κινητό:', (string)$r['pic_mobile']],
             ['Location / Θέση:', (string)$r['location'], 'Email:', (string)$r['pic_email']]];
    $half = $W / 2; $rh = 7.0;
    foreach ($rows as $row) { $h = max($pdf->kvHeight($half, 38, $row[1], $rh), $pdf->kvHeight($half, 38, $row[3], $rh)); $cell($L, $y, $half, $h, $row[0], $row[1]); $cell($L + $half, $y, $half, $h, $row[2], $row[3]); $y += $h; }
    $y += 5;

    /* ---- hours summary per engineer and discipline */
    $sum = $r['summary'];
    $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 3, 'Hours summary / Σύνοψη ωρών'); $y += 5;
    $scols = [['Engineer / Μηχανικός', 56, 'L'], ['Discipline / Ειδικότητα', 44, 'L'], ['WT', 18, 'C'], ['TT', 18, 'C'], ['NC', 18, 'C'], ['Total / Σύνολο', $W - 154, 'C']];
    $hrow($L, $y, $scols); $y += 5.5;
    foreach ($sum['rows'] ?: [['engineer' => '', 'specialty' => '', 'wt' => 0, 'tt' => 0, 'nc' => 0, 'total' => 0]] as $sr) {
        $drow($L, $y, $scols, [$sr['engineer'], $sr['specialty'], fsr_fmt_hours((float)$sr['wt']), fsr_fmt_hours((float)$sr['tt']), fsr_fmt_hours((float)($sr['nc'] ?? 0)), fsr_fmt_hours((float)$sr['total'])]); $y += 5.3;
    }
    $drow($L, $y, $scols, ['Total / Σύνολο', '', fsr_fmt_hours($sum['wt']), fsr_fmt_hours($sum['tt']), fsr_fmt_hours((float)($sum['nc'] ?? 0)), fsr_fmt_hours($sum['total'])], 5.3, true); $y += 5.3;
    $y += 5;

    /* ---- tasks */
    $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 3, 'Service Report / Εργασίες', 'C', $W); $y += 6;
    $works = $r['works'] ?: [];
    if (!$works) $works[] = ['seq' => 1, 'description' => '', 'parts' => [], 'open_issues' => '', 'lines' => [], 'photos' => []];
    $leftW = $W * 0.5; $rightW = $W - $leftW; $typeW = 14;
    $tcols = [['Engineer / Μηχανικός', 52, 'L'], ['Discipline / Ειδικότητα', 44, 'L'], ['Start', 18, 'C'], ['End', 18, 'C'], ['Type', 14, 'C'], ['Hours', $W - 146, 'C']];
    foreach ($works as $wk) {
        $pdf->setFont('R', 8);
        $descH = max(26, $pdf->paragraphHeight($leftW - 4, (string)$wk['description'], 4.0) + 8);
        $parts = $wk['parts'] ?: []; while (count($parts) < FSR_MAX_PARTS) $parts[] = ['d' => '', 't' => ''];
        $issuesLines = max(2, count($pdf->wrap((string)$wk['open_issues'], $rightW - 4)));
        $rightH = 5 + count($parts) * 5 + 5 + $issuesLines * 4.2 + 2;
        $bh = max($descH, $rightH);
        $lines = $wk['lines'] ?: [];
        $linesH = 5.5 + max(1, count($lines)) * 5.3;
        $need(5 + $bh + $linesH + 4);
        // task title bar
        $pdf->setColor($navy); $pdf->rect($L, $y, $W, 5, 'F'); $pdf->setColor('#FFFFFF'); $pdf->setFont('B', 8);
        $pdf->text($L + 1.8, $y + 3.6, 'Task ' . (int)$wk['seq'] . ' / Εργασία ' . (int)$wk['seq']); $y += 5;
        // left: description
        $pdf->setColor($head); $pdf->rect($L, $y, $leftW, 5, 'F');
        $pdf->setColor($line); $pdf->rect($L, $y, $leftW, $bh); $pdf->line($L, $y + 5, $L + $leftW, $y + 5);
        $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($L + 1.8, $y + 3.6, 'Description:');
        $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->paragraph($L + 2, $y + 6, $leftW - 4, (string)$wk['description'], 4.0);
        // right: parts + issues
        $rx = $L + $leftW; $ry = $y;
        $pdf->setColor($head); $pdf->rect($rx, $ry, $rightW, 5, 'F');
        $pdf->setColor($line); $pdf->rect($rx, $y, $rightW, $bh); $pdf->rect($rx, $ry, $rightW - $typeW, 5); $pdf->rect($rx + $rightW - $typeW, $ry, $typeW, 5);
        $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($rx + 1.8, $ry + 3.6, 'Spare Parts / Ανταλλακτικά:'); $pdf->text($rx + $rightW - $typeW, $ry + 3.6, 'Type', 'C', $typeW);
        $ry += 5;
        foreach ($parts as $pt) {
            $pdf->setColor($line); $pdf->rect($rx, $ry, $rightW - $typeW, 5); $pdf->rect($rx + $rightW - $typeW, $ry, $typeW, 5);
            $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->text($rx + 1.8, $ry + 3.6, (string)$pt['d']); $pdf->text($rx + $rightW - $typeW, $ry + 3.6, (string)$pt['t'], 'C', $typeW);
            $ry += 5;
        }
        $pdf->setColor($head); $pdf->rect($rx, $ry, $rightW, 5, 'F'); $pdf->setColor($line); $pdf->rect($rx, $ry, $rightW, 5);
        $oiTone = trim((string)$wk['open_issues']) !== '' ? ($r['status'] === 'disputed' ? 'red' : 'amber') : null; if ($oiTone) { $pdf->setColor('#' . rc_hex($oiTone)); $pdf->rect($rx, $ry, 1.5, 5 + $issuesLines * 4.2 + 1, 'F'); }
        $pdf->setColor($oiTone ? '#' . rc_hex($oiTone) : $navy); $pdf->setFont('B', 7.5); $pdf->text($rx + 2.6, $ry + 3.6, 'Open Issues / Εκκρεμότητες:'); $ry += 5;
        $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->paragraph($rx + 2, $ry + 0.5, $rightW - 4, (string)$wk['open_issues'], 4.2);
        $y += $bh;
        // time lines of the task
        $hrow($L, $y, $tcols); $y += 5.5;
        foreach ($lines ?: [null] as $ln) {
            $drow($L, $y, $tcols, $ln ? [(string)($ln['engineer'] ?: $ln['remarks']), $ln['specialty'], fsr_fmt_time($ln['start_time']), fsr_fmt_time($ln['end_time']), $ln['time_type'], fsr_fmt_hours((float)$ln['hours'])] : ['', '', '', '', '', '']);
            $y += 5.3;
        }
        // photos of the task, 3 per row, with captions as figures
        $photos = $wk['photos'] ?: [];
        if ($photos) {
            $cw = ($W - 10) / 3; $ch = 44;
            foreach (array_chunk($photos, 3) as $chunk) {
                $need($ch + 10);
                foreach ($chunk as $i => $ph) {
                    $cx = $L + $i * ($cw + 5);
                    $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($cx, $y + 2, $cw, $ch);
                    $path = cp_storage() . '/' . $ph['stored_name'];
                    if (is_file($path)) $pdf->image($path, $cx + 1, $y + 3, $cw - 2, $ch - 2);
                    $pdf->setColor($ink); $pdf->setFont('B', 6.8); $pdf->text($cx, $y + $ch + 5.5, 'Figure ' . $ph['figure'] . ($ph['title'] ? ' — ' . $ph['title'] : ''), 'C', $cw);
                }
                $y += $ch + 9;
            }
        }
        $y += 4;
    }

    /* ---- acceptance boxes */
    $need(48);
    $SP = fsr_sign_parts($r); $gTxt = $SP['g']; $cTxt = $SP['c']; $cSub = $SP['sub'];   // R97-057: name and details on their own lines, each kept inside the box
    $half = $W / 2; $bx = 42.0;
    // R68-001: the drawn signatures (sp_files fsr_sign, stored by the engineer's app and by the customer's app / web page) are printed inside the boxes
    $gSig = !empty($r['golden_sign_id']) ? cp_file_bytes((int)$r['golden_sign_id']) : null;
    $cSig = !empty($r['customer_sign_id']) && $r['status'] === 'accepted' ? cp_file_bytes((int)$r['customer_sign_id']) : null;
    $sigShown = false;
    foreach ([[$L, 'SUBMITTED BY GOLDEN A/S', 'Submitted electronically / Υποβλήθηκε ηλεκτρονικά', $gTxt, $gSig],
              [$L + $half, 'CUSTOMER ACCEPTANCE', $cSub, $cTxt, $cSig]] as [$sx, $lab, $sub, $txt, $sig]) {
        $pdf->setColor($line); $pdf->setLineWidth(0.3); $pdf->rect($sx + 2, $y + 2, $half - 4, $bx);
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($sx, $y + 10, $lab, 'C', $half);
        $pdf->setColor($ink); $pdf->fitText($sx + 4, $y + 18, $sub, $half - 8, 'C', 'R', 7.5);
        $three = trim((string)$txt[1]) !== '';                                                   // name, capacity and date on three lines
        $drawn = $sig ? $pdf->image($sig, $sx + $half / 2 - 24, $y + 19.3, 48, $three ? 11.5 : 13) : false;     // centred above the name line
        $ly = $three ? 31.6 : 33.5;
        if ($drawn) { $sigShown = $sigShown || $sx > $L; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($sx + $half / 2 - 26, $y + $ly, $sx + $half / 2 + 26, $y + $ly); $pdf->setColor($ink); }
        $ty = $drawn ? ($three ? 35.0 : 37.2) : 25.0;
        $pdf->fitText($sx + 4, $y + $ty, (string)$txt[0], $half - 8, 'C', 'B', 8.5);
        if ($three) { $ty += 3.4; $pdf->fitText($sx + 4, $y + $ty, (string)$txt[1], $half - 8, 'C', 'R', 7); }
        $pdf->fitText($sx + 4, $y + $ty + ($three ? 3.3 : ($drawn ? 3.6 : 4.5)), (string)$txt[2], $half - 8, 'C', 'R', 7);
        if ($sx > $L && $r['customer_remark'] && !$drawn && !$three) {
            $pdf->setFont('B', 7.5); $pdf->text($sx + 5, $y + 34.5, 'Customer notes / Σημειώσεις πελάτη:');
            $pdf->setFont('R', 7.5); $pdf->paragraph($sx + 5, $y + 35.5, $half - 10, (string)$r['customer_remark'], 3.6, 2);
        }
    }
    $y += $bx + 6;
    if ($r['customer_remark'] && (mb_strlen((string)$r['customer_remark']) > 120 || $sigShown || trim((string)$cTxt[1]) !== '')) {       // long notes (or notes next to a drawn signature) get their own block
        $pdf->setFont('R', 8); $h = $pdf->paragraphHeight($W - 4, (string)$r['customer_remark'], 4.0) + 8; $need($h);
        $pdf->setColor($head); $pdf->rect($L, $y, $W, 5, 'F'); $pdf->setColor($line); $pdf->rect($L, $y, $W, $h);
        $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($L + 1.8, $y + 3.6, 'Customer notes / Σημειώσεις πελάτη');
        $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->paragraph($L + 2, $y + 6, $W - 4, (string)$r['customer_remark'], 4.0);
        $y += $h + 4;
    }
    $pdf->setColor($ink); $pdf->setFont('R', 7);
    $pdf->text($L, $y + 3, 'This report was produced and accepted electronically on the Golden A/S customer portal. Every submission and acceptance is logged with user, date, time and address.', 'C', $W);
    return $pdf->bytes('Field Service Report ' . $r['report_no']);
}

/* ---- DOCX: the same report in Word ---------------------------------- */
function fsr_render_docx(array $r): string {
    require_once __DIR__ . '/docx.php';
    $d = new DocxWriter();
    $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null); $logo = (string) @file_get_contents($bd['logo']);
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4';
    $d->grid([[['lines' => ['FIELD SERVICE REPORT', FSR_FORM_CODE], 'b' => true, 'sz' => 14, 'color' => $NAVY],
               ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    $d->para('Report No:  ' . $r['report_no'] . ($r['version'] > 1 ? '   (R' . ((int)$r['version'] - 1) . ')' : '') . (!empty($r['source']['report_no']) ? '   (' . t('fsr.from_ext', ['no' => $r['source']['report_no']]) . ')' : '') . (!empty($r['jo']) ? '      Work Order:  ' . $r['jo']['jo_no'] : ''), ['b' => true, 'align' => 'left']);
    $kv = fn($l) => ['lines' => [$l], 'b' => true, 'sz' => 8]; $vv = fn($v) => ['text' => (string)$v, 'sz' => 9];
    $d->grid([
        [$kv('Date / Ημερομηνία:'), $vv(date('Y-m-d', strtotime($r['report_date']))), $kv('PIC / Υπεύθυνος:'), $vv($r['pic_name'])],
        [$kv('Customer / Πελάτης:'), $vv($r['customer_name'] ?: $r['customer_db_name']), $kv('Position / Θέση:'), $vv($r['pic_position'])],
        [$kv('Vessel / Πλοίο:'), $vv($r['vessel_name']), $kv('Mobile / Κινητό:'), $vv($r['pic_mobile'])],
        [$kv('Location / Θέση:'), $vv($r['location']), $kv('Email:'), $vv($r['pic_email'])],
    ], [1900, 2920, 1900, 2920], ['border' => '9AA5B1']);
    $hdr = fn($t) => ['text' => $t, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center'];
    $c = fn($t, $al = 'left', $b = false) => ['text' => (string)$t, 'sz' => 9, 'align' => $al, 'b' => $b];
    // summary
    $d->para('Hours summary / Σύνοψη ωρών', ['b' => true, 'align' => 'left', 'color' => $NAVY]);
    $sum = $r['summary'];
    $rows = [[$hdr('Engineer / Μηχανικός'), $hdr('Discipline / Ειδικότητα'), $hdr('WT'), $hdr('TT'), $hdr('NC'), $hdr('Total / Σύνολο')]];
    foreach ($sum['rows'] as $sr) $rows[] = [$c($sr['engineer']), $c($sr['specialty']), $c(fsr_fmt_hours((float)$sr['wt']), 'center'), $c(fsr_fmt_hours((float)$sr['tt']), 'center'), $c(fsr_fmt_hours((float)($sr['nc'] ?? 0)), 'center'), $c(fsr_fmt_hours((float)$sr['total']), 'center')];
    $rows[] = [$c('Total / Σύνολο', 'left', true), '', $c(fsr_fmt_hours($sum['wt']), 'center', true), $c(fsr_fmt_hours($sum['tt']), 'center', true), $c(fsr_fmt_hours((float)($sum['nc'] ?? 0)), 'center', true), $c(fsr_fmt_hours($sum['total']), 'center', true)];
    $d->grid($rows, [3000, 2440, 1000, 1000, 1000, 1200], ['border' => '9AA5B1']);
    $d->para('Service Report / Εργασίες', ['b' => true, 'align' => 'center', 'color' => $NAVY]);
    $works = $r['works'] ?: [['seq' => 1, 'description' => '', 'parts' => [], 'open_issues' => '', 'lines' => [], 'photos' => []]];
    foreach ($works as $wk) {
        $parts = $wk['parts'] ?: []; while (count($parts) < FSR_MAX_PARTS) $parts[] = ['d' => '', 't' => ''];
        $rows = [[['text' => 'Task ' . (int)$wk['seq'] . ' / Εργασία ' . (int)$wk['seq'], 'b' => true, 'sz' => 8, 'fill' => $NAVY, 'color' => 'FFFFFF', 'span' => 3]],
                 [['text' => 'Description:', 'b' => true, 'sz' => 8, 'fill' => $HEAD], ['text' => 'Spare Parts / Ανταλλακτικά:', 'b' => true, 'sz' => 8, 'fill' => $HEAD], ['text' => 'Type', 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center']]];
        foreach ($parts as $i => $pt) {
            $row = $i === 0 ? [['text' => (string)$wk['description'], 'sz' => 9, 'valign' => 'top', '_vm' => 'restart']] : [['text' => '', '_vm' => 'continue']];
            $row[] = ['text' => (string)$pt['d'], 'sz' => 9]; $row[] = ['text' => (string)$pt['t'], 'sz' => 9, 'align' => 'center'];
            $rows[] = $row;
        }
        $rows[] = [['text' => '', '_vm' => 'continue'], ['text' => 'Open Issues / Εκκρεμότητες:', 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'span' => 2]];
        $rows[] = [['text' => '', '_vm' => 'continue'], ['text' => (string)$wk['open_issues'], 'sz' => 9, 'span' => 2, 'h' => 500]];
        $d->grid($rows, [4820, 3820, 1000], ['border' => '9AA5B1', 'tight' => true]);
        $trows = [[$hdr('Engineer / Μηχανικός'), $hdr('Discipline / Ειδικότητα'), $hdr('Start'), $hdr('End'), $hdr('Type'), $hdr('Hours')]];
        foreach ($wk['lines'] ?: [null] as $ln) $trows[] = $ln ? [$c($ln['engineer'] ?: $ln['remarks']), $c($ln['specialty']), $c(fsr_fmt_time($ln['start_time']), 'center'), $c(fsr_fmt_time($ln['end_time']), 'center'), $c($ln['time_type'], 'center'), $c(fsr_fmt_hours((float)$ln['hours']), 'center')] : ['', '', '', '', '', ''];
        $d->grid($trows, [2800, 2440, 1000, 1000, 1000, 1400], ['border' => '9AA5B1']);
        if ($wk['photos']) {
            $cells = [];
            foreach ($wk['photos'] as $ph) { $bytes = cp_photo_bytes((int)$ph['id']); $cells[] = $bytes ? ['img' => [$bytes, 5.2, 4.0], 'lines' => ['Figure ' . $ph['figure'] . ($ph['title'] ? ' — ' . $ph['title'] : '')], 'sz' => 7, 'b' => true, 'align' => 'center'] : ''; }
            while (count($cells) % 3) $cells[] = '';
            $d->grid(array_chunk($cells, 3), [3213, 3213, 3214], ['border' => 'D9D9D9']);
        }
    }
    [$gTxt, $cTxt, $cSub] = fsr_sign_texts($r);
    $box = fn($lab, $sub, $txt) => ['lines' => [$lab, '', $sub, $txt], 'b' => true, 'sz' => 9, 'align' => 'center', 'color' => $NAVY, 'h' => 1400, 'valign' => 'center'];
    $d->grid([[$box('SUBMITTED BY GOLDEN A/S', 'Submitted electronically / Υποβλήθηκε ηλεκτρονικά', $gTxt), $box('CUSTOMER ACCEPTANCE', $cSub, $cTxt)]], [4820, 4820], ['border' => '9AA5B1']);
    if ($r['customer_remark']) $d->kv('Customer notes / Σημειώσεις πελάτη', (string)$r['customer_remark']);
    [$a, $b] = fsr_legend();
    $d->para($a, ['i' => true, 'sz' => 8, 'align' => 'left']); $d->para($b, ['i' => true, 'sz' => 8, 'align' => 'left']);
    $d->para('This report was produced and accepted electronically on the Golden A/S customer portal. Every submission and acceptance is logged with user, date, time and address.', ['i' => true, 'sz' => 7, 'align' => 'center']);
    return $d->bytes('Field Service Report ' . $r['report_no'], $bd['footer'] . ' · ' . FSR_FORM_CODE . ' · ' . $r['report_no']);
}

/** Turn 'vmerge' marker cells into Word vertical merges (first cell of the
 *  column keeps its text, following ones continue the merge). */
function fsr_docx_vmerge(array $rows): array {
    foreach ($rows as $i => &$row) {
        foreach ($row as $j => &$c) {
            if (is_array($c) && !empty($c['vmerge'])) { $c['_vm'] = 'continue'; unset($c['vmerge']); }
            elseif ($i === 1 && $j === 0 && is_array($c)) { $c['_vm'] = 'restart'; }
        }
    }
    return $rows;
}

/* ------------------------------------------------------------ R38-003: service calls without project, external reports */

function fsr_ext_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM fsr_reports LIKE 'kind'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** External report number: EXT-YYYYMMDD-INI-n. */
function fsr_ext_number(string $engineer, string $date): string {
    $ini = ''; foreach (preg_split('/\s+/', trim($engineer)) as $part) if ($part !== '') $ini .= mb_strtoupper(mb_substr($part, 0, 1));
    $ini = preg_replace('/[^A-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $ini)) ?: 'GS';
    $base = 'EXT-' . str_replace('-', '', substr($date, 0, 10)) . '-' . $ini . '-'; $n = 1;
    while (scalar('SELECT COUNT(*) FROM fsr_reports WHERE report_no = ?', [$base . $n])) $n++;
    return $base . $n;
}
/** The project an engineer reports under for a plain service call on a vessel: the vessel's general project (GEN-<id>). */
function fsr_project_for_vessel(int $vesselId): int { throw new RuntimeException(t('doc.need_project')); }   // R72-008: no vessel-level reports any more — a project is chosen first
/** Attach an external report (a file written outside the portal). Returns the id. */
function fsr_ext_save_from_post(): int {
    require_staff(); if (!fsr_ext_has()) throw new RuntimeException(t('fsr.ext_need_db'));
    $u = current_user(); $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $vid = (int)($_POST['vessel_id'] ?? 0); $pid = (int)($_POST['project_id'] ?? 0);
    if ($pid) { $p = one('SELECT * FROM projects WHERE id = ?', [$pid]); if (!$p) throw new RuntimeException(t('err.notfound')); $vid = $vid ?: (int)$p['vessel_id']; }
    if (!$pid) throw new RuntimeException(t('doc.need_project'));
    $p = one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; if ($sid && !one('SELECT id FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['report_date'] ?? '')) ? $_POST['report_date'] : date('Y-m-d');
    if (empty($_FILES['file']['name'])) throw new RuntimeException(t('fsr.ext_need_file'));
    $eng = $str('ext_engineers', 255); $no = fsr_ext_number($eng ?: (string)($u['full_name'] ?? ''), $date);
    q("INSERT INTO fsr_reports (project_id, subproject_id, report_date, customer_name, vessel_name, location, pic_name, pic_position, pic_mobile, pic_email, report_no, kind, ext_summary, ext_engineers, owner_visible, status, submitted_by, submitted_name, submitted_at, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,'external',?,?,?,'submitted',?,?,NOW(),?)",
      [$pid, $sid, $date, (string)($p['customer_name'] ?? ''), (string)($p['vessel'] ?? ''), $str('location', 160), $str('pic_name', 120), $str('pic_position', 120), $str('pic_mobile', 40), $str('pic_email', 190),
       $no, mb_substr(trim((string)($_POST['ext_summary'] ?? '')), 0, 4000) ?: null, $eng, (int)!empty($_POST['owner_visible']), (int)$u['id'], (string)$u['full_name'], (int)$u['id']]);
    $id = (int) db()->lastInsertId();
    $fid = cp_store_file($_FILES['file'], $pid, $sid, 'fsr_ext', !empty($_POST['owner_visible']) ? 'external' : 'internal', $no, null, $id);
    if (!$fid) { q('DELETE FROM fsr_reports WHERE id = ?', [$id]); throw new RuntimeException(t('err.upload')); }
    q('UPDATE fsr_reports SET ext_file_id = ?, pdf_file_id = IF(? LIKE ?, ?, NULL) WHERE id = ?', [$fid, (string)$_FILES['file']['name'], '%.pdf', $fid, $id]);
    fsr_event($id, 'created', t('fsr.ev_ext_attached'));
    if (isset($_POST['ticket_id']) && function_exists('tk_link')) tk_link('fsr_reports', $id, (int)$_POST['ticket_id']);
    return $id;
}
/** R46-001: move an external report (and its files) to another project / sub-project — after a service call was filed on the
 *  vessel-in-general project, or on the wrong one. The converted internal report, if any, is not touched. */
function fsr_ext_relink(int $id): void {
    require_staff(); $r = fsr_load($id); if (!$r || ($r['kind'] ?? 'internal') !== 'external') throw new RuntimeException(t('err.notfound'));
    $pid = (int)($_POST['project_id'] ?? 0); [$sc, $sa] = scope_sql('p'); $p = $pid ? one("SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ? AND {$sc}", array_merge([$pid], $sa)) : null;
    if (!$p) throw new RuntimeException(t('err.notfound'));
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; if ($sid && !one('SELECT id FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    if ((int)$r['project_id'] === $pid && (int)($r['subproject_id'] ?? 0) === (int)$sid) return;
    q('UPDATE fsr_reports SET project_id = ?, subproject_id = ?, customer_name = ?, vessel_name = ? WHERE id = ?', [$pid, $sid, (string)($p['customer_name'] ?? ''), (string)($p['vessel'] ?? ''), $id]);
    foreach (['ext_file_id', 'pdf_file_id', 'docx_file_id'] as $col) if (!empty($r[$col])) q('UPDATE sp_files SET project_id = ?, subproject_id = ? WHERE id = ?', [$pid, $sid, (int)$r[$col]]);
    try { q('UPDATE sp_files SET project_id = ?, subproject_id = ? WHERE fsr_id = ?', [$pid, $sid, $id]); } catch (Throwable $e) {}
    $sub = $sid ? one('SELECT code, name FROM subprojects WHERE id = ?', [$sid]) : null;
    fsr_event($id, 'saved', t('fsr.ev_relinked') . ' ' . trim(($p['project_code'] ?: $p['name']) . ($sub ? ' › ' . $sub['code'] . ' ' . $sub['name'] : '')));
}
/** External reports not yet turned into an internal one (nothing of them reaches accounting). */
function fsr_ext_pending(): array {
    if (!fsr_ext_has()) return [];
    return all("SELECT f.id, f.report_no, f.report_date, f.ext_engineers, f.location, f.submitted_name, p.project_code, p.name AS project_name, v.name AS vessel, v.id AS vessel_id FROM fsr_reports f JOIN projects p ON p.id = f.project_id LEFT JOIN vessels v ON v.id = p.vessel_id WHERE f.kind = 'external' AND f.converted_id IS NULL ORDER BY f.report_date, f.id");
}

/* ------------------------------------------------------------------ R82-001: parts used on site leave the stock */
/** Every part with an inventory code and no movement yet is issued from the default warehouse (negative stock allowed — the van stock is counted later); the movement id is kept in the part so a re-save never issues twice. Parts cost on the sub-project when the report has one. */
function fsr_parts_issue(int $reportId): void {
    if (!function_exists('stk_has') || !stk_has()) return;
    $r = one('SELECT id, report_no, project_id, subproject_id FROM fsr_reports WHERE id = ?', [$reportId]); if (!$r) return; $u = current_user();
    foreach (all('SELECT id, parts FROM fsr_works WHERE report_id = ?', [$reportId]) as $w) {
        $parts = $w['parts'] ? (json_decode((string)$w['parts'], true) ?: []) : []; $changed = false;
        foreach ($parts as &$p) {
            if (empty($p['c']) || !empty($p['m'])) continue; $it = stk_item_by_code((string)$p['c']); if (!$it) continue; $qty = (float)($p['q'] ?? 1) ?: 1.0;
            try { $mid = stk_move((int)$it['id'], stk_default_wh(), 'out', $qty, ['unit_price' => $it['last_price'], 'project_id' => $r['project_id'] ? (int)$r['project_id'] : null, 'subproject_id' => $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'reason' => 'issue', 'ref_kind' => 'fsr', 'ref_id' => $reportId, 'note' => (string)$r['report_no'], 'allow_negative' => true, 'item_consumable' => !empty($it['is_consumable'])]); if (function_exists('nf_push') && function_exists('prq_proc_ids') && (float) scalar('SELECT qty_on_hand FROM inv_stock WHERE item_id = ? AND warehouse_id = ?', [(int)$it['id'], stk_default_wh()]) < -0.0005) nf_push(prq_proc_ids(), 'work:stock', 'stk_neg:' . (int)$it['id'], t('stk.neg_title', ['c' => (string)$it['code']]), t('stk.neg_body', ['n' => (string)$r['report_no']]), url('inventory_item', ['id' => (int)$it['id']]), null, null);   /* R86-001 */ } catch (Throwable $e) { continue; }
            $p['m'] = $mid; $changed = true;
            if ($r['subproject_id'] && function_exists('bg_has') && bg_has() && $it['last_price'] && ($p['t'] ?? 'I') !== 'W') { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, created_by, created_name) VALUES (?,CURDATE(),?,?,?,?,?,?,?,?)', [(int)$r['subproject_id'], 'parts', t('stk.stock'), mb_substr($it['code'] . ' · ' . $it['description'] . ' × ' . stk_qty($qty), 0, 255), mb_substr((string)$r['report_no'], 0, 60), round($qty * (float)$it['last_price'], 2), $it['currency'], (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null]); if (function_exists('eng_rows_reset')) eng_rows_reset(); }
        }
        unset($p);
        if ($changed) q('UPDATE fsr_works SET parts = ? WHERE id = ?', [json_encode($parts, JSON_UNESCAPED_UNICODE), (int)$w['id']]);
    }
    cache_bump_later('inv');
}

