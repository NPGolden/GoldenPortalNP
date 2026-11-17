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
 *
 * GAS-CP-R97-081 — Marine cyber safety (IACS UR E26 / E27), a second area of the same module: the computer-based systems (CBS) we
 * produce in our projects (register mprod), the documents UR E27 3.1 asks for each of them (mdoc, children of a product) and the
 * software releases of the programming team with their approval and life cycle (msw, children of a product). A product is linked
 * to a project, a sub-project and a vessel; its documents and releases follow it. Access to this area: those of the ISMS plus the
 * programming team (setting ism_mteam). A release is approved by somebody other than its developer.
 */

const ISM_MAX_FILE = 26214400;   // 25 MB
const ISM_EXT = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'msg', 'eml', 'zip'];

function ism_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'ism_records'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ism_team(): array { $v = json_decode((string) cp_setting('ism_team', '[]'), true); return is_array($v) ? array_values(array_unique(array_map('intval', $v))) : []; }
/** R97-083: who may decide which positions have access — the CEO and the General Manager only (staff role names). */
function ism_top(): bool { static $v = null; if ($v === null) $v = is_staff() && function_exists('cp_role_name') && in_array(cp_role_name(), ['ceo', 'general manager'], true); return $v; }
function ism_manage(): bool { return is_staff() && (is_cp_admin() || mgmt_can()); }
function ism_has_links(): bool { static $h = null; if ($h === null) { try { $h = ism_has() && (bool) scalar("SHOW COLUMNS FROM ism_records LIKE 'parent_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ism_has_ext(): bool { static $h = null; if ($h === null) { try { $h = ism_has_links() && (bool) scalar("SHOW COLUMNS FROM ism_records LIKE 'supplier_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ism_mteam(): array { $v = json_decode((string) cp_setting('ism_mteam', '[]'), true); return is_array($v) ? array_values(array_unique(array_map('intval', $v))) : []; }
/** Marine cyber safety area: the ISMS people and the programming team. */
function ism_can_marine(): bool { static $v = null; if ($v === null) $v = ism_has_links() && is_staff() && (ism_can() || in_array((int)(current_user()['id'] ?? 0), ism_mteam(), true)); return $v; }
function ism_grp(string $reg): string { return (string)(ism_regs()[$reg]['grp'] ?? 'iso'); }
function ism_can_reg(string $reg): bool { return ism_grp($reg) === 'marine' ? ism_can_marine() : ism_can(); }
/** The page of an area: 'isms' (ISO 27001) or 'mcs' (marine cyber safety). */
function ism_page(string $reg): string { return ism_grp($reg) === 'marine' ? 'mcs' : 'isms'; }
/**
 * R97-082 — access by position: the staff roles and the disciplines whose people have the whole of IT & Cybersecurity.
 * Until management saves a choice, the names decide: top management and QHSE, project managers, IT, automation and electrical engineers.
 * Returns ['roles' => [ids], 'disc' => [ids], 'saved' => bool].
 */
const ISM_ROLE_RX = '/\b(ceo|owner|general manager|managing director|director|technical manager|cto|coo|qhse|hse|quality|project manager|pm|it|ict|cyber|automation|electrical|programmer|software)\b|διευθ|ποιότητ|αυτοματ|ηλεκτρολ|πληροφορ|έργου/u';
const ISM_DISC_RX = '/\b(automation|electrical|it|ict|information technology|software|programming|cyber|project management|qhse|quality|management)\b|αυτοματ|ηλεκτρολ|πληροφορ|ποιότητ|διαχείριση έργ/u';
function ism_access(): array {
    static $A = null; if ($A !== null) return $A;
    $s = json_decode((string) cp_setting('ism_access', ''), true);
    if (is_array($s) && isset($s['roles'], $s['disc'])) return $A = ['roles' => array_map('intval', (array)$s['roles']), 'disc' => array_map('intval', (array)$s['disc']), 'saved' => true];
    $A = ['roles' => [], 'disc' => [], 'saved' => false];
    try { foreach (all('SELECT id, name FROM staff_roles WHERE is_active = 1') as $r) if (preg_match(ISM_ROLE_RX, mb_strtolower((string)$r['name']))) $A['roles'][] = (int)$r['id'];
          foreach (all('SELECT id, name FROM disciplines WHERE is_active = 1') as $r) if (preg_match(ISM_DISC_RX, mb_strtolower((string)$r['name']))) $A['disc'][] = (int)$r['id']; } catch (Throwable $e) {}
    return $A;
}
function ism_by_position(): bool { $u = current_user(); if (!$u) return false; $A = ism_access(); return (!empty($u['staff_role_id']) && in_array((int)$u['staff_role_id'], $A['roles'], true)) || (!empty($u['discipline_id']) && in_array((int)$u['discipline_id'], $A['disc'], true)); }
function ism_can(): bool { static $v = null; if ($v === null) $v = ism_has() && is_staff() && (ism_manage() || in_array((int)(current_user()['id'] ?? 0), ism_team(), true) || ism_by_position()); return $v; }

/**
 * The registers. 'st' statuses [key => tone], 'closed' the statuses that need no further action, 'f' the own fields
 * [key => type]: text · area · date · num · score (1–5) · sel:<option list>. Labels come from the language files (ism.f_*, ism.s_*, ism.o_*).
 */
function ism_regs(?string $grp = null): array {
    static $all = null; if ($all !== null) return $grp === null ? $all : array_filter($all, fn($R) => ($R['grp'] ?? 'iso') === $grp);
    $all = [
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
        'supplier'  => ['icon' => '🤝', 'clause' => 'A 5.19 – 5.22',  'prefix' => 'SUP', 'st' => ['candidate' => 'gold', 'active' => 'green', 'conditional' => 'gold', 'blocked' => 'bad', 'ended' => 'mute'], 'closed' => ['active', 'conditional', 'blocked', 'ended'],
                        'f' => ['service' => 'text', 'data_access' => 'sel:none,internal,confidential,personal', 'criticality' => 'score', 'agreement' => 'text', 'last_review' => 'date', 'notes' => 'area'], 'ref' => false, 'due' => false, 'review' => true, 'auto' => ['rating', 'rating_result']],
        /* R97-083 — evaluation of a supplier of the register (A 5.19 – 5.22): six criteria 1–5, a result; the last one sets the state of the supplier and its next evaluation */
        'supeval'   => ['icon' => '📝', 'clause' => 'A 5.19 – 5.22',  'prefix' => 'SEV', 'st' => ['planned' => 'gold', 'done' => 'green', 'cancelled' => 'mute'], 'closed' => ['done', 'cancelled'],
                        'f' => ['eval_kind' => 'sel:initial,periodic,after_incident', 'evaluated_on' => 'date', 'evaluator' => 'text', 'c_agreement' => 'score', 'c_certification' => 'score', 'c_access' => 'score', 'c_incidents' => 'score', 'c_continuity' => 'score', 'c_quality' => 'score', 'eval_result' => 'sel:res_approved,res_conditional,res_rejected', 'conditions' => 'area', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => false, 'parent' => 'supplier', 'auto' => ['rating']],
        'training'  => ['icon' => '🎓', 'clause' => '7.2 · 7.3 · A 6.3', 'prefix' => 'TRN', 'st' => ['planned' => 'gold', 'done' => 'green'], 'closed' => ['done'],
                        'f' => ['audience' => 'text', 'attendees' => 'num', 'trainer' => 'text', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => false],
        'legal'     => ['icon' => '⚖', 'clause' => 'A 5.31',         'prefix' => 'LEG', 'st' => ['applicable' => 'green', 'not_applicable' => 'mute'], 'closed' => ['applicable', 'not_applicable'],
                        'f' => ['source' => 'text', 'obligation' => 'area', 'how' => 'area'], 'ref' => false, 'due' => false, 'review' => true],
        /* R97-081 — marine cyber safety (IACS UR E26 / E27) */
        'mprod'     => ['grp' => 'marine', 'icon' => '🚢', 'clause' => 'UR E27 · UR E26 · UR E22', 'prefix' => 'CBS', 'st' => ['preparing' => 'gold', 'submitted' => 'navy', 'comments' => 'bad', 'approved' => 'green', 'in_service' => 'green', 'retired' => 'mute'], 'closed' => ['approved', 'in_service', 'retired'],
                        'f' => ['system' => 'text', 'maker_model' => 'text', 'platform' => 'text', 'cbs_cat' => 'sel:cat_i,cat_ii,cat_iii', 'zone' => 'text', 'untrusted' => 'sel:no,yes', 'class_society' => 'sel:lr,bv,dnv,abs,rina,nk,kr,ccs,other', 'route' => 'sel:plan_approval,type_approval', 'class_ref' => 'text', 'cert_no' => 'text', 'cert_valid' => 'date', 'description' => 'area', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => true, 'links' => true],
        'mdoc'      => ['grp' => 'marine', 'icon' => '📑', 'clause' => 'UR E27 3.1', 'prefix' => 'CD', 'st' => ['missing' => 'bad', 'draft' => 'gold', 'internal_review' => 'gold', 'submitted' => 'navy', 'comments' => 'bad', 'approved' => 'green', 'na' => 'mute'], 'closed' => ['approved', 'na'],
                        'f' => ['doc_no' => 'text', 'revision' => 'text', 'submitted_on' => 'date', 'approved_on' => 'date', 'class_comments' => 'area', 'notes' => 'area'], 'ref' => true, 'due' => true, 'review' => false, 'parent' => 'mprod'],
        'msw'       => ['grp' => 'marine', 'icon' => '💾', 'clause' => 'UR E27 5 · 3.1.9 · UR E22', 'prefix' => 'SW', 'st' => ['development' => 'mute', 'testing' => 'gold', 'review' => 'gold', 'approved' => 'navy', 'released' => 'green', 'installed' => 'green', 'superseded' => 'mute', 'withdrawn' => 'bad'], 'closed' => ['installed', 'superseded', 'withdrawn'],
                        'f' => ['version' => 'text', 'component' => 'sel:plc,hmi,scada,firmware,network,os,other', 'change_type' => 'sel:first_issue,feature,bugfix,security_update,config', 'change' => 'area', 'moc_ref' => 'text', 'repo_ref' => 'text', 'checksum' => 'text', 'tested_by' => 'text', 'test_ref' => 'text', 'class_notify' => 'sel:not_needed,needed,notified,accepted', 'installed_on' => 'date', 'installed_by' => 'text', 'rollback' => 'area', 'notes' => 'area'], 'ref' => false, 'due' => true, 'review' => false, 'parent' => 'mprod', 'auto' => ['approved_by', 'approved_on']],
    ];
    return $grp === null ? $all : array_filter($all, fn($R) => ($R['grp'] ?? 'iso') === $grp);
}
const ISM_EVAL_CRIT = ['c_agreement', 'c_certification', 'c_access', 'c_incidents', 'c_continuity', 'c_quality'];
/** The ten documents UR E27 (Rev.1) 3.1 asks for a computer-based system. */
const ISM_E27_DOCS = [['3.1.1', 'CBS asset inventory'], ['3.1.2', 'Topology diagrams'], ['3.1.3', 'Description of security capabilities'], ['3.1.4', 'Test procedure of security capabilities'], ['3.1.5', 'Security configuration guidelines'], ['3.1.6', 'Secure development lifecycle documents'], ['3.1.7', 'Plans for maintenance and verification of the CBS'], ['3.1.8', 'Information supporting the owner\'s incident response and recovery plan'], ['3.1.9', 'Management of change plan'], ['3.1.10', 'Test reports']];
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

/** Columns and joins of the links: a document or a release takes project, sub-project and vessel from its product. */
function ism_link_sql(): array {
    if (!ism_has_links()) return ['', ''];
    $x = ism_has_ext() ? [', sc.name AS supplier_name', ' LEFT JOIN companies sc ON sc.id = COALESCE(r.supplier_id, pr.supplier_id)'] : ['', ''];
    return [$x[0] . ", pr.code AS parent_code, pr.title AS parent_title, pr.status AS parent_status, COALESCE(r.project_id, pr.project_id) AS x_project, COALESCE(r.subproject_id, pr.subproject_id) AS x_sub, COALESCE(r.vessel_id, pr.vessel_id) AS x_vessel, pj.name AS project_name, pj.project_code AS project_code, sb.code AS sub_code, sb.name AS sub_name, vs.name AS vessel_name",
            " LEFT JOIN ism_records pr ON pr.id = r.parent_id LEFT JOIN projects pj ON pj.id = COALESCE(r.project_id, pr.project_id) LEFT JOIN subprojects sb ON sb.id = COALESCE(r.subproject_id, pr.subproject_id) LEFT JOIN vessels vs ON vs.id = COALESCE(r.vessel_id, pr.vessel_id)" . $x[1]];
}
function ism_get(int $id): ?array { if (!ism_has() || !$id) return null; [$c, $j] = ism_link_sql(); return one('SELECT r.*, (SELECT COUNT(*) FROM ism_files f WHERE f.record_id = r.id) AS n_files' . $c . ' FROM ism_records r' . $j . ' WHERE r.id = ?', [$id]) ?: null; }
function ism_children(int $pid, string $reg): array { return ism_has_links() ? all('SELECT r.*, (SELECT COUNT(*) FROM ism_files f WHERE f.record_id = r.id) AS n_files FROM ism_records r WHERE r.parent_id = ? AND r.reg = ? ORDER BY r.sort, r.id', [$pid, $reg]) : []; }
function ism_list(string $reg, array $f = []): array {
    $w = ['r.reg = ?']; $a = [$reg];
    if (($f['status'] ?? '') !== '') { $w[] = 'r.status = ?'; $a[] = $f['status']; }
    if (!empty($f['owner'])) { $w[] = 'r.owner_user_id = ?'; $a[] = (int)$f['owner']; }
    if (($f['q'] ?? '') !== '') { $w[] = '(r.code LIKE ? OR r.title LIKE ? OR r.ref LIKE ? OR r.data LIKE ?)'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l); }
    [$c, $j] = ism_link_sql();
    if ($j !== '') { foreach (['vessel' => 'vessel_id', 'project' => 'project_id', 'sub' => 'subproject_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "COALESCE(r.$col, pr.$col) = ?"; $a[] = (int)$f[$k]; } if (!empty($f['parent'])) { $w[] = 'r.parent_id = ?'; $a[] = (int)$f['parent']; } }
    $rows = all('SELECT r.*, (SELECT COUNT(*) FROM ism_files f WHERE f.record_id = r.id) AS n_files' . $c . ' FROM ism_records r' . $j . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY r.sort, r.code, r.id', $a);
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
    $reg = (string)($_POST['reg'] ?? ''); $R = ism_reg($reg); if (!$R) throw new RuntimeException(t('err.notfound'));
    if (!ism_can_reg($reg)) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $old = $id ? ism_get($id) : null; if ($id && (!$old || $old['reg'] !== $reg)) throw new RuntimeException(t('err.notfound'));
    $S = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $D = function (string $k): ?string { $v = trim((string)($_POST[$k] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; };
    $title = $S('title', 255);
    /* R97-083: a supplier of the register is a company of the supplier list — once only; its name is the title when none is typed */
    $supId = null; if ($reg === 'supplier' && ism_has_ext()) { $supId = (int)($_POST['supplier_id'] ?? 0) ?: null;
        if ($supId) { $co = one('SELECT co.id, co.name FROM companies co WHERE co.id = ? AND ' . sup_where(), [$supId]); if (!$co) $supId = null; else { if ($title === '') $title = mb_substr((string)$co['name'], 0, 255); if (scalar("SELECT COUNT(*) FROM ism_records WHERE reg = 'supplier' AND supplier_id = ? AND id <> ?", [$supId, $id])) throw new RuntimeException(t('ism.e_sup_twice')); } } }
    if ($reg === 'supeval' && $title === '') { $pp = ism_get((int)($_POST['parent_id'] ?? ($old['parent_id'] ?? 0))); if ($pp) $title = mb_substr((string)$pp['title'], 0, 255); }
    if ($title === '') throw new RuntimeException(t('ism.e_title'));
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
    $od0 = $old ? ism_data($old) : []; $u0 = current_user();
    /* links: a product to project / sub-project / vessel; a document or a release to its product */
    $L = ['parent_id' => null, 'project_id' => null, 'subproject_id' => null, 'vessel_id' => null];
    if (!empty($R['parent'])) { $pid = (int)($_POST['parent_id'] ?? ($old['parent_id'] ?? 0)); $P = $pid ? ism_get($pid) : null; if (!$P || $P['reg'] !== $R['parent']) throw new RuntimeException(t('ism.e_parent')); $L['parent_id'] = $pid; }
    if (!empty($R['links'])) {
        $pj = (int)($_POST['project_id'] ?? 0); $sb = (int)($_POST['subproject_id'] ?? 0); $vs = (int)($_POST['vessel_id'] ?? 0);
        if ($sb) { $x = one('SELECT id, project_id FROM subprojects WHERE id = ?', [$sb]); if (!$x || ($pj && (int)$x['project_id'] !== $pj)) $sb = 0; elseif (!$pj) $pj = (int)$x['project_id']; }
        if ($pj) { $x = one('SELECT id, vessel_id FROM projects WHERE id = ?', [$pj]); if (!$x) { $pj = 0; $sb = 0; } elseif (!$vs && $x['vessel_id']) $vs = (int)$x['vessel_id']; }
        if ($vs && !scalar('SELECT id FROM vessels WHERE id = ?', [$vs])) $vs = 0;
        $L = ['parent_id' => null, 'project_id' => $pj ?: null, 'subproject_id' => $sb ?: null, 'vessel_id' => $vs ?: null];
    }
    /* software release: approved by somebody other than the developer; no release or installation without an approval */
    if ($reg === 'msw') {
        foreach (['approved_by', 'approved_on'] as $k) if (isset($od0[$k])) $data[$k] = $od0[$k];
        $was = (string)($old['status'] ?? ''); $order = array_keys($R['st']); $ix = fn($s) => (int) array_search($s, $order, true);
        if (($data['version'] ?? '') === '') throw new RuntimeException(t('ism.e_version'));
        if ($status === 'approved' && $was !== 'approved') {
            if ($owner && $owner === (int)($u0['id'] ?? 0) && !ism_manage()) throw new RuntimeException(t('ism.e_own_release'));
            $data['approved_by'] = (string)($u0['full_name'] ?? ''); $data['approved_on'] = date('Y-m-d');
        }
        if (in_array($status, ['released', 'installed'], true) && empty($data['approved_by'])) throw new RuntimeException(t('ism.e_not_approved'));
        if ($ix($status) < $ix('approved')) unset($data['approved_by'], $data['approved_on']);   // back to development or test: the approval no longer stands
        if ($status === 'installed' && empty($data['installed_on'])) $data['installed_on'] = date('Y-m-d');
    }
    if ($reg === 'supplier') foreach (['rating', 'rating_result'] as $k) if (isset($od0[$k])) $data[$k] = $od0[$k];
    if ($reg === 'supeval') {
        $cr = array_filter(array_map(fn($k) => (int)($data[$k] ?? 0), ISM_EVAL_CRIT)); if ($cr) $data['rating'] = (int) round(100 * array_sum($cr) / (5 * count($cr)));
        if ($status === 'done') { if (count($cr) < count(ISM_EVAL_CRIT)) throw new RuntimeException(t('ism.e_eval_scores')); if (empty($data['evaluated_on'])) $data['evaluated_on'] = date('Y-m-d'); if (empty($data['evaluator'])) $data['evaluator'] = (string)($u0['full_name'] ?? '');
            if (empty($data['eval_result'])) $data['eval_result'] = $data['rating'] >= 70 ? 'res_approved' : ($data['rating'] >= 50 ? 'res_conditional' : 'res_rejected'); }
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
        if (ism_has_links()) { q('UPDATE ism_records SET parent_id = ?, project_id = ?, subproject_id = ?, vessel_id = ? WHERE id = ?', array_merge(array_values($L), [$id])); foreach (['project_id' => 'project', 'subproject_id' => 'sub-project', 'vessel_id' => 'vessel'] as $k => $lab) if (!empty($R['links']) && (int)($old[$k] ?? 0) !== (int)$L[$k]) $ch[] = $lab; }
        if ($ch) ism_event($id, $old['status'] !== $status ? 'status' : 'update', implode(' · ', $ch));
        if ($supId !== null || $reg === 'supplier') { if (ism_has_ext()) q('UPDATE ism_records SET supplier_id = ? WHERE id = ?', [$supId, $id]); }
        if ($reg === 'msw' && $old['status'] !== $status) ism_release_notify($id, $code, $title, (string)($data['version'] ?? ''), $status, $owner);
    } else {
        q('INSERT INTO ism_records (reg, code, title, status, owner_user_id, owner_name, due_date, review_date, ref, data, updated_name, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge([$reg], $row, [(int)($u['id'] ?? 0) ?: null, $row[9]]));
        $id = (int) db()->lastInsertId(); ism_event($id, 'create', $code);
        if (ism_has_links()) q('UPDATE ism_records SET parent_id = ?, project_id = ?, subproject_id = ?, vessel_id = ? WHERE id = ?', array_merge(array_values($L), [$id]));
        if ($supId !== null && ism_has_ext()) q('UPDATE ism_records SET supplier_id = ? WHERE id = ?', [$supId, $id]);
        if ($reg === 'mprod') foreach (ISM_E27_DOCS as $i => [$ref, $tt]) q("INSERT INTO ism_records (reg, code, title, status, ref, sort, data, parent_id, created_name) VALUES ('mdoc', ?, ?, 'missing', ?, ?, '{}', ?, 'IACS UR E27')", [$code . '-D' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT), $tt, 'UR E27 ' . $ref, $i + 1, $id]);
        if ($reg === 'msw' && $status !== 'development') ism_release_notify($id, $code, $title, (string)($data['version'] ?? ''), $status, $owner);
    }
    if ($reg === 'supeval' && $status === 'done') ism_eval_apply((int)$L['parent_id']);
    return $id;
}
/** A release that waits for review goes to the programming team and the ISMS people; an approved, released or installed one to its developer. */
function ism_release_notify(int $id, string $code, string $title, string $ver, string $status, int $owner): void {
    if (!function_exists('nf_push') || !in_array($status, ['review', 'approved', 'released', 'installed', 'withdrawn'], true)) return; $u = current_user();
    $to = $status === 'review' ? array_values(array_unique(array_merge(ism_mteam(), ism_team(), array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role = 'admin' LIMIT 5"), 'id'))))) : ($owner ? [$owner] : []); if (!$to) return;
    try { nf_push($to, 'ism:release', 'ism:' . $id . ':' . $status, t('ism.nf_release', ['c' => $code, 't' => mb_substr($title, 0, 70), 'v' => $ver, 's' => t('ism.s_' . $status)]), null, url('isms_rec', ['id' => $id]), (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
function ism_delete(int $id): string { if (!ism_manage()) throw new RuntimeException(t('err.noaccess')); $r = ism_get($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    if (ism_has_links()) foreach (all('SELECT id FROM ism_records WHERE parent_id = ?', [$id]) as $c) ism_delete((int)$c['id']);   // a product goes with its documents and releases
    foreach (ism_files($id) as $f) @unlink(ism_dir() . '/' . $f['stored_name']); q('DELETE FROM ism_files WHERE record_id = ?', [$id]); q('DELETE FROM ism_events WHERE record_id = ?', [$id]); q('DELETE FROM ism_records WHERE id = ?', [$id]); return (string)$r['reg']; }

/* ------------------------------------------------------------ files */
function ism_dir(): string { $d = dirname(__DIR__) . '/storage/isms'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function ism_file_add(int $rid, ?array $up, string $note): void {
    $r = ism_get($rid); if (!$r) throw new RuntimeException(t('err.notfound')); if (!ism_can_reg((string)$r['reg'])) throw new RuntimeException(t('err.noaccess'));
    if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) throw new RuntimeException(t('ism.e_file'));
    $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)); if ((int)$up['size'] > ISM_MAX_FILE || !in_array($ext, ISM_EXT, true)) throw new RuntimeException(t('ism.e_file'));
    $stored = bin2hex(random_bytes(16)) . '.' . $ext; if (!@move_uploaded_file((string)$up['tmp_name'], ism_dir() . '/' . $stored)) throw new RuntimeException(t('ism.e_file'));
    $u = current_user(); $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename((string)$up['name'])), 0, 190);
    q('INSERT INTO ism_files (record_id, stored_name, original_name, size_bytes, sha256, note, uploaded_by, uploaded_name) VALUES (?,?,?,?,?,?,?,?)', [$rid, $stored, $name, (int)$up['size'], (string) hash_file('sha256', ism_dir() . '/' . $stored), mb_substr(trim($note), 0, 255) ?: null, (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 120)]);
    ism_event($rid, 'file', $name);
}
function ism_file_delete(int $fid): int { $f = one('SELECT f.*, r.reg FROM ism_files f JOIN ism_records r ON r.id = f.record_id WHERE f.id = ?', [$fid]); if (!$f) throw new RuntimeException(t('err.notfound')); if (!ism_can_reg((string)$f['reg'])) throw new RuntimeException(t('err.noaccess')); @unlink(ism_dir() . '/' . $f['stored_name']); q('DELETE FROM ism_files WHERE id = ?', [$fid]); ism_event((int)$f['record_id'], 'file_del', (string)$f['original_name']); return (int)$f['record_id']; }
function ism_file_send(int $fid): void {
    $f = one('SELECT f.*, r.reg FROM ism_files f JOIN ism_records r ON r.id = f.record_id WHERE f.id = ?', [$fid]); if (!$f || !ism_can_reg((string)$f['reg'])) { http_response_code(403); exit; } $p = ism_dir() . '/' . $f['stored_name']; if (!is_file($p)) { http_response_code(404); exit; }
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
    foreach (ism_regs('iso') as $k => $R) $O['regs'][$k] = ['n' => 0, 'open' => 0, 'due' => 0, 'st' => array_fill_keys(array_keys($R['st']), 0)];
    foreach (all('SELECT * FROM ism_records ORDER BY COALESCE(due_date, review_date), id') as $r) {
        $k = (string)$r['reg']; $R = ism_reg($k); if (!$R || !isset($O['regs'][$k])) continue; $x = &$O['regs'][$k]; $x['n']++; if (isset($x['st'][$r['status']])) $x['st'][$r['status']]++;
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
    foreach (ism_regs('iso') as $k => $R) { $x = $O['regs'][$k]; $sum[] = [ism_reg_title($k), $R['clause'], $x['n'], $x['open'], $x['due']]; }
    $sheets[] = ['name' => 'Summary', 'first' => true, 'header' => [t('ism.register'), t('ism.clause'), t('ism.n_records'), t('ism.n_open'), t('ism.n_due')], 'rows' => $sum, 'widths' => [38, 20, 12, 12, 12]];
    foreach (ism_regs('iso') as $k => $R) {
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
            if (!$want || !$r[$col] || $r[$col] > $lim) continue; $to = $r['owner_user_id'] ? [(int)$r['owner_user_id']] : (($R['grp'] ?? '') === 'marine' ? array_values(array_unique(array_merge(ism_mteam(), $team))) : $team); if (!$to) continue;
            if ($r['reg'] === 'mdoc' && $r['status'] === 'missing' && !$r['due_date']) continue;
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
    if (!ism_can() && !ism_can_marine()) throw new RuntimeException(t('err.noaccess'));
    switch ($action) {
        case 'ism_save': $id = ism_save_from_post(); flash('good', t('ism.saved')); redirect('isms_rec', ['id' => $id]);
        case 'ism_delete': $x = ism_get((int)($_POST['id'] ?? 0)); $reg = ism_delete((int)($_POST['id'] ?? 0)); flash('good', t('ism.deleted')); if ($x && $x['parent_id']) redirect('isms_rec', ['id' => (int)$x['parent_id']]); redirect(ism_page($reg), ['reg' => $reg]);
        case 'ism_tr_create': $rid = ism_tr_create((int)($_POST['id'] ?? 0)); flash('good', t('ism.tr_created')); redirect('isms_rec', ['id' => $rid], '#hrtr');
        case 'ism_tr_link': $rid = ism_tr_link((int)($_POST['id'] ?? 0), (int)($_POST['hr_training_id'] ?? 0)); flash('good', t('ism.saved')); redirect('isms_rec', ['id' => $rid], '#hrtr');
        case 'ism_tr_attend': $rid = (int)($_POST['id'] ?? 0); $n = ism_tr_attend($rid, (array)($_POST['pid'] ?? []), !empty($_POST['close'])); flash('good', t('ism.tr_attended', ['n' => $n])); redirect('isms_rec', ['id' => $rid], '#hrtr');
        case 'ism_mteam_save': if (!ism_manage()) throw new RuntimeException(t('err.noaccess')); $ok = array_map('intval', array_column(ism_users(), 'id')); cp_setting_set('ism_mteam', json_encode(array_values(array_intersect(array_map('intval', (array)($_POST['team'] ?? [])), $ok)))); flash('good', t('ism.saved')); redirect('mcs');
        case 'ism_file_add': $rid = (int)($_POST['id'] ?? 0); ism_file_add($rid, $_FILES['file'] ?? null, (string)($_POST['note'] ?? '')); flash('good', t('ism.file_ok')); redirect('isms_rec', ['id' => $rid], '#files');
        case 'ism_file_del': $rid = ism_file_delete((int)($_POST['file_id'] ?? 0)); flash('good', t('ism.file_deleted')); redirect('isms_rec', ['id' => $rid], '#files');
        case 'ism_access_save': if (!ism_top()) throw new RuntimeException(t('err.noaccess'));
            $rs = array_map('intval', array_column(all('SELECT id FROM staff_roles'), 'id')); $ds = array_map('intval', array_column(all('SELECT id FROM disciplines'), 'id'));
            cp_setting_set('ism_access', json_encode(['roles' => array_values(array_intersect(array_map('intval', (array)($_POST['roles'] ?? [])), $rs)), 'disc' => array_values(array_intersect(array_map('intval', (array)($_POST['disc'] ?? [])), $ds))])); flash('good', t('ism.saved')); redirect('isms', [], '#access');
        case 'ism_team_save': if (!ism_can()) throw new RuntimeException(t('err.noaccess')); if (!ism_manage()) throw new RuntimeException(t('err.noaccess')); $ok = array_map('intval', array_column(ism_users(), 'id')); cp_setting_set('ism_team', json_encode(array_values(array_intersect(array_map('intval', (array)($_POST['team'] ?? [])), $ok)))); flash('good', t('ism.saved')); redirect('isms');
        default: throw new RuntimeException(t('err.notfound'));
    }
    return true;
}

/* ------------------------------------------------------------ R97-081 — marine cyber safety: overview, export, cards on the sub-project and vessel pages */
/** Every product with the state of its ten documents and of its software. $f: vessel, project, sub (ids). */
function ism_marine_products(array $f = []): array {
    if (!ism_has_links()) return []; $P = ism_list('mprod', $f); if (!$P) return [];
    $ids = array_map(fn($p) => (int)$p['id'], $P); $in = implode(',', $ids); $by = array_fill_keys($ids, ['docs' => 0, 'docs_ok' => 0, 'docs_na' => 0, 'sw' => null, 'pending' => 0, 'n_sw' => 0]);
    foreach (all("SELECT parent_id, reg, status, data, id FROM ism_records WHERE parent_id IN ($in) AND reg IN ('mdoc','msw') ORDER BY id") as $c) {
        $x = &$by[(int)$c['parent_id']];
        if ($c['reg'] === 'mdoc') { if ($c['status'] === 'na') $x['docs_na']++; else { $x['docs']++; if ($c['status'] === 'approved') $x['docs_ok']++; } }
        else { $x['n_sw']++; $d = ism_data($c); if (in_array($c['status'], ['released', 'installed'], true)) $x['sw'] = ['v' => (string)($d['version'] ?? ''), 'status' => (string)$c['status'], 'id' => (int)$c['id']]; elseif (in_array($c['status'], ['development', 'testing', 'review', 'approved'], true)) $x['pending']++; }
        unset($x);
    }
    foreach ($P as &$p) $p['_m'] = $by[(int)$p['id']]; unset($p);
    return $P;
}
function ism_marine_export(): void {
    if (!ism_can_marine()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php'; $sheets = [];
    $rows = []; foreach (ism_marine_products() as $p) { $d = ism_data($p); $m = $p['_m']; $rows[] = [$p['code'], $p['title'], (string)$p['vessel_name'], trim((string)$p['project_code'] . ' ' . (string)$p['project_name']), trim((string)$p['sub_code'] . ' ' . (string)$p['sub_name']), !empty($d['class_society']) ? t('ism.o_' . $d['class_society']) : '', !empty($d['route']) ? t('ism.o_' . $d['route']) : '', t('ism.s_' . $p['status']), (string)($d['class_ref'] ?? ''), (string)($d['cert_no'] ?? ''), (string)($d['cert_valid'] ?? ''), $m['docs_ok'] . ' / ' . $m['docs'], $m['sw'] ? $m['sw']['v'] : '', $m['pending'], (string)$p['owner_name'], (string)$p['due_date']]; }
    $sheets[] = ['name' => 'Products', 'first' => true, 'header' => [t('ism.code'), t('ism.title_f'), t('ism.vessel'), t('ism.project'), t('ism.subproject'), t('ism.f_class_society'), t('ism.f_route'), t('th.status'), t('ism.f_class_ref'), t('ism.f_cert_no'), t('ism.f_cert_valid'), t('ism.m_docs'), t('ism.m_sw'), t('ism.m_pending'), t('ism.owner'), t('ism.due_date')], 'rows' => $rows, 'widths' => [12, 40, 22, 30, 30, 14, 18, 16, 18, 18, 12, 12, 14, 10, 22, 12]];
    foreach (['mdoc', 'msw'] as $k) { $R = ism_reg($k);
        $h = [t('ism.product'), t('ism.vessel'), t('ism.project'), t('ism.code'), t('ism.title_f'), t('ism.ref'), t('th.status'), t($k === 'msw' ? 'ism.own_msw' : 'ism.owner'), t('ism.due_date')]; foreach ($R['f'] as $fk => $_) $h[] = t('ism.f_' . $fk); if ($k === 'msw') { $h[] = t('ism.f_approved_by'); $h[] = t('ism.f_approved_on'); } $h[] = t('ism.files'); $rows = [];
        foreach (ism_list($k) as $r) { $d = ism_data($r); $row = [trim((string)$r['parent_code'] . ' ' . (string)$r['parent_title']), (string)$r['vessel_name'], trim((string)$r['project_code'] . ' ' . (string)$r['project_name']), $r['code'], $r['title'], (string)$r['ref'], t('ism.s_' . $r['status']), (string)$r['owner_name'], (string)$r['due_date']]; foreach ($R['f'] as $fk => $type) { $v = $d[$fk] ?? ''; $row[] = str_starts_with($type, 'sel:') && $v !== '' ? t('ism.o_' . $v) : $v; } if ($k === 'msw') { $row[] = (string)($d['approved_by'] ?? ''); $row[] = (string)($d['approved_on'] ?? ''); } $row[] = (int)$r['n_files']; $rows[] = $row; }
        $sheets[] = ['name' => mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', ism_reg_title($k)), 0, 31), 'header' => $h, 'rows' => $rows, 'widths' => array_merge([34, 22, 28, 14, 40, 14, 16, 22, 12], array_fill(0, count($h) - 9, 22))];
    }
    xlsx_download('GAS-marine-cyber-safety_' . date('Y-m-d') . '.xlsx', $sheets);
}
/** Card on the sub-project page ($f = ['sub' => id, 'project' => id, 'vessel' => id]) and on the vessel page ($f = ['vessel' => id]). Nothing for those without access. */
function ism_marine_card(array $f): void {
    if (!function_exists('ism_can_marine') || !ism_can_marine()) return;
    $new = array_filter(['reg' => 'mprod', 'project' => (int)($f['project'] ?? 0), 'sub' => (int)($f['sub'] ?? 0), 'vessel' => (int)($f['vessel'] ?? 0)]);
    if (!empty($f['sub'])) { $P = array_merge(ism_marine_products(['sub' => (int)$f['sub']]), array_values(array_filter(ism_marine_products(['project' => (int)($f['project'] ?? 0)]), fn($p) => !$p['x_sub']))); } else $P = ism_marine_products(['vessel' => (int)($f['vessel'] ?? 0)]);
    echo '<div class="card" id="mcs"><div class="card-head"><h2>🚢 ' . tt('ism.m_card') . ' <span class="count">' . count($P) . '</span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="' . url('isms_rec', $new) . '">+ ' . tt('ism.m_new') . '</a></div></div>';
    if (!$P) echo '<p class="hint">' . tt('ism.m_card_none') . '</p>';
    else { echo '<div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th>' . tt('ism.product') . '</th>' . (empty($f['sub']) ? '<th>' . tt('ism.project') . '</th>' : '') . '<th>' . tt('th.status') . '</th><th>' . tt('ism.m_docs') . '</th><th>' . tt('ism.m_sw') . '</th></tr></thead><tbody>';
        foreach ($P as $p) { $m = $p['_m']; echo '<tr><td><a href="' . url('isms_rec', ['id' => (int)$p['id']]) . '"><b class="mono">' . e($p['code']) . '</b> ' . e($p['title']) . '</a></td>' . (empty($f['sub']) ? '<td>' . ($p['x_sub'] ? '<a href="' . url('subproject', ['id' => (int)$p['x_sub']]) . '">' . e(trim((string)$p['sub_code'] . ' ' . (string)$p['sub_name'])) . '</a>' : e((string)$p['project_name'])) . '</td>' : '') . '<td>' . ism_status_pill('mprod', (string)$p['status']) . '</td><td>' . pill($m['docs'] && $m['docs_ok'] === $m['docs'] ? 'green' : 'gold', $m['docs_ok'] . ' / ' . $m['docs']) . '</td><td>' . ($m['sw'] ? '<span class="mono">' . e($m['sw']['v']) . '</span>' : '<span class="hint">—</span>') . ($m['pending'] ? ' ' . pill('gold', '+' . $m['pending']) : '') . '</td></tr>'; }
        echo '</tbody></table></div>'; }
    echo '</div>';
}

/* ------------------------------------------------------------ R97-083 — suppliers of the supplier list and their evaluation; training kept in HR › Training */
/** The last finished evaluation of a supplier sets its rating, its state and the date of the next evaluation (12 months when critical or with access to confidential / personal information, else 24). */
function ism_eval_apply(int $supRecId): void {
    $S = ism_get($supRecId); if (!$S || $S['reg'] !== 'supplier') return; $last = null;
    foreach (ism_children($supRecId, 'supeval') as $e) { if ($e['status'] !== 'done') continue; $d = ism_data($e); if (!$last || (string)($d['evaluated_on'] ?? '') >= (string)(ism_data($last)['evaluated_on'] ?? '')) $last = $e; }
    if (!$last) return; $d = ism_data($last); $sd = ism_data($S); $on = (string)($d['evaluated_on'] ?? date('Y-m-d'));
    $sd['rating'] = (int)($d['rating'] ?? 0); $sd['rating_result'] = (string)($d['eval_result'] ?? ''); $sd['last_review'] = $on;
    $months = ((int)($sd['criticality'] ?? 0) >= 4 || in_array($sd['data_access'] ?? '', ['confidential', 'personal'], true)) ? 12 : 24;
    $st = $S['status'] === 'ended' ? 'ended' : (['res_approved' => 'active', 'res_conditional' => 'conditional', 'res_rejected' => 'blocked'][$sd['rating_result']] ?? $S['status']);
    q('UPDATE ism_records SET data = ?, status = ?, review_date = ?, updated_at = NOW() WHERE id = ?', [json_encode($sd, JSON_UNESCAPED_UNICODE), $st, date('Y-m-d', strtotime($on . ' +' . $months . ' months')), $supRecId]);
    ism_event($supRecId, $st !== $S['status'] ? 'status' : 'update', $last['code'] . ' · ' . $sd['rating'] . ' % · ' . ($sd['rating_result'] ? t('ism.o_' . $sd['rating_result']) : '') . ($st !== $S['status'] ? ' · ' . t('ism.s_' . $S['status']) . ' → ' . t('ism.s_' . $st) : ''));
}
/** The suppliers of the supplier list (Procurement › Suppliers) that can be chosen. */
function ism_supplier_options(): array { try { return function_exists('sup_where') ? all('SELECT co.id, co.name, co.city, co.country, co.is_active FROM companies co WHERE ' . sup_where() . ' ORDER BY co.name') : []; } catch (Throwable $e) { return []; } }
/** Card on Procurement › Suppliers for those of the ISMS: the state of the supplier in the ISO 27001 register. */
function ism_supplier_card(int $companyId): void {
    if (!function_exists('ism_can') || !ism_can() || !ism_has_ext() || !$companyId) return;
    $r = one("SELECT * FROM ism_records WHERE reg = 'supplier' AND supplier_id = ?", [$companyId]);
    echo '<div class="card" id="isms"><h2>🛡 ' . tt('ism.sup_card') . '</h2>';
    if (!$r) echo '<p class="hint">' . tt('ism.sup_card_none') . '</p><a class="btn btn--ghost btn--sm" href="' . url('isms_rec', ['reg' => 'supplier', 'supplier' => $companyId]) . '">+ ' . tt('ism.sup_card_add') . '</a>';
    else { $d = ism_data($r); $why = ism_due($r); echo '<p><a href="' . url('isms_rec', ['id' => (int)$r['id']]) . '"><b class="mono">' . e($r['code']) . '</b></a> ' . ism_status_pill('supplier', (string)$r['status']) . (isset($d['rating']) ? ' ' . pill((int)$d['rating'] >= 70 ? 'green' : ((int)$d['rating'] >= 50 ? 'gold' : 'bad'), (int)$d['rating'] . ' %') : '') . ($why ? ' ' . pill('bad', t('ism.sup_eval_due')) : '') . '</p><p class="hint">' . (!empty($d['last_review']) ? tt('ism.f_last_review') . ': ' . e(fmt_date($d['last_review'])) . ' · ' : '') . ($r['review_date'] ? tt('ism.sup_next') . ': ' . e(fmt_date($r['review_date'])) : tt('ism.sup_never')) . '</p>'; }
    echo '</div>';
}
/** HR training behind an ISO training record, with its members. */
function ism_tr(array $r): ?array { if (!ism_has_ext() || empty($r['hr_training_id']) || !function_exists('hr_tr_has') || !hr_tr_has()) return null; $t = one('SELECT * FROM hr_trainings WHERE id = ?', [(int)$r['hr_training_id']]); if (!$t) return null; $t['members'] = all('SELECT m.person_id, m.status, p.name_en FROM hr_training_members m JOIN hr_people p ON p.id = m.person_id WHERE m.training_id = ? ORDER BY p.name_en', [(int)$t['id']]); return $t; }
function ism_tr_rec(int $rid): array { $r = ism_get($rid); if (!$r || $r['reg'] !== 'training' || !ism_can() || !ism_has_ext() || !function_exists('hr_tr_has') || !hr_tr_has()) throw new RuntimeException(t('err.noaccess')); return $r; }
/** Create the training in HR › Training from the ISO record (internal, offered to everybody) and link the two. */
function ism_tr_create(int $rid): int {
    $r = ism_tr_rec($rid); if (!empty($r['hr_training_id']) && scalar('SELECT id FROM hr_trainings WHERE id = ?', [(int)$r['hr_training_id']])) return $rid; $d = ism_data($r); $u = current_user();
    q("INSERT INTO hr_trainings (kind, title, provider, trainer, date_from, date_to, description, target, status, created_by) VALUES ('internal', ?, 'Golden A/S — ISO 27001', ?, ?, ?, ?, 'all', ?, ?)", [mb_substr((string)$r['title'], 0, 160), mb_substr((string)($d['trainer'] ?? ''), 0, 120) ?: null, $r['due_date'], $r['due_date'], mb_substr(trim('ISO/IEC 27001 — ' . $r['code'] . "\n" . (string)($d['audience'] ?? '') . "\n" . (string)($d['notes'] ?? '')), 0, 8000), $r['status'] === 'done' ? 'done' : 'closed', (int)($u['id'] ?? 0) ?: null]);
    $tid = (int) db()->lastInsertId(); q('UPDATE ism_records SET hr_training_id = ? WHERE id = ?', [$tid, $rid]); ism_event($rid, 'update', 'HR › Training #' . $tid); return $rid;
}
function ism_tr_link(int $rid, int $tid): int { $r = ism_tr_rec($rid); if ($tid && !scalar('SELECT id FROM hr_trainings WHERE id = ?', [$tid])) throw new RuntimeException(t('err.notfound')); q('UPDATE ism_records SET hr_training_id = ? WHERE id = ?', [$tid ?: null, $rid]); ism_event($rid, 'update', 'HR › Training ' . ($tid ? '#' . $tid : '—')); return $rid; }
/** Attendance: the ticked people attended (written to HR › Training, so it shows in each personnel record); those un-ticked who were marked attended become absent. Returns the number who attended. */
function ism_tr_attend(int $rid, array $pids, bool $close): int {
    $r = ism_tr_rec($rid); $t = ism_tr($r); if (!$t) throw new RuntimeException(t('err.notfound')); $tid = (int)$t['id']; $me = (int)(current_user()['id'] ?? 0);
    $ok = array_map('intval', array_column(all('SELECT id FROM hr_people WHERE is_active = 1'), 'id')); $pids = array_values(array_intersect(array_unique(array_map('intval', $pids)), $ok));
    foreach ($pids as $pid) q("INSERT INTO hr_training_members (training_id, person_id, status, applied_at, decided_by, decided_at) VALUES (?, ?, 'attended', NOW(), ?, NOW()) ON DUPLICATE KEY UPDATE status = 'attended', decided_by = VALUES(decided_by), decided_at = NOW()", [$tid, $pid, $me ?: null]);
    foreach ($t['members'] as $m) if ($m['status'] === 'attended' && !in_array((int)$m['person_id'], $pids, true)) q("UPDATE hr_training_members SET status = 'absent', decided_by = ?, decided_at = NOW() WHERE training_id = ? AND person_id = ?", [$me ?: null, $tid, (int)$m['person_id']]);
    $n = (int) scalar("SELECT COUNT(*) FROM hr_training_members WHERE training_id = ? AND status = 'attended'", [$tid]); $d = ism_data($r); $d['attendees'] = $n;
    q('UPDATE ism_records SET data = ?' . ($close ? ", status = 'done'" : '') . ', updated_at = NOW() WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $rid]); if ($close) q("UPDATE hr_trainings SET status = 'done' WHERE id = ?", [$tid]);
    ism_event($rid, $close && $r['status'] !== 'done' ? 'status' : 'update', t('ism.f_attendees') . ': ' . $n . ($close && $r['status'] !== 'done' ? ' · ' . t('ism.s_' . $r['status']) . ' → ' . t('ism.s_done') : '')); return $n;
}
/** Line on the HR training page: the ISO 27001 record it belongs to. */
function ism_tr_badge(int $hrTrainingId): string { if (!function_exists('ism_has_ext') || !ism_has_ext()) return ''; $r = one("SELECT id, code, title FROM ism_records WHERE reg = 'training' AND hr_training_id = ?", [$hrTrainingId]); if (!$r) return ''; return '<p class="hint">🛡 ' . tt('ism.tr_badge') . ' ' . (ism_can() ? '<a href="' . url('isms_rec', ['id' => (int)$r['id']]) . '">' . e($r['code']) . '</a>' : e($r['code'])) . '</p>'; }
