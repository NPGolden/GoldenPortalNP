<?php
/**
 * HR · Employee card (GAS-CP-R56-001): employment periods (hire → termination → re-hire on the same card), contracts with or
 * without an end date, total service = company periods + prior service, documents attached to the card and to each event.
 * Needs migrate_cp_r56_employee_card.sql. Builds on app/hr.php + app/hr2.php.
 */
declare(strict_types=1);

const HR_CONTRACTS = ['indefinite', 'fixed', 'rotation', 'seasonal', 'probation', 'apprentice', 'internship', 'project', 'other'];
const HR_MARITAL = ['single', 'married', 'divorced', 'widowed', 'partnership'];
const HR_END_REASONS = ['resignation', 'dismissal', 'contract_end', 'retirement', 'mutual', 'other'];
const HR_DOC_KINDS = ['contract', 'hire', 'termination', 'ergani', 'id', 'certificate', 'training', 'evaluation', 'other'];
const HR_DOC_MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/msword' => 'doc', 'application/zip' => 'docx'];

function hr3_has(): bool { static $h = null; if ($h === null) { try { $h = hr2_has() && (bool) scalar("SHOW TABLES LIKE 'hr_employments'") && (bool) scalar("SHOW COLUMNS FROM hr_people LIKE 'left_date'"); } catch (Throwable $e) { $h = false; } } return $h; }

/* ------------------------------------------------------------ employment periods */
function hr_employments(int $pid): array { return hr3_has() ? all('SELECT e.*, cu.full_name AS by_name FROM hr_employments e LEFT JOIN customer_users cu ON cu.id = e.created_by WHERE e.person_id = ? ORDER BY e.date_from, e.id', [$pid]) : []; }
/** The open period (hired, not terminated) or null. */
function hr_employment_open(int $pid): ?array { return hr3_has() ? one("SELECT * FROM hr_employments WHERE person_id = ? AND kind IN ('hire','rehire') AND date_to IS NULL ORDER BY date_from DESC LIMIT 1", [$pid]) : null; }
/** Months of service at Golden A/S from all periods (closed ones to their end, the open one to $at). */
function hr_company_months(int $pid, ?string $at = null): int {
    $m = 0; $end = new DateTime($at ?: date('Y-m-d'));
    foreach (hr_employments($pid) as $e) { if (!in_array($e['kind'], ['hire', 'rehire'], true)) continue; $a = new DateTime($e['date_from']); $b = $e['date_to'] ? new DateTime($e['date_to']) : $end; if ($b > $a) { $d = $a->diff($b); $m += $d->y * 12 + $d->m; } }
    return $m;
}
/** Service breakdown in months: company (periods, or the hiring date when R56 is not installed), prior, total. */
function hr_service(array $p, ?string $at = null): array {
    $company = hr3_has() ? hr_company_months((int)$p['id'], $at) : (hr_service_months($p, $at) - (int)($p['prior_months'] ?? 0));
    $prior = (int)($p['prior_months'] ?? 0); return ['company' => $company, 'prior' => $prior, 'total' => $company + $prior];
}
function hr_fmt_months(int $m): string { $y = intdiv($m, 12); $r = $m % 12; return ($y ? $y . ' ' . t('hr.y') . ' ' : '') . $r . ' ' . t('hr.m'); }

/** Hire / re-hire / change / terminate — POST ev=hire|rehire|change|end, date, contract_type, part_time, contract_end, job_title, reason, note, file doc[] (multiple). */
function hr_employment_event_from_post(int $pid): int {
    if (!hr_can() || !hr3_has()) throw new RuntimeException(t('err.noaccess'));
    $p = hr_person($pid); if (!$p) throw new RuntimeException(t('err.notfound'));
    $ev = (string)($_POST['ev'] ?? ''); $date = (string)($_POST['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException(t('pl.bad_dates'));
    $ct = in_array($_POST['contract_type'] ?? '', HR_CONTRACTS, true) ? $_POST['contract_type'] : 'indefinite'; $pt = (int)!empty($_POST['part_time']);
    $ce = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['contract_end'] ?? '')) ? $_POST['contract_end'] : null; if ($ct !== 'fixed' && $ct !== 'seasonal' && $ct !== 'probation' && $ct !== 'project' && $ct !== 'internship' && $ct !== 'apprentice') $ce = null;
    $jt = mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 120) ?: null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null; $me = (int)(current_user()['id'] ?? 0);
    $open = hr_employment_open($pid);
    db()->beginTransaction();
    try {
        if ($ev === 'hire' || $ev === 'rehire') {
            if ($open) throw new RuntimeException(t('hr.emp_already_open'));
            $kind = scalar('SELECT COUNT(*) FROM hr_employments WHERE person_id = ?', [$pid]) ? 'rehire' : 'hire';
            q('INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, contract_end, job_title, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, $kind, $date, $ct, $pt, $ce, $jt, $note, $me]); $id = (int) db()->lastInsertId();
            q('UPDATE hr_people SET hire_date = ?, contract_type = ?, part_time = ?, contract_end = ?, job_title = COALESCE(?, job_title), left_date = NULL, is_active = 1 WHERE id = ?', [$date, $ct, $pt, $ce, $jt, $pid]);
        } elseif ($ev === 'change') {
            if (!$open) throw new RuntimeException(t('hr.emp_not_open'));
            q('INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, contract_end, job_title, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, 'change', $date, $ct, $pt, $ce, $jt, $note, $me]); $id = (int) db()->lastInsertId();
            q('UPDATE hr_employments SET contract_type = ?, part_time = ?, contract_end = ?, job_title = COALESCE(?, job_title) WHERE id = ?', [$ct, $pt, $ce, $jt, (int)$open['id']]);
            q('UPDATE hr_people SET contract_type = ?, part_time = ?, contract_end = ?, job_title = COALESCE(?, job_title) WHERE id = ?', [$ct, $pt, $ce, $jt, $pid]);
        } elseif ($ev === 'end') {
            if (!$open) throw new RuntimeException(t('hr.emp_not_open')); if ($date < $open['date_from']) throw new RuntimeException(t('pl.bad_dates'));
            $reason = in_array($_POST['reason'] ?? '', HR_END_REASONS, true) ? $_POST['reason'] : 'other';
            q('INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, reason, note, created_by) VALUES (?,?,?,?,?,?,?,?)', [$pid, 'end', $date, $open['contract_type'], (int)$open['part_time'], $reason, $note, $me]); $id = (int) db()->lastInsertId();
            q('UPDATE hr_employments SET date_to = ?, reason = ? WHERE id = ?', [$date, $reason, (int)$open['id']]);
            q('UPDATE hr_people SET left_date = ?, is_active = 0, contract_end = NULL WHERE id = ?', [$date, $pid]);
            if (!empty($p['user_id'])) q('UPDATE customer_users SET is_active = 0 WHERE id = ? AND role = ?', [(int)$p['user_id'], 'staff']);   // the portal account closes with the employment (administrators are never touched)
            hr_off_open($pid, $id);                                                                                      // R60-001: release-of-contract procedure
        } else throw new RuntimeException(t('err.notfound'));
        hr_docs_store_from_files($pid, $id, (string)($_POST['doc_kind'] ?? ($ev === 'end' ? 'termination' : 'contract')), $note);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $id;
}
/** Delete a mistaken event (last one only, HR). */
function hr_employment_delete(int $id): void {
    if (!hr_can() || !hr3_has()) throw new RuntimeException(t('err.noaccess')); $e = one('SELECT * FROM hr_employments WHERE id = ?', [$id]); if (!$e) throw new RuntimeException(t('err.notfound'));
    $last = one('SELECT id FROM hr_employments WHERE person_id = ? ORDER BY date_from DESC, id DESC LIMIT 1', [(int)$e['person_id']]); if ((int)$last['id'] !== $id) throw new RuntimeException(t('hr.emp_not_last'));
    if (scalar('SELECT COUNT(*) FROM hr_docs WHERE employment_id = ?', [$id])) throw new RuntimeException(t('hr.emp_has_docs'));
    if ($e['kind'] === 'end') { q("UPDATE hr_employments SET date_to = NULL, reason = NULL WHERE person_id = ? AND kind IN ('hire','rehire') AND date_to = ?", [(int)$e['person_id'], $e['date_from']]); q('UPDATE hr_people SET left_date = NULL, is_active = 1 WHERE id = ?', [(int)$e['person_id']]); }
    q('DELETE FROM hr_employments WHERE id = ?', [$id]);
    if ($e['kind'] === 'hire' || $e['kind'] === 'rehire') { $prev = one("SELECT * FROM hr_employments WHERE person_id = ? AND kind IN ('hire','rehire') ORDER BY date_from DESC LIMIT 1", [(int)$e['person_id']]); q('UPDATE hr_people SET hire_date = ?, is_active = ?, left_date = ? WHERE id = ?', [$prev['date_from'] ?? null, $prev && !$prev['date_to'] ? 1 : 0, $prev['date_to'] ?? null, (int)$e['person_id']]); }
}

/* ------------------------------------------------------------ documents */
function hr_docs(int $pid): array { return hr3_has() ? all('SELECT d.*, cu.full_name AS by_name FROM hr_docs d LEFT JOIN customer_users cu ON cu.id = d.uploaded_by WHERE d.person_id = ? ORDER BY d.created_at DESC, d.id DESC', [$pid]) : []; }
/** Store every file of $_FILES['doc'] (multiple) for the person / event. */
function hr_docs_store_from_files(int $pid, ?int $empId, string $kind, ?string $note = null): int {
    $f = $_FILES['doc'] ?? null; if (!$f) return 0; $kind = in_array($kind, HR_DOC_KINDS, true) ? $kind : 'other'; $me = (int)(current_user()['id'] ?? 0); $n = 0;
    $names = (array)$f['name']; $tmps = (array)$f['tmp_name']; $errs = (array)$f['error']; $sizes = (array)$f['size'];
    foreach ($names as $i => $name) {
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
        if ((int)$sizes[$i] > 15 * 1024 * 1024) throw new RuntimeException(t('hr.doc_big'));
        $mime = (string)(mime_content_type($tmps[$i]) ?: ''); $ext = HR_DOC_MIME[$mime] ?? null; $origExt = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if ($ext === 'docx' && !in_array($origExt, ['docx', 'doc'], true)) $ext = null;   // zip-based: accept only when the original was a Word file
        if (!$ext) throw new RuntimeException(t('hr.doc_type2'));
        $dir = dirname(__DIR__) . '/storage/hr/people/' . $pid; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $fn = bin2hex(random_bytes(12)) . '.' . $ext; if (!move_uploaded_file($tmps[$i], $dir . '/' . $fn)) throw new RuntimeException(t('hr.doc_fail'));
        q('INSERT INTO hr_docs (person_id, employment_id, kind, name, path, sha, size, note, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, $empId, $kind, mb_substr((string)$name, 0, 160), 'people/' . $pid . '/' . $fn, hash_file('sha256', $dir . '/' . $fn), (int)$sizes[$i], $note ? mb_substr($note, 0, 200) : null, $me]); $n++;
    }
    return $n;
}
function hr_doc_upload_from_post(int $pid): int { if (!hr_can() || !hr3_has()) throw new RuntimeException(t('err.noaccess')); if (!hr_person($pid)) throw new RuntimeException(t('err.notfound')); $emp = (int)($_POST['employment_id'] ?? 0) ?: null; return hr_docs_store_from_files($pid, $emp, (string)($_POST['doc_kind'] ?? 'other'), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200) ?: null); }
function hr_doc_delete(int $id): void { if (!hr_can() || !hr3_has()) throw new RuntimeException(t('err.noaccess')); $d = one('SELECT * FROM hr_docs WHERE id = ?', [$id]); if (!$d) throw new RuntimeException(t('err.notfound')); $f = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (is_file($f)) @unlink($f); q('DELETE FROM hr_docs WHERE id = ?', [$id]); }
/** ?p=hr_pdoc&id= — stream a card document to HR / admins, or to the person himself. */
function hr_pdoc_stream(int $id): void {
    $d = hr3_has() ? one('SELECT d.*, p.user_id FROM hr_docs d JOIN hr_people p ON p.id = d.person_id WHERE d.id = ?', [$id]) : null; $u = current_user(); if (!$d || !$u) { http_response_code(404); exit; }
    if (!(hr_can() || (int)$d['user_id'] === (int)$u['id'])) { http_response_code(403); exit; }
    $f = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (!is_file($f)) { http_response_code(404); exit; }
    $ct = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    $inline = in_array($ct, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
    header('Content-Type: ' . $ct); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Content-Length: ' . filesize($f)); header('Cache-Control: private, max-age=0');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$d['name']) . '"'); readfile($f); exit;
}

/* ------------------------------------------------------------ warnings for the People tab */
/** Fixed-term contracts ending within $days days and already past. */
function hr_contract_alerts(int $days = 60): array { if (!hr3_has()) return []; return all('SELECT id, name_en, contract_end, DATEDIFF(contract_end, CURDATE()) AS left_days FROM hr_people WHERE is_active = 1 AND contract_end IS NOT NULL AND contract_end <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY contract_end', [$days]); }
/** Map a register contract label (Greek or code) to the portal code + part-time flag. */
function hr_contract_from_label(string $v): array {
    $l = mb_strtolower(trim($v)); $pt = (int)(str_contains($l, 'μερική') || str_contains($l, 'part'));
    foreach (['αορίστου' => 'indefinite', 'indefinite' => 'indefinite', 'ορισμένου' => 'fixed', 'fixed' => 'fixed', 'περιτροπ' => 'rotation', 'rotation' => 'rotation', 'εποχ' => 'seasonal', 'seasonal' => 'seasonal', 'δοκιμ' => 'probation', 'probation' => 'probation', 'μαθητ' => 'apprentice', 'apprentice' => 'apprentice', 'πρακτ' => 'internship', 'internship' => 'internship', 'έργου' => 'project', 'project' => 'project'] as $k => $c) if ($l !== '' && str_contains($l, $k)) return [$c, $pt];
    return [$l === '' ? 'indefinite' : 'other', $pt];
}
function hr_marital_from_label(string $v): ?string { $l = mb_strtolower(trim($v)); foreach (['άγαμ' => 'single', 'single' => 'single', 'έγγαμ' => 'married', 'married' => 'married', 'διαζ' => 'divorced', 'divorced' => 'divorced', 'χήρ' => 'widowed', 'widow' => 'widowed', 'συμβίωσ' => 'partnership', 'partner' => 'partnership'] as $k => $c) if ($l !== '' && str_contains($l, $k)) return $c; return null; }

/* ------------------------------------------------------------ HR ↔ employee messages (GAS-CP-R56-002) */
function hr_msg_has(): bool { static $h = null; if ($h === null) { try { $h = hr3_has() && (bool) scalar("SHOW TABLES LIKE 'hr_messages'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_messages(int $pid): array { return hr_msg_has() ? all('SELECT m.*, cu.full_name AS from_name FROM hr_messages m LEFT JOIN customer_users cu ON cu.id = m.from_user_id WHERE m.person_id = ? ORDER BY m.created_at, m.id', [$pid]) : []; }
/** Unread messages for the current side: HR counts employee messages, the employee counts HR messages. */
function hr_messages_unread(?int $pid = null): int {
    if (!hr_msg_has()) return 0; $u = current_user(); if (!$u) return 0;
    if (hr_can()) return (int) scalar('SELECT COUNT(*) FROM hr_messages WHERE from_hr = 0 AND seen_at IS NULL' . ($pid ? ' AND person_id = ' . (int)$pid : ''));
    $p = hr_person_of_user((int)$u['id']); return $p ? (int) scalar('SELECT COUNT(*) FROM hr_messages WHERE person_id = ? AND from_hr = 1 AND seen_at IS NULL', [(int)$p['id']]) : 0;
}
function hr_messages_mark_seen(int $pid): void { if (!hr_msg_has()) return; if (hr_can()) q('UPDATE hr_messages SET seen_at = NOW() WHERE person_id = ? AND from_hr = 0 AND seen_at IS NULL', [$pid]); else q('UPDATE hr_messages SET seen_at = NOW() WHERE person_id = ? AND from_hr = 1 AND seen_at IS NULL', [$pid]); }
/** POST person_id (HR) or none (employee), body. Visible only to HR / admins and the person. */
function hr_message_send_from_post(): int {
    if (!hr_msg_has()) throw new RuntimeException(t('err.noaccess')); $u = current_user(); if (!$u || !is_staff()) throw new RuntimeException(t('err.noaccess'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 2000); if ($body === '') throw new RuntimeException(t('hr.msg_need'));
    if (hr_can()) { $pid = (int)($_POST['person_id'] ?? 0); $p = hr_person($pid); if (!$p) throw new RuntimeException(t('err.notfound')); $fromHr = 1; }
    else { $p = hr_person_of_user((int)$u['id']); if (!$p) throw new RuntimeException(t('hr.no_person')); $pid = (int)$p['id']; $fromHr = 0; }
    q('INSERT INTO hr_messages (person_id, from_user_id, from_hr, body) VALUES (?,?,?,?)', [$pid, (int)$u['id'], $fromHr, $body]); $id = (int) db()->lastInsertId();
    if (function_exists('nf_push')) {
        $to = $fromHr ? array_filter([(int)($p['user_id'] ?? 0)]) : hr_hr_user_ids(); $to = array_values(array_unique(array_filter(array_map('intval', $to), fn($x) => $x !== (int)$u['id'])));
        if ($to) nf_push($to, 'hrm:new', 'hrm:' . $pid, t('hr.nf_msg') . ' — ' . ($fromHr ? 'HR' : $p['name_en']), mb_substr($body, 0, 140), $fromHr ? url('my_leave') . '#hr-msg' : url('hr_person', ['id' => $pid]) . '#hr-msg', (int)$u['id'], $u['full_name'] ?? null);
    }
    return $id;
}
/** The message thread card (same markup for the employee card and My leave). */
function hr_messages_card(int $pid, bool $hrSide): string {
    $msgs = hr_messages($pid); hr_messages_mark_seen($pid); $h = '<div class="card" id="hr-msg"><h2>💬 ' . tt('hr.msg_title') . '</h2><p class="hint">' . tt($hrSide ? 'hr.msg_hint_hr' : 'hr.msg_hint_emp') . '</p>';
    if (!$msgs) $h .= '<p class="hint">' . tt('hr.msg_none') . '</p>'; else { $h .= '<div class="hr-thread">'; foreach ($msgs as $m) $h .= '<div class="hr-m' . ($m['from_hr'] ? ' is-hr' : '') . '"><div class="hr-m-head"><b>' . e($m['from_name'] ?? ($m['from_hr'] ? 'HR' : '')) . '</b>' . ($m['from_hr'] ? ' <span class="hint">HR</span>' : '') . ' <span class="hint">' . e(fmt_datetime($m['created_at'])) . ($m['seen_at'] ? ' · ✔ ' . tt('hr.msg_seen') : '') . '</span></div><div class="hr-m-body">' . nl2br(e($m['body'])) . '</div></div>'; $h .= '</div>'; }
    $h .= '<form method="post" action="' . ($hrSide ? url('hr_person', ['id' => $pid]) : url('my_leave')) . '#hr-msg" data-lock>' . csrf_field() . '<input type="hidden" name="action" value="hr_msg_send"><input type="hidden" name="person_id" value="' . $pid . '"><div class="field"><textarea name="body" rows="3" maxlength="2000" required data-plain placeholder="' . tt('hr.msg_ph') . '"></textarea></div><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">' . tt('hr.msg_send') . '</button></div></form></div>';
    return $h;
}

/* ------------------------------------------------------------ release of a contract — off-boarding procedure (GAS-CP-R60-001) */
/** Checklist items per jurisdiction; the key is the language key suffix hr.off_<key>. */
const HR_OFFBOARD_GR = ['notice', 'ergani', 'settlement', 'leave_payout', 'certificate', 'property', 'accounts', 'handover', 'projects', 'exit_interview', 'file'];
const HR_OFFBOARD_IT = ['notice', 'unilav', 'tfr', 'leave_payout', 'cu', 'property', 'accounts', 'handover', 'projects', 'exit_interview', 'file'];
function hr_off_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_offboarding'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_off_items(array $p): array { return (function_exists('hr_jurisdiction') ? hr_jurisdiction($p) : 'GR') === 'IT' ? HR_OFFBOARD_IT : HR_OFFBOARD_GR; }
/** Open the procedure for an "end" event: one row per item of the person's jurisdiction. */
function hr_off_open(int $pid, int $empId): void {
    if (!hr_off_has()) return; $p = hr_person($pid); if (!$p) return;
    foreach (hr_off_items($p) as $i => $k) q('INSERT IGNORE INTO hr_offboarding (person_id, employment_id, item_key, sort_order) VALUES (?,?,?,?)', [$pid, $empId, $k, $i]);
}
function hr_off_list(int $empId): array { return hr_off_has() ? all('SELECT o.*, cu.full_name AS by_name FROM hr_offboarding o LEFT JOIN customer_users cu ON cu.id = o.done_by WHERE o.employment_id = ? ORDER BY o.sort_order', [$empId]) : []; }
function hr_off_progress(int $empId): array { $rows = hr_off_list($empId); $d = count(array_filter($rows, fn($r) => $r['done'])); return ['done' => $d, 'total' => count($rows), 'complete' => $rows && $d === count($rows)]; }
/** HR ticks / unticks items; a note per item; when the last item is done the release is logged on the card. */
function hr_off_save_from_post(int $pid): void {
    if (!hr_can() || !hr_off_has()) throw new RuntimeException(t('err.noaccess')); $empId = (int)($_POST['employment_id'] ?? 0); $me = (int)(current_user()['id'] ?? 0);
    if (!scalar('SELECT COUNT(*) FROM hr_employments WHERE id = ? AND person_id = ? AND kind = ?', [$empId, $pid, 'end'])) throw new RuntimeException(t('err.notfound'));
    $before = hr_off_progress($empId);
    foreach (hr_off_list($empId) as $r) {
        $done = !empty($_POST['done'][$r['item_key']]); $note = mb_substr(trim((string)($_POST['onote'][$r['item_key']] ?? '')), 0, 300) ?: null;
        if ($done !== (bool)$r['done']) { q('UPDATE hr_offboarding SET done = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?', [(int)$done, $done ? $me : null, $done ? date('Y-m-d H:i:s') : null, $note, (int)$r['id']]); if (function_exists('hr_audit')) hr_audit($pid, 'employment', t('hr.off_title') . ' · ' . t('hr.off_' . $r['item_key']), $r['done'] ? '✔' : '—', $done ? '✔' : '—', $note); }
        elseif ($note !== $r['note']) q('UPDATE hr_offboarding SET note = ? WHERE id = ?', [$note, (int)$r['id']]);
    }
    $after = hr_off_progress($empId);
    if ($after['complete'] && !$before['complete'] && function_exists('hr_audit')) hr_audit($pid, 'employment', t('hr.off_title'), null, t('hr.off_released'), null);
}
/** Open procedures across the company (HR dashboard). */
function hr_off_pending(): array {
    if (!hr_off_has()) return [];
    return all("SELECT p.id, p.name_en, e.id AS employment_id, e.date_from AS end_date, e.reason, SUM(o.done) AS done, COUNT(*) AS total FROM hr_offboarding o JOIN hr_employments e ON e.id = o.employment_id JOIN hr_people p ON p.id = o.person_id GROUP BY p.id, p.name_en, e.id, e.date_from, e.reason HAVING done < total ORDER BY e.date_from DESC");
}
