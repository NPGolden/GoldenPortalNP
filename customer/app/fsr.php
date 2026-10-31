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

function fsr_specialties(): array {
    return ['Service Engineer', 'Automation Engineer', 'Electrical Engineer', 'Electrician', 'Navigation Engineer',
            'Commissioning Engineer', 'Project Manager', 'Technician', 'Supervisor'];
}
function fsr_time_types(): array { return ['WT' => 'WT', 'TT' => 'TT', 'NC' => 'NC']; }   // NC = not chargeable (R46-005)
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
    if (!$id && function_exists('tm_allowed_ids') && ($tmIds = tm_allowed_ids($pid)) !== null && (!$sid || !in_array($sid, $tmIds, true))) throw new RuntimeException(t('tm.not_allowed'));   // R25-001
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
            $parts[] = ['d' => mb_substr($pd, 0, 120), 't' => $pt];
        }
        $lines = [];
        for ($k = 0; $k < FSR_MAX_LINES; $k++) {
            $eng = trim((string)($_POST['wk_eng'][$i][$k] ?? ''));
            $st  = (string)($_POST['wk_start'][$i][$k] ?? ''); $en = (string)($_POST['wk_end'][$i][$k] ?? '');
            if ($eng === '' && $st === '' && $en === '') continue;
            $st = preg_match('/^\d{1,2}:\d{2}$/', $st) ? $st . ':00' : null;
            $en = preg_match('/^\d{1,2}:\d{2}$/', $en) ? $en . ':00' : null;
            $lines[] = ['engineer' => mb_substr($eng, 0, 120) ?: null, 'specialty' => mb_substr(trim((string)($_POST['wk_spec'][$i][$k] ?? '')), 0, 80) ?: 'Service Engineer',
                        'start' => $st, 'end' => $en, 'type' => in_array($_POST['wk_type'][$i][$k] ?? '', ['TT', 'WT', 'NC'], true) ? $_POST['wk_type'][$i][$k] : 'WT'];
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
            q('INSERT INTO fsr_lines (report_id, work_id, seq, specialty, engineer, start_time, end_time, time_type, hours, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$id, $wid, ++$lineSeq, $ln['specialty'], $ln['engineer'], $ln['start'], $ln['end'], $ln['type'], fsr_hours($ln['start'], $ln['end']), $ln['engineer']]);
        }
    }
    // stored photos follow their task: removed tasks lose theirs, the rest are renumbered
    foreach (all("SELECT id, fsr_task FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo'", [$id]) as $ph) {
        if (!isset($keep[(int)$ph['fsr_task']])) cp_delete_file((int)$ph['id']);
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

/** Texts for the two acceptance boxes: [golden, customer, customerSub]. */
function fsr_sign_texts(array $r): array {
    $g = $r['submitted_at'] ? sprintf('%s · %s', $r['submitted_name'] ?: '', fmt_dt_doc($r['submitted_at'])) : '';
    $c = ''; $sub = 'Pending acceptance / Εκκρεμεί αποδοχή';
    if ($r['status'] === 'accepted' && $r['accepted_at']) {
        $c = sprintf('%s · %s', $r['accepted_name'] ?: '', fmt_dt_doc($r['accepted_at']));
        if ($r['accepted_ip']) $c .= ' · IP ' . $r['accepted_ip'];
        $sub = 'Accepted electronically / Αποδοχή ηλεκτρονικά';
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
    [$gTxt, $cTxt, $cSub] = fsr_sign_texts($r);
    $half = $W / 2; $bx = 42.0;
    // R68-001: the drawn signatures (sp_files fsr_sign, stored by the engineer's app and by the customer's app / web page) are printed inside the boxes
    $gSig = !empty($r['golden_sign_id']) ? cp_file_bytes((int)$r['golden_sign_id']) : null;
    $cSig = !empty($r['customer_sign_id']) && $r['status'] === 'accepted' ? cp_file_bytes((int)$r['customer_sign_id']) : null;
    $sigShown = false;
    foreach ([[$L, 'SUBMITTED BY GOLDEN A/S', 'Submitted electronically / Υποβλήθηκε ηλεκτρονικά', $gTxt, $gSig],
              [$L + $half, 'CUSTOMER ACCEPTANCE', $cSub, $cTxt, $cSig]] as [$sx, $lab, $sub, $txt, $sig]) {
        $pdf->setColor($line); $pdf->setLineWidth(0.3); $pdf->rect($sx + 2, $y + 2, $half - 4, $bx);
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($sx, $y + 10, $lab, 'C', $half);
        $pdf->setColor($ink); $pdf->setFont('R', 7.5); $pdf->text($sx, $y + 18, $sub, 'C', $half);
        $drawn = $sig ? $pdf->image($sig, $sx + $half / 2 - 24, $y + 19.5, 48, 13) : false;     // 48 × 13 mm, centred above the name line
        if ($drawn) { $sigShown = $sigShown || $sx > $L; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($sx + $half / 2 - 26, $y + 33.5, $sx + $half / 2 + 26, $y + 33.5); $pdf->setColor($ink); }
        $pdf->setFont('B', 8.5); $pdf->text($sx, $y + ($drawn ? 38 : 26), $txt, 'C', $half);
        if ($sx > $L && $r['customer_remark'] && !$drawn) {
            $pdf->setFont('B', 7.5); $pdf->text($sx + 5, $y + 33, 'Customer notes / Σημειώσεις πελάτη:');
            $pdf->setFont('R', 7.5); $pdf->paragraph($sx + 5, $y + 34, $half - 10, (string)$r['customer_remark'], 3.6, 2);
        }
    }
    $y += $bx + 6;
    if ($r['customer_remark'] && (mb_strlen((string)$r['customer_remark']) > 120 || $sigShown)) {       // long notes (or notes next to a drawn signature) get their own block
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
            foreach ($wk['photos'] as $ph) { $bytes = cp_file_bytes((int)$ph['id']); $cells[] = $bytes ? ['img' => [$bytes, 5.2, 4.0], 'lines' => ['Figure ' . $ph['figure'] . ($ph['title'] ? ' — ' . $ph['title'] : '')], 'sz' => 7, 'b' => true, 'align' => 'center'] : ''; }
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

function fsr_ext_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW COLUMNS FROM fsr_reports LIKE 'kind'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** External report number: EXT-YYYYMMDD-INI-n. */
function fsr_ext_number(string $engineer, string $date): string {
    $ini = ''; foreach (preg_split('/\s+/', trim($engineer)) as $part) if ($part !== '') $ini .= mb_strtoupper(mb_substr($part, 0, 1));
    $ini = preg_replace('/[^A-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $ini)) ?: 'GS';
    $base = 'EXT-' . str_replace('-', '', substr($date, 0, 10)) . '-' . $ini . '-'; $n = 1;
    while (scalar('SELECT COUNT(*) FROM fsr_reports WHERE report_no = ?', [$base . $n])) $n++;
    return $base . $n;
}
/** The project an engineer reports under for a plain service call on a vessel: the vessel's general project (GEN-<id>). */
function fsr_project_for_vessel(int $vesselId): int { if (!function_exists('ob_general_project')) throw new RuntimeException(t('err.notfound')); return ob_general_project($vesselId); }
/** Attach an external report (a file written outside the portal). Returns the id. */
function fsr_ext_save_from_post(): int {
    require_staff(); if (!fsr_ext_has()) throw new RuntimeException(t('fsr.ext_need_db'));
    $u = current_user(); $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $vid = (int)($_POST['vessel_id'] ?? 0); $pid = (int)($_POST['project_id'] ?? 0);
    if ($pid) { $p = one('SELECT * FROM projects WHERE id = ?', [$pid]); if (!$p) throw new RuntimeException(t('err.notfound')); $vid = $vid ?: (int)$p['vessel_id']; }
    elseif ($vid) $pid = fsr_project_for_vessel($vid);
    if (!$pid) throw new RuntimeException(t('acc.need_scope'));
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
