<?php
/**
 * HR — equipment issued to employees and its handover.   GAS-CP-R97-124 (v1.188.0)
 *
 * What the company gives a person to work with — instruments, tools, laptop, telephone, working clothes, safety equipment,
 * keys and cards, vehicle, documents — is written line by line on the card of the employee. The lines go on an ISSUE LIST
 * that the employee accepts (in the portal, or on paper recorded by HR). What is given back goes on a HANDOVER LIST that
 * HR accepts line by line (in order · damaged · missing · not to be returned). Both lists are numbered documents with a PDF.
 *   hr_eq_items.status: draft → pending (on an issue list, not accepted yet) → held → returned | missing | writeoff
 *     a line "missing" that goes on a later handover list is copied to it; the old line stays on its list as "followed"
 *   hr_eq_docs  issue : sent → accepted | disputed | cancelled      return: open → accepted | cancelled
 * Nothing of this module is sent to the assistant.
 */
declare(strict_types=1);

const HRE_CATS    = ['instrument', 'tool', 'it', 'phone', 'clothes', 'ppe', 'access', 'vehicle', 'docs', 'other'];
const HRE_CONDS   = ['new', 'good', 'used', 'worn'];
const HRE_RESULTS = ['ok', 'damaged', 'missing', 'writeoff'];
const HRE_WITH    = ['pending', 'held', 'missing'];           // still in the name of the employee

function hre_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('hr2_has') && hr2_has() && (bool) scalar("SHOW TABLES LIKE 'hr_eq_items'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hre_can(): bool { return hre_has() && is_staff() && ((function_exists('hr_can') && hr_can()) || is_cp_admin()); }
/** R97-125 — categories the signed-in person may prepare lines for: HR and administrators all; the IT & Cybersecurity team computers, telephones
 *  and keys / cards; QHSE instruments, safety equipment and working clothes; the warehouse tools, working clothes and safety equipment.
 *  Preparing is not issuing: the lines wait as drafts and HR puts them on the issue list. */
function hre_prep_cats(): array {
    static $c = null; if ($c !== null) return $c; $c = []; if (!hre_has() || !is_staff()) return $c; if (hre_can()) return $c = HRE_CATS;
    try {
        if (function_exists('ism_team') && (in_array(hre_me(), ism_team(), true) || (function_exists('ism_by_position') && ism_by_position()))) $c = array_merge($c, ['it', 'phone', 'access']);
        if (function_exists('qh_can') && qh_can()) $c = array_merge($c, ['instrument', 'ppe', 'clothes']);
        if (function_exists('stk_flag') && stk_flag('can_warehouse')) $c = array_merge($c, ['tool', 'clothes', 'ppe']);
    } catch (Throwable $e) {}
    return $c = array_values(array_unique($c));
}
function hre_me(): int { return (int)(current_user()['id'] ?? 0); }
function hre_my_person(): ?array { return hre_has() && function_exists('hr_person_of_user') ? hr_person_of_user(hre_me()) : null; }
function hre_cat_icon(string $c): string { return ['instrument' => '📏', 'tool' => '🧰', 'it' => '💻', 'phone' => '📱', 'clothes' => '👕', 'ppe' => '🦺', 'access' => '🔑', 'vehicle' => '🚗', 'docs' => '📚', 'other' => '📦'][$c] ?? '📦'; }
function hre_cat_label(string $c): string { return t('hre.c_' . (in_array($c, HRE_CATS, true) ? $c : 'other')); }
function hre_qty($v): string { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }
function hre_item_pill(array $i): string {
    if (in_array($i['status'], ['held', 'missing'], true) && !empty($i['return_doc_id']) && ($i['return_status'] ?? 'open') === 'open') return pill('gold', t('hre.st_returning'));
    return pill(['draft' => 'mute', 'pending' => 'warn', 'held' => 'green', 'returned' => 'mute', 'missing' => 'bad', 'writeoff' => 'mute'][$i['status']] ?? 'mute', t('hre.st_' . $i['status']));
}
function hre_doc_pill(array $d): string {
    return pill(['sent' => 'warn', 'open' => 'warn', 'accepted' => 'green', 'disputed' => 'bad', 'cancelled' => 'mute'][$d['status']] ?? 'mute', t('hre.ds_' . $d['status'] . ($d['kind'] === 'return' && $d['status'] === 'open' ? '_r' : '')));
}

/* ------------------------------------------------------------------ reading */
function hre_items(int $pid, ?array $statuses = null): array {
    if (!hre_has()) return []; $w = " AND i.status <> 'followed'"; $a = [$pid];
    if ($statuses) { $w = ' AND i.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; $a = array_merge($a, $statuses); }
    return all('SELECT i.*, di.doc_no AS issue_no, dr.doc_no AS return_no, dr.status AS return_status FROM hr_eq_items i LEFT JOIN hr_eq_docs di ON di.id = i.issue_doc_id LEFT JOIN hr_eq_docs dr ON dr.id = i.return_doc_id WHERE i.person_id = ?' . $w . ' ORDER BY FIELD(i.status, \'draft\', \'pending\', \'missing\', \'held\', \'returned\', \'writeoff\'), i.category, i.description, i.id', $a);
}
/** A line that can go on a new handover list: held and on no list, or not returned on a list that is closed. */
function hre_free(array $i): bool { return ($i['status'] === 'held' && empty($i['return_doc_id'])) || ($i['status'] === 'missing' && (empty($i['return_doc_id']) || ($i['return_status'] ?? '') === 'accepted')); }
function hre_doc(int $id): ?array { return hre_has() && $id ? (one('SELECT * FROM hr_eq_docs WHERE id = ?', [$id]) ?: null) : null; }
function hre_docs(int $pid): array { return hre_has() ? all('SELECT d.*, (SELECT COUNT(*) FROM hr_eq_items i WHERE (d.kind = \'issue\' AND i.issue_doc_id = d.id AND i.clone_of IS NULL) OR (d.kind = \'return\' AND i.return_doc_id = d.id)) AS n_items FROM hr_eq_docs d WHERE d.person_id = ? ORDER BY d.id DESC', [$pid]) : []; }
function hre_doc_items(array $d): array { return all('SELECT * FROM hr_eq_items WHERE ' . ($d['kind'] === 'issue' ? 'clone_of IS NULL AND issue_doc_id' : 'return_doc_id') . ' = ? ORDER BY category, description, id', [(int)$d['id']]); }
function hre_can_view(array $d): bool { if (hre_can()) return true; $p = hre_my_person(); return $p && (int)$p['id'] === (int)$d['person_id']; }
/** Number of lines still in the name of a person (for the card "After leaving"). */
function hre_with_count(int $pid): int { try { return hre_has() ? (int) scalar("SELECT COUNT(*) FROM hr_eq_items WHERE person_id = ? AND status IN ('pending','held','missing')", [$pid]) : 0; } catch (Throwable $e) { return 0; } }
/** Earlier descriptions, for the suggestions of the form. */
function hre_suggest(): array { try { return array_column(all('SELECT description, COUNT(*) AS n FROM hr_eq_items GROUP BY description ORDER BY n DESC, description LIMIT 200'), 'description'); } catch (Throwable $e) { return []; } }
/** Instruments of the calibration register (QHSE) that can be put on a line: [id => label]. */
function hre_instruments(): array {
    if (!function_exists('ism_has') || !ism_has()) return []; $o = [];
    try { foreach (all("SELECT id, code, title, data FROM ism_records WHERE reg = 'qequip' AND status <> 'retired' ORDER BY code") as $r) { $d = json_decode((string)$r['data'], true) ?: []; $o[(int)$r['id']] = ['label' => trim($r['code'] . ' · ' . $r['title'] . (!empty($d['serial']) ? ' · S/N ' . $d['serial'] : '')), 'title' => trim((string)$r['title'] . (!empty($d['maker_model']) ? ' — ' . $d['maker_model'] : '')), 'serial' => (string)($d['serial'] ?? ''), 'code' => (string)$r['code']]; } } catch (Throwable $e) {}
    return $o;
}
/** R97-125 — what can be taken from a register onto a line: instruments of the calibration register and hardware of the IT asset inventory.
 *  [id => label, title, serial, code, cat, grp] — only code, name and serial number are read. */
function hre_sources(): array {
    static $o = null; if ($o !== null) return $o; $o = [];
    foreach (hre_instruments() as $id => $x) $o[$id] = $x + ['cat' => 'instrument', 'grp' => 'cal'];
    if (function_exists('ism_has') && ism_has()) { try { foreach (all("SELECT id, code, title, data FROM ism_records WHERE reg = 'asset' AND status <> 'retired' ORDER BY code") as $r) { $d = json_decode((string)$r['data'], true) ?: []; if (($d['type'] ?? 'hardware') !== 'hardware') continue;
        $o[(int)$r['id']] = ['label' => trim($r['code'] . ' · ' . $r['title']), 'title' => (string)$r['title'], 'serial' => '', 'code' => (string)$r['code'], 'cat' => preg_match('/phone|mobile|smartphone|sim\b|κινητ|τηλ[εέ]φ/iu', (string)$r['title']) ? 'phone' : 'it', 'grp' => 'it']; } } catch (Throwable $e) {} }
    return $o;
}
/** Who holds an instrument of the calibration register now (name or null). */
function hre_holder_of(int $srcId): ?string { try { return hre_has() ? (scalar("SELECT p.name_en FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id WHERE i.src_id = ? AND i.status IN ('draft','pending','held','missing') ORDER BY i.id DESC LIMIT 1", [$srcId]) ?: null) : null; } catch (Throwable $e) { return null; } }

/* ------------------------------------------------------------------ lines (HR) */
function hre_add_from_post(): int {
    $mine = hre_prep_cats(); if (!$mine) throw new RuntimeException(t('err.noaccess'));
    $pid = (int)($_POST['person_id'] ?? 0); $p = hr_person($pid); if (!$p || (!hre_can() && !$p['is_active'])) throw new RuntimeException(t('err.notfound'));
    $cat = (string)($_POST['category'] ?? 'other'); if (!in_array($cat, HRE_CATS, true)) $cat = 'other';
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200); $serial = mb_substr(trim((string)($_POST['serial_no'] ?? '')), 0, 80); $tag = mb_substr(trim((string)($_POST['asset_tag'] ?? '')), 0, 40);
    $src = (int)($_POST['src_id'] ?? 0) ?: null;
    if ($src) { $ins = hre_sources()[$src] ?? null; if (!$ins) $src = null; else { if ($desc === '') $desc = mb_substr($ins['title'], 0, 200); if ($serial === '') $serial = mb_substr($ins['serial'], 0, 80); if ($tag === '') $tag = mb_substr($ins['code'], 0, 40); $cat = $ins['cat'];
        if ($h = hre_holder_of($src)) throw new RuntimeException(t('hre.err_held', ['n' => $h])); } }
    $asset = (int)($_POST['inv_sn'] ?? 0); $ast = null;   // R97-252: a company asset of the inventory, by its serial number
    if ($asset && function_exists('sn_assets_free')) { $ast = sn_assets_free()[$asset] ?? null; if (!$ast) throw new RuntimeException(t('sn.asset_gone')); if ($desc === '') $desc = mb_substr($ast['title'], 0, 200); $serial = mb_substr($ast['serial'], 0, 80); if ($tag === '' && $ast['tag'] !== '') $tag = mb_substr($ast['tag'], 0, 40); $_POST['qty'] = '1'; }
    if ($desc === '') throw new RuntimeException(t('hre.err_desc'));
    if (!in_array($cat, $mine, true)) throw new RuntimeException(t('hre.err_cat'));
    $exp = trim((string)($_POST['expires_on'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = null;
    $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '1')); if ($qty <= 0 || $qty > 9999) $qty = 1.0;
    $cond = (string)($_POST['cond_out'] ?? 'new'); if (!in_array($cond, HRE_CONDS, true)) $cond = 'new';
    q('INSERT INTO hr_eq_items (person_id, category, description, serial_no, asset_tag, qty, size, cond_out, note, status, src_id, created_by, expires_on) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$pid, $cat, $desc, $serial ?: null, $tag ?: null, $qty, mb_substr(trim((string)($_POST['size'] ?? '')), 0, 20) ?: null, $cond, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null, 'draft', $src, hre_me(), $exp]);
    if ($ast) { $eqId = (int) db()->lastInsertId(); try { stk_tx(function () use ($asset, $pid, $eqId, $p) { sn_assign_person($asset, $pid, $eqId, (string)$p['name_en']); q('UPDATE hr_eq_items SET inv_serial_id = ? WHERE id = ?', [$asset, $eqId]); }); } catch (Throwable $e) { q('DELETE FROM hr_eq_items WHERE id = ?', [$eqId]); throw $e; } }
    if (!hre_can() && function_exists('nf_push')) { $u = current_user(); try { nf_push(array_values(array_diff(hre_hr_uids(), [hre_me()])), 'hre', 'hre:prep:' . $pid . ':' . date('Ymd'), t('hre.nf_prepared', ['n' => (string)$p['name_en']]), null, url('hr_person', ['id' => $pid, 'tab' => 'eq']) . '#eq-add', (int)$u['id'], (string)$u['full_name']); } catch (Throwable $e) {} }
    return $pid;
}
function hre_del_from_post(): int {
    $mine = hre_prep_cats(); if (!$mine) throw new RuntimeException(t('err.noaccess'));
    $i = one('SELECT * FROM hr_eq_items WHERE id = ?', [(int)($_POST['item_id'] ?? 0)]); if (!$i) throw new RuntimeException(t('err.notfound'));
    if (!hre_can() && ((int)$i['created_by'] !== hre_me() || !in_array($i['category'], $mine, true))) throw new RuntimeException(t('err.noaccess'));   // a preparer removes only a draft of his / her own
    if ($i['status'] !== 'draft') throw new RuntimeException(t('hre.err_not_draft'));
    if (!empty($i['inv_serial_id']) && function_exists('sn_release_person')) stk_tx(fn() => sn_release_person((int)$i['inv_serial_id'], 'back', t('sn.ref_not_issued')));   // R97-252: the asset is in the warehouse again
    q('DELETE FROM hr_eq_items WHERE id = ?', [(int)$i['id']]); return (int)$i['person_id'];
}

/* ------------------------------------------------------------------ documents */
function hre_next_no(string $kind): string {
    $pre = ($kind === 'issue' ? 'EQI-' : 'EQR-') . date('Y') . '-';
    $n = (int) scalar('SELECT COALESCE(MAX(CAST(SUBSTRING(doc_no, ?) AS UNSIGNED)), 0) FROM hr_eq_docs WHERE doc_no LIKE ?', [strlen($pre) + 1, $pre . '%']);
    return $pre . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}
function hre_hr_uids(): array { return function_exists('hry_recipients') ? array_map(fn($r) => (int)$r['id'], hry_recipients()) : []; }
function hre_notify(array $uids, array $d, string $key, string $url, ?string $body = null): void {
    if (!function_exists('nf_push')) return; $u = current_user(); $uids = array_values(array_diff(array_unique(array_filter(array_map('intval', $uids))), [hre_me()])); if (!$uids) return;
    $p = hr_person((int)$d['person_id']);
    try { nf_push($uids, 'hre', 'hre:' . (int)$d['id'] . ':' . $key, t($key, ['no' => $d['doc_no'], 'n' => (string)($p['name_en'] ?? '')]), $body, $url, $u ? (int)$u['id'] : null, $u ? (string)$u['full_name'] : null); } catch (Throwable $e) {}
}
function hre_audit(int $pid, string $what, ?string $new = null, ?string $note = null): void { if (function_exists('hr_audit')) { try { hr_audit($pid, 'equipment', $what, null, $new, $note); } catch (Throwable $e) {} } }

/** HR: the draft lines (all, or the ticked ones) go on an issue list, sent to the employee for acceptance. */
function hre_issue_from_post(bool $keepDates = false): array {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess'));
    $pid = (int)($_POST['person_id'] ?? 0); $p = hr_person($pid); if (!$p) throw new RuntimeException(t('err.notfound'));
    $pick = array_map('intval', (array)($_POST['it'] ?? []));
    $rows = array_values(array_filter(hre_items($pid, ['draft']), fn($i) => !$pick || in_array((int)$i['id'], $pick, true)));
    if (!$rows) throw new RuntimeException(t('hre.err_no_lines'));
    $u = current_user(); $pdo = db(); $pdo->beginTransaction();
    try {
        $no = hre_next_no('issue');
        q('INSERT INTO hr_eq_docs (person_id, kind, doc_no, doc_date, status, note, created_by, created_name) VALUES (?,?,?,?,?,?,?,?)', [$pid, 'issue', $no, date('Y-m-d'), 'sent', mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null, hre_me(), (string)($u['full_name'] ?? '')]);
        $id = (int)$pdo->lastInsertId();
        foreach ($rows as $i) q("UPDATE hr_eq_items SET status = 'pending', issue_doc_id = ? WHERE id = ? AND status = 'draft'", [$id, (int)$i['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $d = hre_doc($id); hre_audit($pid, t('hre.issue_list') . ' ' . $no, count($rows) . ' ' . t('hre.lines'));
    if (!empty($p['user_id']) && !empty($p['account_active'])) hre_notify([(int)$p['user_id']], $d, 'hre.nf_issue', url('my_equipment'));
    return $d;
}
/** The employee accepts an issue list — or confirms a handover list prepared by HR. $paperName: recorded by HR from the signed paper. */
function hre_accept(int $docId, ?string $paperName = null): array {
    $d = hre_doc($docId); if (!$d) throw new RuntimeException(t('err.notfound'));
    $paper = $paperName !== null;
    if ($paper) { if (!hre_can()) throw new RuntimeException(t('err.noaccess')); $paperName = mb_substr(trim($paperName), 0, 160); if ($paperName === '') throw new RuntimeException(t('hre.err_name')); }
    else { $p = hre_my_person(); if (!$p || (int)$p['id'] !== (int)$d['person_id']) throw new RuntimeException(t('err.noaccess')); if (empty($_POST['agree'])) throw new RuntimeException(t('hre.err_agree')); }
    $name = $paper ? $paperName : (string)(current_user()['full_name'] ?? ''); $via = $paper ? 'paper' : 'portal'; $ip = $paper ? null : (function_exists('sgn_ip') ? (sgn_ip() ?: null) : null);
    if ($d['kind'] === 'issue') {
        if (!in_array($d['status'], ['sent', 'disputed'], true)) throw new RuntimeException(t('hre.err_state'));
        $pdo = db(); $pdo->beginTransaction();
        try {
            q("UPDATE hr_eq_docs SET status = 'accepted', emp_at = NOW(), emp_via = ?, emp_by = ?, emp_name = ?, emp_ip = ? WHERE id = ?", [$via, hre_me(), $name, $ip, $docId]);
            q("UPDATE hr_eq_items SET status = 'held', issued_on = COALESCE(issued_on, CURDATE()) WHERE issue_doc_id = ? AND status = 'pending'", [$docId]);   // R97-125: a line imported with its date keeps it
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        hre_audit((int)$d['person_id'], t('hre.issue_list') . ' ' . $d['doc_no'], t('hre.ds_accepted') . ' — ' . $name . ' (' . t('hre.via_' . $via) . ')');
        if (!$paper) hre_notify(array_merge(hre_hr_uids(), [(int)$d['created_by']]), $d, 'hre.nf_accepted', url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']));
    } else {
        if ($d['status'] !== 'open' || $d['emp_at']) throw new RuntimeException(t('hre.err_state'));
        q('UPDATE hr_eq_docs SET emp_at = NOW(), emp_via = ?, emp_by = ?, emp_name = ?, emp_ip = ? WHERE id = ?', [$via, hre_me(), $name, $ip, $docId]);
        hre_audit((int)$d['person_id'], t('hre.return_list') . ' ' . $d['doc_no'], t('hre.emp_confirmed') . ' — ' . $name . ' (' . t('hre.via_' . $via) . ')');
        if (!$paper) hre_notify(array_merge(hre_hr_uids(), [(int)$d['created_by']]), $d, 'hre.nf_declared', url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']));
    }
    return hre_doc($docId);
}
/** The employee does not agree with an issue list: the remark goes to HR. */
function hre_dispute(int $docId, string $remark): void {
    $d = hre_doc($docId); $p = hre_my_person(); if (!$d || !$p || (int)$p['id'] !== (int)$d['person_id']) throw new RuntimeException(t('err.noaccess'));
    if ($d['kind'] !== 'issue' || $d['status'] !== 'sent') throw new RuntimeException(t('hre.err_state'));
    $remark = mb_substr(trim($remark), 0, 500); if ($remark === '') throw new RuntimeException(t('hre.err_remark'));
    q("UPDATE hr_eq_docs SET status = 'disputed', emp_remark = ?, emp_by = ? WHERE id = ?", [$remark, hre_me(), $docId]);
    hre_audit((int)$d['person_id'], t('hre.issue_list') . ' ' . $d['doc_no'], t('hre.ds_disputed'), $remark);
    hre_notify(array_merge(hre_hr_uids(), [(int)$d['created_by']]), $d, 'hre.nf_disputed', url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']), $remark);
}
/** HR withdraws a list that is not accepted yet: issue → the lines are drafts again; handover → the lines stay with the employee. */
function hre_cancel(int $docId): array {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess')); $d = hre_doc($docId); if (!$d) throw new RuntimeException(t('err.notfound'));
    if (!in_array($d['status'], ['sent', 'disputed', 'open'], true)) throw new RuntimeException(t('hre.err_state'));
    $pdo = db(); $pdo->beginTransaction();
    try {
        if ($d['kind'] === 'issue') q("UPDATE hr_eq_items SET status = 'draft', issue_doc_id = NULL WHERE issue_doc_id = ? AND status = 'pending'", [$docId]);
        else q('UPDATE hr_eq_items SET return_doc_id = NULL WHERE return_doc_id = ? AND status IN (\'held\',\'missing\')', [$docId]);
        q("UPDATE hr_eq_docs SET status = 'cancelled' WHERE id = ?", [$docId]); $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    hre_audit((int)$d['person_id'], t($d['kind'] === 'issue' ? 'hre.issue_list' : 'hre.return_list') . ' ' . $d['doc_no'], t('hre.ds_cancelled'));
    return $d;
}
/** A handover list of the ticked lines (held or missing, not on another open list). By HR for any person, by the employee for his/her own. */
function hre_handover_from_post(): array {
    $mine = hre_my_person(); $pid = (int)($_POST['person_id'] ?? 0); $byHr = hre_can() && $pid > 0;
    if (!$byHr) { if (!$mine) throw new RuntimeException(t('err.noaccess')); $pid = (int)$mine['id']; }
    $p = hr_person($pid); if (!$p) throw new RuntimeException(t('err.notfound'));
    $pick = array_map('intval', (array)($_POST['it'] ?? []));
    $rows = array_values(array_filter(hre_items($pid, ['held', 'missing']), fn($i) => hre_free($i) && in_array((int)$i['id'], $pick, true)));
    if (!$rows) throw new RuntimeException(t('hre.err_no_pick'));
    $u = current_user(); $pdo = db(); $pdo->beginTransaction();
    try {
        $no = hre_next_no('return');
        q('INSERT INTO hr_eq_docs (person_id, kind, doc_no, doc_date, status, note, created_by, created_name, emp_at, emp_via, emp_by, emp_name, emp_ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$pid, 'return', $no, date('Y-m-d'), 'open', mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null, hre_me(), (string)($u['full_name'] ?? ''), null, $byHr ? null : 'portal', $byHr ? null : hre_me(), $byHr ? null : (string)($u['full_name'] ?? ''), $byHr ? null : (function_exists('sgn_ip') ? (sgn_ip() ?: null) : null)]);
        $id = (int)$pdo->lastInsertId(); if (!$byHr) q('UPDATE hr_eq_docs SET emp_at = NOW() WHERE id = ?', [$id]);   // the clock of the database, as everywhere else
        foreach ($rows as $i) {
            if ($i['status'] === 'missing' && !empty($i['return_doc_id'])) {   // not returned on an earlier list: that list keeps its line, a copy goes on this one
                q('INSERT INTO hr_eq_items (person_id, category, description, serial_no, asset_tag, qty, size, cond_out, note, status, issue_doc_id, issued_on, return_doc_id, src_id, created_by, clone_of) SELECT person_id, category, description, serial_no, asset_tag, qty, size, cond_out, note, \'missing\', issue_doc_id, issued_on, ?, src_id, created_by, id FROM hr_eq_items WHERE id = ?', [$id, (int)$i['id']]);
                q("UPDATE hr_eq_items SET status = 'followed' WHERE id = ?", [(int)$i['id']]);
            } else q('UPDATE hr_eq_items SET return_doc_id = ? WHERE id = ?', [$id, (int)$i['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $d = hre_doc($id); hre_audit($pid, t('hre.return_list') . ' ' . $no, count($rows) . ' ' . t('hre.lines'));
    if ($byHr) { if (!empty($p['user_id']) && !empty($p['account_active'])) hre_notify([(int)$p['user_id']], $d, 'hre.nf_return', url('my_equipment')); }
    else hre_notify(hre_hr_uids(), $d, 'hre.nf_declared', url('hr_person', ['id' => $pid, 'tab' => 'eq']));
    return $d;
}
/** HR accepts a handover list: every line gets its result. */
function hre_receive_from_post(): array {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess'));
    $d = hre_doc((int)($_POST['doc_id'] ?? 0)); if (!$d || $d['kind'] !== 'return') throw new RuntimeException(t('err.notfound'));
    if ($d['status'] !== 'open') throw new RuntimeException(t('hre.err_state'));
    $res = (array)($_POST['res'] ?? []); $notes = (array)($_POST['rnote'] ?? []); $conds = (array)($_POST['cond_in'] ?? []); $items = hre_doc_items($d);
    foreach ($items as $i) if (!in_array((string)($res[$i['id']] ?? ''), HRE_RESULTS, true)) throw new RuntimeException(t('hre.err_results'));
    $u = current_user(); $pdo = db(); $pdo->beginTransaction(); $cnt = array_fill_keys(HRE_RESULTS, 0);
    try {
        foreach ($items as $i) { $r = (string)$res[$i['id']]; $cnt[$r]++; $nt = mb_substr(trim((string)($notes[$i['id']] ?? '')), 0, 300) ?: null; $ci = (string)($conds[$i['id']] ?? ''); if (!in_array($ci, HRE_CONDS, true)) $ci = null;
            if ($r === 'missing') q("UPDATE hr_eq_items SET status = 'missing', return_note = ? WHERE id = ?", [$nt, (int)$i['id']]);
            else q('UPDATE hr_eq_items SET status = ?, returned_on = CURDATE(), cond_in = ?, return_note = ? WHERE id = ?', [$r === 'writeoff' ? 'writeoff' : 'returned', $r === 'damaged' ? 'damaged' : $ci, $nt, (int)$i['id']]);
            if ($r !== 'missing' && !empty($i['inv_serial_id']) && function_exists('sn_release_person')) sn_release_person((int)$i['inv_serial_id'], $r === 'writeoff' ? 'scrapped' : 'back', t('hre.return_list') . ' ' . $d['doc_no'] . ' · ' . t('hre.r_' . $r)); }   // R97-252: the asset returns to the warehouse, or is written off
        q("UPDATE hr_eq_docs SET status = 'accepted', hr_at = NOW(), hr_by = ?, hr_name = ? WHERE id = ?", [hre_me(), (string)($u['full_name'] ?? ''), (int)$d['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    hre_audit((int)$d['person_id'], t('hre.return_list') . ' ' . $d['doc_no'], t('hre.hr_accepted') . ' — ' . implode(' · ', array_map(fn($k) => t('hre.r_' . $k) . ': ' . $cnt[$k], array_keys(array_filter($cnt)))));
    $p = hr_person((int)$d['person_id']); if ($p && !empty($p['user_id']) && !empty($p['account_active'])) hre_notify([(int)$p['user_id']], $d, 'hre.nf_received', url('my_equipment'));
    return hre_doc((int)$d['id']);
}

/* ------------------------------------------------------------------ overviews (HR dashboard, register, reminders) */
/** What waits: issue lists not accepted, handover lists for HR, leavers with equipment, new employees with nothing issued. */
function hre_waiting(): array {
    $o = ['issue' => [], 'return' => [], 'leavers' => [], 'new' => [], 'exp' => []]; if (!hre_has()) return $o;
    try {
        foreach (all("SELECT d.*, p.name_en, p.is_active FROM hr_eq_docs d JOIN hr_people p ON p.id = d.person_id WHERE d.status IN ('sent','disputed','open') ORDER BY d.created_at") as $d) $o[$d['kind'] === 'issue' ? 'issue' : 'return'][] = $d;
        $o['leavers'] = all("SELECT p.id, p.name_en, p.left_date, COUNT(*) AS n FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id WHERE p.is_active = 0 AND i.status IN ('pending','held','missing') GROUP BY p.id, p.name_en, p.left_date ORDER BY p.left_date DESC");
        $o['exp'] = all("SELECT i.id, i.person_id, i.description, i.expires_on, p.name_en FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id WHERE p.is_active = 1 AND i.status IN ('pending','held') AND i.expires_on IS NOT NULL AND i.expires_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY i.expires_on LIMIT 200");
        $o['new'] = all("SELECT p.id, p.name_en, p.hire_date FROM hr_people p WHERE p.is_active = 1 AND p.hire_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND NOT EXISTS (SELECT 1 FROM hr_eq_items i WHERE i.person_id = p.id) ORDER BY p.hire_date DESC");
    } catch (Throwable $e) {}
    return $o;
}
/** R97-287: lines prepared and not issued yet — [lines, people]. */
function hre_inv_has(): bool { static $h = null; if ($h === null) { try { $h = hre_has() && (bool) one("SHOW COLUMNS FROM hr_eq_items LIKE 'inv_serial_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hre_drafts(): array { try { $r = hre_has() ? one("SELECT COUNT(*) AS n, COUNT(DISTINCT i.person_id) AS p FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id WHERE i.status = 'draft' AND p.is_active = 1") : null; return [(int)($r['n'] ?? 0), (int)($r['p'] ?? 0)]; } catch (Throwable $e) { return [0, 0]; } }
/** One issue list for every active person who has prepared lines. Returns [lists, lines, people without a portal account]. */
function hre_issue_all(): array {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess')); @set_time_limit(180); $lists = 0; $lines = 0; $noAcc = 0; $keep = $_POST;
    foreach (all("SELECT i.person_id, COUNT(*) AS n FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id WHERE i.status = 'draft' AND p.is_active = 1 GROUP BY i.person_id ORDER BY i.person_id LIMIT 500") as $r) {
        $_POST = ['person_id' => (int)$r['person_id'], 'note' => (string)($keep['note'] ?? '')];
        try { hre_issue_from_post(); $lists++; $lines += (int)$r['n']; $p = hr_person((int)$r['person_id']); if (empty($p['user_id']) || empty($p['account_active'])) $noAcc++; } catch (RuntimeException $e) { error_log('[hre] issue all: ' . $e->getMessage()); }
    }
    $_POST = $keep; return [$lists, $lines, $noAcc];
}
/** The register: every line, with the person.  $f: cat, st (with | returned | all), q */
function hre_register(array $f = []): array {
    if (!hre_has()) return []; $w = ["i.status <> 'followed'"]; $a = [];
    if (in_array($f['cat'] ?? '', HRE_CATS, true)) { $w[] = 'i.category = ?'; $a[] = $f['cat']; }
    $st = (string)($f['st'] ?? 'with'); if ($st === 'with') $w[] = hre_inv_has() ? "(i.status IN ('pending','held','missing') OR (i.status = 'draft' AND i.inv_serial_id IS NOT NULL))" : "i.status IN ('pending','held','missing')";   /* R97-287: a company asset that left the warehouse in the name of the person is with the person, also before the list is issued */ elseif ($st === 'returned') $w[] = "i.status IN ('returned','writeoff')"; elseif ($st === 'missing') $w[] = "i.status = 'missing'"; elseif ($st === 'draft') $w[] = "i.status = 'draft'"; elseif ($st === 'exp') $w[] = "i.status IN ('pending','held') AND i.expires_on IS NOT NULL AND i.expires_on <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
    if (($qq = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(i.description LIKE ? OR i.serial_no LIKE ? OR i.asset_tag LIKE ? OR p.name_en LIKE ?)'; $l = '%' . $qq . '%'; array_push($a, $l, $l, $l, $l); }
    return all('SELECT i.*, p.name_en, p.is_active AS person_active, di.doc_no AS issue_no, dr.doc_no AS return_no, dr.status AS return_status FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id LEFT JOIN hr_eq_docs di ON di.id = i.issue_doc_id LEFT JOIN hr_eq_docs dr ON dr.id = i.return_doc_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.name_en, i.category, i.description LIMIT 3000', $a);
}
function hre_register_sheets(array $rows): array {
    $h = [t('hr.person'), t('hre.category'), t('hre.description'), t('hre.serial'), t('hre.tag'), t('hre.qty'), t('hre.size'), t('hre.cond_out'), t('th.status'), t('hre.issue_list'), t('hre.issued_on'), t('hre.expires'), t('hre.return_list'), t('hre.returned_on'), t('hre.result_note')];
    $o = []; foreach ($rows as $i) $o[] = [(string)$i['name_en'], hre_cat_label((string)$i['category']), (string)$i['description'], (string)$i['serial_no'], (string)$i['asset_tag'], (float)$i['qty'], (string)$i['size'], t('hre.k_' . $i['cond_out']), t('hre.st_' . $i['status']), (string)$i['issue_no'], (string)$i['issued_on'], (string)$i['expires_on'], (string)$i['return_no'], (string)$i['returned_on'], (string)$i['return_note']];
    return [['name' => t('hre.title'), 'header' => $h, 'rows' => $o, 'widths' => [28, 18, 40, 20, 14, 8, 10, 12, 16, 16, 12, 12, 16, 12, 30]]];
}
/** Daily: the employee is reminded of an issue list not accepted after 3 days (then weekly); HR of a handover list waiting 3 days. */
function hre_sweep(): int {
    if (!hre_has() || !function_exists('nf_push')) return 0; $n = 0;
    try {
        foreach (all("SELECT d.*, p.user_id, cu.is_active AS acc FROM hr_eq_docs d JOIN hr_people p ON p.id = d.person_id LEFT JOIN customer_users cu ON cu.id = p.user_id WHERE d.status IN ('sent','open') AND d.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND (d.reminded_at IS NULL OR d.reminded_at < DATE_SUB(NOW(), INTERVAL 7 DAY))") as $d) {
            $to = $d['kind'] === 'issue' ? ((int)$d['user_id'] && (int)$d['acc'] ? [(int)$d['user_id']] : []) : hre_hr_uids(); $p = hr_person((int)$d['person_id']);
            if ($to) $n += nf_push($to, 'hre', 'hre:' . (int)$d['id'] . ':rem:' . date('oW'), t($d['kind'] === 'issue' ? 'hre.nf_rem_issue' : 'hre.nf_rem_return', ['no' => $d['doc_no'], 'n' => (string)($p['name_en'] ?? '')]), null, $d['kind'] === 'issue' ? url('my_equipment') : url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']), null, null);
            q('UPDATE hr_eq_docs SET reminded_at = NOW() WHERE id = ?', [(int)$d['id']]);
        }
        /* R97-125: equipment to be replaced within 30 days — HR and the employee are told once */
        $hr = hre_hr_uids();
        foreach (all("SELECT i.id, i.description, i.expires_on, p.name_en, p.user_id, cu.is_active AS acc FROM hr_eq_items i JOIN hr_people p ON p.id = i.person_id LEFT JOIN customer_users cu ON cu.id = p.user_id WHERE p.is_active = 1 AND i.status IN ('pending','held') AND i.expires_on IS NOT NULL AND i.expires_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND i.exp_notified IS NULL LIMIT 100") as $i) {
            $to = array_values(array_unique(array_filter(array_merge($hr, (int)$i['user_id'] && (int)$i['acc'] ? [(int)$i['user_id']] : []))));
            if ($to) $n += nf_push($to, 'hre', 'hre:exp:' . (int)$i['id'], t('hre.nf_exp', ['t' => mb_substr((string)$i['description'], 0, 80), 'n' => (string)$i['name_en'], 'd' => fmt_date($i['expires_on'])]), null, url('hr_equipment', ['st' => 'exp']), null, null);
            q('UPDATE hr_eq_items SET exp_notified = CURDATE() WHERE id = ?', [(int)$i['id']]);
        }
    } catch (Throwable $e) {}
    return $n;
}

/* ------------------------------------------------------------------ standard kits per discipline (R97-125) */
function hre_kits_has(): bool { static $h = null; if ($h === null) { try { $h = hre_has() && (bool) scalar("SHOW TABLES LIKE 'hr_eq_kits'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Kit lines: of one discipline (0 = everybody), or all. */
function hre_kits(?int $disc = null): array { if (!hre_kits_has()) return []; return $disc === null ? all('SELECT k.*, d.name AS discipline FROM hr_eq_kits k LEFT JOIN disciplines d ON d.id = k.discipline_id ORDER BY (k.discipline_id = 0) DESC, d.name, k.category, k.description') : all('SELECT * FROM hr_eq_kits WHERE discipline_id = ? ORDER BY category, description', [$disc]); }
function hre_kit_for(array $p): array { if (!hre_kits_has()) return []; $d = (int)($p['department_id'] ?? 0); return all('SELECT * FROM hr_eq_kits WHERE discipline_id = 0' . ($d ? ' OR discipline_id = ' . $d : '') . ' ORDER BY category, description'); }
function hre_kit_save_from_post(): void {
    if (!hre_can() || !hre_kits_has()) throw new RuntimeException(t('err.noaccess'));
    $cat = (string)($_POST['category'] ?? 'other'); if (!in_array($cat, HRE_CATS, true)) $cat = 'other'; $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200); if ($desc === '') throw new RuntimeException(t('hre.err_desc'));
    $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '1')); if ($qty <= 0 || $qty > 9999) $qty = 1.0; $rm = (int)($_POST['replace_months'] ?? 0); $rm = $rm > 0 && $rm <= 240 ? $rm : null;
    $disc = (int)($_POST['discipline_id'] ?? 0); if ($disc && !scalar('SELECT COUNT(*) FROM disciplines WHERE id = ?', [$disc])) $disc = 0;
    q('INSERT INTO hr_eq_kits (discipline_id, category, description, qty, replace_months) VALUES (?,?,?,?,?)', [$disc, $cat, $desc, $qty, $rm]);
}
function hre_kit_del_from_post(): void { if (!hre_can() || !hre_kits_has()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM hr_eq_kits WHERE id = ?', [(int)($_POST['kit_id'] ?? 0)]); }
/** The kit of the person's discipline (and the kit of everybody) as lines to be issued; a line the person already has is not added twice. Returns the number added. */
function hre_kit_apply(int $pid): int {
    $p = hr_person($pid); if (!$p) return 0; $have = array_map(fn($i) => mb_strtolower(trim((string)$i['description'])), hre_items($pid, ['draft', 'pending', 'held'])); $n = 0;
    foreach (hre_kit_for($p) as $k) { if (in_array(mb_strtolower(trim((string)$k['description'])), $have, true)) continue;
        q("INSERT INTO hr_eq_items (person_id, category, description, qty, cond_out, status, created_by, expires_on) VALUES (?,?,?,?,'new','draft',?,?)", [$pid, (string)$k['category'], (string)$k['description'], (float)$k['qty'], hre_me() ?: null, $k['replace_months'] ? date('Y-m-d', strtotime('+' . (int)$k['replace_months'] . ' months')) : null]); $n++; }
    if ($n) hre_audit($pid, t('hre.kit_title'), $n . ' ' . t('hre.lines'));
    return $n;
}
/** "My first weeks" started for an account (soc_onboard_start): the kit of the discipline waits on the card as lines to be issued. */
function hre_kit_on_start(int $uid): int { try { if (!hre_kits_has() || !function_exists('hr_person_of_user')) return 0; $p = hr_person_of_user($uid); if (!$p || hre_items((int)$p['id'])) return 0; return hre_kit_apply((int)$p['id']); } catch (Throwable $e) { return 0; } }

/* ------------------------------------------------------------------ Excel: what the personnel already hold (R97-125) */
const HRE_IMPORT_COLS = ['emp_code' => ['Employee code', 'Code'], 'name' => ['Employee', 'Employee name', 'Name'], 'category' => ['Category'], 'description' => ['Description'], 'serial_no' => ['Serial No.', 'Serial', 'Serial number'], 'asset_tag' => ['Company No.', 'Company number', 'Asset tag'],
                         'qty' => ['Qty', 'Quantity'], 'size' => ['Size'], 'cond_out' => ['Condition'], 'issued_on' => ['Given on', 'Issued on', 'Date'], 'expires_on' => ['Replace by', 'Expiry', 'Expires on'], 'note' => ['Notes', 'Note', 'Remarks']];
function hre_import_template(): void {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess')); require_once __DIR__ . '/xlsxw.php';
    $rows = [['', 'Name Surname', 'Computer / IT', 'Laptop — make and model', 'SERIAL-0001', 'IT-001', 1, '', 'Good', date('Y') . '-01-15', '', 'example row — delete it']];
    $lists = [['Category', implode(' · ', array_map(fn($c) => t_in('hre.c_' . $c, 'en'), HRE_CATS))], ['Condition', 'New · Good · Used · Worn'], ['Employee', t('hre.imp_n1')], ['Dates', t('hre.imp_n2')], ['', t('hre.imp_n3')]];
    xlsx_download('GAS-import-hr-equipment.xlsx', [['name' => 'Equipment', 'first' => true, 'header' => array_map(fn($a) => $a[0], array_values(HRE_IMPORT_COLS)), 'rows' => $rows, 'widths' => [16, 28, 20, 40, 20, 16, 8, 10, 12, 14, 14, 30]], ['name' => 'Lists and rules', 'header' => ['Column', 'Values'], 'rows' => $lists, 'widths' => [20, 120]]]);
}
/** Reads the workbook: every good row becomes a line to be issued on the card of the person. With $issue, one issue list per person is made
 *  and sent for acceptance. Returns ['added', 'people', 'lists', 'skipped' => [[row, reason]]]. The file is not kept. */
function hre_import_from_upload(bool $issue): array {
    if (!hre_can()) throw new RuntimeException(t('err.noaccess'));
    $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('hre.imp_nofile'));
    if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx' || (int)$f['size'] > 5 * 1024 * 1024) throw new RuntimeException(t('hre.imp_nofile'));
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($f['tmp_name']); if (count($rows) < 2) throw new RuntimeException(t('hre.imp_empty'));
    $map = xlsx_header_map($rows[0], HRE_IMPORT_COLS); if ($map['description'] === null || ($map['emp_code'] === null && $map['name'] === null)) throw new RuntimeException(t('hre.imp_cols'));
    $norm = fn(string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))); $byCode = []; $byName = [];
    foreach (hr_people(false) as $p) { if (!$p['is_active']) continue; if ((string)$p['emp_code'] !== '') $byCode[$norm((string)$p['emp_code'])] = (int)$p['id']; foreach (['name_en', 'name_el'] as $k) if ((string)($p[$k] ?? '') !== '') { $nn = $norm((string)$p[$k]); $byName[$nn] = isset($byName[$nn]) && $byName[$nn] !== (int)$p['id'] ? 0 : (int)$p['id']; } }
    $cats = []; foreach (HRE_CATS as $c) { $cats[$norm($c)] = $c; foreach (['en', 'el', 'it'] as $lg) $cats[$norm(t_in('hre.c_' . $c, $lg))] = $c; }
    $conds = []; foreach (HRE_CONDS as $c) { $conds[$norm($c)] = $c; foreach (['en', 'el', 'it'] as $lg) $conds[$norm(t_in('hre.k_' . $c, $lg))] = $c; }
    $get = fn(array $r, string $k) => $map[$k] === null ? '' : trim((string)($r[$map[$k]] ?? '')); $o = ['added' => 0, 'people' => [], 'lists' => 0, 'skipped' => []]; $pdo = db(); $pdo->beginTransaction();
    try {
        foreach (array_slice($rows, 1, 3000, true) as $ri => $r) {
            $desc = mb_substr($get($r, 'description'), 0, 200); $code = $get($r, 'emp_code'); $name = $get($r, 'name'); if ($desc === '' && $code === '' && $name === '') continue;
            if (stripos($get($r, 'note'), 'example row') !== false) continue;
            $pid = $code !== '' ? ($byCode[$norm($code)] ?? 0) : 0; if (!$pid && $name !== '') $pid = $byName[$norm($name)] ?? 0;
            if (!$pid) { $o['skipped'][] = [$ri + 1, t('hre.imp_noperson', ['n' => $code !== '' ? $code : $name])]; continue; }
            if ($desc === '') { $o['skipped'][] = [$ri + 1, t('hre.err_desc')]; continue; }
            $serial = mb_substr($get($r, 'serial_no'), 0, 80);
            if ($serial !== '' && scalar("SELECT COUNT(*) FROM hr_eq_items WHERE serial_no = ? AND status IN ('draft','pending','held','missing')", [$serial])) { $o['skipped'][] = [$ri + 1, t('hre.imp_dup', ['s' => $serial])]; continue; }
            $qty = (float) str_replace(',', '.', $get($r, 'qty') ?: '1'); if ($qty <= 0 || $qty > 9999) $qty = 1.0;
            q('INSERT INTO hr_eq_items (person_id, category, description, serial_no, asset_tag, qty, size, cond_out, note, status, issued_on, expires_on, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$pid, $cats[$norm($get($r, 'category'))] ?? 'other', $desc, $serial ?: null, mb_substr($get($r, 'asset_tag'), 0, 40) ?: null, $qty, mb_substr($get($r, 'size'), 0, 20) ?: null, $conds[$norm($get($r, 'cond_out'))] ?? 'used', mb_substr($get($r, 'note'), 0, 300) ?: null, 'draft', xlsx_date($get($r, 'issued_on')), xlsx_date($get($r, 'expires_on')), hre_me()]);
            $o['added']++; $o['people'][$pid][] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if ($issue) foreach ($o['people'] as $pid => $ids) { try { $_POST['person_id'] = $pid; $_POST['it'] = $ids; $_POST['note'] = t('hre.imp_list_note'); hre_issue_from_post(true); $o['lists']++; } catch (Throwable $e) {} }
    $o['people'] = count($o['people']); return $o;
}

/* ------------------------------------------------------------------ PDF */
function hre_pdf(array $d): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $navy = '#1F3A5F'; $gold = '#C9A227'; $green = '#2E7D32'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $isRet = $d['kind'] === 'return'; $title = t($isRet ? 'hre.return_list' : 'hre.issue_list'); $docNo = 'GAS-HR-' . $d['doc_no'];
    $p = hr_person((int)$d['person_id']) ?: ['name_en' => '', 'emp_code' => '', 'department' => '', 'job_title' => '']; $items = hre_doc_items($d);
    $bd = function_exists('br_doc') ? br_doc(null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $title) {
        $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], $L, 10, 45, 16);
        $pdf->setColor($navy); $pdf->setFont('B', 14); $pdf->text($R, 18, mb_strtoupper($title), 'R');
        $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($R, 24, $docNo, 'R');
        $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 37;
    };
    $footer = function () use ($pdf, $L, $R, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 278) { $footer(); $header(); return true; } return false; };
    $h2 = function (string $s) use ($pdf, &$y, $navy, $gold, $L, $R, $need) { $need(14); $y += 2; $pdf->setFont('B', 10.5); $pdf->setColor($navy); $pdf->text($L, $y + 4, $s); $y += 6; $pdf->setColor($gold); $pdf->setLineWidth(0.35); $pdf->line($L, $y, $R, $y); $y += 3.5; };
    $para = function (string $s) use ($pdf, &$y, $ink, $L, $W, $need) { foreach ($pdf->wrap($s, $W) as $ln) { $need(4.6); $pdf->setFont('R', 9); $pdf->setColor($ink); $pdf->text($L, $y + 3.4, $ln); $y += 4.6; } $y += 1.5; };
    $grid = function (array $rows, array $wcol) use ($pdf, &$y, $navy, $ink, $line, $head, $L, $need) {
        foreach ($rows as $r) { $pdf->setFont('R', 8.6); $hh = (float)($r['h'] ?? 7.0); unset($r['h']); foreach ([1, 3] as $k) if (isset($r[$k])) $hh = max($hh, count($pdf->wrap((string)$r[$k], $wcol[$k] - 3)) * 4.0 + 3); $need($hh);
            $x = $L; foreach ($r as $k => $cell) { $cw = $wcol[$k]; if ($k % 2 === 0) { $pdf->setColor($head); $pdf->rect($x, $y, $cw, $hh, 'F'); $pdf->setColor($navy); $pdf->setFont('B', 7.6); $pdf->text($x + 1.5, $y + 4.6, (string)$cell); }
                else { $pdf->setColor($ink); $pdf->setFont('R', 8.6); foreach ($pdf->wrap((string)$cell, $cw - 3) as $j => $ln) $pdf->text($x + 1.5, $y + 4.6 + $j * 4.0, $ln); }
                $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->rect($x, $y, $cw, $hh, 'S'); $x += $cw; }
            $y += $hh; }
        $y += 4;
    };
    $dt = fn($v) => $v ? fmt_date(substr((string)$v, 0, 10)) : '—'; $dtt = fn($v) => $v ? fmt_datetime((string)$v) : '';
    $header(); $footer();
    $grid([[t('hr.person'), (string)$p['name_en'], t('hre.f_code'), (string)($p['emp_code'] ?? '') ?: '—'],
           [t('hre.f_discipline'), (string)($p['department'] ?? '') ?: '—', t('hre.f_job'), (string)($p['job_title'] ?? '') ?: '—'],
           [t('hre.f_docno'), $docNo, t('th.date'), $dt($d['doc_date'])],
           [t('hre.f_prepared'), (string)$d['created_name'] ?: '—', t('th.status'), t('hre.ds_' . $d['status'] . ($isRet && $d['status'] === 'open' ? '_r' : ''))]], [30, 60, 30, 60]);
    if (trim((string)$d['note']) !== '') $para((string)$d['note']);
    /* the lines */
    $cols = $isRet ? [[8, '#'], [26, t('hre.category')], [62, t('hre.description')], [34, t('hre.serial') . ' / ' . t('hre.tag')], [12, t('hre.qty')], [38, t('hre.result')]]
                   : [[8, '#'], [28, t('hre.category')], [70, t('hre.description')], [38, t('hre.serial') . ' / ' . t('hre.tag')], [12, t('hre.qty')], [24, t('hre.cond')]];
    $thead = function () use ($pdf, &$y, $cols, $L, $navy) { $x = $L; $pdf->setColor($navy); $pdf->rect($L, $y, array_sum(array_column($cols, 0)), 7, 'F'); $pdf->setColor('#FFFFFF'); $pdf->setFont('B', 7.6); foreach ($cols as [$cw, $lb]) { $pdf->text($x + 1.5, $y + 4.7, $lb); $x += $cw; } $y += 7; };
    $h2(t('hre.lines_title') . ' (' . count($items) . ')'); $thead();
    foreach ($items as $n => $i) {
        $sn = trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /'); $desc = (string)$i['description'] . ((string)$i['size'] !== '' ? ' — ' . t('hre.size') . ' ' . $i['size'] : '') . ((string)$i['note'] !== '' ? ' (' . $i['note'] . ')' : '');
        $last = $isRet ? ($d['status'] === 'accepted' ? t('hre.r_' . (in_array($i['status'], ['missing', 'followed'], true) ? 'missing' : ($i['status'] === 'writeoff' ? 'writeoff' : ($i['cond_in'] === 'damaged' ? 'damaged' : 'ok')))) . ((string)$i['return_note'] !== '' ? ' — ' . $i['return_note'] : '') : '') : t('hre.k_' . $i['cond_out']);
        $cells = [(string)($n + 1), hre_cat_label((string)$i['category']), $desc, $sn, hre_qty($i['qty']), $last];
        $pdf->setFont('R', 8.4); $hh = 6.5; foreach ($cells as $k => $c) $hh = max($hh, count($pdf->wrap($c, $cols[$k][0] - 3)) * 3.9 + 2.6);
        if ($need($hh)) $thead();
        $x = $L; foreach ($cells as $k => $c) { $cw = $cols[$k][0]; $pdf->setColor($ink); $pdf->setFont('R', 8.4); foreach ($pdf->wrap($c, $cw - 3) as $j => $ln) $pdf->text($x + 1.5, $y + 4.3 + $j * 3.9, $ln); $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->rect($x, $y, $cw, $hh, 'S'); $x += $cw; }
        $y += $hh;
    }
    $y += 5;
    /* declaration and acceptance */
    $h2(t($isRet ? 'hre.s_decl_r' : 'hre.s_decl')); foreach (range(1, $isRet ? 2 : 4) as $k) $para('•  ' . t(($isRet ? 'hre.decl_r' : 'hre.decl_') . $k));
    $need(48); $h2(t('hre.s_accept'));
    $how = fn(?string $via) => $via ? ' (' . t('hre.via_' . $via) . ')' : '';
    if ($isRet) $grid([[t('hre.a_handed'), (string)($d['emp_name'] ?: $p['name_en']), t('th.date'), $d['emp_at'] ? $dtt($d['emp_at']) . $how($d['emp_via']) : ''],
                       [t('hre.a_sign'), '', '', '', 'h' => 15.0],
                       [t('hre.a_received'), (string)$d['hr_name'], t('th.date'), $d['hr_at'] ? $dtt($d['hr_at']) . $how('portal') : ''],
                       [t('hre.a_sign'), '', '', '', 'h' => 15.0]], [60, 54, 14, 52]);
    else $grid([[t('hre.a_issued'), (string)$d['created_name'], t('th.date'), $dt($d['doc_date'])],
                [t('hre.a_sign'), '', '', '', 'h' => 15.0],
                [t('hre.a_accepted'), (string)($d['emp_name'] ?: $p['name_en']), t('th.date'), $d['emp_at'] && $d['status'] === 'accepted' ? $dtt($d['emp_at']) . $how($d['emp_via']) : ''],
                [t('hre.a_sign'), '', '', '', 'h' => 15.0]], [60, 54, 14, 52]);
    if ($d['status'] === 'disputed' && (string)$d['emp_remark'] !== '') $para(t('hre.ds_disputed') . ': ' . $d['emp_remark']);
    return $pdf->bytes($docNo . ' ' . $title);
}
function hre_pdf_out(array $d): void {
    $b = hre_pdf($d); while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="GAS-HR-' . $d['doc_no'] . '.pdf"'); header('Content-Length: ' . strlen($b)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); echo $b; exit;
}

/* ------------------------------------------------------------------ actions (index.php, before pm_handle_action) */
function hre_handle_action(string $action): bool {
    if (!str_starts_with($action, 'hre_')) return false;
    if (!hre_has()) throw new RuntimeException(t('err.noaccess'));
    $back = fn(int $pid, string $hash = '#eq-lines') => redirect('hr_person', ['id' => $pid, 'tab' => 'eq', '_r' => uniqid()], $hash);
    switch ($action) {
        case 'hre_add':      $pid = hre_add_from_post(); flash('good', t('hre.added')); if (!hre_can()) redirect('eq_prepare', ['person' => $pid, '_r' => uniqid()]); $back($pid, '#eq-add');
        case 'hre_del':      $pid = hre_del_from_post(); flash('good', t('hre.removed')); if (!hre_can()) redirect('eq_prepare', ['person' => $pid, '_r' => uniqid()]); $back($pid, '#eq-add');
        case 'hre_kit_save': hre_kit_save_from_post(); flash('good', t('hre.added')); redirect('hr_equipment', ['_r' => uniqid()], '#eq-kits');
        case 'hre_kit_del':  hre_kit_del_from_post(); flash('good', t('hre.removed')); redirect('hr_equipment', ['_r' => uniqid()], '#eq-kits');
        case 'hre_kit_apply': { if (!hre_can()) throw new RuntimeException(t('err.noaccess')); $pid = (int)($_POST['person_id'] ?? 0); $n = hre_kit_apply($pid); flash($n ? 'good' : 'warn', t($n ? 'hre.kit_added' : 'hre.kit_nothing', ['n' => $n])); $back($pid, '#eq-add'); }
        case 'hre_import':   { $r = hre_import_from_upload(!empty($_POST['issue'])); $_SESSION['hre_import'] = $r; flash($r['added'] ? 'good' : 'warn', t('hre.imp_done', ['a' => $r['added'], 'p' => $r['people'], 'l' => $r['lists'], 's' => count($r['skipped'])])); redirect('hr_equipment', ['st' => 'draft', '_r' => uniqid()], '#eq-import'); }
        case 'hre_issue_all': { [$xl, $xn, $xa] = hre_issue_all(); flash($xl ? 'good' : 'warn', t($xa ? 'hre.dr_done_paper' : 'hre.dr_done', ['l' => $xl, 'n' => $xn, 'a' => $xa])); redirect('hr_equipment', ['_r' => uniqid()], '#eq-wait'); }   // R97-287
        case 'hre_issue':    $d = hre_issue_from_post(); flash('good', t('hre.issued', ['no' => $d['doc_no']])); $back((int)$d['person_id'], '#eq-docs');
        case 'hre_paper':    $d = hre_accept((int)($_POST['doc_id'] ?? 0), (string)($_POST['signer'] ?? '')); flash('good', t('hre.accepted_ok', ['no' => $d['doc_no']])); $back((int)$d['person_id'], '#eq-docs');
        case 'hre_cancel':   $d = hre_cancel((int)($_POST['doc_id'] ?? 0)); flash('good', t('hre.cancelled', ['no' => $d['doc_no']])); $back((int)$d['person_id'], '#eq-docs');
        case 'hre_receive':  $d = hre_receive_from_post(); flash('good', t('hre.received_ok', ['no' => $d['doc_no']])); $back((int)$d['person_id'], '#eq-docs');
        case 'hre_handover': { $byHr = hre_can() && (int)($_POST['person_id'] ?? 0) > 0; $d = hre_handover_from_post(); flash('good', t('hre.handover_ok', ['no' => $d['doc_no']])); if ($byHr) $back((int)$d['person_id'], '#eq-docs'); redirect('my_equipment', ['_r' => uniqid()]); }
        case 'hre_accept':   $d = hre_accept((int)($_POST['doc_id'] ?? 0)); flash('good', t($d['kind'] === 'issue' ? 'hre.accepted_ok' : 'hre.confirmed_ok', ['no' => $d['doc_no']])); redirect('my_equipment', ['_r' => uniqid()]);
        case 'hre_dispute':  hre_dispute((int)($_POST['doc_id'] ?? 0), (string)($_POST['remark'] ?? '')); flash('good', t('hre.disputed_ok')); redirect('my_equipment', ['_r' => uniqid()]);
    }
    throw new RuntimeException(t('err.noaccess'));
}
