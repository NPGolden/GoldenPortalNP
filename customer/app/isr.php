<?php
/**
 * Inspection reports — form SF-GLD-020.                                         GAS-CP-R23-003
 *
 * One report per vessel / project (optionally a sub-project) with the cover data of
 * the template (date, report no, vessel, customer, location, PIC, subject, class /
 * flag, builder / year, hull no), the three introductory texts (subject and
 * objective, main findings and recommendations, risks and hazards) and one block
 * per inspected system — picked from the vessel-systems list or the template's own
 * section list, or "Other" with a free description. Each block carries: condition
 * of equipment as found, a checklist of questions / answers (pre-filled from the
 * template's question bank when the system is known), class regulations if
 * applicable, risk analysis with a level, proposed corrective actions and
 * attachments (photos, PDFs, drawings). Blocks are added and removed like the
 * tasks of the field service report.
 *
 * Workflow: draft → checking (submitted for check) → issued (PDF + DOCX archived
 * with revision R[n], visible to the owner). Re-open returns to draft; issuing
 * again produces the next revision.
 */
declare(strict_types=1);
require_once __DIR__ . '/isr_bank.php';

const ISR_FORM_CODE = 'SF-GLD-020';
const ISR_MAX_ITEMS = 80;
const ISR_MAX_FILES = 300;

function isr_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'isr_reports'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function isr_risk_levels(): array { return ['none' => t('isr.rk_none'), 'low' => t('isr.rk_low'), 'medium' => t('isr.rk_medium'), 'high' => t('isr.rk_high'), 'critical' => t('isr.rk_critical')]; }
function isr_risk_pill(string $l): string {
    $tone = ['none' => 'mute', 'low' => 'green', 'medium' => 'gold', 'high' => 'bad', 'critical' => 'bad'][$l] ?? 'mute';
    return pill($tone, isr_risk_levels()[$l] ?? $l);
}
function isr_status_pill(string $s): string { return pill(['draft' => 'mute', 'checking' => 'gold', 'issued' => 'green'][$s] ?? 'mute', t('isr.st_' . $s)); }

/** Systems offered by the picker: the portal's vessel systems + the template sections, de-duplicated, sorted. */
function isr_system_options(): array {
    $out = [];
    try { foreach (all('SELECT id, name, abbr FROM vessel_systems WHERE is_active = 1 ORDER BY sort_order, name') as $s) $out[mb_strtolower($s['name'])] = ['id' => (int)$s['id'], 'name' => $s['name'], 'abbr' => $s['abbr']]; } catch (Throwable $e) {}
    foreach (isr_template_systems() as $n) { $k = mb_strtolower($n); if (!isset($out[$k])) $out[$k] = ['id' => 0, 'name' => $n, 'abbr' => null]; }
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return array_values($out);
}
/** Question bank keyed by lower-cased system name (template sections; a few aliases). */
function isr_bank_lc(): array {
    static $c = null;
    if ($c === null) {
        $c = []; foreach (isr_bank() as $k => $qs) $c[mb_strtolower($k)] = array_map(fn($q) => preg_replace('/\s*\n+\s*/u', ' — ', trim((string)$q)), $qs);   // one line per question in the form
        $alias = ['main switchboard (msb)' => 'main switchboard', 'emergency switchboard' => 'emcy switchboard', 'emergency generator' => 'emcy generator', 'alarm & monitoring system' => 'alarm and monitoring system',
                  'ias' => 'alarm and monitoring system', 'fire dampers' => 'fire dampers', 'fire doors' => 'fire doors', 'navigation lights' => 'navigation lighting', 'bow thruster system' => 'bow thruster'];
        foreach ($alias as $a => $b) if (isset($c[$b]) && !isset($c[$a])) $c[$a] = $c[$b];
    }
    return $c;
}

function isr_load(int $id): ?array {
    if (!isr_has() || !$id) return null;
    [$scope, $args] = scope_sql('p'); array_unshift($args, $id);
    $r = one("SELECT r.*, p.name AS project_name, p.project_code, p.customer_id, c.name AS customer_db_name, v.name AS vessel_db_name, s.code AS sub_code, s.name AS sub_name
              FROM isr_reports r JOIN projects p ON p.id = r.project_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN subprojects s ON s.id = r.subproject_id
              WHERE r.id = ? AND {$scope}", $args);
    if (!$r) return null;
    if (!is_staff() && $r['status'] !== 'issued') return null;
    $r['items'] = all('SELECT * FROM isr_items WHERE report_id = ? ORDER BY seq', [$id]);
    $files = all("SELECT * FROM sp_files WHERE isr_id = ? AND kind = 'isr_file' ORDER BY isr_item, id", [$id]);
    $fig = 0;
    foreach ($files as &$f) { $f['is_image'] = str_starts_with((string)$f['mime_type'], 'image/'); if ($f['is_image']) $f['figure'] = ++$fig; }
    unset($f);
    foreach ($r['items'] as &$it) { $it['checklist'] = $it['checklist'] ? (json_decode((string)$it['checklist'], true) ?: []) : []; $it['files'] = array_values(array_filter($files, fn($f) => (int)$f['isr_item'] === (int)$it['seq'])); }
    unset($it);
    $r['files'] = $files;
    $r['events'] = all('SELECT * FROM isr_events WHERE report_id = ? ORDER BY id', [$id]);
    return $r;
}
function isr_list(?int $projectId = null, ?int $subId = null, ?int $vesselId = null): array {
    if (!isr_has()) return [];
    [$scope, $args] = scope_sql('p'); $w = [$scope];
    if ($projectId) { $w[] = 'r.project_id = ?'; $args[] = $projectId; }
    if ($subId) { $w[] = 'r.subproject_id = ?'; $args[] = $subId; }
    if ($vesselId) { $w[] = 'p.vessel_id = ?'; $args[] = $vesselId; }
    if (!is_staff()) $w[] = "r.status = 'issued'";
    return all("SELECT r.*, p.name AS project_name, p.project_code, v.name AS vessel, s.code AS sub_code, s.name AS sub_name, (SELECT COUNT(*) FROM isr_items i WHERE i.report_id = r.id) AS n_items,
                       (SELECT MAX(FIELD(i.risk_level, 'none', 'low', 'medium', 'high', 'critical')) FROM isr_items i WHERE i.report_id = r.id) AS max_risk
                FROM isr_reports r JOIN projects p ON p.id = r.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN subprojects s ON s.id = r.subproject_id
                WHERE " . implode(' AND ', $w) . ' ORDER BY r.report_date DESC, r.id DESC', $args);
}
function isr_event(int $id, string $ev, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO isr_events (report_id, event, user_id, user_name, note) VALUES (?,?,?,?,?)', [$id, $ev, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $note]);
}
function isr_number(string $engineer, string $date): string {
    $ini = ''; foreach (preg_split('/\s+/', trim($engineer)) as $w) if ($w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    $ini = preg_replace('/[^A-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ini)) ?: 'GAS';
    $base = 'ISR-' . date('Ymd', strtotime($date)) . '-' . $ini;
    $n = (int) scalar('SELECT COUNT(*) FROM isr_reports WHERE report_no LIKE ?', [$base . '-%']) + 1;
    while (scalar('SELECT COUNT(*) FROM isr_reports WHERE report_no = ?', [$base . '-' . sprintf('%02d', $n)])) $n++;
    return $base . '-' . sprintf('%02d', $n);
}
function isr_item_files(string $field, int $i): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'][$i]) || !is_array($f['name'][$i])) return [];
    $out = [];
    foreach ($f['name'][$i] as $n => $name) $out[$n] = ['name' => $name, 'type' => $f['type'][$i][$n], 'tmp_name' => $f['tmp_name'][$i][$n], 'error' => $f['error'][$i][$n], 'size' => $f['size'][$i][$n]];
    return $out;
}

function isr_save_from_post(?int $id): int {
    $u = require_staff();
    $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $pid = (int)($_POST['project_id'] ?? 0); $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    $p = one('SELECT p.*, v.name AS vessel, v.imo, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
    if (!$p) throw new RuntimeException(t('err.notfound'));
    if ($sid && !one('SELECT id FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    if (!$id && function_exists('tm_allowed_ids') && ($tmIds = tm_allowed_ids($pid)) !== null && (!$sid || !in_array($sid, $tmIds, true))) throw new RuntimeException(t('tm.not_allowed'));   // R25-001
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['report_date'] ?? '')) ? $_POST['report_date'] : date('Y-m-d');
    $fields = [$pid, $sid, $date, $str('subject', 200), $str('customer_name', 160) ?? $p['customer_name'], $str('vessel_name', 120) ?? $p['vessel'], $str('location', 160), $str('pic_name', 120),
               $str('class_flag', 120), $str('builder_year', 120), $str('hull_no', 60), $str('objective', 8000), $str('findings', 8000), $str('risks', 8000), $str('performed_name', 120) ?? $u['full_name']];
    if ($id) {
        $r = one('SELECT * FROM isr_reports WHERE id = ?', [$id]); if (!$r) throw new RuntimeException(t('err.notfound'));
        if ($r['status'] === 'issued') throw new RuntimeException(t('isr.locked'));
        q('UPDATE isr_reports SET project_id=?, subproject_id=?, report_date=?, subject=?, customer_name=?, vessel_name=?, location=?, pic_name=?, class_flag=?, builder_year=?, hull_no=?, objective=?, findings=?, risks=?, performed_name=? WHERE id = ?', [...$fields, $id]);
        q('DELETE FROM isr_items WHERE report_id = ?', [$id]);
        isr_event($id, 'saved');
    } else {
        $no = isr_number((string)$u['full_name'], $date);
        q('INSERT INTO isr_reports (project_id, subproject_id, report_date, subject, customer_name, vessel_name, location, pic_name, class_flag, builder_year, hull_no, objective, findings, risks, performed_name, report_no, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$fields, $no, (int)$u['id']]);
        $id = (int) db()->lastInsertId();
        isr_event($id, 'created');
    }
    // systems, in form order; attachments follow their block by original seq
    $seq = 0; $keep = []; $itemNo = [];
    $idx = array_slice(array_keys((array)($_POST['it_name'] ?? [])), 0, ISR_MAX_ITEMS);
    $levels = array_keys(isr_risk_levels());
    foreach ($idx as $i) {
        $i = (int)$i;
        $sysId = (int)($_POST['it_sys'][$i] ?? 0) ?: null; $name = mb_substr(trim((string)($_POST['it_name'][$i] ?? '')), 0, 160);
        if ($sysId) { $sn = scalar('SELECT name FROM vessel_systems WHERE id = ?', [$sysId]); if ($sn) $name = (string)$sn; else $sysId = null; }
        $cond = trim((string)($_POST['it_cond'][$i] ?? '')); $regs = trim((string)($_POST['it_regs'][$i] ?? '')); $risk = trim((string)($_POST['it_risk'][$i] ?? '')); $act = trim((string)($_POST['it_act'][$i] ?? ''));
        $lvl = in_array($_POST['it_level'][$i] ?? '', $levels, true) ? $_POST['it_level'][$i] : 'none';
        $chk = [];
        foreach ((array)($_POST['it_q'][$i] ?? []) as $j => $qq) { $qq = mb_substr(trim((string)$qq), 0, 1000); $aa = mb_substr(trim((string)($_POST['it_a'][$i][$j] ?? '')), 0, 1000); if ($qq !== '' || $aa !== '') $chk[] = ['q' => $qq, 'a' => $aa]; }
        $orig = (int)($_POST['it_orig'][$i] ?? 0);
        $hasFiles = $orig && scalar("SELECT COUNT(*) FROM sp_files WHERE isr_id = ? AND kind = 'isr_file' AND isr_item = ?", [$id, $orig]);
        $newFiles = array_filter(isr_item_files('files', $i), fn($f) => ($f['error'] ?? 1) === UPLOAD_ERR_OK);
        if ($name === '' && $cond === '' && $act === '' && !$chk && !$hasFiles && !$newFiles) continue;
        if ($name === '') $name = t('isr.other');
        q('INSERT INTO isr_items (report_id, seq, system_id, system_name, condition_found, checklist, class_regs, risk_level, risk_analysis, actions) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$id, ++$seq, $sysId, $name, mb_substr($cond, 0, 8000) ?: null, $chk ? json_encode($chk, JSON_UNESCAPED_UNICODE) : null, mb_substr($regs, 0, 4000) ?: null, $lvl, mb_substr($risk, 0, 8000) ?: null, mb_substr($act, 0, 8000) ?: null]);
        $itemNo[$i] = $seq; if ($orig) $keep[$orig] = $seq;
    }
    foreach (all("SELECT id, isr_item FROM sp_files WHERE isr_id = ? AND kind = 'isr_file'", [$id]) as $f) if (!isset($keep[(int)$f['isr_item']])) cp_delete_file((int)$f['id']);
    foreach ($keep as $old => $new) q("UPDATE sp_files SET isr_item = ? WHERE isr_id = ? AND kind = 'isr_file' AND isr_item = ?", [1000 + $new, $id, $old]);
    q("UPDATE sp_files SET isr_item = isr_item - 1000 WHERE isr_id = ? AND kind = 'isr_file' AND isr_item > 1000", [$id]);
    foreach ((array)($_POST['file_caption'] ?? []) as $fid => $cap) q("UPDATE sp_files SET title = ? WHERE id = ? AND isr_id = ? AND kind = 'isr_file'", [mb_substr(trim((string)$cap), 0, 160) ?: null, (int)$fid, $id]);
    $have = (int) scalar("SELECT COUNT(*) FROM sp_files WHERE isr_id = ? AND kind = 'isr_file'", [$id]);
    foreach ($itemNo as $i => $no) {
        $caps = (array)($_POST['new_caption'][$i] ?? []);
        foreach (isr_item_files('files', $i) as $n => $f) {
            if ($have >= ISR_MAX_FILES) break;
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
            $fid = cp_store_file($f, $pid, $sid, 'isr_file', 'external', mb_substr(trim((string)($caps[$n] ?? '')), 0, 160) ?: null, null, null, ['doc_no' => (string) scalar('SELECT report_no FROM isr_reports WHERE id = ?', [$id]), 'label' => 'sys' . $no, 'isr_id' => $id]);
            if ($fid) { q('UPDATE sp_files SET isr_id = ?, isr_item = ? WHERE id = ?', [$id, $no, $fid]); $have++; }
        }
    }
    if (function_exists('eq_isr_link')) eq_isr_link($id, (array)($_POST['eq_ids'] ?? []), (string)($_POST['eq_new'] ?? ''), (int)($p['vessel_id'] ?? 0) ?: null);   // R27-003
    return $id;
}

/* ------------------------------------------------------------ workflow */

function isr_submit(int $id): void {
    $u = require_staff(); $r = isr_load($id); if (!$r || $r['status'] !== 'draft') throw new RuntimeException(t('isr.not_draft'));
    if (!$r['items']) throw new RuntimeException(t('isr.incomplete'));
    q('UPDATE isr_reports SET status = "checking", submitted_by = ?, submitted_at = NOW() WHERE id = ?', [(int)$u['id'], $id]);
    isr_event($id, 'submitted');
}
function isr_issue(int $id): void {
    $u = require_staff(); $r = isr_load($id); if (!$r || $r['status'] === 'issued') throw new RuntimeException(t('isr.not_pending'));
    if (!$r['items']) throw new RuntimeException(t('isr.incomplete'));
    if (!is_cp_admin() && (int)$r['created_by'] === (int)$u['id'] && $r['status'] === 'checking') throw new RuntimeException(t('isr.self_check'));
    q('UPDATE isr_reports SET status = "issued", version = IF(issued_at IS NULL, version, version + 1), checked_by = ?, checked_name = ?, checked_at = NOW(), issued_at = NOW() WHERE id = ?', [(int)$u['id'], $u['full_name'], $id]);
    isr_event($id, 'issued');
    isr_generate_documents($id);
    if (function_exists('eq_isr_sync')) eq_isr_sync($id);   // R27-003: the service history of the units follows the issued report
}
function isr_reopen(int $id): void {
    $u = require_staff(); $r = isr_load($id); if (!$r || $r['status'] === 'draft') throw new RuntimeException(t('isr.not_pending'));
    if ($r['status'] === 'issued' && !is_cp_admin() && (int)$r['checked_by'] !== (int)$u['id']) throw new RuntimeException(t('err.noaccess'));
    q('UPDATE isr_reports SET status = "draft" WHERE id = ?', [$id]);
    isr_event($id, 'reopened');
    if (function_exists('eq_isr_sync')) eq_isr_sync($id);
}
function isr_delete(int $id): void {
    require_cp_admin(); $r = isr_load($id); if (!$r) return;
    foreach (all('SELECT id FROM sp_files WHERE isr_id = ?', [$id]) as $f) cp_delete_file((int)$f['id']);
    cp_delete_file($r['docx_file_id'] ? (int)$r['docx_file_id'] : null); cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null);
    q('DELETE FROM isr_events WHERE report_id = ?', [$id]); q('DELETE FROM isr_items WHERE report_id = ?', [$id]); q('DELETE FROM isr_reports WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------ documents */

function isr_generate_documents(int $id): void {
    $r = isr_load($id); if (!$r) return;
    cp_delete_file($r['docx_file_id'] ? (int)$r['docx_file_id'] : null); cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null);
    $base = $r['report_no'] . '-R' . (int)$r['version'];
    $meta = ['doc_no' => $r['report_no'], 'revision' => 'R' . (int)$r['version'], 'isr_id' => $id];
    $pdfId  = cp_store_bytes(isr_render_pdf($r), $base . '.pdf', 'application/pdf', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'isr_doc', 'external', null, $base . '.pdf', $meta);
    $docxId = cp_store_bytes(isr_render_docx($r), $base . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'isr_doc', 'external', null, $base . '.docx', $meta);
    q('UPDATE sp_files SET isr_id = ? WHERE id IN (?, ?)', [$id, $pdfId, $docxId]);
    q('UPDATE isr_reports SET pdf_file_id = ?, docx_file_id = ? WHERE id = ?', [$pdfId, $docxId, $id]);
}
function isr_cover_rows(array $r): array {
    return [['Date:', date('d.m.Y', strtotime($r['report_date'])), 'Report No:', $r['report_no'] . ' · R' . (int)$r['version']],
            ['Vessel:', (string)($r['vessel_name'] ?: $r['vessel_db_name']), 'Customer:', (string)($r['customer_name'] ?: $r['customer_db_name'])],
            ['Vessel Location:', (string)$r['location'], 'P.I.C.:', (string)$r['pic_name']],
            ['Subject:', (string)$r['subject'], 'CLASS / FLAG:', (string)$r['class_flag']],
            ['Builder / Year Built:', (string)$r['builder_year'], 'HULL No:', (string)$r['hull_no']]];
}
function isr_render_pdf(array $r): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter();
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $mute = '#5F6B76';
    $L = 15.0; $R = 195.0; $W = $R - $L; $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null); $logo = $bd['logo']; $y = 0.0;
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, &$y) { $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, 'INSPECTION REPORT'); $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, ISR_FORM_CODE); $pdf->image($logo, $R - 42, 9, 42, 15); $y = 30.0; if ($pdf->pageNo() === 1) { $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y += rc_legend_pdf($pdf, $L, 27.2, $R - $L); } };
    $footer = function () use ($pdf, $ink, $L, $W, $r, $bd) { $pdf->setColor($ink); $pdf->setFont('R', 7); $pdf->text($L, 291, $bd['footer'] . ' · ' . ISR_FORM_CODE . ' · ' . $r['report_no'] . ' · R' . (int)$r['version']); $pdf->text($L, 291, 'Page ' . $pdf->pageNo(), 'R', $W); };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 272) $newPage(); };
    $h1 = function (string $t) use ($pdf, $navy, $gold, $L, $W, &$y, $need) { $need(14); $y += 3; $pdf->setColor($navy); $pdf->setFont('B', 11); $pdf->text($L, $y + 4, $t); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 6, $L + $W, $y + 6); $y += 9; };
    $h2 = function (string $t) use ($pdf, $navy, $L, &$y, $need) { $need(10); $y += 1.5; $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 3.5, $t); $y += 6; };
    $para = function (string $t, float $size = 8.5) use ($pdf, $ink, $L, $W, &$y, $need, $newPage) {
        $t = trim($t); if ($t === '') { $pdf->setColor('#9AA5B1'); $pdf->setFont('R', 8); $pdf->text($L, $y + 3, '—'); $y += 5; return; }
        $pdf->setColor($ink); $pdf->setFont('R', $size);
        foreach (preg_split('/\r?\n/', $t) as $ln) { $lines = $pdf->wrap($ln === '' ? ' ' : $ln, $W - 2); foreach ($lines as $wl) { $need(5); $pdf->text($L + 1, $y + 3.2, $wl); $y += 4.1; } }
        $y += 1.5;
    };
    $cell = function (float $x, float $yy, float $w, float $h, string $label, string $value) use ($pdf, $navy, $ink, $line) { $pdf->kvCell($x, $yy, $w, 34, $label, $value, ['h' => $h, 'labelSize' => 7.5, 'line' => $line, 'navy' => $navy, 'ink' => $ink]); };   // R59-002: wraps
    $header(); $footer();
    $half = $W / 2; $rh = 7.0;
    foreach (isr_cover_rows($r) as $row) { $h = max($pdf->kvHeight($half, 34, $row[1], $rh), $pdf->kvHeight($half, 34, $row[3], $rh)); $cell($L, $y, $half, $h, $row[0], $row[1]); $cell($L + $half, $y, $half, $h, $row[2], $row[3]); $y += $h; }
    $y += 4;
    $h2('Document subject and objective:'); $para((string)$r['objective']);
    $h2('Main findings and recommendations:'); $para((string)$r['findings']);
    $h2('Risks and hazards related to the current condition:'); $para((string)$r['risks']);
    // summary table of systems
    $items = $r['items'];
    if ($items) {
        $h2('Inspected systems — summary');
        $cols = [['#', 8, 'C'], ['System', 82, 'L'], ['Risk', 24, 'C'], ['Corrective action proposed', $W - 114, 'L']];
        $need(7); $x = $L; $pdf->setColor($head); $pdf->rect($L, $y, $W, 5.5, 'F');
        foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $y, $cw, 5.5); $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($x + 1.5, $y + 3.9, $lab, $al, $cw - 3); $x += $cw; }
        $y += 5.5;
        foreach ($items as $it) { $need(5.5); $x = $L; $vals = [(string)$it['seq'], (string)$it['system_name'], isr_risk_levels()[$it['risk_level']] ?? '', trim((string)$it['actions']) !== '' ? 'Yes' : '—'];
            $rtone = ($rk = fn($l) => ['none' => 'green', 'low' => 'green', 'medium' => 'amber', 'high' => 'red', 'critical' => 'red'][$l] ?? null)($it['risk_level']); rc_bar_pdf($pdf, $rtone, $L, $y, 5.3);
            foreach ($cols as $k => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $y, $cw, 5.3); $pdf->setColor($k === 2 && $rtone ? '#' . rc_hex($rtone) : $ink); $pdf->setFont($k === 2 && $rtone ? 'B' : 'R', 8); $pdf->text($x + 1.5, $y + 3.8, mb_strimwidth($vals[$k], 0, 60, '…'), $al, $cw - 3); $x += $cw; }
            $y += 5.3; }
        $y += 2;
    }
    foreach ($items as $it) {
        $newPage();
        $h1($it['seq'] . '. ' . $it['system_name']);
        $h2('Condition of equipment as found'); $para((string)$it['condition_found']);
        if ($it['checklist']) {
            $need(8); $x = $L; $pdf->setColor($head); $pdf->rect($L, $y, $W, 5.5, 'F'); $pdf->setColor($line); $pdf->rect($L, $y, $W * 0.55, 5.5); $pdf->rect($L + $W * 0.55, $y, $W * 0.45, 5.5);
            $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($L + 1.5, $y + 3.9, 'Question'); $pdf->text($L + $W * 0.55 + 1.5, $y + 3.9, 'Answer'); $y += 5.5;
            foreach ($it['checklist'] as $qa) {
                $pdf->setFont('R', 7.5); $ql = $pdf->wrap((string)$qa['q'], $W * 0.55 - 3); $al = $pdf->wrap((string)$qa['a'] === '' ? ' ' : (string)$qa['a'], $W * 0.45 - 3); $hh = max(count($ql), count($al)) * 3.6 + 2;
                $need($hh); $pdf->setColor($line); $pdf->rect($L, $y, $W * 0.55, $hh); $pdf->rect($L + $W * 0.55, $y, $W * 0.45, $hh); $pdf->setColor($ink);
                foreach ($ql as $n => $wl) $pdf->text($L + 1.5, $y + 3.2 + $n * 3.6, $wl); foreach ($al as $n => $wl) $pdf->text($L + $W * 0.55 + 1.5, $y + 3.2 + $n * 3.6, $wl);
                $y += $hh;
            }
            $y += 2;
        }
        if (trim((string)$it['class_regs']) !== '') { $h2('Class regulations'); $para((string)$it['class_regs']); }
        $rtone2 = ((fn($l) => ['none' => 'green', 'low' => 'green', 'medium' => 'amber', 'high' => 'red', 'critical' => 'red'][$l] ?? null))($it['risk_level']); $h2('Risk analysis — level: ' . (isr_risk_levels()[$it['risk_level']] ?? '')); if ($rtone2) { $pdf->setColor('#' . rc_hex($rtone2)); $pdf->rect($L - 2.2, $y - 5.2, 1.5, 5.2, 'F'); } $para((string)$it['risk_analysis']);
        $h2('Proposed corrective actions'); $para((string)$it['actions']);
        $imgs = array_values(array_filter($it['files'], fn($f) => $f['is_image'])); $docs = array_values(array_filter($it['files'], fn($f) => !$f['is_image']));
        if ($imgs) {
            $h2('Figures'); $cw = ($W - 6) / 3; $ch = $cw * 0.75;
            foreach (array_chunk($imgs, 3) as $row) { $need($ch + 9); $x = $L; foreach ($row as $ph) { $b = cp_file_bytes((int)$ph['id']); if ($b) $pdf->image($b, $x, $y, $cw, $ch); $pdf->setColor($mute); $pdf->setFont('B', 7); $pdf->text($x, $y + $ch + 3.5, mb_strimwidth('Figure ' . $ph['figure'] . ($ph['title'] ? ' — ' . $ph['title'] : ''), 0, 48, '…'), 'C', $cw); $x += $cw + 3; } $y += $ch + 7; }
        }
        if ($docs) { $h2('Attachments'); foreach ($docs as $f) $para('• ' . ($f['title'] ?: $f['original_name']) . ' (' . $f['original_name'] . ')', 8); }
    }
    $newPage();
    $h1('Signatures');
    $box = function (float $x, string $lab, string $txt) use ($pdf, $navy, $ink, $line, $L, $W, &$y) { $w = $W / 2 - 3; $pdf->setColor($line); $pdf->rect($x, $y, $w, 26); $pdf->setColor($navy); $pdf->setFont('B', 8); $pdf->text($x + 2, $y + 5, $lab); $pdf->setColor($ink); $pdf->setFont('R', 8.5); $pdf->text($x + 2, $y + 12, $txt); $pdf->setColor('#9AA5B1'); $pdf->setFont('R', 7); $pdf->text($x + 2, $y + 22, 'Signature:'); };
    $perf = ($r['performed_name'] ?: '') . ($r['submitted_at'] ? ' · ' . date('d.m.Y', strtotime($r['submitted_at'])) : '');
    $chk = $r['checked_name'] ? $r['checked_name'] . ' · ' . fmt_dt_doc($r['checked_at']) : '';
    $box($L, 'Performed by:', $perf); $box($L + $W / 2 + 3, 'Checked by:', $chk); $y += 30;
    $pdf->setColor($mute); $pdf->setFont('R', 7); $pdf->text($L, $y + 3, 'This report was produced electronically on the Golden A/S customer portal; issue, check and revision are logged with user, date and time.', 'C', $W);
    return $pdf->bytes('Inspection Report ' . $r['report_no']);
}
function isr_render_docx(array $r): string {
    require_once __DIR__ . '/docx.php';
    $d = new DocxWriter(); $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null); $logo = (string) @file_get_contents($bd['logo']); $NAVY = '0B1E2D'; $HEAD = 'EEF1F4';
    $d->grid([[['lines' => ['INSPECTION REPORT', ISR_FORM_CODE], 'b' => true, 'sz' => 14, 'color' => $NAVY], ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    $kv = fn($l) => ['lines' => [$l], 'b' => true, 'sz' => 8]; $vv = fn($v) => ['text' => (string)$v, 'sz' => 9];
    $rows = []; foreach (isr_cover_rows($r) as $row) $rows[] = [$kv($row[0]), $vv($row[1]), $kv($row[2]), $vv($row[3])];
    $d->grid($rows, [2100, 2720, 2100, 2720], ['border' => '9AA5B1']);
    $sec = function (string $t) use ($d, $NAVY) { $d->para($t, ['b' => true, 'align' => 'left', 'color' => $NAVY]); };
    $sec('Document subject and objective:'); $d->para((string)$r['objective'] ?: '—');
    $sec('Main findings and recommendations:'); $d->para((string)$r['findings'] ?: '—');
    $sec('Risks and hazards related to the current condition:'); $d->para((string)$r['risks'] ?: '—');
    $hdr = fn($t) => ['text' => $t, 'b' => true, 'sz' => 8, 'fill' => $HEAD]; $c = fn($t) => ['text' => (string)$t, 'sz' => 9];
    if ($r['items']) {
        $sec('Inspected systems — summary');
        $rows = [[$hdr(''), $hdr('#'), $hdr('System'), $hdr('Risk'), $hdr('Corrective action proposed')]];
        foreach ($r['items'] as $it) { $rt = ((fn($l) => ['none' => 'green', 'low' => 'green', 'medium' => 'amber', 'high' => 'red', 'critical' => 'red'][$l] ?? null))($it['risk_level']); $rows[] = [['text' => '', 'fill' => rc_hex($rt) ?: 'FFFFFF'], $c($it['seq']), $c($it['system_name']), array_merge($c(isr_risk_levels()[$it['risk_level']] ?? ''), $rt ? ['color' => rc_hex($rt), 'b' => true] : []), $c(trim((string)$it['actions']) !== '' ? 'Yes' : '—')]; }
        $d->grid($rows, [180, 600, 4500, 1600, 2760], ['border' => '9AA5B1']);
    }
    foreach ($r['items'] as $it) {
        $d->pageBreak();
        $d->heading($it['seq'] . '. ' . $it['system_name'], 1);
        $d->heading('Condition of equipment as found', 2); $d->para((string)$it['condition_found'] ?: '—');
        if ($it['checklist']) { $rows = [[$hdr('Question'), $hdr('Answer')]]; foreach ($it['checklist'] as $qa) $rows[] = [$c($qa['q']), $c($qa['a'])]; $d->grid($rows, [5300, 4340], ['border' => '9AA5B1']); }
        if (trim((string)$it['class_regs']) !== '') { $d->heading('Class regulations', 2); $d->para((string)$it['class_regs']); }
        $d->heading('Risk analysis — level: ' . (isr_risk_levels()[$it['risk_level']] ?? ''), 2); $d->para((string)$it['risk_analysis'] ?: '—');
        $d->heading('Proposed corrective actions', 2); $d->para((string)$it['actions'] ?: '—');
        $imgs = array_values(array_filter($it['files'], fn($f) => $f['is_image'])); $docs = array_values(array_filter($it['files'], fn($f) => !$f['is_image']));
        if ($imgs) { $cells = []; foreach ($imgs as $ph) { $b = cp_file_bytes((int)$ph['id']); $cells[] = $b ? ['img' => [$b, 5.2, 4.0], 'lines' => ['Figure ' . $ph['figure'] . ($ph['title'] ? ' — ' . $ph['title'] : '')], 'sz' => 7, 'b' => true, 'align' => 'center'] : ''; } while (count($cells) % 3) $cells[] = ''; $d->grid(array_chunk($cells, 3), [3213, 3213, 3214], ['border' => 'D9D9D9']); }
        if ($docs) { $d->heading('Attachments', 2); foreach ($docs as $f) $d->bullet(($f['title'] ?: $f['original_name']) . ' (' . $f['original_name'] . ')'); }
    }
    $d->pageBreak();
    $perf = ($r['performed_name'] ?: '') . ($r['submitted_at'] ? ' · ' . date('d.m.Y', strtotime($r['submitted_at'])) : '');
    $chk = $r['checked_name'] ? $r['checked_name'] . ' · ' . fmt_dt_doc($r['checked_at']) : '';
    $box = fn($lab, $txt) => ['lines' => [$lab, $txt, '', 'Signature:'], 'b' => true, 'sz' => 9, 'align' => 'left', 'color' => $NAVY, 'h' => 1400, 'valign' => 'top'];
    $d->grid([[$box('Performed by:', $perf), $box('Checked by:', $chk)]], [4820, 4820], ['border' => '9AA5B1']);
    $d->para('This report was produced electronically on the Golden A/S customer portal; issue, check and revision are logged with user, date and time.', ['i' => true, 'sz' => 7, 'align' => 'center']);
    return $d->bytes('Inspection Report ' . $r['report_no'], $bd['footer'] . ' · ' . ISR_FORM_CODE . ' · ' . $r['report_no']);
}
