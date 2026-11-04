<?php
/**
 * Function checks / commissioning forms filled on line.                            GAS-CP-R77-001
 *
 * A function check is an OEM commissioning sheet (first template: Emerson / AVENTICS Marex OS III) that the
 * service engineer fills on the portal instead of on paper: header data, the check items of each side
 * (tested / not existing / not tested), the verified adjustment values, the closing statement, remarks and
 * the two signatures. It belongs to a project (R72-008) and optionally a sub-project.
 * Workflow: draft → submitted (engineer signed; the customer may sign on the spot — then accepted at once —
 * or later on the portal) → accepted / disputed. Re-open returns a submitted sheet to draft (next revision).
 * Templates: app/fc_forms.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/fc_forms.php';

function fc_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'fc_reports'"); } catch (Throwable $e) { $h = false; } } return $h; }
function fc_status_pill(string $s): string { return pill(['draft' => 'mute', 'submitted' => 'gold', 'accepted' => 'green', 'disputed' => 'bad'][$s] ?? 'mute', t('fc.s_' . $s)); }
function fc_ip(): ?string { return trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]) ?: null; }
function fc_event(int $id, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO fc_events (report_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)', [$id, $ev, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), fc_ip(), $note]); }

function fc_number(string $form, string $date): string {
    $base = 'FC-' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $form)) . '-' . str_replace('-', '', substr($date, 0, 10));
    $n = (int) scalar('SELECT COUNT(*) FROM fc_reports WHERE report_no LIKE ?', [$base . '%']) + 1;
    $no = $base . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    while (scalar('SELECT COUNT(*) FROM fc_reports WHERE report_no = ?', [$no])) $no = $base . '-' . str_pad((string)++$n, 2, '0', STR_PAD_LEFT);
    return $no;
}
function fc_load(int $id): ?array {
    if (!fc_has() || !$id) return null;
    [$scope, $args] = scope_sql('p'); array_unshift($args, $id);
    $r = one("SELECT r.*, p.name AS project_name, p.project_code, p.customer_id, p.vessel_id, c.name AS customer_db_name, v.name AS vessel_name, v.imo, v.yard AS vessel_yard, s.code AS sub_code, s.name AS sub_name
              FROM fc_reports r JOIN projects p ON p.id = r.project_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN subprojects s ON s.id = r.subproject_id
              WHERE r.id = ? AND {$scope}", $args);
    if (!$r) return null;
    if (!is_staff() && $r['status'] === 'draft') return null;
    $r['data'] = $r['data'] ? (json_decode((string)$r['data'], true) ?: []) : [];
    $r['tpl'] = fc_form((string)$r['form']);
    $r['events'] = all('SELECT * FROM fc_events WHERE report_id = ? ORDER BY id', [$id]);
    return $r;
}
function fc_list(?int $projectId = null, ?int $subId = null, ?int $vesselId = null): array {
    if (!fc_has()) return [];
    [$scope, $args] = scope_sql('p'); $w = [$scope];
    if ($projectId) { $w[] = 'r.project_id = ?'; $args[] = $projectId; } if ($subId) { $w[] = 'r.subproject_id = ?'; $args[] = $subId; } if ($vesselId) { $w[] = 'p.vessel_id = ?'; $args[] = $vesselId; }
    if (!is_staff()) $w[] = "r.status <> 'draft'";
    return all("SELECT r.id, r.form, r.report_no, r.report_date, r.status, r.version, r.engineer_name, r.customer_name, r.pdf_file_id, r.project_id, r.subproject_id, p.project_code, p.name AS project_name, v.name AS vessel, s.code AS sub_code, s.name AS sub_name
                FROM fc_reports r JOIN projects p ON p.id = r.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN subprojects s ON s.id = r.subproject_id WHERE " . implode(' AND ', $w) . ' ORDER BY r.report_date DESC, r.id DESC', $args);
}
/** Default header values from the project / vessel / engineer. */
function fc_prefill(array $tpl, array $p, array $u): array {
    $h = [];
    foreach ($tpl['head'] as [$k, $lab, $kind]) $h[$k] = match ($kind) { 'vessel' => (string)($p['vessel'] ?? ''), 'imo' => (string)($p['imo'] ?? ''), 'tech' => (string)($u['full_name'] ?? ''), 'project' => (string)($p['project_code'] ?? ''), 'date' => date('Y-m-d'), default => '' };
    if (isset($h['yard']) && $h['yard'] === '') $h['yard'] = (string)($p['vessel_yard'] ?? '');
    return $h;
}
/** Read the sheet from the POST into the JSON structure (whitelisted keys only). */
function fc_data_from_post(array $tpl): array {
    $D = ['head' => [], 'chk' => [], 'free' => [], 'val' => [], 'extra' => [], 'closing' => !empty($_POST['closing']) ? 1 : 0];
    $hp = (array)($_POST['head'] ?? []); foreach ($tpl['head'] as [$k, $lab, $kind]) $D['head'][$k] = mb_substr(trim((string)($hp[$k] ?? '')), 0, 120);
    $cp = (array)($_POST['chk'] ?? []); $fp = (array)($_POST['free'] ?? []); $vp = (array)($_POST['val'] ?? []);
    foreach (array_keys($tpl['sides']) as $s) {
        foreach ($tpl['groups'] as $g) foreach ($g['rows'] as $i => $row) foreach ($row as $j => $lab) {
            $k = $g['key'] . '.' . $i . '.' . $j; $v = (string)(($cp[$s] ?? [])[$k] ?? ''); $D['chk'][$s][$k] = in_array($v, ['ok', 'na'], true) ? $v : '';
            if ($lab === null) $D['free'][$s][$k] = mb_substr(trim((string)(($fp[$s] ?? [])[$k] ?? '')), 0, 40);
        }
        foreach ($tpl['values'] as [$k, $lab, $u1, $u2]) { $x = (array)(($vp[$s] ?? [])[$k] ?? []); $D['val'][$s][$k] = ['v1' => mb_substr(trim((string)($x['v1'] ?? '')), 0, 20), 'v2' => mb_substr(trim((string)($x['v2'] ?? '')), 0, 20)]; if ($lab === null) $D['val'][$s][$k]['label'] = mb_substr(trim((string)($x['label'] ?? '')), 0, 40); }
    }
    $ep = (array)($_POST['extra'] ?? []); foreach ($tpl['extra'] as [$k, $lab, $u]) $D['extra'][$k] = mb_substr(trim((string)($ep[$k] ?? '')), 0, 20);
    return $D;
}
function fc_save_from_post(?int $id): int {
    $u = require_staff(); if (!fc_has()) throw new RuntimeException(t('fc.need_db'));
    $form = (string)($_POST['form'] ?? 'marex3'); $tpl = fc_form($form); if (!$tpl) throw new RuntimeException(t('err.notfound'));
    $pid = (int)($_POST['project_id'] ?? 0); $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if (!$pid) throw new RuntimeException(t('doc.need_project'));
    [$ps, $pa] = scope_sql('p'); if (!one("SELECT id FROM projects p WHERE p.id = ? AND {$ps}", array_merge([$pid], $pa))) throw new RuntimeException(t('err.notfound'));
    if ($sid && !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['report_date'] ?? '')) ? (string)$_POST['report_date'] : date('Y-m-d');
    $D = fc_data_from_post($tpl); $remarks = mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 4000) ?: null; $cust = mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 120) ?: null;
    if ($id) {
        $r = fc_load($id); if (!$r || $r['status'] !== 'draft') throw new RuntimeException(t('fc.not_draft'));
        q('UPDATE fc_reports SET project_id = ?, subproject_id = ?, report_date = ?, data = ?, remarks = ?, customer_name = ?, engineer_name = ? WHERE id = ?', [$pid, $sid, $date, json_encode($D, JSON_UNESCAPED_UNICODE), $remarks, $cust, $D['head']['tech'] ?? $u['full_name'], $id]);
        fc_event($id, 'saved');
    } else {
        $no = fc_number($form, $date);
        q('INSERT INTO fc_reports (form, report_no, project_id, subproject_id, report_date, data, remarks, customer_name, created_by, engineer_name) VALUES (?,?,?,?,?,?,?,?,?,?)', [$form, $no, $pid, $sid, $date, json_encode($D, JSON_UNESCAPED_UNICODE), $remarks, $cust, (int)$u['id'], $D['head']['tech'] ?? $u['full_name']]);
        $id = (int) db()->lastInsertId(); fc_event($id, 'created', $no);
    }
    return $id;
}
function fc_store_signature(string $dataUrl, array $r, string $side): ?int {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
    $png = base64_decode($m[1], true); if ($png === false || strlen($png) < 200 || strlen($png) > 400000 || !@getimagesizefromstring($png)) return null;
    return cp_store_bytes($png, 'signature.png', 'image/png', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'fc_sign', 'external', null, null, ['sign' => $side, 'fc' => (int)$r['id']]);
}
/** Submit: the engineer signs; with the customer's signature drawn on the spot the sheet is accepted at once. */
function fc_submit(int $id): string {
    $u = require_staff(); $r = fc_load($id); if (!$r || $r['status'] !== 'draft') throw new RuntimeException(t('fc.not_draft'));
    $gs = isset($_POST['signature']) ? fc_store_signature((string)$_POST['signature'], $r, 'golden') : null;
    if (!$gs && !$r['golden_sign_id']) throw new RuntimeException(t('fc.sign_needed'));
    $cs = isset($_POST['customer_signature']) ? fc_store_signature((string)$_POST['customer_signature'], $r, 'customer') : null;
    $cust = mb_substr(trim((string)($_POST['customer_name'] ?? ($r['customer_name'] ?? ''))), 0, 120) ?: null;
    if ($cs && !$cust) throw new RuntimeException(t('fc.customer_name_needed'));
    $st = $cs ? 'accepted' : 'submitted';
    q("UPDATE fc_reports SET status = ?, submitted_by = ?, submitted_name = ?, submitted_at = NOW(), golden_sign_id = COALESCE(?, golden_sign_id), customer_name = ?, customer_sign_id = COALESCE(?, customer_sign_id), accepted_by = ?, accepted_name = ?, accepted_at = ?, accepted_ip = ? WHERE id = ?",
      [$st, (int)$u['id'], (string)$u['full_name'], $gs, $cust, $cs, $cs ? (int)$u['id'] : null, $cs ? $cust . ' (' . t('fc.signed_on_site') . ')' : null, $cs ? date('Y-m-d H:i:s') : null, $cs ? fc_ip() : null, $id]);
    fc_event($id, $st, $cs ? t('fc.signed_on_site') : null);
    fc_generate_documents($id);
    return $st;
}
function fc_reopen(int $id): void {
    require_staff(); $r = fc_load($id); if (!$r || !in_array($r['status'], ['submitted', 'disputed'], true)) throw new RuntimeException(t('fc.not_reopenable'));
    q("UPDATE fc_reports SET status = 'draft', version = version + 1, golden_sign_id = NULL, customer_sign_id = NULL, accepted_by = NULL, accepted_name = NULL, accepted_at = NULL, accepted_ip = NULL WHERE id = ?", [$id]);
    fc_event($id, 'reopened');
}
function fc_delete(int $id): void {
    require_staff(); $r = fc_load($id); if (!$r || $r['status'] !== 'draft') throw new RuntimeException(t('fc.not_draft'));
    cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null); q('DELETE FROM fc_events WHERE report_id = ?', [$id]); q('DELETE FROM fc_reports WHERE id = ?', [$id]);
}
/** The customer's decision on the portal (accepted with a drawn signature, or disputed with a remark). */
function fc_customer_decide(int $id, string $decision, string $remark, ?string $signatureDataUrl = null): void {
    $au = current_user(); if (!$au) throw new RuntimeException(t('err.noaccess'));
    $r = fc_load($id); if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('fc.not_pending'));
    $isCustomer = !is_staff() && (int)$au['customer_id'] === (int)$r['customer_id'];
    if (!$isCustomer && !is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    $remark = mb_substr(trim($remark), 0, 2000);
    if ($decision === 'disputed') { if ($remark === '') throw new RuntimeException(t('fsr.remark_needed')); q("UPDATE fc_reports SET status = 'disputed', customer_remark = ? WHERE id = ?", [$remark, $id]); fc_event($id, 'disputed', $remark); fc_generate_documents($id); return; }
    if ($decision !== 'accepted') throw new RuntimeException(t('err.notfound'));
    $cs = $signatureDataUrl ? fc_store_signature($signatureDataUrl, $r, 'customer') : null;
    $name = $au['full_name'] . (!$isCustomer ? ' (' . t('fsr.on_behalf') . ')' : '');
    q("UPDATE fc_reports SET status = 'accepted', accepted_by = ?, accepted_name = ?, accepted_at = NOW(), accepted_ip = ?, customer_remark = ?, customer_name = COALESCE(customer_name, ?), customer_sign_id = COALESCE(?, customer_sign_id) WHERE id = ?", [(int)$au['id'], $name, fc_ip(), $remark ?: null, (string)$au['full_name'], $cs, $id]);
    fc_event($id, 'accepted', $remark ?: null); fc_generate_documents($id);
}
function fc_generate_documents(int $id): void {
    $r = fc_load($id); if (!$r) return;
    cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null);
    $base = $r['report_no'] . '-R' . (int)$r['version'];
    $pdfId = cp_store_bytes(fc_render_pdf($r), $base . '.pdf', 'application/pdf', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'fc_doc', 'external', null, $base . '.pdf', ['fc' => $id]);
    q('UPDATE fc_reports SET pdf_file_id = ? WHERE id = ?', [$pdfId, $id]);
}
/** Progress: tested / not existing / open items over both sides. */
function fc_progress(array $r): array {
    $tpl = $r['tpl']; $n = ['ok' => 0, 'na' => 0, 'open' => 0];
    foreach (array_keys($tpl['sides']) as $s) foreach ($tpl['groups'] as $g) foreach ($g['rows'] as $i => $row) foreach ($row as $j => $lab) { if ($lab === null && trim((string)($r['data']['free'][$s][$g['key'] . '.' . $i . '.' . $j] ?? '')) === '') continue; $v = (string)($r['data']['chk'][$s][$g['key'] . '.' . $i . '.' . $j] ?? ''); $n[$v === '' ? 'open' : $v]++; }
    return $n;
}

/* ------------------------------------------------------------------ PDF — one page that mirrors the OEM sheet */
function fc_render_pdf(array $r): string {
    require_once __DIR__ . '/pdf.php';
    $tpl = $r['tpl']; $D = $r['data']; $pdf = new PdfWriter();
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $mute = '#5F6B76'; $green = '#2E7D32';
    $L = 12.0; $R = 198.0; $W = $R - $L; $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null);
    $pdf->addPage();
    $pdf->setColor($navy); $pdf->setFont('B', 14); $pdf->text($L, 16, $tpl['title']); $pdf->setColor($gold); $pdf->setFont('R', 8); $pdf->text($L, 21, $tpl['maker'] . ' · ' . $tpl['code'] . ' · ' . $r['report_no'] . ' · R' . (int)$r['version']);
    $pdf->image($bd['logo'], $R - 34, 8, 34, 12.6);
    $pdf->setColor($ink); $pdf->setFont('R', 7); $pdf->text($L, 291, $bd['footer'] . ' · ' . $tpl['code'] . ' · ' . $r['report_no'] . ' · R' . (int)$r['version'] . ' · ' . ($r['project_code'] ?: '') . ($r['sub_code'] ? ' / ' . $r['sub_code'] : ''));
    $y = 26.0; $half = $W / 2; $rh = 6.5;
    $pairs = array_chunk($tpl['head'], 2);
    foreach ($pairs as $pr) { $x = $L; foreach ($pr as [$k, $lab, $kind]) { $v = (string)($D['head'][$k] ?? ''); if ($kind === 'date' && $v !== '') $v = fmt_date($v); $pdf->kvCell($x, $y, $half, 36, $lab, $v, ['h' => $rh, 'labelSize' => 7.5, 'line' => $line, 'navy' => $navy, 'ink' => $ink]); $x += $half; } $y += $rh; }
    $y += 4; $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 3, $tpl['intro'], 'C', $W); $y += 7;
    // check grid
    $sides = array_keys($tpl['sides']); $colW = $half; $numW = 5.0; $cellW = ($colW - $numW) / 2; $rowH = 4.9;
    $pdf->setColor($head); $pdf->rect($L, $y, $W, 5.5, 'F'); $x = $L; foreach ($sides as $s) { $pdf->setColor($line); $pdf->rect($x, $y, $colW, 5.5); $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($x, $y + 3.9, $tpl['sides'][$s], 'C', $colW); $x += $colW; } $y += 5.5;
    $box = function (float $x, float $yy, string $state, string $label, float $w) use ($pdf, $ink, $line, $green, $mute) {
        $pdf->setColor($line); $pdf->rect($x + 1.2, $yy + 1.1, 3, 3);
        if ($state === 'ok') { $pdf->setColor($green); $pdf->setLineWidth(0.5); $pdf->line($x + 1.7, $yy + 2.7, $x + 2.6, $yy + 3.7); $pdf->line($x + 2.6, $yy + 3.7, $x + 4.0, $yy + 1.6); $pdf->setLineWidth(0.2); }
        $pdf->setColor($state === 'na' ? $mute : $ink); $pdf->setFont('R', 7.3); $pdf->text($x + 5.4, $yy + 3.6, mb_substr($label, 0, 30));
        if ($state === 'na') { $tw = min($pdf->width($label, 'R', 7.3), $w - 6.5); $pdf->setColor($mute); $pdf->setLineWidth(0.35); $pdf->line($x + 5.2, $yy + 2.6, $x + 5.6 + $tw, $yy + 2.6); $pdf->setLineWidth(0.2); }
    };
    foreach ($tpl['groups'] as $g) {
        $pdf->setColor($head); $pdf->rect($L, $y, $W, 4.6, 'F'); $pdf->setColor($line); $pdf->rect($L, $y, $W, 4.6); $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($L + 1.5, $y + 3.3, $g['title']); $y += 4.6;
        foreach ($g['rows'] as $i => $row) {
            $x = $L;
            foreach ($sides as $s) {
                $pdf->setColor($line); $pdf->rect($x, $y, $numW, $rowH); $pdf->setColor($mute); $pdf->setFont('R', 7); $pdf->text($x, $y + 3.6, (string)($i + 1), 'C', $numW);
                foreach ($row as $j => $lab) { $k = $g['key'] . '.' . $i . '.' . $j; $cx = $x + $numW + $j * $cellW; $pdf->setColor($line); $pdf->rect($cx, $y, $cellW, $rowH); $label = $lab ?? (string)($D['free'][$s][$k] ?? ''); if ($label !== '') $box($cx, $y, (string)($D['chk'][$s][$k] ?? ''), $label, $cellW); }
                $x += $colW;
            }
            $y += $rowH;
        }
    }
    // values
    $y += 1.5; $pdf->setColor($head); $pdf->rect($L, $y, $W, 4.6, 'F'); $x = $L; foreach ($sides as $s) { $pdf->setColor($line); $pdf->rect($x, $y, $colW, 4.6); $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($x, $y + 3.3, $tpl['values_title'], 'C', $colW); $x += $colW; } $y += 4.6;
    $vLab = 32.0; $vW = ($colW - $numW - $vLab) / 2;
    foreach ($tpl['values'] as [$k, $lab, $u1, $u2]) {
        $x = $L;
        foreach ($sides as $s) {
            $v = $D['val'][$s][$k] ?? []; $label = $lab ?? (string)($v['label'] ?? '');
            $pdf->setColor($line); $pdf->rect($x, $y, $numW, $rowH); $pdf->rect($x + $numW, $y, $vLab, $rowH); $pdf->rect($x + $numW + $vLab, $y, $vW, $rowH); $pdf->rect($x + $numW + $vLab + $vW, $y, $vW, $rowH);
            $pdf->setColor($mute); $pdf->setFont('B', 7); $pdf->text($x, $y + 3.6, $k, 'C', $numW); $pdf->setColor($ink); $pdf->setFont('R', 7.3); $pdf->text($x + $numW + 1.2, $y + 3.6, mb_substr($label, 0, 26));
            foreach ([[(string)($v['v1'] ?? ''), $u1, $x + $numW + $vLab], [(string)($v['v2'] ?? ''), $u2, $x + $numW + $vLab + $vW]] as [$val, $unit, $vx]) { if ($val !== '') { $pdf->setColor($ink); $pdf->setFont('B', 7.5); $pdf->text($vx + 1, $y + 3.6, $val); } $pdf->setColor($mute); $pdf->setFont('R', 6.5); $pdf->text($vx, $y + 3.6, $unit, 'R', $vW - 1); }
            $x += $colW;
        }
        $y += $rowH;
    }
    // extra line
    $ex = []; foreach ($tpl['extra'] as [$k, $lab, $u]) $ex[] = $lab . ': ' . ((string)($D['extra'][$k] ?? '') !== '' ? $D['extra'][$k] : '____') . ($u ? ' ' . $u : '');
    $pdf->setColor($line); $pdf->rect($L, $y, $W, $rowH); $pdf->setColor($ink); $pdf->setFont('R', 7.5); $pdf->text($L, $y + 3.6, implode('     ', $ex), 'C', $W); $y += $rowH + 3;
    // closing
    $pdf->setColor($line); $pdf->rect($L + 1, $y + 0.6, 3.4, 3.4); if (!empty($D['closing'])) { $pdf->setColor($green); $pdf->setLineWidth(0.55); $pdf->line($L + 1.6, $y + 2.3, $L + 2.6, $y + 3.4); $pdf->line($L + 2.6, $y + 3.4, $L + 4.1, $y + 1.2); $pdf->setLineWidth(0.2); }
    $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L + 6.5, $y + 3.4, $tpl['closing']); $y += 6;
    $limit = 256.0;   // the signature boxes start here at the latest
    $blocks = array_values(array_filter([['Remarks', (string)$r['remarks'], 4], ['Customer remark', (string)$r['customer_remark'], 3]], fn($b) => trim($b[1]) !== ''));
    foreach ($blocks as $bi => [$ttl, $txt, $mx]) {
        $left = count($blocks) - $bi; $avail = (int) floor((($limit - $y) / $left - 5) / 3.6); if ($avail < 1) break;   // share the room between the blocks
        $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($L, $y + 3, $ttl); $y += 4; $pdf->setColor($ink); $pdf->setFont('R', 7.5); $y += $pdf->paragraph($L, $y, $W, $txt, 3.6, min($mx, $avail)) + 1;
    }
    // signatures
    $y = min(max($y + 2, 236.0), 258.0); $bw = $half - 4; $bh = 26.0;   // never below the footer
    $sig = function (float $x, string $title, string $who, string $when, ?int $fileId) use ($pdf, $navy, $ink, $line, $mute, $y, $bw, $bh) {
        $pdf->setColor($line); $pdf->rect($x, $y, $bw, $bh); $pdf->setColor($navy); $pdf->setFont('B', 8); $pdf->text($x + 2, $y + 4.5, $title);
        if ($fileId && ($b = cp_file_bytes($fileId))) $pdf->image($b, $x + 2, $y + 6, 48, 14.4);
        $pdf->setColor($ink); $pdf->setFont('R', 7.5); $pdf->text($x + 2, $y + $bh - 6.5, $who); $pdf->setColor($mute); $pdf->setFont('R', 7); $pdf->text($x + 2, $y + $bh - 2.5, $when);
    };
    $sig($L, 'Signature customer', (string)($r['accepted_name'] ?: ($r['customer_name'] ?: '')), $r['accepted_at'] ? fmt_dt_doc($r['accepted_at']) . ($r['accepted_ip'] ? ' · ' . $r['accepted_ip'] : '') : ($r['status'] === 'disputed' ? 'Disputed — see remark' : 'Not yet signed'), $r['customer_sign_id'] ? (int)$r['customer_sign_id'] : null);
    $sig($L + $half + 4, 'Signature service technician', (string)($r['submitted_name'] ?: $r['engineer_name'] ?: ''), $r['submitted_at'] ? fmt_dt_doc($r['submitted_at']) : '', $r['golden_sign_id'] ? (int)$r['golden_sign_id'] : null);
    $pdf->setColor($mute); $pdf->setFont('R', 6.5); $pdf->text($L, $y + $bh + 3.5, 'Filled and signed electronically on the Golden A/S customer portal; every step is logged with user, date, time and address.', 'C', $W);
    return $pdf->bytes($tpl['title'] . ' ' . $r['report_no']);
}
