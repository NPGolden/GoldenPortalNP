<?php
/** R67-001 · HR Assistant — guided flows with a staging area approved by HR: the employee completes / corrects his own card step by step
 *  (identity · contact · identification & bank · education · documents · review), the assistant helps (explain, free-text extraction, document reading),
 *  nothing reaches hr_people before HR approves field by field. Engine ready for the hiring, leaving and dismissal flows (R67-002 / R67-003).
 *  GAS-CP-R67-001 */
declare(strict_types=1);

const HRA_KINDS      = ['card', 'hire', 'leave', 'dismiss'];
const HRA_OPEN       = ['draft', 'submitted', 'manager', 'hr', 'ceo'];
const HRA_PURGE_DAYS = 90;
/** Fields an employee may propose for his own card (never: salary, payroll code, ΟΑΕΔ, grade, hiring date, prior service, contract, department). */
const HRA_EMP_FIELDS = ['name_el', 'father_name', 'mother_name', 'birth_date', 'birth_place', 'nationality', 'gender', 'marital', 'spouse_name', 'children', 'children_years',
    'personal_email', 'phone_mobile', 'phone_home', 'address', 'postcode', 'city', 'country', 'emergency_name', 'emergency_phone',
    'amka', 'afm', 'doy', 'id_number', 'passport_no', 'iban', 'bank', 'blood_group', 'education', 'transport', 'phone_work', 'extension'];
const HRA_DOC_KINDS  = ['id', 'certificate', 'contract', 'other'];

/* ------------------------------------------------------------------ presence & access */
function hra_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_flows'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** HR / Owner review and apply flows (they see the personal values); plain administrators only see counts. */
function hra_can_manage(): bool { return hra_has() && function_exists('hr_personal_can') && hr_personal_can(); }
/** Any staff account linked to an employee card may complete its own card. */
function hra_can_use(): bool { return hra_has() && is_staff() && (bool) hr_person_of_user((int)(current_user()['id'] ?? 0)); }
function hra_can_view(array $f): bool { $me = (int)(current_user()['id'] ?? 0); if (hra_can_manage()) return true; return !$f['confidential'] && $me && (int)$f['user_id'] === $me; }

/* ------------------------------------------------------------------ encryption at rest (staged secret values) */
function hra_key(): string {
    $salt = (string) cp_setting('hra_salt', ''); if ($salt === '') { $salt = bin2hex(random_bytes(32)); cp_setting_set('hra_salt', $salt); }
    return sodium_crypto_generichash('gascp-hra|' . lic_instance_id() . '|' . (string) cfg('app.session_name', 'GASCP') . '|' . $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}
function hra_enc(?string $v): ?string { if ($v === null) return null; $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($n . sodium_crypto_secretbox($v, $n, hra_key())); }
function hra_dec(?string $v): ?string { if ($v === null || $v === '') return $v; $raw = base64_decode($v, true); if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null; $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hra_key()); return $p === false ? null : $p; }
function hra_is_secret(string $field): bool { return in_array($field, HR_PERSONAL_SECRET, true); }

/* ------------------------------------------------------------------ field catalogue */
/** type · max · label key · options (select) · hint key; the order here is the order on the review page. */
function hra_field_meta(): array {
    static $m = null; if ($m !== null) return $m;
    $m = [
        'name_el' => ['text', 120, 'hr.name_el'], 'father_name' => ['text', 80, 'hr.father_name'], 'mother_name' => ['text', 80, 'hr.mother_name'],
        'birth_date' => ['date', 10, 'hr.birth_date'], 'birth_place' => ['text', 80, 'hr.birth_place'], 'nationality' => ['text', 60, 'hr.nationality'],
        'gender' => ['select', 1, 'hr.gender', ['' => 'hr.g_na', 'm' => 'hr.g_m', 'f' => 'hr.g_f']], 'marital' => ['select', 12, 'hr.marital', ['' => '—'] + array_combine(HR_MARITAL, array_map(fn($x) => 'hr.ms_' . $x, HR_MARITAL))],
        'spouse_name' => ['text', 80, 'hr.spouse_name'], 'children' => ['number', 2, 'hr.children'], 'children_years' => ['text', 60, 'hra.f_children_years', null, 'hra.h_children_years'],
        'personal_email' => ['email', 190, 'hr.personal_email'], 'phone_mobile' => ['tel', 40, 'hr.phone_mobile'], 'phone_home' => ['tel', 40, 'hr.phone_home'],
        'address' => ['text', 200, 'hr.address'], 'postcode' => ['text', 12, 'hr.postcode'], 'city' => ['text', 80, 'hr.city'], 'country' => ['text', 60, 'hr.country_res'],
        'emergency_name' => ['text', 120, 'hr.emergency_name'], 'emergency_phone' => ['tel', 40, 'hr.emergency_phone'],
        'amka' => ['text', 11, 'hr.amka', null, 'hra.h_amka'], 'afm' => ['text', 9, 'hr.afm', null, 'hra.h_afm'], 'doy' => ['text', 80, 'hr.doy'], 'id_number' => ['text', 30, 'hr.id_number'],
        'passport_no' => ['text', 30, 'hr.passport_no'], 'iban' => ['text', 34, 'hr.iban', null, 'hra.h_iban'], 'bank' => ['text', 80, 'hr.bank'], 'blood_group' => ['select', 5, 'hr.blood_group', ['' => '—', 'A+' => 'A+', 'A-' => 'A-', 'B+' => 'B+', 'B-' => 'B-', 'AB+' => 'AB+', 'AB-' => 'AB-', 'O+' => 'O+', 'O-' => 'O-']],
        'education' => ['text', 120, 'hr.education'], 'transport' => ['text', 60, 'hr.transport'], 'phone_work' => ['tel', 40, 'hr.phone_work'], 'extension' => ['text', 10, 'hr.extension'],
    ];
    return $m;
}
function hra_label(string $field): string { $m = hra_field_meta()[$field] ?? null; return $m ? t($m[2]) : $field; }
/** Value of a field as it is on the card now (normalised string, '' when empty). */
function hra_current(array $p, string $field): string { $v = $p[$field] ?? null; if ($v === null) return ''; if ($field === 'children') return (string)(int)$v; return trim((string)$v); }
/** Display form of a value: option label for selects, masked for secrets when $mask. */
function hra_show(string $field, ?string $v, bool $mask = false): string {
    $v = (string)($v ?? ''); if ($v === '') return '—'; $m = hra_field_meta()[$field] ?? null;
    if ($m && $m[0] === 'select' && isset($m[3][$v])) return $m[3][$v] === '—' ? '—' : (str_contains((string)$m[3][$v], '.') ? t((string)$m[3][$v]) : (string)$m[3][$v]);
    return $mask && hra_is_secret($field) ? hr_mask($v) : $v;
}

/** Validate and normalise ONE proposed value; throws RuntimeException with a translated message. */
function hra_validate(string $field, string $v, array $p): string {
    $v = trim($v); $m = hra_field_meta()[$field] ?? null; if (!$m || !in_array($field, HRA_EMP_FIELDS, true)) throw new RuntimeException(t('err.noaccess'));
    if ($v === '') return '';
    if (in_array($field, HR_PERSONAL, true)) { $o = hr_personal_from([$field => $v]); $v = (string)($o[$field] ?? ''); }
    switch ($field) {
        case 'gender': if (!in_array($v, ['m', 'f'], true)) throw new RuntimeException(t('hra.bad_value')); break;
        case 'marital': if (!in_array($v, HR_MARITAL, true)) throw new RuntimeException(t('hra.bad_value')); break;
        case 'blood_group': if (!isset($m[3][$v])) throw new RuntimeException(t('hra.bad_value')); break;
        case 'children': if (!ctype_digit($v) || (int)$v > 12) throw new RuntimeException(t('hra.bad_value')); $v = (string)(int)$v; break;
        case 'children_years': if (!preg_match('/^\d{4}(\s*[,;\/ ]\s*\d{4})*$/', $v)) throw new RuntimeException(t('hra.bad_years')); $v = implode(', ', preg_split('/[^\d]+/', $v, -1, PREG_SPLIT_NO_EMPTY)); break;
        case 'birth_date': if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v > date('Y-m-d', strtotime('-15 years')) || $v < '1930-01-01') throw new RuntimeException(t('hra.bad_birth')); break;
        case 'afm': if (!hra_afm_ok($v)) throw new RuntimeException(t('hra.bad_afm_check')); break;
        case 'amka': $bd = (string)($p['birth_date'] ?? ''); if ($bd !== '' && substr($v, 0, 6) !== date('dmy', strtotime($bd))) throw new RuntimeException(t('hra.bad_amka_date')); break;
    }
    return mb_substr($v, 0, max(1, (int)$m[1]));
}
/** Greek ΑΦΜ check digit (mod 11). */
function hra_afm_ok(string $afm): bool { if (!preg_match('/^\d{9}$/', $afm) || $afm === '000000000') return false; $s = 0; for ($i = 0; $i < 8; $i++) $s += (int)$afm[$i] * (2 ** (8 - $i)); return ($s % 11) % 10 === (int)$afm[8]; }

/* ------------------------------------------------------------------ templates (Settings › HR › HR Assistant) */
function hra_default_template(string $kind): array {
    return match ($kind) {
        'card' => ['steps' => [
            ['key' => 'identity', 'icon' => '🪪', 'fields' => ['name_el', 'father_name', 'mother_name', 'birth_date', 'birth_place', 'nationality', 'gender', 'marital', 'spouse_name', 'children', 'children_years']],
            ['key' => 'contact', 'icon' => '📍', 'fields' => ['personal_email', 'phone_mobile', 'phone_home', 'address', 'postcode', 'city', 'country', 'emergency_name', 'emergency_phone']],
            ['key' => 'ids', 'icon' => '🔐', 'stepup' => true, 'fields' => ['amka', 'afm', 'doy', 'id_number', 'passport_no', 'iban', 'bank', 'blood_group']],
            ['key' => 'work', 'icon' => '🎓', 'fields' => ['education', 'transport', 'phone_work', 'extension']],
            ['key' => 'docs', 'icon' => '📎', 'docs' => ['id', 'certificate', 'other']],
            ['key' => 'review', 'icon' => '✅', 'review' => true],
        ], 'required' => ['personal_email', 'phone_mobile', 'address', 'birth_date', 'amka', 'afm', 'iban', 'emergency_name', 'emergency_phone'], 'approvers' => ['hr']],
        default => ['steps' => [], 'required' => [], 'approvers' => ['hr', 'ceo']],
    };
}
/** Effective template: the stored one for the branch, else '*', else the default — always sanitised against the field catalogue. */
function hra_template(string $kind, string $branch = 'GR'): array {
    $def = hra_default_template($kind);
    if (!hra_has()) return $def;
    $row = one("SELECT definition FROM hr_flow_templates WHERE kind = ? AND branch IN (?, '*') AND is_active = 1 ORDER BY branch = '*' LIMIT 1", [$kind, $branch]);
    if (!$row) return $def;
    $t = json_decode((string)$row['definition'], true); if (!is_array($t) || empty($t['steps'])) return $def;
    return hra_template_clean($t, $def);
}
function hra_template_clean(array $t, array $def): array {
    $meta = hra_field_meta(); $steps = []; $seen = [];
    foreach ((array)($t['steps'] ?? []) as $s) {
        if (!is_array($s) || empty($s['key']) || !preg_match('/^[a-z0-9_]{1,24}$/', (string)$s['key'])) continue;
        $o = ['key' => (string)$s['key'], 'icon' => mb_substr((string)($s['icon'] ?? '•'), 0, 4)];
        if (!empty($s['review'])) $o['review'] = true;
        elseif (isset($s['docs'])) $o['docs'] = array_values(array_intersect(array_map('strval', (array)$s['docs']), HRA_DOC_KINDS)) ?: ['other'];
        else { $f = []; foreach ((array)($s['fields'] ?? []) as $k) { $k = (string)$k; if (isset($meta[$k]) && in_array($k, HRA_EMP_FIELDS, true) && !isset($seen[$k])) { $f[] = $k; $seen[$k] = 1; } } if (!$f) continue; $o['fields'] = $f; if (!empty($s['stepup'])) $o['stepup'] = true; }
        if (!empty($s['title'])) $o['title'] = mb_substr((string)$s['title'], 0, 80);
        $steps[] = $o;
    }
    if (!$steps) return $def;
    if (!array_filter($steps, fn($s) => !empty($s['review']))) $steps[] = ['key' => 'review', 'icon' => '✅', 'review' => true];
    return ['steps' => $steps, 'required' => array_values(array_intersect(array_map('strval', (array)($t['required'] ?? [])), $seen ? array_keys($seen) : [])), 'approvers' => array_values(array_intersect(array_map('strval', (array)($t['approvers'] ?? ['hr'])), ['manager', 'hr', 'ceo'])) ?: ['hr']];
}
function hra_templates(): array { return hra_has() ? all('SELECT * FROM hr_flow_templates ORDER BY kind, branch') : []; }
function hra_template_save_from_post(): void {
    if (!hra_can_manage()) throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($_POST['kind'] ?? '', HRA_KINDS, true) ? $_POST['kind'] : 'card'; $branch = preg_match('/^(\*|[A-Z]{2,4})$/', (string)($_POST['branch'] ?? '*')) ? $_POST['branch'] : '*';
    $json = trim((string)($_POST['definition'] ?? '')); $t = json_decode($json, true); if (!is_array($t)) throw new RuntimeException(t('hra.tpl_bad_json'));
    $clean = hra_template_clean($t, hra_default_template($kind)); if ($clean === hra_default_template($kind) && empty($t['steps'])) throw new RuntimeException(t('hra.tpl_bad_json'));
    $me = (int)(current_user()['id'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: ucfirst($kind) . ' ' . $branch;
    q('INSERT INTO hr_flow_templates (kind, branch, name, definition, is_active, updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), definition = VALUES(definition), is_active = VALUES(is_active), updated_by = VALUES(updated_by)',
      [$kind, $branch, $name, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), (int)!empty($_POST['is_active']), $me]);
    if (function_exists('cp_log')) try { cp_log('hra_template', "$kind/$branch"); } catch (Throwable $e) {}
}

/* ------------------------------------------------------------------ flows */
function hra_code(): string { $y = date('Y'); q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['hra', $y]); return 'HRA-' . $y . '-' . str_pad((string)(int) db()->lastInsertId(), 4, '0', STR_PAD_LEFT); }
function hra_flow(int $id): ?array { return hra_has() ? one('SELECT f.*, p.name_en, p.name_el, p.emp_code, p.jurisdiction, u.full_name AS user_name, u.email AS user_email, u.totp_enabled FROM hr_flows f LEFT JOIN hr_people p ON p.id = f.person_id LEFT JOIN customer_users u ON u.id = f.user_id WHERE f.id = ?', [$id]) : null; }
/** The open flow of a user for a kind (one at a time). */
function hra_my_flow(int $uid, string $kind = 'card'): ?array { if (!hra_has() || !$uid) return null; $r = one('SELECT id FROM hr_flows WHERE user_id = ? AND kind = ? AND status IN (' . implode(',', array_fill(0, count(HRA_OPEN), '?')) . ') ORDER BY id DESC LIMIT 1', [$uid, $kind, ...HRA_OPEN]); return $r ? hra_flow((int)$r['id']) : null; }
function hra_my_flows(int $uid, int $limit = 10): array { return hra_has() && $uid ? all('SELECT * FROM hr_flows WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$limit, [$uid]) : []; }
function hra_log(int $flowId, string $event, ?string $detail = null): void { try { q('INSERT INTO hr_flow_log (flow_id, user_id, event, detail, ip) VALUES (?,?,?,?,?)', [$flowId, (int)(current_user()['id'] ?? 0) ?: null, mb_substr($event, 0, 24), $detail !== null ? mb_substr($detail, 0, 300) : null, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]); } catch (Throwable $e) {} }
function hra_log_list(int $flowId): array { return all('SELECT l.*, u.full_name FROM hr_flow_log l LEFT JOIN customer_users u ON u.id = l.user_id WHERE l.flow_id = ? ORDER BY l.id', [$flowId]); }

/** Open a "My card" flow for the signed-in employee (or return the open one). */
function hra_start_card(): int {
    if (!hra_can_use()) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $me = (int)$u['id']; $p = hr_person_of_user($me); if (!$p) throw new RuntimeException(t('hr.no_person'));
    if ($f = hra_my_flow($me, 'card')) return (int)$f['id'];
    q('INSERT INTO hr_flows (code, kind, person_id, user_id, branch, status, step, title, opened_by) VALUES (?,?,?,?,?,?,?,?,?)', [hra_code(), 'card', (int)$p['id'], $me, (string)($p['jurisdiction'] ?? 'GR'), 'draft', 0, (string)$p['name_en'], $me]);
    $id = (int) db()->lastInsertId(); hra_log($id, 'opened', 'card'); return $id;
}
function hra_consent(array $f): void { if ((int)$f['user_id'] !== (int)(current_user()['id'] ?? 0) || $f['status'] !== 'draft') throw new RuntimeException(t('err.noaccess')); if (empty($_POST['consent'])) throw new RuntimeException(t('hra.consent_need')); q('UPDATE hr_flows SET consent_at = NOW(), consent_ip = ?, step = GREATEST(step, 1) WHERE id = ? AND consent_at IS NULL', [mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (int)$f['id']]); hra_log((int)$f['id'], 'consent'); }

/** Proposed fields of a flow, decrypted: [field => row + old/new plain]. */
function hra_fields(int $flowId): array {
    $out = [];
    foreach (all('SELECT * FROM hr_flow_fields WHERE flow_id = ? ORDER BY id', [$flowId]) as $r) { if ($r['enc']) { $r['old_value'] = hra_dec($r['old_value']); $r['new_value'] = hra_dec($r['new_value']); } $out[$r['field']] = $r; }
    $order = array_flip(array_keys(hra_field_meta())); uksort($out, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99)); return $out;
}
/** Stage one value: equal to the card → the proposal is dropped; different → upserted with the card value as "old". */
function hra_set(array $f, array $p, string $field, string $value, string $source = 'user'): void {
    $new = hra_validate($field, $value, $p); $cur = hra_current($p, $field); $enc = hra_is_secret($field);
    if ($new === $cur) { q('DELETE FROM hr_flow_fields WHERE flow_id = ? AND field = ?', [(int)$f['id'], $field]); return; }
    q('INSERT INTO hr_flow_fields (flow_id, field, old_value, new_value, enc, source, decision) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE old_value = VALUES(old_value), new_value = VALUES(new_value), enc = VALUES(enc), source = VALUES(source), decision = \'pending\', hr_note = NULL',
      [(int)$f['id'], $field, $enc ? hra_enc($cur) : $cur, $enc ? hra_enc($new) : $new, (int)$enc, in_array($source, ['user', 'assistant', 'document', 'hr'], true) ? $source : 'user', 'pending']);
}
/** Save the fields of one wizard step from $_POST (filling side). */
function hra_step_save_from_post(array $f): int {
    $me = (int)(current_user()['id'] ?? 0); if ((int)$f['user_id'] !== $me || $f['status'] !== 'draft') throw new RuntimeException(t('err.noaccess'));
    $p = hr_person((int)$f['person_id']); if (!$p) throw new RuntimeException(t('hr.no_person'));
    $tpl = hra_template($f['kind'], (string)$f['branch']); $i = (int)($_POST['step'] ?? 0); $step = $tpl['steps'][$i] ?? null; if (!$step || empty($step['fields'])) throw new RuntimeException(t('err.notfound'));
    if (!empty($step['stepup'])) hra_stepup_check();
    $src = (array)($_POST['src'] ?? []);
    foreach ($step['fields'] as $k) { if (!array_key_exists($k, $_POST['f'] ?? [])) continue; hra_set($f, $p, $k, (string)$_POST['f'][$k], in_array($src[$k] ?? 'user', ['assistant', 'document'], true) ? $src[$k] : 'user'); }
    $next = min($i + 2, count($tpl['steps'])); q('UPDATE hr_flows SET step = GREATEST(step, ?) WHERE id = ?', [$next, (int)$f['id']]); hra_log((int)$f['id'], 'step', (string)$step['key']);
    return $i + 1;
}
/** Authenticator step-up for accounts with 2FA: the 6-digit code must accompany the request. */
function hra_stepup_check(): void {
    $u = current_user(); if (empty($u['totp_enabled'])) return;
    $code = preg_replace('/\D/', '', (string)($_POST['totp'] ?? '')); if (strlen($code) !== 6 || !totp_verify((string)$u['totp_secret'], $code)) throw new RuntimeException(t('hra.totp_need'));
}
/** Missing required fields (card value empty and no pending proposal). */
function hra_missing(array $f, array $p, array $tpl, ?array $fields = null): array { $fields ??= hra_fields((int)$f['id']); $miss = []; foreach ($tpl['required'] as $k) { $v = isset($fields[$k]) ? (string)$fields[$k]['new_value'] : hra_current($p, $k); if ($v === '') $miss[] = $k; } return $miss; }
/** Completeness of a card over the employee-editable fields (for My leave and the dashboard). */
function hra_completeness(array $p): array { $n = 0; $miss = []; foreach (HRA_EMP_FIELDS as $k) { if (hra_current($p, $k) !== '') $n++; else $miss[] = $k; } return ['pct' => (int) round(100 * $n / count(HRA_EMP_FIELDS)), 'missing' => $miss]; }

function hra_submit(array $f): void {
    $me = (int)(current_user()['id'] ?? 0); if ((int)$f['user_id'] !== $me || $f['status'] !== 'draft') throw new RuntimeException(t('err.noaccess'));
    if (!$f['consent_at']) throw new RuntimeException(t('hra.consent_need'));
    $p = hr_person((int)$f['person_id']); $tpl = hra_template($f['kind'], (string)$f['branch']); $fields = hra_fields((int)$f['id']);
    if ($miss = hra_missing($f, $p, $tpl, $fields)) throw new RuntimeException(t('hra.missing', ['v' => implode(', ', array_map('hra_label', $miss))]));
    if (!$fields && !scalar('SELECT COUNT(*) FROM hr_flow_docs WHERE flow_id = ?', [(int)$f['id']])) throw new RuntimeException(t('hra.nothing'));
    if (empty($_POST['declare'])) throw new RuntimeException(t('hra.declare_need'));
    hra_stepup_check();
    q("UPDATE hr_flows SET status = 'hr', submitted_at = NOW(), assignee_id = NULL WHERE id = ?", [(int)$f['id']]); hra_log((int)$f['id'], 'submitted', count($fields) . ' fields'); hra_notify(hra_flow((int)$f['id']), 'submitted');
}
function hra_withdraw(array $f): void { $me = (int)(current_user()['id'] ?? 0); if ((int)$f['user_id'] !== $me || !in_array($f['status'], ['draft', 'hr', 'submitted'], true)) throw new RuntimeException(t('err.noaccess')); q("UPDATE hr_flows SET status = 'withdrawn', decided_at = NOW(), purge_after = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?", [HRA_PURGE_DAYS, (int)$f['id']]); hra_log((int)$f['id'], 'withdrawn'); }

/* ------------------------------------------------------------------ HR review */
function hra_queue(string $which = 'open'): array {
    if (!hra_has()) return [];
    $w = $which === 'open' ? "f.status IN ('submitted','manager','hr','ceo')" : ($which === 'draft' ? "f.status = 'draft'" : "f.status IN ('applied','rejected','withdrawn')");
    return all("SELECT f.*, p.name_en, p.emp_code, u.full_name AS user_name, (SELECT COUNT(*) FROM hr_flow_fields x WHERE x.flow_id = f.id) AS n_fields, (SELECT COUNT(*) FROM hr_flow_docs d WHERE d.flow_id = f.id) AS n_docs FROM hr_flows f LEFT JOIN hr_people p ON p.id = f.person_id LEFT JOIN customer_users u ON u.id = f.user_id WHERE $w ORDER BY f.submitted_at DESC, f.id DESC LIMIT 200");
}
function hra_pending_count(): int { try { return hra_has() ? (int) scalar("SELECT COUNT(*) FROM hr_flows WHERE status IN ('submitted','manager','hr','ceo')") : 0; } catch (Throwable $e) { return 0; } }
function hra_field_decide(array $f, string $field, string $decision, ?string $note): void { if (!hra_can_manage() || !in_array($f['status'], ['hr', 'submitted'], true) || !in_array($decision, ['approved', 'rejected', 'pending'], true)) throw new RuntimeException(t('err.noaccess')); q('UPDATE hr_flow_fields SET decision = ?, hr_note = ? WHERE flow_id = ? AND field = ?', [$decision, $note !== null ? mb_substr($note, 0, 200) : null, (int)$f['id'], $field]); hra_log((int)$f['id'], 'field_' . $decision, $field); }
/** Send the flow back to the employee with a note (status → draft). */
function hra_return(array $f, string $note): void { if (!hra_can_manage() || !in_array($f['status'], ['hr', 'submitted'], true)) throw new RuntimeException(t('err.noaccess')); if (trim($note) === '') throw new RuntimeException(t('hra.note_need')); q("UPDATE hr_flows SET status = 'draft', decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [mb_substr($note, 0, 500), (int)(current_user()['id'] ?? 0), (int)$f['id']]); hra_log((int)$f['id'], 'returned', $note); hra_notify(hra_flow((int)$f['id']), 'returned'); }
function hra_reject(array $f, string $note): void { if (!hra_can_manage() || !in_array($f['status'], ['hr', 'submitted'], true)) throw new RuntimeException(t('err.noaccess')); if (trim($note) === '') throw new RuntimeException(t('hra.note_need')); q("UPDATE hr_flows SET status = 'rejected', decision_note = ?, decided_by = ?, decided_at = NOW(), purge_after = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?", [mb_substr($note, 0, 500), (int)(current_user()['id'] ?? 0), HRA_PURGE_DAYS, (int)$f['id']]); hra_log((int)$f['id'], 'rejected', $note); hra_notify(hra_flow((int)$f['id']), 'rejected'); }

/** Approve: every field not rejected is written to the card through the portal's own save path, documents are filed, the audit is written. */
function hra_apply(array $f, ?string $note): void {
    if (!hra_can_manage() || !in_array($f['status'], ['hr', 'submitted'], true)) throw new RuntimeException(t('err.noaccess'));
    $pid = (int)$f['person_id']; $before = hr_person($pid); if (!$before) throw new RuntimeException(t('hr.no_person'));
    $fields = hra_fields((int)$f['id']); $apply = []; $rej = [];
    foreach ($fields as $k => $r) { if ($r['decision'] === 'rejected') { $rej[] = $k; continue; } $apply[$k] = hra_validate($k, (string)$r['new_value'], $before); }
    db()->beginTransaction();
    try {
        if ($apply) hra_write_card($pid, $before, $apply);
        q("UPDATE hr_flow_fields SET decision = 'approved' WHERE flow_id = ? AND decision <> 'rejected'", [(int)$f['id']]);
        $nDocs = hra_docs_file($f, $pid);
        q("UPDATE hr_flows SET status = 'applied', decision_note = ?, decided_by = ?, decided_at = NOW(), purge_after = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?", [$note !== null ? mb_substr($note, 0, 500) : null, (int)(current_user()['id'] ?? 0), HRA_PURGE_DAYS, (int)$f['id']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    $after = hr_person($pid) ?? []; if (function_exists('hr_audit_card')) hr_audit_card($pid, $before, $after, 'HR Assistant ' . $f['code'] . ($rej ? ' · ' . t('hra.rejected_fields') . ': ' . implode(', ', array_map('hra_label', $rej)) : ''));
    if ($nDocs && function_exists('hr_audit')) hr_audit($pid, 'document', 'added', null, (string)$nDocs, 'HR Assistant ' . $f['code']);
    hra_log((int)$f['id'], 'applied', count($apply) . ' fields, ' . $nDocs . ' docs' . ($rej ? ', rejected ' . implode(',', $rej) : '')); hra_notify(hra_flow((int)$f['id']), 'applied');
}
/** Write approved values on the card: personal columns through hr_personal_save (merged with the current row), the rest column by column. */
function hra_write_card(int $pid, array $row, array $apply): void {
    $personal = array_intersect_key($apply, array_flip(hr_personal_fields())); $plain = array_diff_key($apply, $personal);
    if ($personal) { $in = []; foreach (hr_personal_fields() as $k) $in[$k] = array_key_exists($k, $personal) ? $personal[$k] : (string)($row[$k] ?? ''); hr_personal_save($pid, $in); }
    foreach ($plain as $k => $v) { if (!in_array($k, ['name_el', 'gender', 'marital', 'children', 'children_years'], true)) continue; q("UPDATE hr_people SET `$k` = ? WHERE id = ?", [$k === 'children' ? (int)$v : ($v === '' ? null : $v), $pid]); }
}

/* ------------------------------------------------------------------ documents of a flow */
function hra_docs(int $flowId): array { return all('SELECT d.*, u.full_name AS by_name FROM hr_flow_docs d LEFT JOIN customer_users u ON u.id = d.uploaded_by WHERE d.flow_id = ? ORDER BY d.id', [$flowId]); }
function hra_doc_store_from_files(array $f, string $kind): int {
    $me = (int)(current_user()['id'] ?? 0); if (!hra_can_view($f) || ($f['status'] !== 'draft' && !hra_can_manage())) throw new RuntimeException(t('err.noaccess'));
    $up = $_FILES['doc'] ?? null; if (!$up) return 0; $kind = in_array($kind, HRA_DOC_KINDS, true) ? $kind : 'other'; $n = 0;
    $names = (array)$up['name']; $tmps = (array)$up['tmp_name']; $errs = (array)$up['error']; $sizes = (array)$up['size'];
    foreach ($names as $i => $name) {
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
        if ((int)$sizes[$i] > 15 * 1024 * 1024) throw new RuntimeException(t('hr.doc_big'));
        $mime = (string)(mime_content_type($tmps[$i]) ?: ''); $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null; if (!$ext) throw new RuntimeException(t('hra.doc_type'));
        if ($ext === 'pdf' && hra_pdf_active($tmps[$i])) throw new RuntimeException(t('hra.doc_pdf_js'));
        if ($ext !== 'pdf') { $re = hra_reencode($tmps[$i], $ext); if ($re === null) throw new RuntimeException(t('hra.doc_type')); file_put_contents($tmps[$i], $re); $ext = 'jpg'; }
        $dir = dirname(__DIR__) . '/storage/hr/flows/' . (int)$f['id']; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $fn = bin2hex(random_bytes(12)) . '.' . $ext; if (!@rename($tmps[$i], $dir . '/' . $fn) && !copy($tmps[$i], $dir . '/' . $fn)) throw new RuntimeException(t('hr.doc_fail'));
        q('INSERT INTO hr_flow_docs (flow_id, kind, name, path, sha, size, uploaded_by) VALUES (?,?,?,?,?,?,?)', [(int)$f['id'], $kind, mb_substr((string)$name, 0, 160), 'flows/' . (int)$f['id'] . '/' . $fn, hash_file('sha256', $dir . '/' . $fn), (int)filesize($dir . '/' . $fn), $me]); $n++;
    }
    if ($n) hra_log((int)$f['id'], 'doc', "$kind ×$n"); return $n;
}
/** Images are re-encoded with GD (EXIF and any payload dropped), longest side 2000 px, JPEG 88. */
function hra_reencode(string $path, string $ext): ?string {
    if (!function_exists('imagecreatefromstring')) return (string) file_get_contents($path);
    $im = @imagecreatefromstring((string) file_get_contents($path)); if (!$im) return null;
    if (function_exists('exif_read_data') && $ext === 'jpg') { try { $o = (int)(@exif_read_data($path)['Orientation'] ?? 1); if ($o === 3) $im = imagerotate($im, 180, 0); elseif ($o === 6) $im = imagerotate($im, -90, 0); elseif ($o === 8) $im = imagerotate($im, 90, 0); } catch (Throwable $e) {} }
    $w = imagesx($im); $h = imagesy($im); $max = 2000; if (max($w, $h) > $max) { $s = $max / max($w, $h); $im = imagescale($im, (int)round($w * $s), (int)round($h * $s)); }
    ob_start(); imagejpeg($im, null, 88); return (string) ob_get_clean();
}
/** A PDF carrying JavaScript, launch actions or embedded files is refused. */
function hra_pdf_active(string $path): bool { $s = (string) file_get_contents($path, false, null, 0, 4 * 1024 * 1024); return (bool) preg_match('~/(JavaScript|JS|Launch|EmbeddedFile|OpenAction)\b~', $s); }
function hra_doc_delete(array $f, int $docId): void { $me = (int)(current_user()['id'] ?? 0); $d = one('SELECT * FROM hr_flow_docs WHERE id = ? AND flow_id = ?', [$docId, (int)$f['id']]); if (!$d || !(((int)$f['user_id'] === $me && $f['status'] === 'draft') || hra_can_manage())) throw new RuntimeException(t('err.noaccess')); $p = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (is_file($p)) @unlink($p); q('DELETE FROM hr_flow_docs WHERE id = ?', [$docId]); hra_log((int)$f['id'], 'doc_del', (string)$d['name']); }
function hra_doc_stream(int $id): void {
    $d = hra_has() ? one('SELECT d.*, f.user_id, f.confidential, f.status FROM hr_flow_docs d JOIN hr_flows f ON f.id = d.flow_id WHERE d.id = ?', [$id]) : null; $u = current_user(); if (!$d || !$u) { http_response_code(404); exit; }
    if (!hra_can_view($d)) { http_response_code(403); exit; }
    $f = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (!is_file($f)) { http_response_code(404); exit; }
    $ct = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    header('Content-Type: ' . $ct); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Content-Length: ' . filesize($f)); header('Cache-Control: private, max-age=0');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$d['name']) . '"'); readfile($f); exit;
}
/** On approval the documents move to the card (hr_docs) under storage/hr/people/<pid>/. */
function hra_docs_file(array $f, int $pid): int {
    $n = 0; $dir = dirname(__DIR__) . '/storage/hr/people/' . $pid; $me = (int)(current_user()['id'] ?? 0);
    foreach (hra_docs((int)$f['id']) as $d) {
        $src = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (!is_file($src)) continue; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $fn = basename($d['path']); if (!@rename($src, $dir . '/' . $fn)) continue;
        q('INSERT INTO hr_docs (person_id, employment_id, kind, name, path, sha, size, note, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, null, in_array($d['kind'], HR_DOC_KINDS, true) ? $d['kind'] : 'other', $d['name'], 'people/' . $pid . '/' . $fn, $d['sha'], (int)$d['size'], 'HR Assistant ' . $f['code'], $me]);
        q('DELETE FROM hr_flow_docs WHERE id = ?', [(int)$d['id']]); $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ assistant (portal ai_run, page "hra") */
/** Fill the fields of a step from free text or an identity-document photo; suggestions only — the employee confirms each one. */
function hra_assist(array $f, int $stepIdx, string $text, ?array $image): array {
    if (!function_exists('ai_can') || !ai_can('hra')) throw new RuntimeException(t('ai.off'));
    if ((int)$f['user_id'] !== (int)(current_user()['id'] ?? 0) || $f['status'] !== 'draft') throw new RuntimeException(t('err.noaccess'));
    $tpl = hra_template($f['kind'], (string)$f['branch']); $step = $tpl['steps'][$stepIdx] ?? null; if (!$step || empty($step['fields'])) throw new RuntimeException(t('err.notfound'));
    $meta = hra_field_meta(); $fields = [];
    foreach ($step['fields'] as $k) { $m = $meta[$k]; $fd = ['name' => $k, 'label' => t($m[2]), 'type' => $m[0] === 'select' ? 'select' : ($m[0] === 'date' ? 'date' : 'text'), 'value' => '']; if ($m[0] === 'select') $fd['options'] = array_map(fn($v, $l) => ['value' => (string)$v, 'label' => $l === '—' ? '' : (str_contains((string)$l, '.') ? t((string)$l) : (string)$l)], array_keys($m[3]), $m[3]); $fields[] = $fd; }
    $text = mb_substr(trim($text), 0, 6000); if ($text === '' && !$image) throw new RuntimeException(t('hra.assist_need'));
    $prompt = ($image ? t('hra.assist_p_doc') : t('hra.assist_p_text')) . "\n" . t('hra.assist_rules') . ($text !== '' ? "\n\n" . $text : '');
    $r = ai_run('fill', ['title' => t('hra.title'), 'headline' => t('hra.step_' . $step['key']), 'fields' => $fields], $prompt, $image, 'hra');
    if (empty($r['ok'])) throw new RuntimeException((string)($r['error'] ?? t('ai.failed')));
    $out = []; foreach ((array)($r['fields'] ?? []) as $k => $v) { if (!in_array($k, $step['fields'], true)) continue; $v = trim((string)$v); if ($v === '') continue; try { $out[$k] = hra_validate($k, $v, hr_person((int)$f['person_id']) ?? []); } catch (Throwable $e) { /* an unusable suggestion is dropped */ } }
    $_SESSION['hra_ai'][(int)$f['id']][$stepIdx] = ['fields' => $out, 'notes' => mb_substr((string)($r['notes'] ?? $r['text'] ?? ''), 0, 600), 'src' => $image ? 'document' : 'assistant'];
    hra_log((int)$f['id'], 'assist', ($image ? 'document' : 'text') . ' → ' . count($out) . ' fields'); return $out;
}
function hra_assist_from_post(array $f): int {
    $stepIdx = (int)($_POST['step'] ?? 0); $image = null;
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        if ((int)$_FILES['image']['size'] > 4 * 1024 * 1024) throw new RuntimeException(t('hr.doc_big'));
        $mime = (string)(mime_content_type($_FILES['image']['tmp_name']) ?: ''); if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException(t('hra.doc_type'));
        $bytes = hra_reencode($_FILES['image']['tmp_name'], 'jpg'); if ($bytes === null) throw new RuntimeException(t('hra.doc_type')); $image = ['type' => 'image/jpeg', 'bytes' => $bytes];
    }
    return count(hra_assist($f, $stepIdx, (string)($_POST['text'] ?? ''), $image));
}

/* ------------------------------------------------------------------ notifications */
function hra_notify(?array $f, string $ev): void {
    if (!$f || !function_exists('nf_push')) return; $me = (int)(current_user()['id'] ?? 0); $title = t('hra.nf_' . $ev) . ' — ' . $f['code'] . ' · ' . (string)($f['title'] ?? $f['name_en'] ?? '');
    try {
        if ($ev === 'submitted') nf_push(hr_hr_user_ids(), 'hra:' . $ev, 'hra:' . $f['id'], $title, null, url('hra_flow', ['id' => (int)$f['id']]), $me ?: null, current_user()['full_name'] ?? null);
        elseif ($f['user_id']) nf_push([(int)$f['user_id']], 'hra:' . $ev, 'hra:' . $f['id'], $title, $f['decision_note'] ?: null, url('hra_flow', ['id' => (int)$f['id']]), $me ?: null, current_user()['full_name'] ?? null);
    } catch (Throwable $e) {}
}

/* ------------------------------------------------------------------ housekeeping (hooked into nf_sweep) */
/** Staged values and files of closed flows are removed after HRA_PURGE_DAYS; the flow row and its log stay. */
function hra_purge(): int {
    if (!hra_has()) return 0; $n = 0;
    foreach (all("SELECT id FROM hr_flows WHERE purge_after IS NOT NULL AND purge_after < CURDATE() AND status IN ('applied','rejected','withdrawn') LIMIT 50") as $r) {
        $id = (int)$r['id']; foreach (hra_docs($id) as $d) { $p = dirname(__DIR__) . '/storage/hr/' . $d['path']; if (is_file($p)) @unlink($p); }
        q('DELETE FROM hr_flow_docs WHERE flow_id = ?', [$id]); q('DELETE FROM hr_flow_fields WHERE flow_id = ?', [$id]); q('UPDATE hr_flows SET purge_after = NULL WHERE id = ?', [$id]); hra_log($id, 'purged'); $n++;
    }
    return $n;
}
/** Nudge: a draft untouched for 7 days, or a submission waiting for HR for 3 days. */
function hra_remind(): int {
    if (!hra_has() || !function_exists('nf_push')) return 0; $today = date('Y-m-d'); if (cp_setting('hra_remind_day') === $today) return 0; cp_setting_set('hra_remind_day', $today); $n = 0;
    foreach (all("SELECT * FROM hr_flows WHERE status = 'draft' AND user_id IS NOT NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND updated_at > DATE_SUB(NOW(), INTERVAL 60 DAY)") as $f) { try { $n += nf_push([(int)$f['user_id']], 'hra:remind', 'hra:' . $f['id'], t('hra.nf_remind') . ' — ' . $f['code'], null, url('hra_flow', ['id' => (int)$f['id']]), null, null); } catch (Throwable $e) {} }
    foreach (all("SELECT * FROM hr_flows WHERE status IN ('hr','submitted') AND submitted_at < DATE_SUB(NOW(), INTERVAL 3 DAY)") as $f) { try { $n += nf_push(hr_hr_user_ids(), 'hra:waiting', 'hra:' . $f['id'], t('hra.nf_waiting') . ' — ' . $f['code'], null, url('hra_flow', ['id' => (int)$f['id']]), null, null); } catch (Throwable $e) {} }
    return $n;
}
function hra_status_pill(string $s): string { $tone = ['draft' => 'mute', 'submitted' => 'gold', 'manager' => 'gold', 'hr' => 'gold', 'ceo' => 'gold', 'applied' => 'green', 'rejected' => 'red', 'withdrawn' => 'mute'][$s] ?? 'mute'; return pill($tone, t('hra.st_' . $s)); }
