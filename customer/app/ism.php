<?php
/**
 * IT & Cybersecurity › ISO 27001 — the information security management system (ISMS) of Golden A/S.   GAS-CP-R97-080
 *
 * The filing and reporting ISO/IEC 27001:2022 asks for, as registers of one shape: documented information, risks, statement of
 * applicability (the 93 controls of Annex A), assets, incidents, audits and reviews, nonconformities, objectives, suppliers,
 * training, legal requirements. Every record has a code, title, status, owner, due / review date, the fields of its register,
 * attached files and a history. One table for the records (ism_records, the fields of a register in `data` as JSON), one for
 * files (ism_files, stored under storage/isms), one for the history (ism_events).
 *
 * Access: administrators, management and the ISMS team (setting ism_team). Any staff member can report a security event.
 * Nothing in this module is sent to the assistant.
 */

const ISM_MAX_FILE = 26214400;   // 25 MB
const ISM_EXT = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'msg', 'eml', 'zip'];

function ism_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'ism_records'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ism_team(): array { $v = json_decode((string) cp_setting('ism_team', '[]'), true); return is_array($v) ? array_values(array_unique(array_map('intval', $v))) : []; }
function ism_manage(): bool { return is_staff() && (is_cp_admin() || mgmt_can()); }
function ism_can(): bool { static $v = null; if ($v === null) $v = ism_has() && is_staff() && (ism_manage() || in_array((int)(current_user()['id'] ?? 0), ism_team(), true)); return $v; }

/**
 * The registers. 'st' statuses [key => tone], 'closed' the statuses that need no further action, 'f' the own fields
 * [key => type]: text · area · date · num · score (1–5) · sel:<option list>. Labels come from the language files (ism.f_*, ism.s_*, ism.o_*).
 */
function ism_regs(): array {
    return [
        'doc'       => ['icon' => '📄', 'clause' => '7.5',           'prefix' => 'ISMS', 'st' => ['draft' => 'mute', 'review' => 'gold', 'approved' => 'green', 'obsolete' => 'mute'], 'closed' => ['approved', 'obsolete'],
                        'f' => ['version' => 'text', 'approved_by' => 'text', 'approved_on' => 'date', 'summary' => 'area'], 'ref' => true, 'due' => false, 'review' => true],
        'risk'      => ['icon' => '⚠', 'clause' => '6.1.2 · 8.2 · 8.3', 'prefix' => 'RSK', 'st' => ['open' => 'bad', 'treating' => 'gold', 'accepted' => 'navy', 'closed' => 'green'], 'closed' => ['accepted', 'closed'],
                        'f' => ['asset' => 'text', 'threat' => 'area', 'vulnerability' => 'area', 'likelihood' => 'score', 'impact' => 'score', 'treatment' => 'sel:mitigate,accept,transfer,avoid', 'plan' => 'area', 'res_likelihood' => 'score', 'res_impact' => 'score'], 'ref' => true, 'due' => true, 'review' => true],
        'soa'       => ['icon' => '☑', 'clause' => '6.1.3 d',        'prefix' => 'A',   'st' => ['planned' => 'bad', 'partial' => 'gold', 'done' => 'green', 'na' => 'mute'], 'closed' => ['done', 'na'],
                        'f' => ['justification' => 'area', 'how' => 'area'], 'ref' => false, 'due' => true, 'review' => true],
        'asset'     => ['icon' => '🖥', 'clause' => 'A 5.9',          'prefix' => 'AST', 'st' => ['in_use' => 'green', 'retired' => 'mute'], 'closed' => ['in_use', 'retired'],
                        'f' => ['type' => 'sel:hardware,software,information,service,site,people', 'classification' => 'sel:public,internal,confidential,restricted', 'location' => 'text', 'criticality' => 'score', 'notes' => 'area'], 'ref' => false, 'due' => false, 'review' => true],
        'incident'  => ['icon' => '🚨', 'clause' => 'A 5.24 – 5.28',  'prefix' => 'INC', 'st' => ['reported' => 'bad', 'assessing' => 'gold', 'contained' => 'navy', 'closed' => 'green'], 'closed' => ['closed'],
                        'f' => ['occurred_on' => 'date', 'reported_by' => 'text', 'category' => 'sel:malware,phishing,access,data,availability,physical,policy,other', 'severity' => 'sel:low,medium,high,critical', 'affected' => 'text', 'description' => 'area', 'containment' => 'area', 'root_cause' => 'area', 'lessons' => 'area', 'notified' => 'text', 'closed_on' => 'date'], 'ref' => false, 'due' => true, 'review' => false],
        'audit'     => ['icon' => '🔍', 'clause' => '9.2 · 9.3',      'prefix' => 'AUD', 'st' => ['planned' => 'gold', 'done' => 'green', 'cancelled' => 'mute'], 'closed' => ['done', 'cancelled'],
                        'f' => ['kind' => 'sel:internal_audit,management_review,external_audit,supplier_audit,access_review,restore_test,continuity_test', 'done_on' => 'date', 'scope' => 'area', 'auditor' => 'text', 'result' => 'area', 'findings' => 'num'], 'ref' => true, 'due' => true, 'review' => false],
        'nc'        => ['icon' => '🛠', 'clause' => '10.2',           'prefix' => 'NC',  'st' => ['open' => 'bad', 'action' => 'gold', 'verify' => 'navy', 'closed' => 'green'], 'closed' => ['closed'],
                        'f' => ['source' => 'sel:internal_audit,external_audit,incident,management_review,other', 'description' => 'area', 'root_cause' => 'area', 'correction' => 'area', 'action' => 'area', 'effectiveness' => 'area', 'closed_on' => 'date'], 'ref' => true, 'due' => true, 'review' => false],
        'objective' => ['icon' => '🎯', 'clause' => '6.2 · 9.1',      'prefix' => 'OBJ', 'st' => ['active' => 'gold', 'met' => 'green', 'missed' => 'bad', 'dropped' => 'mute'], 'closed' => ['met', 'missed', 'dropped'],
                        'f' => ['measure' => 'text', 'target' => 'text', 'result' => 'text', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => true],
        'supplier'  => ['icon' => '🤝', 'clause' => 'A 5.19 – 5.22',  'prefix' => 'SUP', 'st' => ['active' => 'green', 'ended' => 'mute'], 'closed' => ['active', 'ended'],
                        'f' => ['service' => 'text', 'data_access' => 'sel:none,internal,confidential,personal', 'criticality' => 'score', 'agreement' => 'text', 'last_review' => 'date', 'notes' => 'area'], 'ref' => false, 'due' => false, 'review' => true],
        'training'  => ['icon' => '🎓', 'clause' => '7.2 · 7.3 · A 6.3', 'prefix' => 'TRN', 'st' => ['planned' => 'gold', 'done' => 'green'], 'closed' => ['done'],
                        'f' => ['audience' => 'text', 'attendees' => 'num', 'trainer' => 'text', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => false],
        'legal'     => ['icon' => '⚖', 'clause' => 'A 5.31',         'prefix' => 'LEG', 'st' => ['applicable' => 'green', 'not_applicable' => 'mute'], 'closed' => ['applicable', 'not_applicable'],
                        'f' => ['source' => 'text', 'obligation' => 'area', 'how' => 'area'], 'ref' => false, 'due' => false, 'review' => true],
    ];
}
function ism_reg(string $k): ?array { $r = ism_regs()[$k] ?? null; if ($r) $r['key'] = $k; return $r; }
function ism_reg_title(string $k): string { return t('ism.r_' . $k); }
function ism_status_pill(string $reg, string $st): string { $R = ism_reg($reg); return pill($R['st'][$st] ?? 'mute', t('ism.s_' . $st)); }
function ism_opts(string $type): array { return str_starts_with($type, 'sel:') ? explode(',', substr($type, 4)) : []; }
function ism_data(array $r): array { $d = json_decode((string)($r['data'] ?? ''), true); return is_array($d) ? $d : []; }
function ism_score(array $d, string $pre = ''): int { return (int)($d[$pre . 'likelihood'] ?? 0) * (int)($d[$pre . 'impact'] ?? 0); }
function ism_level(int $score): array { return $score >= 15 ? ['bad', t('ism.lv_high')] : ($score >= 8 ? ['gold', t('ism.lv_medium')] : ($score > 0 ? ['green', t('ism.lv_low')] : ['mute', '—'])); }
/** Is something due on the record: its status still needs action and the due date has passed, or the review date has passed. */
function ism_due(array $r): ?string {
    $R = ism_reg((string)$r['reg']); $today = date('Y-m-d'); if (!$R) return null;
    if ($r['due_date'] && $r['due_date'] < $today && !in_array($r['status'], $R['closed'], true)) return 'due';
    if ($r['review_date'] && $r['review_date'] <= $today && !in_array($r['status'], ['obsolete', 'retired', 'ended', 'dropped', 'na', 'not_applicable', 'cancelled'], true)) return 'review';
    return null;
}

function ism_get(int $id): ?array { return ism_has() && $id ? (one('SELECT r.*, (SELECT COUNT(*) FROM ism_files f WHERE f.record_id = r.id) AS n_files FROM ism_records r WHERE r.id = ?', [$id]) ?: null) : null; }
function ism_list(string $reg, array $f = []): array {
    $w = ['r.reg = ?']; $a = [$reg];
    if (($f['status'] ?? '') !== '') { $w[] = 'r.status = ?'; $a[] = $f['status']; }
    if (!empty($f['owner'])) { $w[] = 'r.owner_user_id = ?'; $a[] = (int)$f['owner']; }
    if (($f['q'] ?? '') !== '') { $w[] = '(r.code LIKE ? OR r.title LIKE ? OR r.ref LIKE ? OR r.data LIKE ?)'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l); }
    $rows = all('SELECT r.*, (SELECT COUNT(*) FROM ism_files f WHERE f.record_id = r.id) AS n_files FROM ism_records r WHERE ' . implode(' AND ', $w) . ' ORDER BY r.sort, r.code, r.id', $a);
    $R = ism_reg($reg); $show = (string)($f['show'] ?? '');
    if ($show === 'open') $rows = array_values(array_filter($rows, fn($r) => !in_array($r['status'], $R['closed'], true)));
    if ($show === 'due') $rows = array_values(array_filter($rows, fn($r) => ism_due($r) !== null));
    return $rows;
}
function ism_next_code(string $reg): string {
    $R = ism_reg($reg); $max = 0; foreach (all('SELECT code FROM ism_records WHERE reg = ?', [$reg]) as $x) if (preg_match('/-(\d+)$/', (string)$x['code'], $m)) $max = max($max, (int)$m[1]);
    return $R['prefix'] . '-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function ism_event(int $rid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO ism_events (record_id, event, user_id, user_name, note) VALUES (?,?,?,?,?)', [$rid, mb_substr($ev, 0, 24), (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 120), $note !== null ? mb_substr($note, 0, 600) : null]); }
function ism_events(int $rid): array { return all('SELECT * FROM ism_events WHERE record_id = ? ORDER BY id DESC LIMIT 200', [$rid]); }
function ism_files(int $rid): array { return all('SELECT * FROM ism_files WHERE record_id = ? ORDER BY id DESC', [$rid]); }
function ism_users(): array { return all("SELECT id, full_name FROM customer_users WHERE is_active = 1 AND role <> 'customer' ORDER BY full_name"); }

/** Save a record from the form. Returns its id. */
function ism_save_from_post(): int {
    if (!ism_can()) throw new RuntimeException(t('err.noaccess'));
    $reg = (string)($_POST['reg'] ?? ''); $R = ism_reg($reg); if (!$R) throw new RuntimeException(t('err.notfound'));
    $id = (int)($_POST['id'] ?? 0); $old = $id ? ism_get($id) : null; if ($id && (!$old || $old['reg'] !== $reg)) throw new RuntimeException(t('err.notfound'));
    $S = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $D = function (string $k): ?string { $v = trim((string)($_POST[$k] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; };
    $title = $S('title', 255); if ($title === '') throw new RuntimeException(t('ism.e_title'));
    $status = (string)($_POST['status'] ?? ''); if (!isset($R['st'][$status])) $status = (string) array_key_first($R['st']);
    $owner = (int)($_POST['owner_user_id'] ?? 0); $ownerName = $owner ? (string) scalar("SELECT full_name FROM customer_users WHERE id = ? AND role <> 'customer'", [$owner]) : ''; if ($ownerName === '') $owner = 0;
    $data = [];
    foreach ($R['f'] as $k => $type) {
        $v = trim((string)($_POST['d'][$k] ?? ''));
        if ($type === 'date') $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        elseif ($type === 'score') $v = in_array($v, ['1', '2', '3', '4', '5'], true) ? (int)$v : '';
        elseif ($type === 'num') $v = is_numeric($v) ? 0 + $v : '';
        elseif (str_starts_with($type, 'sel:')) $v = in_array($v, ism_opts($type), true) ? $v : '';
        else $v = mb_substr($v, 0, $type === 'area' ? 6000 : 255);
        if ($v !== '') $data[$k] = $v;
    }
    $code = $S('code', 40); if ($code === '') $code = $old ? (string)$old['code'] : ism_next_code($reg);
    if (scalar('SELECT COUNT(*) FROM ism_records WHERE reg = ? AND code = ? AND id <> ?', [$reg, $code, $id])) throw new RuntimeException(t('ism.e_code', ['c' => $code]));
    $u = current_user(); $row = [$code, $title, $status, $owner ?: null, $ownerName ?: null, !empty($R['due']) ? $D('due_date') : null, !empty($R['review']) ? $D('review_date') : null, $S('ref', 60) ?: null, json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr((string)($u['full_name'] ?? ''), 0, 120)];
    if ($old) {
        q('UPDATE ism_records SET code = ?, title = ?, status = ?, owner_user_id = ?, owner_name = ?, due_date = ?, review_date = ?, ref = ?, data = ?, updated_name = ?, updated_at = NOW() WHERE id = ?', array_merge($row, [$id]));
        $ch = []; if ($old['status'] !== $status) $ch[] = t('ism.s_' . $old['status']) . ' → ' . t('ism.s_' . $status);
        foreach (['title', 'code', 'ref', 'due_date', 'review_date'] as $k) if ((string)($old[$k] ?? '') !== (string)(['title' => $title, 'code' => $code, 'ref' => $row[7], 'due_date' => $row[5], 'review_date' => $row[6]][$k] ?? '')) $ch[] = $k;
        if ((int)$old['owner_user_id'] !== $owner) $ch[] = 'owner';
        $od = ism_data($old); foreach ($R['f'] as $k => $_) if ((string)($od[$k] ?? '') !== (string)($data[$k] ?? '')) $ch[] = $k;
        if ($ch) ism_event($id, $old['status'] !== $status ? 'status' : 'update', implode(' · ', $ch));
    } else {
        q('INSERT INTO ism_records (reg, code, title, status, owner_user_id, owner_name, due_date, review_date, ref, data, updated_name, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge([$reg], $row, [(int)($u['id'] ?? 0) ?: null, $row[9]]));
        $id = (int) db()->lastInsertId(); ism_event($id, 'create', $code);
    }
    return $id;
}
function ism_delete(int $id): string { if (!ism_manage()) throw new RuntimeException(t('err.noaccess')); $r = ism_get($id); if (!$r) throw new RuntimeException(t('err.notfound')); foreach (ism_files($id) as $f) @unlink(ism_dir() . '/' . $f['stored_name']); q('DELETE FROM ism_files WHERE record_id = ?', [$id]); q('DELETE FROM ism_events WHERE record_id = ?', [$id]); q('DELETE FROM ism_records WHERE id = ?', [$id]); return (string)$r['reg']; }

/* ------------------------------------------------------------ files */
function ism_dir(): string { $d = dirname(__DIR__) . '/storage/isms'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function ism_file_add(int $rid, ?array $up, string $note): void {
    if (!ism_can()) throw new RuntimeException(t('err.noaccess')); $r = ism_get($rid); if (!$r) throw new RuntimeException(t('err.notfound'));
    if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) throw new RuntimeException(t('ism.e_file'));
    $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)); if ((int)$up['size'] > ISM_MAX_FILE || !in_array($ext, ISM_EXT, true)) throw new RuntimeException(t('ism.e_file'));
    $stored = bin2hex(random_bytes(16)) . '.' . $ext; if (!@move_uploaded_file((string)$up['tmp_name'], ism_dir() . '/' . $stored)) throw new RuntimeException(t('ism.e_file'));
    $u = current_user(); $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename((string)$up['name'])), 0, 190);
    q('INSERT INTO ism_files (record_id, stored_name, original_name, size_bytes, sha256, note, uploaded_by, uploaded_name) VALUES (?,?,?,?,?,?,?,?)', [$rid, $stored, $name, (int)$up['size'], (string) hash_file('sha256', ism_dir() . '/' . $stored), mb_substr(trim($note), 0, 255) ?: null, (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 120)]);
    ism_event($rid, 'file', $name);
}
function ism_file_delete(int $fid): int { if (!ism_can()) throw new RuntimeException(t('err.noaccess')); $f = one('SELECT * FROM ism_files WHERE id = ?', [$fid]); if (!$f) throw new RuntimeException(t('err.notfound')); @unlink(ism_dir() . '/' . $f['stored_name']); q('DELETE FROM ism_files WHERE id = ?', [$fid]); ism_event((int)$f['record_id'], 'file_del', (string)$f['original_name']); return (int)$f['record_id']; }
function ism_file_send(int $fid): void {
    if (!ism_can()) { http_response_code(403); exit; } $f = one('SELECT * FROM ism_files WHERE id = ?', [$fid]); $p = $f ? ism_dir() . '/' . $f['stored_name'] : ''; if (!$f || !is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', (string)$f['original_name']) . '"'); header('Content-Length: ' . filesize($p)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); readfile($p); exit;
}

/* ------------------------------------------------------------ first filling: the documents ISO 27001 asks for and the 93 controls of Annex A */
function ism_seed(): int {
    if (!ism_has() || cp_setting('ism_seeded', '') === '1') return 0; $n = 0;
    $docs = [['4.3', 'Scope of the ISMS'], ['5.2', 'Information security policy'], ['5.3', 'Roles, responsibilities and authorities'], ['6.1.2', 'Risk assessment and risk treatment methodology'], ['6.1.3 d', 'Statement of Applicability'], ['6.1.3 e · 8.3', 'Risk treatment plan'], ['6.2', 'Information security objectives'], ['7.2', 'Evidence of competence'], ['7.5', 'Control of documented information'], ['8.1', 'Operational planning and control'], ['8.2', 'Risk assessment results'], ['9.1', 'Monitoring and measurement results'], ['9.2', 'Internal audit programme and results'], ['9.3', 'Management review results'], ['10.2', 'Nonconformities and corrective actions'],
             ['A 5.9', 'Inventory of information and other associated assets'], ['A 5.10', 'Acceptable use of information and assets'], ['A 5.12', 'Information classification and labelling'], ['A 5.14', 'Information transfer rules'], ['A 5.15', 'Access control policy'], ['A 5.19', 'Supplier security policy'], ['A 5.24 · 5.26', 'Incident management procedure'], ['A 5.29 · 5.30', 'Business continuity and ICT readiness plan'], ['A 5.31', 'Legal, regulatory and contractual requirements'], ['A 5.37', 'Documented operating procedures'], ['A 6.6', 'Confidentiality / non-disclosure agreement'], ['A 6.7', 'Remote working policy'], ['A 8.13', 'Backup policy'], ['A 8.15', 'Logging and monitoring'], ['A 8.24', 'Use of cryptography'], ['A 8.25', 'Secure development policy'], ['A 8.32', 'Change management procedure']];
    if (!(int) scalar("SELECT COUNT(*) FROM ism_records WHERE reg = 'doc'")) foreach ($docs as $i => [$ref, $title]) { q("INSERT INTO ism_records (reg, code, title, status, ref, sort, data, created_name) VALUES ('doc', ?, ?, 'draft', ?, ?, '{}', 'ISO/IEC 27001:2022')", ['ISMS-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), $title, $ref, $i + 1]); $n++; }
    $A = ['5' => ['Policies for information security', 'Information security roles and responsibilities', 'Segregation of duties', 'Management responsibilities', 'Contact with authorities', 'Contact with special interest groups', 'Threat intelligence', 'Information security in project management', 'Inventory of information and other associated assets', 'Acceptable use of information and other associated assets', 'Return of assets', 'Classification of information', 'Labelling of information', 'Information transfer', 'Access control', 'Identity management', 'Authentication information', 'Access rights', 'Information security in supplier relationships', 'Addressing information security within supplier agreements', 'Managing information security in the ICT supply chain', 'Monitoring, review and change management of supplier services', 'Information security for use of cloud services', 'Information security incident management planning and preparation', 'Assessment and decision on information security events', 'Response to information security incidents', 'Learning from information security incidents', 'Collection of evidence', 'Information security during disruption', 'ICT readiness for business continuity', 'Legal, statutory, regulatory and contractual requirements', 'Intellectual property rights', 'Protection of records', 'Privacy and protection of PII', 'Independent review of information security', 'Compliance with policies, rules and standards for information security', 'Documented operating procedures'],
          '6' => ['Screening', 'Terms and conditions of employment', 'Information security awareness, education and training', 'Disciplinary process', 'Responsibilities after termination or change of employment', 'Confidentiality or non-disclosure agreements', 'Remote working', 'Information security event reporting'],
          '7' => ['Physical security perimeters', 'Physical entry', 'Securing offices, rooms and facilities', 'Physical security monitoring', 'Protecting against physical and environmental threats', 'Working in secure areas', 'Clear desk and clear screen', 'Equipment siting and protection', 'Security of assets off-premises', 'Storage media', 'Supporting utilities', 'Cabling security', 'Equipment maintenance', 'Secure disposal or re-use of equipment'],
          '8' => ['User endpoint devices', 'Privileged access rights', 'Information access restriction', 'Access to source code', 'Secure authentication', 'Capacity management', 'Protection against malware', 'Management of technical vulnerabilities', 'Configuration management', 'Information deletion', 'Data masking', 'Data leakage prevention', 'Information backup', 'Redundancy of information processing facilities', 'Logging', 'Monitoring activities', 'Clock synchronization', 'Use of privileged utility programs', 'Installation of software on operational systems', 'Networks security', 'Security of network services', 'Segregation of networks', 'Web filtering', 'Use of cryptography', 'Secure development life cycle', 'Application security requirements', 'Secure system architecture and engineering principles', 'Secure coding', 'Security testing in development and acceptance', 'Outsourced development', 'Separation of development, test and production environments', 'Change management', 'Test information', 'Protection of information systems during audit testing']];
    if (!(int) scalar("SELECT COUNT(*) FROM ism_records WHERE reg = 'soa'")) { $s = 0; foreach ($A as $th => $list) foreach ($list as $i => $title) { q("INSERT INTO ism_records (reg, code, title, status, ref, sort, data, created_name) VALUES ('soa', ?, ?, 'planned', ?, ?, '{}', 'ISO/IEC 27001:2022 Annex A')", ['A ' . $th . '.' . ($i + 1), $title, ['5' => 'Organizational', '6' => 'People', '7' => 'Physical', '8' => 'Technological'][$th], ++$s]); $n++; } }
    cp_setting_set('ism_seeded', '1'); return $n;
}

/* ------------------------------------------------------------ overview, report, reminders */
function ism_overview(): array {
    $O = ['regs' => [], 'due' => [], 'soa' => ['applicable' => 0, 'done' => 0, 'partial' => 0, 'planned' => 0, 'na' => 0], 'risk' => ['high' => 0, 'medium' => 0, 'low' => 0, 'open' => 0], 'next' => []];
    foreach (ism_regs() as $k => $R) $O['regs'][$k] = ['n' => 0, 'open' => 0, 'due' => 0, 'st' => array_fill_keys(array_keys($R['st']), 0)];
    foreach (all('SELECT * FROM ism_records ORDER BY COALESCE(due_date, review_date), id') as $r) {
        $k = (string)$r['reg']; $R = ism_reg($k); if (!$R) continue; $x = &$O['regs'][$k]; $x['n']++; if (isset($x['st'][$r['status']])) $x['st'][$r['status']]++;
        $open = !in_array($r['status'], $R['closed'], true); if ($open) $x['open']++; $d = ism_due($r); if ($d) { $x['due']++; if (count($O['due']) < 40) $O['due'][] = $r + ['_why' => $d]; } unset($x);
        if ($k === 'soa') { if (isset($O['soa'][$r['status']])) $O['soa'][$r['status']]++; if ($r['status'] !== 'na') $O['soa']['applicable']++; }
        if ($k === 'risk' && $open) { $O['risk']['open']++; $sc = ism_score(ism_data($r)); $O['risk'][$sc >= 15 ? 'high' : ($sc >= 8 ? 'medium' : 'low')]++; }
        if ($k === 'audit' && $r['status'] === 'planned' && $r['due_date'] && count($O['next']) < 6) $O['next'][] = $r;
    }
    return $O;
}
/** Every register as a sheet of one workbook — the filing for an audit or a management review. */
function ism_export(): void {
    if (!ism_can()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php'; $sheets = []; $O = ism_overview(); $sum = [];
    foreach (ism_regs() as $k => $R) { $x = $O['regs'][$k]; $sum[] = [ism_reg_title($k), $R['clause'], $x['n'], $x['open'], $x['due']]; }
    $sheets[] = ['name' => 'Summary', 'first' => true, 'header' => [t('ism.register'), t('ism.clause'), t('ism.n_records'), t('ism.n_open'), t('ism.n_due')], 'rows' => $sum, 'widths' => [38, 20, 12, 12, 12]];
    foreach (ism_regs() as $k => $R) {
        $h = [t('ism.code'), t('ism.title_f'), t('ism.ref'), t('th.status'), t('ism.owner'), t('ism.due_date'), t('ism.review_date')]; foreach ($R['f'] as $fk => $_) $h[] = t('ism.f_' . $fk); if ($k === 'risk') { $h[] = t('ism.score'); $h[] = t('ism.res_score'); } $h[] = t('ism.files'); $rows = [];
        foreach (ism_list($k) as $r) { $d = ism_data($r); $row = [$r['code'], $r['title'], (string)$r['ref'], t('ism.s_' . $r['status']), (string)$r['owner_name'], (string)$r['due_date'], (string)$r['review_date']]; foreach ($R['f'] as $fk => $type) { $v = $d[$fk] ?? ''; $row[] = str_starts_with($type, 'sel:') && $v !== '' ? t('ism.o_' . $v) : $v; } if ($k === 'risk') { $row[] = ism_score($d) ?: ''; $row[] = ism_score($d, 'res_') ?: ''; } $row[] = (int)$r['n_files']; $rows[] = $row; }
        $sheets[] = ['name' => mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', ism_reg_title($k)), 0, 31), 'header' => $h, 'rows' => $rows, 'widths' => array_merge([12, 46, 16, 16, 22, 12, 12], array_fill(0, count($h) - 7, 24))];
    }
    xlsx_download('GAS-ISMS-registers_' . date('Y-m-d') . '.xlsx', $sheets);
}
/** Hourly: tell the owner (or the ISMS team) once about a record whose due or review date is within 14 days or has passed. */
function ism_sweep(): int {
    if (!ism_has() || time() - (int) cp_setting('ism_sweep_at', '0') < 3600 || !function_exists('nf_push')) return 0; cp_setting_set('ism_sweep_at', (string) time()); $n = 0; $lim = date('Y-m-d', strtotime('+14 days')); $team = array_values(array_unique(array_merge(ism_team(), array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu WHERE cu.is_active = 1 AND cu.role = 'admin' LIMIT 5"), 'id')))));
    foreach (all("SELECT * FROM ism_records WHERE (due_date IS NOT NULL AND due_date <= ?) OR (review_date IS NOT NULL AND review_date <= ?)", [$lim, $lim]) as $r) {
        $R = ism_reg((string)$r['reg']); if (!$R) continue; $open = !in_array($r['status'], $R['closed'], true);
        foreach ([['due_date', $open, 'ism.nf_due'], ['review_date', !in_array($r['status'], ['obsolete', 'retired', 'ended', 'dropped', 'na', 'not_applicable', 'cancelled'], true), 'ism.nf_review']] as [$col, $want, $key]) {
            if (!$want || !$r[$col] || $r[$col] > $lim) continue; $to = $r['owner_user_id'] ? [(int)$r['owner_user_id']] : $team; if (!$to) continue;
            $n += (int) nf_push($to, 'ism:due', 'ism:' . $r['id'] . ':' . $col . ':' . $r[$col], t($key, ['c' => $r['code'], 't' => mb_substr((string)$r['title'], 0, 80), 'd' => fmt_date($r[$col])]), null, url('isms_rec', ['id' => (int)$r['id']]), null, null);
        }
    }
    return $n;
}

/* ------------------------------------------------------------ actions */
function ism_handle_action(string $action): bool {
    if (!str_starts_with($action, 'ism_')) return false;
    if (!ism_has() || !is_staff()) throw new RuntimeException(t('err.noaccess'));
    if ($action === 'ism_report') {   // any staff member reports a security event (A 6.8)
        $what = mb_substr(trim((string)($_POST['what'] ?? '')), 0, 6000); $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if ($title === '' || $what === '') throw new RuntimeException(t('ism.e_report'));
        $u = current_user(); $when = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['when'] ?? '')) ? (string)$_POST['when'] : date('Y-m-d'); $code = ism_next_code('incident');
        q("INSERT INTO ism_records (reg, code, title, status, data, created_by, created_name, updated_name) VALUES ('incident', ?, ?, 'reported', ?, ?, ?, ?)", [$code, $title, json_encode(['occurred_on' => $when, 'reported_by' => (string)($u['full_name'] ?? ''), 'affected' => mb_substr(trim((string)($_POST['affected'] ?? '')), 0, 255), 'description' => $what], JSON_UNESCAPED_UNICODE), (int)$u['id'], (string)$u['full_name'], (string)$u['full_name']]);
        $rid = (int) db()->lastInsertId(); ism_event($rid, 'create', $code);
        $to = array_values(array_unique(array_merge(ism_team(), array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role = 'admin' LIMIT 5"), 'id')))));
        if ($to && function_exists('nf_push')) nf_push($to, 'ism:incident', 'ism:' . $rid, t('ism.nf_incident', ['c' => $code, 't' => $title]), null, url('isms_rec', ['id' => $rid]), (int)$u['id'], (string)$u['full_name']);
        flash('good', t('ism.report_ok', ['c' => $code])); redirect('isms_report', ['sent' => 1]);
    }
    if (!ism_can()) throw new RuntimeException(t('err.noaccess'));
    switch ($action) {
        case 'ism_save': $id = ism_save_from_post(); flash('good', t('ism.saved')); redirect('isms_rec', ['id' => $id]);
        case 'ism_delete': $reg = ism_delete((int)($_POST['id'] ?? 0)); flash('good', t('ism.deleted')); redirect('isms', ['reg' => $reg]);
        case 'ism_file_add': $rid = (int)($_POST['id'] ?? 0); ism_file_add($rid, $_FILES['file'] ?? null, (string)($_POST['note'] ?? '')); flash('good', t('ism.file_ok')); redirect('isms_rec', ['id' => $rid], '#files');
        case 'ism_file_del': $rid = ism_file_delete((int)($_POST['file_id'] ?? 0)); flash('good', t('ism.file_deleted')); redirect('isms_rec', ['id' => $rid], '#files');
        case 'ism_team_save': if (!ism_manage()) throw new RuntimeException(t('err.noaccess')); $ok = array_map('intval', array_column(ism_users(), 'id')); cp_setting_set('ism_team', json_encode(array_values(array_intersect(array_map('intval', (array)($_POST['team'] ?? [])), $ok)))); flash('good', t('ism.saved')); redirect('isms');
        default: throw new RuntimeException(t('err.notfound'));
    }
    return true;
}
