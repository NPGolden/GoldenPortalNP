<?php
/**
 * Engineering BOM revisions.                                  GAS-CP-R80-002
 * Engineering (the sub-project team, the PM, the enabled disciplines, admins) uploads or
 * types the bill of materials of a sub-project as revision 0, 1, 2 …; Procurement reviews
 * the issued revision against the inventory (coverage), proposes changes (alternative part,
 * quantity, removal, addition) with a reason; Engineering accepts or rejects each change;
 * Procurement approves the revision when nothing is pending — it becomes the current BOM
 * (the earlier approved one is superseded). "Requisition from BOM" turns the uncovered
 * lines into a draft purchase requisition (R80-001).
 * Tables: bom_revisions · bom_lines · bom_changes · bom_events.
 */
declare(strict_types=1);

const BOM_STATES = ['draft', 'issued', 'approved', 'superseded', 'rejected', 'withdrawn'];
const BOM_TONE   = ['draft' => 'mute', 'issued' => 'gold', 'approved' => 'ok', 'superseded' => 'navy', 'rejected' => 'bad', 'withdrawn' => 'mute'];
const BOM_KINDS  = ['alternative', 'qty', 'remove', 'add'];
const BOM_C_TONE = ['proposed' => 'gold', 'accepted' => 'ok', 'rejected' => 'bad', 'withdrawn' => 'mute'];

function bom_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'bom_revisions'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** Engineering side: admins, PMs, the sub-project team, the enabled disciplines. */
function bom_eng_can(int $sid): bool {
    $u = current_user(); if (!$u || !is_staff() || !bom_has()) return false; static $c = [];
    if (isset($c[$sid])) return $c[$sid];
    if (is_cp_admin() || (function_exists('pm_is_pm') && pm_is_pm((int)$u['id']))) return $c[$sid] = true;
    if ((int) scalar('SELECT p.pm_user_id FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sid]) === (int)$u['id']) return $c[$sid] = true;
    try { if (scalar('SELECT 1 FROM sp_team WHERE subproject_id = ? AND user_id = ?', [$sid, (int)$u['id']])) return $c[$sid] = true; } catch (Throwable $e) {}
    $d = (int)($u['discipline_id'] ?? 0); return $c[$sid] = $d > 0 && function_exists('stk_enabled_disciplines') && in_array($d, stk_enabled_disciplines(), true);
}
/** Procurement side. */
function bom_pr_can(): bool { return function_exists('pr_admin') && pr_admin(); }
function bom_can_view(int $sid): bool { return is_staff() && bom_has() && (bom_eng_can($sid) || (function_exists('pr_can') && pr_can()) || (function_exists('fin_visible') && fin_visible())); }
function bom_pill(string $s): string { return pill(BOM_TONE[$s] ?? 'mute', t('bom.s_' . $s)); }
function bom_c_pill(string $s): string { return pill(BOM_C_TONE[$s] ?? 'mute', t('bom.c_' . $s)); }

/* ------------------------------------------------------------------ read */
const BOM_SELECT = "SELECT b.*, s.code AS sub_code, s.name AS sub_name, s.project_id, p.project_code, p.name AS project_name,
                    (SELECT COUNT(*) FROM bom_lines l WHERE l.bom_rev_id = b.id AND l.status <> 'removed') AS n_lines,
                    (SELECT COUNT(*) FROM bom_changes c WHERE c.bom_rev_id = b.id AND c.status = 'proposed') AS n_pending
                    FROM bom_revisions b JOIN subprojects s ON s.id = b.subproject_id JOIN projects p ON p.id = s.project_id";
function bom_load(int $id): ?array { return bom_has() && $id ? one(BOM_SELECT . ' WHERE b.id = ?', [$id]) ?: null : null; }
function bom_revisions(int $sid): array { return bom_has() ? all(BOM_SELECT . ' WHERE b.subproject_id = ? ORDER BY b.revision DESC', [$sid]) : []; }
function bom_current(int $sid): ?array { return bom_has() ? one(BOM_SELECT . " WHERE b.subproject_id = ? AND b.status = 'approved' ORDER BY b.revision DESC LIMIT 1", [$sid]) ?: null : null; }
/** Lines with inventory stock and coverage: free = on hand − reserved; covered / partial / to buy. */
function bom_lines(int $bid): array {
    $rows = all('SELECT l.*, i.code AS item_code, i.description AS item_desc, i.unit AS item_unit, i.last_price, i.status AS item_status,
                 (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS on_hand,
                 (SELECT COALESCE(SUM(s.qty_reserved),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS reserved,
                 (SELECT COUNT(*) FROM bom_changes c WHERE c.line_id = l.id AND c.status = \'proposed\') AS n_pending
                 FROM bom_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.bom_rev_id = ? ORDER BY l.seq, l.id', [$bid]);
    foreach ($rows as &$l) { $free = max(0.0, (float)$l['on_hand'] - (float)$l['reserved']); $l['free'] = $free; $l['cover'] = !$l['item_id'] ? 'none' : ($free >= (float)$l['qty'] - 0.0005 ? 'full' : ($free > 0 ? 'partial' : 'zero')); $l['to_buy'] = $l['item_id'] ? max(0.0, (float)$l['qty'] - $free) : (float)$l['qty']; }
    return $rows;
}
function bom_changes(int $bid): array { return all('SELECT c.*, a.code AS alt_code, a.description AS alt_desc, l.seq AS line_seq, l.description AS line_desc, l.qty AS line_qty FROM bom_changes c LEFT JOIN inv_items a ON a.id = c.alt_item_id LEFT JOIN bom_lines l ON l.id = c.line_id WHERE c.bom_rev_id = ? ORDER BY c.id DESC', [$bid]); }
function bom_events(int $bid): array { return all('SELECT * FROM bom_events WHERE bom_rev_id = ? ORDER BY id DESC', [$bid]); }
function bom_event(int $bid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO bom_events (bom_rev_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)', [$bid, $ev, $u['id'] ?? null, $u['full_name'] ?? null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $note !== null ? mb_substr($note, 0, 600) : null]); }
function bom_coverage(array $lines): array {
    $c = ['lines' => 0, 'full' => 0, 'partial' => 0, 'zero' => 0, 'none' => 0, 'to_buy' => 0, 'est' => 0.0, 'est_to_buy' => 0.0];
    foreach ($lines as $l) { if ($l['status'] === 'removed') continue; $c['lines']++; $c[$l['cover']]++; if ($l['to_buy'] > 0) $c['to_buy']++; if ($l['est_price'] !== null) { $c['est'] += (float)$l['qty'] * (float)$l['est_price']; $c['est_to_buy'] += (float)$l['to_buy'] * (float)$l['est_price']; } }
    return $c;
}
function bom_recalc(int $bid): float {
    $t = 0.0;
    foreach (all("SELECT * FROM bom_lines WHERE bom_rev_id = ? AND status <> 'removed'", [$bid]) as $l) {
        $e = prq_line_estimate($l);
        q('UPDATE bom_lines SET est_price = ?, price_origin = ? WHERE id = ?', [$e['price'], $e['origin'], (int)$l['id']]);
        if ($e['price'] !== null) $t += (float)$l['qty'] * $e['price'];
    }
    q('UPDATE bom_revisions SET est_total = ? WHERE id = ?', [round($t, 2), $bid]); return round($t, 2);
}
function bom_notify(array $ids, int $bid, string $kind, string $title, ?string $body = null): void {
    if (!function_exists('nf_push') || !$ids) return; $u = current_user();
    try { nf_push(array_values(array_unique(array_filter(array_map('intval', $ids)))), 'work:' . $kind, 'bom:' . $bid . ':' . $kind . ':' . time(), $title, $body, url('bom', ['id' => $bid]), (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null); } catch (Throwable $e) {}
}
/** Engineering people of a sub-project: the creator / issuer of the revision, the PM, the team. */
function bom_eng_ids(array $b): array {
    $ids = [(int)$b['created_by'], (int)$b['issued_by'], (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [(int)$b['project_id']])];
    try { foreach (all('SELECT user_id FROM sp_team WHERE subproject_id = ?', [(int)$b['subproject_id']]) as $r) $ids[] = (int)$r['user_id']; } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($ids)));
}

/* ------------------------------------------------------------------ write (engineering) */
function bom_create_from_post(): int {
    $sid = (int)($_POST['subproject_id'] ?? 0); $sp = $sid ? load_subproject($sid) : null; if (!$sp) throw new RuntimeException(t('err.notfound'));
    if (!bom_eng_can($sid)) throw new RuntimeException(t('err.noaccess')); $u = current_user();
    $rev = (int) scalar('SELECT COALESCE(MAX(revision), -1) + 1 FROM bom_revisions WHERE subproject_id = ?', [$sid]);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160) ?: null; $note = trim((string)($_POST['note'] ?? '')) ?: null;
    $from = (int)($_POST['copy_from'] ?? 0);
    q('INSERT INTO bom_revisions (subproject_id, revision, title, created_by, created_name, note) VALUES (?,?,?,?,?,?)', [$sid, $rev, $title, (int)$u['id'], $u['full_name'], $note]);
    $bid = (int) db()->lastInsertId(); bom_event($bid, 'create', 'R' . $rev);
    if ($from) { $src = bom_load($from); if ($src && (int)$src['subproject_id'] === $sid) { foreach (all("SELECT * FROM bom_lines WHERE bom_rev_id = ? AND status <> 'removed' ORDER BY seq, id", [$from]) as $i => $l) q('INSERT INTO bom_lines (bom_rev_id, seq, item_id, code, description, maker, part_no, unit, qty, section, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$bid, $i + 1, $l['item_id'], $l['code'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], $l['qty'], $l['section'], $l['note']]); bom_event($bid, 'copy', 'R' . $src['revision']); } }
    if (!empty($_FILES['file']['tmp_name'])) bom_import($bid, $_FILES['file']);
    bom_recalc($bid); return $bid;
}
/** Upload: xlsx / csv with Code · Description · Qty · Unit · Maker · Part no · Section (Greek headers of the ERP file accepted). Codes are matched to the inventory. */
function bom_import(int $bid, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('im.file'));
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) throw new RuntimeException(t('bom.file_big'));
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($file['tmp_name']); if (count($rows) < 2) throw new RuntimeException(t('stk.imp_empty'));
    $H = ['code' => ['κωδικός', 'code', 'κωδ.', 'item', 'part code', 'article'], 'description' => ['περιγραφή', 'description', 'desc'], 'qty' => ['ποσότητα', 'ποσ.', 'qty', 'quantity', 'q.ty', 'υπόλοιπο'], 'unit' => ['μ.μ', 'μ.μ.', 'unit', 'um', 'uom', 'μον.'], 'maker' => ['κατασκευαστής', 'maker', 'manufacturer', 'brand'], 'part_no' => ['κωδ. εργοστασίου', 'κωδ.εργοστασίου', 'part no', 'part no.', 'part number', 'p/n', 'factory code', 'type'], 'section' => ['section', 'ενότητα', 'group', 'panel', 'drawing', 'σχέδιο'], 'note' => ['note', 'notes', 'σημείωση', 'remarks']];
    $map = []; foreach ($rows[0] as $i => $h) { $n = mb_strtolower(trim((string)$h)); if ($n === '') continue; foreach ($H as $k => $alts) if (!isset($map[$k]) && in_array($n, $alts, true)) { $map[$k] = $i; break; } }
    if (!isset($map['description']) && !isset($map['code'])) throw new RuntimeException(t('stk.imp_no_cols'));
    $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) FROM bom_lines WHERE bom_rev_id = ?', [$bid]); $n = 0; $matched = 0; $warn = [];
    $num = function ($v): ?float { $v = str_replace(['€', ' '], '', trim((string)$v)); if ($v === '') return null; if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); return is_numeric($v) ? (float)$v : null; };
    foreach (array_slice($rows, 1) as $i => $r) {
        $g = fn(string $k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
        $code = mb_substr($g('code'), 0, 60); $desc = $g('description'); if ($code === '' && $desc === '') continue;
        $it = $code !== '' ? stk_item_by_code($code) : null; if ($it) $matched++;
        if ($desc === '' && $it) $desc = (string)$it['description']; if ($desc === '') { $warn[] = t('stk.imp_w_nodesc', ['l' => $i + 2, 'c' => $code]); continue; }
        $qty = $num($g('qty')) ?? 1.0; if ($qty <= 0) $qty = 1.0;
        $unit = $it ? (string)$it['unit'] : (stk_unit_norm($g('unit')) ?: 'pcs');
        q('INSERT INTO bom_lines (bom_rev_id, seq, item_id, code, description, maker, part_no, unit, qty, section, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$bid, ++$seq, $it ? (int)$it['id'] : null, $code ?: null, mb_substr($desc, 0, 255), $it ? $it['maker'] : (mb_substr($g('maker'), 0, 80) ?: null), $it ? $it['factory_code'] : (mb_substr($g('part_no'), 0, 80) ?: null), $unit, $qty, mb_substr($g('section'), 0, 80) ?: null, mb_substr($g('note'), 0, 255) ?: null]);
        $n++;
    }
    bom_event($bid, 'import', t('bom.imported_n', ['n' => $n, 'm' => $matched]) . ($warn ? ' · ' . implode(' · ', array_slice($warn, 0, 5)) : ''));
    return ['n' => $n, 'matched' => $matched, 'warn' => $warn];
}
function bom_editable(array $b): bool { return $b['status'] === 'draft' && bom_eng_can((int)$b['subproject_id']); }
function bom_line_save_from_post(): int {
    $bid = (int)($_POST['bom_id'] ?? 0); $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess'));
    $lid = (int)($_POST['line_id'] ?? 0); $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '0')); if ($qty <= 0) throw new RuntimeException(t('prq.need_qty'));
    $itemId = (int)($_POST['item_id'] ?? 0) ?: null; $it = $itemId ? stk_item($itemId) : null; if ($itemId && !$it) throw new RuntimeException(t('err.notfound'));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255); if ($it) $desc = $desc ?: (string)$it['description']; if ($desc === '') throw new RuntimeException(t('prq.need_desc'));
    $unit = $it ? (string)$it['unit'] : (in_array($_POST['unit'] ?? '', STK_UNITS, true) ? $_POST['unit'] : 'pcs');
    $maker = $it ? ($it['maker'] ?? null) : (mb_substr(trim((string)($_POST['maker'] ?? '')), 0, 80) ?: null); $pn = $it ? ($it['factory_code'] ?? null) : (mb_substr(trim((string)($_POST['part_no'] ?? '')), 0, 80) ?: null);
    $sec = mb_substr(trim((string)($_POST['section'] ?? '')), 0, 80) ?: null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    if ($lid) { if (!scalar('SELECT 1 FROM bom_lines WHERE id = ? AND bom_rev_id = ?', [$lid, $bid])) throw new RuntimeException(t('err.notfound')); q('UPDATE bom_lines SET item_id = ?, code = ?, description = ?, maker = ?, part_no = ?, unit = ?, qty = ?, section = ?, note = ? WHERE id = ?', [$itemId, $it ? $it['code'] : null, $desc, $maker, $pn, $unit, $qty, $sec, $note, $lid]); }
    else { $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM bom_lines WHERE bom_rev_id = ?', [$bid]); q('INSERT INTO bom_lines (bom_rev_id, seq, item_id, code, description, maker, part_no, unit, qty, section, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$bid, $seq, $itemId, $it ? $it['code'] : null, $desc, $maker, $pn, $unit, $qty, $sec, $note]); $lid = (int) db()->lastInsertId(); }
    bom_recalc($bid); return $lid;
}
function bom_line_delete(int $bid, int $lid): void { $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM bom_lines WHERE id = ? AND bom_rev_id = ?', [$lid, $bid]); bom_recalc($bid); }
/** Engineering issues the revision to Procurement. */
function bom_issue(int $bid): void {
    $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess'));
    if (!(int) scalar("SELECT COUNT(*) FROM bom_lines WHERE bom_rev_id = ? AND status <> 'removed'", [$bid])) throw new RuntimeException(t('prq.need_lines'));
    $u = current_user(); bom_recalc($bid);
    q('UPDATE bom_revisions SET status = ?, issued_by = ?, issued_name = ?, issued_at = NOW() WHERE id = ?', ['issued', (int)$u['id'], $u['full_name'], $bid]); bom_event($bid, 'issue');
    bom_notify(prq_proc_ids(), $bid, 'bom_issued', t('bom.nf_issued', ['s' => $b['sub_code'], 'r' => 'R' . $b['revision']]), $b['title'] ?: $b['sub_name']);
}
function bom_withdraw(int $bid, string $note): void {
    $b = bom_load($bid); if (!$b || $b['status'] !== 'issued' || !bom_eng_can((int)$b['subproject_id'])) throw new RuntimeException(t('err.noaccess'));
    q("UPDATE bom_changes SET status = 'withdrawn' WHERE bom_rev_id = ? AND status = 'proposed'", [$bid]);
    q('UPDATE bom_revisions SET status = ? WHERE id = ?', ['draft', $bid]); bom_event($bid, 'withdraw', $note ?: null);
}

/* ------------------------------------------------------------------ write (procurement) */
function bom_change_from_post(): int {
    if (!bom_pr_can()) throw new RuntimeException(t('err.noaccess'));
    $bid = (int)($_POST['bom_id'] ?? 0); $b = bom_load($bid); if (!$b || $b['status'] !== 'issued') throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($_POST['kind'] ?? '', BOM_KINDS, true) ? $_POST['kind'] : 'alternative'; $lid = (int)($_POST['line_id'] ?? 0) ?: null;
    if ($kind !== 'add' && (!$lid || !scalar('SELECT 1 FROM bom_lines WHERE id = ? AND bom_rev_id = ?', [$lid, $bid]))) throw new RuntimeException(t('err.notfound'));
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 600); if ($reason === '') throw new RuntimeException(t('prq.need_reason'));
    $alt = (int)($_POST['alt_item_id'] ?? 0) ?: null; $it = $alt ? stk_item($alt) : null; if ($alt && !$it) throw new RuntimeException(t('err.notfound'));
    $qty = ($_POST['qty'] ?? '') !== '' ? (float) str_replace(',', '.', (string)$_POST['qty']) : null;
    $ad = $it ? (string)$it['description'] : (mb_substr(trim((string)($_POST['alt_description'] ?? '')), 0, 255) ?: null);
    if (in_array($kind, ['alternative', 'add'], true) && !$it && !$ad) throw new RuntimeException(t('bom.need_alt'));
    if (($kind === 'qty' || $kind === 'add') && (!$qty || $qty <= 0)) throw new RuntimeException(t('prq.need_qty'));
    $u = current_user();
    q('INSERT INTO bom_changes (bom_rev_id, line_id, kind, alt_item_id, alt_description, alt_maker, alt_part_no, qty, reason, proposed_by, proposed_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$bid, $kind === 'add' ? null : $lid, $kind, $alt, $ad, $it ? $it['maker'] : (mb_substr(trim((string)($_POST['alt_maker'] ?? '')), 0, 80) ?: null), $it ? $it['factory_code'] : (mb_substr(trim((string)($_POST['alt_part_no'] ?? '')), 0, 80) ?: null), $qty, $reason, (int)$u['id'], $u['full_name']]);
    $cid = (int) db()->lastInsertId(); bom_event($bid, 'propose', t('bom.k_' . $kind) . ' · ' . $reason);
    bom_notify(bom_eng_ids($b), $bid, 'bom_change', t('bom.nf_change', ['s' => $b['sub_code'], 'r' => 'R' . $b['revision']]), t('bom.k_' . $kind) . ' · ' . $reason);
    return $cid;
}
/** Engineering accepts (applied to the line) or rejects a proposed change. */
function bom_change_decide(int $cid, string $decision, string $note): void {
    $c = one('SELECT * FROM bom_changes WHERE id = ?', [$cid]); if (!$c || $c['status'] !== 'proposed') throw new RuntimeException(t('err.notfound'));
    $b = bom_load((int)$c['bom_rev_id']); if (!$b || $b['status'] !== 'issued' || !bom_eng_can((int)$b['subproject_id'])) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $note = mb_substr(trim($note), 0, 400);
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q('UPDATE bom_changes SET status = ?, decided_by = ?, decided_name = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', ['rejected', (int)$u['id'], $u['full_name'], $note, $cid]); bom_event((int)$b['id'], 'reject_change', t('bom.k_' . $c['kind']) . ' · ' . $note); }
    else {
        $bid = (int)$b['id'];
        switch ($c['kind']) {
            case 'alternative': $it = $c['alt_item_id'] ? stk_item((int)$c['alt_item_id']) : null;
                q('UPDATE bom_lines SET item_id = ?, code = ?, description = ?, maker = ?, part_no = ?, unit = COALESCE(?, unit), status = ?, note = CONCAT_WS(\' · \', NULLIF(note, \'\'), ?) WHERE id = ?', [$it ? (int)$it['id'] : null, $it ? $it['code'] : null, $it ? $it['description'] : $c['alt_description'], $it ? $it['maker'] : $c['alt_maker'], $it ? $it['factory_code'] : $c['alt_part_no'], $it ? $it['unit'] : null, 'changed', t('bom.was', ['v' => (string) scalar('SELECT COALESCE(code, description) FROM bom_lines WHERE id = ?', [(int)$c['line_id']])]), (int)$c['line_id']]); break;
            case 'qty': q('UPDATE bom_lines SET qty = ?, status = ? WHERE id = ?', [(float)$c['qty'], 'changed', (int)$c['line_id']]); break;
            case 'remove': q('UPDATE bom_lines SET status = ? WHERE id = ?', ['removed', (int)$c['line_id']]); break;
            case 'add': $it = $c['alt_item_id'] ? stk_item((int)$c['alt_item_id']) : null; $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM bom_lines WHERE bom_rev_id = ?', [$bid]);
                q('INSERT INTO bom_lines (bom_rev_id, seq, item_id, code, description, maker, part_no, unit, qty, status, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$bid, $seq, $it ? (int)$it['id'] : null, $it ? $it['code'] : null, $it ? $it['description'] : $c['alt_description'], $it ? $it['maker'] : $c['alt_maker'], $it ? $it['factory_code'] : $c['alt_part_no'], $it ? $it['unit'] : 'pcs', (float)$c['qty'], 'changed', t('bom.added_by_proc')]); break;
        }
        q('UPDATE bom_changes SET status = ?, decided_by = ?, decided_name = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', ['accepted', (int)$u['id'], $u['full_name'], $note ?: null, $cid]);
        bom_recalc($bid); bom_event($bid, 'accept_change', t('bom.k_' . $c['kind']) . ($note !== '' ? ' · ' . $note : ''));
    }
    bom_notify([(int)$c['proposed_by']], (int)$b['id'], 'bom_decided', t($decision === 'reject' ? 'bom.nf_change_rejected' : 'bom.nf_change_accepted', ['s' => $b['sub_code'], 'r' => 'R' . $b['revision']]), $note ?: null);
}
function bom_change_withdraw(int $cid): void { if (!bom_pr_can()) throw new RuntimeException(t('err.noaccess')); $c = one('SELECT * FROM bom_changes WHERE id = ? AND status = ?', [$cid, 'proposed']); if (!$c) throw new RuntimeException(t('err.notfound')); q("UPDATE bom_changes SET status = 'withdrawn' WHERE id = ?", [$cid]); bom_event((int)$c['bom_rev_id'], 'withdraw_change', t('bom.k_' . $c['kind'])); }
/** Procurement approves (nothing pending) → current BOM; earlier approved revision superseded. Or rejects with a reason → back to engineering as draft. */
function bom_approve(int $bid, string $decision, string $note): void {
    if (!bom_pr_can()) throw new RuntimeException(t('err.noaccess')); $b = bom_load($bid); if (!$b || $b['status'] !== 'issued') throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $note = mb_substr(trim($note), 0, 400);
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q("UPDATE bom_changes SET status = 'withdrawn' WHERE bom_rev_id = ? AND status = 'proposed'", [$bid]); q('UPDATE bom_revisions SET status = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ?', ['rejected', (int)$u['id'], $u['full_name'], $note, $bid]); bom_event($bid, 'reject', $note); bom_notify(bom_eng_ids($b), $bid, 'bom_rejected', t('bom.nf_rejected', ['s' => $b['sub_code'], 'r' => 'R' . $b['revision']]), $note); return; }
    if ((int)$b['n_pending']) throw new RuntimeException(t('bom.pending_first'));
    q("UPDATE bom_revisions SET status = 'superseded' WHERE subproject_id = ? AND status = 'approved' AND id <> ?", [(int)$b['subproject_id'], $bid]);
    bom_recalc($bid); q('UPDATE bom_revisions SET status = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ?', ['approved', (int)$u['id'], $u['full_name'], $note ?: null, $bid]); bom_event($bid, 'approve', $note ?: null);
    bom_notify(bom_eng_ids($b), $bid, 'bom_approved', t('bom.nf_approved', ['s' => $b['sub_code'], 'r' => 'R' . $b['revision']]), $note ?: null);
    cache_bump_later('inv');
}
/** Draft requisition from the uncovered lines of an approved BOM (R80-001). */
function bom_to_requisition(int $bid): int {
    $b = bom_load($bid); if (!$b || $b['status'] !== 'approved' || !(bom_pr_can() || bom_eng_can((int)$b['subproject_id'])) || !prq_can()) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $lines = array_filter(bom_lines($bid), fn($l) => $l['status'] !== 'removed' && $l['to_buy'] > 0.0005);
    if (!$lines) throw new RuntimeException(t('bom.nothing_to_buy'));
    $br = null; try { $br = (int) scalar('SELECT branch_id FROM projects WHERE id = ?', [(int)$b['project_id']]) ?: null; } catch (Throwable $e) {}
    q('INSERT INTO pr_requests (pr_no, branch_id, project_id, subproject_id, bom_rev_id, purpose, title, requester_id, requester_name, discipline_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [prq_number((int)$b['project_id'], (int)$b['subproject_id']), $br, (int)$b['project_id'], (int)$b['subproject_id'], $bid, 'project', mb_substr(t('bom.pr_title', ['r' => 'R' . $b['revision'], 't' => $b['title'] ?: $b['sub_name']]), 0, 160), (int)$u['id'], $u['full_name'], (int)($u['discipline_id'] ?? 0) ?: null]);
    $rid = (int) db()->lastInsertId(); $seq = 0;
    foreach ($lines as $l) q('INSERT INTO pr_lines (request_id, seq, item_id, description, maker, part_no, unit, qty, est_price, note) VALUES (?,?,?,?,?,?,?,?,?,?)', [$rid, ++$seq, $l['item_id'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], round((float)$l['to_buy'], 3), $l['item_id'] ? null : $l['est_price'], $l['section'] ? mb_substr($l['section'], 0, 255) : null]);
    prq_recalc($rid); prq_event($rid, 'create', t('bom.from_bom', ['r' => 'R' . $b['revision']])); bom_event($bid, 'to_pr', (string) scalar('SELECT pr_no FROM pr_requests WHERE id = ?', [$rid]));
    return $rid;
}
function bom_sub_summary(int $sid): array {
    if (!bom_has()) return ['n' => 0, 'current' => null, 'issued' => 0, 'pending' => 0];
    $cur = bom_current($sid);
    return ['n' => (int) scalar('SELECT COUNT(*) FROM bom_revisions WHERE subproject_id = ?', [$sid]), 'current' => $cur, 'issued' => (int) scalar("SELECT COUNT(*) FROM bom_revisions WHERE subproject_id = ? AND status = 'issued'", [$sid]), 'pending' => (int) scalar("SELECT COUNT(*) FROM bom_changes c JOIN bom_revisions b ON b.id = c.bom_rev_id WHERE b.subproject_id = ? AND c.status = 'proposed'", [$sid])];
}
/** R82-001: production kit — a requisition with every line of the approved BOM (full quantities), submitted at once: Procurement approves, the stock is reserved and the warehouse picks the kit; what is missing is bought as usual. */
function bom_to_kit(int $bid): int {
    $b = bom_load($bid); if (!$b || $b['status'] !== 'approved' || !(bom_pr_can() || bom_eng_can((int)$b['subproject_id'])) || !prq_can()) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $lines = array_filter(bom_lines($bid), fn($l) => $l['status'] !== 'removed'); if (!$lines) throw new RuntimeException(t('prq.need_lines'));
    $br = null; try { $br = (int) scalar('SELECT branch_id FROM projects WHERE id = ?', [(int)$b['project_id']]) ?: null; } catch (Throwable $e) {}
    q('INSERT INTO pr_requests (pr_no, branch_id, project_id, subproject_id, bom_rev_id, purpose, title, requester_id, requester_name, discipline_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [prq_number((int)$b['project_id'], (int)$b['subproject_id']), $br, (int)$b['project_id'], (int)$b['subproject_id'], $bid, 'project', mb_substr(t('bom.kit_title', ['r' => 'R' . $b['revision'], 't' => $b['title'] ?: $b['sub_name']]), 0, 160), (int)$u['id'], $u['full_name'], (int)($u['discipline_id'] ?? 0) ?: null]);
    $rid = (int) db()->lastInsertId(); $seq = 0;
    foreach ($lines as $l) q('INSERT INTO pr_lines (request_id, seq, item_id, description, maker, part_no, unit, qty, est_price, note) VALUES (?,?,?,?,?,?,?,?,?,?)', [$rid, ++$seq, $l['item_id'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], (float)$l['qty'], $l['item_id'] ? null : $l['est_price'], $l['section'] ? mb_substr($l['section'], 0, 255) : null]);
    prq_recalc($rid); prq_event($rid, 'create', t('bom.kit_from', ['r' => 'R' . $b['revision']])); bom_event($bid, 'to_kit', (string) scalar('SELECT pr_no FROM pr_requests WHERE id = ?', [$rid]));
    prq_submit($rid);
    return $rid;
}


/* ======================================================================================================================
 * GAS-CP-R93-006 — delete a revision; import template; staged import with inventory cross-check and AI proposals
 * ==================================================================================================================== */
function bom_imp_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'bom_import_rows'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** Administrators and Procurement may delete a revision; an approved revision that already produced a requisition / kit stays (it is part of the audit trail). */
function bom_can_delete(array $b): bool { return bom_has() && is_staff() && (is_cp_admin() || bom_pr_can()); }
function bom_delete(int $bid): int {
    $b = bom_load($bid); if (!$b || !bom_can_delete($b)) throw new RuntimeException(t('err.noaccess'));
    if ($b['status'] === 'approved' && scalar("SELECT COUNT(*) FROM bom_events WHERE bom_rev_id = ? AND event IN ('to_pr','to_kit')", [$bid])) throw new RuntimeException(t('bom.del_blocked'));
    q('DELETE FROM bom_changes WHERE bom_rev_id = ?', [$bid]); q('DELETE FROM bom_lines WHERE bom_rev_id = ?', [$bid]); q('DELETE FROM bom_events WHERE bom_rev_id = ?', [$bid]);
    if (bom_imp_has()) q('DELETE FROM bom_import_rows WHERE bom_rev_id = ?', [$bid]);
    q('DELETE FROM bom_revisions WHERE id = ?', [$bid]);
    if (function_exists('sp_log')) try { sp_log((int)$b['subproject_id'], 'bom_deleted', 'R' . (int)$b['revision'] . ' ' . ($b['title'] ?? '')); } catch (Throwable $e) {}
    return (int)$b['subproject_id'];
}
/** The import template — one sheet, the columns the importer reads, two example lines, a Units sheet. */
function bom_import_template(): void {
    require_once __DIR__ . '/xlsxw.php';
    xlsx_download('GAS-BOM-import-template.xlsx', [
        ['name' => 'BOM', 'first' => true, 'header' => ['Section', 'Qty', 'Unit', 'Description', 'Maker', 'Maker part no', 'Golden code', 'Note'],
         'rows' => [['MSB', 2, 'pcs', 'Circuit breaker 3P 250 A 36 kA, thermal-magnetic', 'Schneider Electric', 'LV431830', '', 'Feeder F12 / F13'],
                    ['Bridge', 25, 'm', 'Cable 3x2.5 mm² 0.6/1 kV halogen-free, armoured', 'Prysmian', 'MPRX 3x2.5', '', '']],
         'widths' => [14, 8, 8, 52, 22, 20, 14, 30]],
        ['name' => 'Units', 'header' => ['Unit', 'Meaning'], 'rows' => array_map(fn($u) => [$u, t('stk.u_' . $u) === 'stk.u_' . $u ? $u : t('stk.u_' . $u)], STK_UNITS), 'widths' => [10, 30]],
        ['name' => 'How to', 'header' => ['Rule'], 'rows' => [['One line per part. Description is mandatory; Qty defaults to 1; Unit must be one of the Units sheet (default pcs).'], ['Golden code = our inventory code when you know it — the line is linked at once.'], ['Maker + Maker part no are what the importer uses to find the item in the inventory (exact, then without spaces / dashes, then the assistant proposes).'], ['After the upload nothing is written: you review every proposed match and Accept, Reject (free-text line) or Create a new inventory item, then Apply.']], 'widths' => [120]],
    ]);
}
function bom_imp_norm(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }
/** Stage the file: parse with the template headers (old aliases tolerated), match deterministically, keep rows for review. Returns counts. */
function bom_import_stage(int $bid, array $file): array {
    if (!bom_imp_has()) return bom_import($bid, $file);   // table missing → legacy direct import
    $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess'));
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('im.file'));
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) throw new RuntimeException(t('bom.file_big'));
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($file['tmp_name']); if (count($rows) < 2) throw new RuntimeException(t('stk.imp_empty'));
    $H = ['section' => ['section', 'τμήμα', 'area', 'system', 'panel', 'location'], 'qty' => ['qty', 'quantity', 'q.ty', 'ποσότητα', 'ποσ.', 'pcs', 'amount'], 'unit' => ['unit', 'um', 'uom', 'μ.μ', 'μ.μ.', 'μον.'],
          'description' => ['description', 'desc', 'περιγραφή', 'designation', 'item description', 'material'], 'maker' => ['maker', 'manufacturer', 'brand', 'κατασκευαστής', 'make', 'vendor'],
          'part_no' => ['maker part no', 'part no', 'part no.', 'part number', 'p/n', 'pn', 'type', 'type no', 'article', 'article no', 'order code', 'factory code', 'κωδ. κατασκευαστή', 'ref'], 'code' => ['golden code', 'code', 'κωδικός', 'κωδ.', 'item code', 'our code', 'stock code'], 'note' => ['note', 'notes', 'remark', 'remarks', 'σημείωση', 'comment']];
    $hdrIdx = 0; foreach ($rows as $ri => $r) { $hits = 0; foreach ($r as $c) { $n = mb_strtolower(trim((string)$c)); foreach ($H as $alts) if ($n !== '' && in_array($n, $alts, true)) $hits++; } if ($hits >= 2) { $hdrIdx = $ri; break; } if ($ri > 10) break; }
    $map = []; foreach ($rows[$hdrIdx] as $i => $h) { $n = mb_strtolower(trim((string)$h)); if ($n === '') continue; foreach ($H as $k => $alts) if (!isset($map[$k]) && in_array($n, $alts, true)) { $map[$k] = $i; break; } }
    if (!isset($map['description'])) throw new RuntimeException(t('bom.imp_need_desc_col'));
    $num = function ($v): ?float { $v = str_replace(['€', ' '], '', trim((string)$v)); if ($v === '') return null; if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); return is_numeric($v) ? (float)$v : null; };
    q('DELETE FROM bom_import_rows WHERE bom_rev_id = ?', [$bid]);
    $seq = 0; $warn = []; $stats = ['rows' => 0, 'code' => 0, 'part_no' => 0, 'part_no_norm' => 0, 'none' => 0];
    $normMap = [];   // normalised maker part no → [lower(maker) => item id] (PHP side, works on every MySQL / MariaDB)
    foreach (all("SELECT id, maker, factory_code FROM inv_items WHERE status <> 'blocked' AND factory_code IS NOT NULL AND factory_code <> ''") as $x) { $k = bom_imp_norm((string)$x['factory_code']); if ($k !== '') $normMap[$k][mb_strtolower((string)$x['maker'])] = (int)$x['id']; }
    foreach (array_slice($rows, $hdrIdx + 1) as $i => $r) {
        $g = fn(string $k, int $max = 255) => isset($map[$k]) ? mb_substr(trim((string)($r[$map[$k]] ?? '')), 0, $max) : '';
        $desc = $g('description'); $code = $g('code', 60); $pn = $g('part_no', 80); $maker = $g('maker', 80);
        if ($desc === '' && $code === '' && $pn === '') continue;
        $it = null; $kind = 'none'; $conf = 0; $reason = null;
        if ($code !== '' && ($it = stk_item_by_code($code))) { $kind = 'code'; $conf = 100; $reason = t('bom.imp_r_code'); }
        elseif ($pn !== '' && ($it = one(STK_SELECT . " WHERE i.status <> 'blocked' AND LOWER(i.factory_code) = LOWER(?)" . ($maker !== '' ? ' ORDER BY (LOWER(i.maker) = LOWER(?)) DESC' : '') . ' LIMIT 1', $maker !== '' ? [$pn, $maker] : [$pn]))) { $kind = 'part_no'; $conf = 95; $reason = t('bom.imp_r_pn'); }
        elseif ($pn !== '' && ($nk = bom_imp_norm($pn)) !== '' && isset($normMap[$nk]) && ($it = stk_item((int)($normMap[$nk][mb_strtolower($maker)] ?? reset($normMap[$nk]))))) { $kind = 'part_no_norm'; $conf = 85; $reason = t('bom.imp_r_pn_norm'); }
        if ($desc === '' && $it) $desc = (string)$it['description'];
        if ($desc === '') { $warn[] = t('stk.imp_w_nodesc', ['l' => $i + $hdrIdx + 2, 'c' => $code ?: $pn]); continue; }
        $qty = $num($g('qty')) ?? 1.0; if ($qty <= 0) $qty = 1.0;
        $unit = $it ? (string)$it['unit'] : (stk_unit_norm($g('unit', 12)) ?: 'pcs');
        q('INSERT INTO bom_import_rows (bom_rev_id, seq, section, qty, unit, description, maker, part_no, code, note, item_id, match_kind, confidence, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$bid, ++$seq, $g('section', 80) ?: null, $qty, $unit, $desc, $maker ?: null, $pn ?: null, $code ?: null, $g('note') ?: null, $it ? (int)$it['id'] : null, $kind, $conf, $reason]);
        $stats['rows']++; $stats[$kind]++;
    }
    if (!$stats['rows']) throw new RuntimeException(t('stk.imp_empty'));
    $ai = 0; if ($stats['none'] && function_exists('ai_can') && ai_can('bom')) { try { $ai = bom_import_ai($bid); } catch (Throwable $e) { $warn[] = 'AI: ' . $e->getMessage(); } }
    bom_event($bid, 'import_staged', t('bom.imp_staged_ev', ['n' => $stats['rows'], 'm' => $stats['code'] + $stats['part_no'] + $stats['part_no_norm'], 'a' => $ai]) . ($warn ? ' · ' . implode(' · ', array_slice($warn, 0, 5)) : ''));
    return $stats + ['ai' => $ai, 'warn' => $warn];
}
/** Candidate items for a row: same maker, or description / part-no tokens; max 8. */
function bom_imp_candidates(array $r): array {
    $toks = array_values(array_filter(array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($r['description'] . ' ' . ($r['part_no'] ?? ''))) ?: []), fn($t) => mb_strlen($t) >= 3 && !in_array($t, ['the', 'and', 'for', 'with', 'type', 'και', 'για'], true)));
    usort($toks, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); $toks = array_slice($toks, 0, 4);
    if (!$toks && empty($r['maker'])) return [];
    $w = []; $a = [];
    foreach ($toks as $t) { $w[] = '(LOWER(i.description) LIKE ? OR LOWER(i.description_en) LIKE ? OR LOWER(i.factory_code) LIKE ?)'; $l = '%' . $t . '%'; array_push($a, $l, $l, $l); }
    $score = $w ? '(' . implode(' + ', array_map(fn($x) => 'IF(' . $x . ',1,0)', $w)) . ')' : '0'; $a2 = $a;
    $mk = ''; if (!empty($r['maker'])) { $mk = ' + IF(LOWER(i.maker) LIKE ?, 2, 0)'; $a2[] = '%' . mb_strtolower(mb_substr((string)$r['maker'], 0, 12)) . '%'; }
    $sql = "SELECT i.id, i.code, i.description, i.maker, i.factory_code, i.unit, (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM inv_stock s WHERE s.item_id = i.id) AS on_hand, ($score$mk) AS sc FROM inv_items i WHERE i.status <> 'blocked' AND (" . ($w ? implode(' OR ', $w) : '0') . (!empty($r['maker']) ? ' OR LOWER(i.maker) LIKE ?' : '') . ") ORDER BY sc DESC, on_hand DESC LIMIT 8";
    $args = array_merge($a2, $a, !empty($r['maker']) ? ['%' . mb_strtolower(mb_substr((string)$r['maker'], 0, 12)) . '%'] : []);
    return all($sql, $args);
}
/** Ask the assistant to pick among the candidates for the unmatched rows (batches of 25, at most 150 rows). Returns rows proposed. */
function bom_import_ai(int $bid): int {
    $rows = all("SELECT * FROM bom_import_rows WHERE bom_rev_id = ? AND match_kind = 'none' AND decision = 'pending' ORDER BY seq LIMIT 150", [$bid]); if (!$rows) return 0;
    $done = 0;
    foreach (array_chunk($rows, 25) as $chunk) {
        $lines = []; $cands = [];
        foreach ($chunk as $r) {
            $c = bom_imp_candidates($r); if (!$c) continue; $cands[(int)$r['seq']] = array_column($c, null, 'id');
            $lines[] = 'Row ' . $r['seq'] . ': ' . $r['description'] . ($r['maker'] ? ' | maker ' . $r['maker'] : '') . ($r['part_no'] ? ' | part no ' . $r['part_no'] : '') . ' | unit ' . $r['unit']
                . "\n  candidates: " . implode('; ', array_map(fn($x) => $x['id'] . ' [' . $x['code'] . '] ' . $x['description'] . ($x['maker'] ? ' · ' . $x['maker'] : '') . ($x['factory_code'] ? ' · pn ' . $x['factory_code'] : '') . ' · ' . $x['unit'] . ' · on hand ' . rtrim(rtrim(number_format((float)$x['on_hand'], 3, '.', ''), '0'), '.'), $c));
        }
        if (!$lines) continue;
        $res = ai_run('match', [], implode("\n", $lines), null, 'bom'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
        foreach ((array)($res['matches'] ?? []) as $seq => $m) {
            if (empty($m['item']) || !isset($cands[$seq][$m['item']]) || $m['confidence'] < 50) { if (isset($cands[$seq]) && $m['reason'] !== '') q("UPDATE bom_import_rows SET reason = ? WHERE bom_rev_id = ? AND seq = ? AND match_kind = 'none'", [mb_substr('AI: ' . $m['reason'], 0, 255), $bid, $seq]); continue; }
            q("UPDATE bom_import_rows SET item_id = ?, match_kind = 'ai', confidence = ?, reason = ? WHERE bom_rev_id = ? AND seq = ? AND match_kind = 'none'", [(int)$m['item'], (int)$m['confidence'], mb_substr('AI: ' . $m['reason'], 0, 255), $bid, $seq]); $done++;
        }
    }
    return $done;
}
function bom_import_rows(int $bid): array {
    if (!bom_imp_has()) return [];
    return all("SELECT r.*, i.code AS item_code, i.description AS item_desc, i.maker AS item_maker, i.factory_code AS item_pn, i.unit AS item_unit, i.status AS item_status,
                       (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM inv_stock s WHERE s.item_id = i.id) AS on_hand, (SELECT COALESCE(SUM(s.qty_reserved),0) FROM inv_stock s WHERE s.item_id = i.id) AS reserved
                FROM bom_import_rows r LEFT JOIN inv_items i ON i.id = r.item_id WHERE r.bom_rev_id = ? ORDER BY r.seq", [$bid]);
}
/** Save the engineer's decisions (row id => accept|reject|new|pending, optional item override) without applying. */
function bom_import_decide(int $bid, array $dec, array $items = []): void {
    $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess'));
    foreach ($dec as $rid => $d) {
        $rid = (int)$rid; if (!in_array($d, ['pending', 'accept', 'reject', 'new'], true)) continue;
        $itemId = (int)($items[$rid] ?? 0);
        if ($itemId) { if (!stk_item($itemId)) continue; q("UPDATE bom_import_rows SET item_id = ?, match_kind = IF(match_kind = 'none', 'ai', match_kind), confidence = GREATEST(confidence, 60), reason = ?, decision = ? WHERE id = ? AND bom_rev_id = ?", [$itemId, t('bom.imp_r_manual'), $d, $rid, $bid]); }
        else q('UPDATE bom_import_rows SET decision = ? WHERE id = ? AND bom_rev_id = ?', [$d, $rid, $bid]);
    }
}
/** Create an inventory item from a staged row (engineer may do this from the import review). Code: Golden code if free, else the maker part number, else BOM-<rev>-<seq>. */
function bom_imp_create_item(array $r, int $bid): int {
    $code = (string)($r['code'] ?? ''); if ($code === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\-\/+]*$/u', $code) || stk_item_by_code($code)) $code = '';
    if ($code === '' && !empty($r['part_no']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\-\/+]*$/u', (string)$r['part_no']) && !stk_item_by_code((string)$r['part_no'])) $code = mb_substr((string)$r['part_no'], 0, 60);
    if ($code === '') { $code = 'BOM-' . $bid . '-' . (int)$r['seq']; $n = 0; while (stk_item_by_code($code)) $code = 'BOM-' . $bid . '-' . (int)$r['seq'] . '-' . (++$n); }
    $u = current_user();
    q('INSERT INTO inv_items (code, description, unit, maker, factory_code, status, currency, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
      [$code, mb_substr((string)$r['description'], 0, 255), stk_unit_norm((string)$r['unit']) ?: 'pcs', $r['maker'] ?: null, $r['part_no'] ?: null, 'active', 'EUR', t('bom.imp_item_note', ['b' => 'BOM #' . $bid, 'u' => (string)($u['full_name'] ?? '')]), (int)($u['id'] ?? 0)]);
    $id = (int) db()->lastInsertId(); cache_bump_later('inv'); return $id;
}
/** Apply the decisions: accept → line linked to the item; reject → free-text line; new → create the item then link; pending rows stay for later. */
function bom_import_apply(int $bid): array {
    $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess'));
    $rows = bom_import_rows($bid); $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) FROM bom_lines WHERE bom_rev_id = ?', [$bid]);
    $n = ['accept' => 0, 'reject' => 0, 'new' => 0, 'pending' => 0];
    foreach ($rows as $r) {
        $d = (string)$r['decision']; if ($d === 'pending') { $n['pending']++; continue; }
        $it = null;
        if ($d === 'accept') { $it = $r['item_id'] ? stk_item((int)$r['item_id']) : null; if (!$it) { $n['pending']++; continue; } }
        if ($d === 'new') { $it = stk_item(bom_imp_create_item($r, $bid)); }
        q('INSERT INTO bom_lines (bom_rev_id, seq, item_id, code, description, maker, part_no, unit, qty, section, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$bid, ++$seq, $it ? (int)$it['id'] : null, $it ? $it['code'] : ($r['code'] ?: null), $it && $r['description'] === '' ? $it['description'] : $r['description'], $it ? $it['maker'] : $r['maker'], $it ? $it['factory_code'] : $r['part_no'], $it ? $it['unit'] : $r['unit'], (float)$r['qty'], $r['section'], $r['note']]);
        q('DELETE FROM bom_import_rows WHERE id = ?', [(int)$r['id']]); $n[$d]++;
    }
    bom_recalc($bid); bom_event($bid, 'import', t('bom.imp_applied_ev', ['a' => $n['accept'], 'r' => $n['reject'], 'n' => $n['new'], 'p' => $n['pending']]));
    return $n;
}
function bom_import_discard(int $bid): void { $b = bom_load($bid); if (!$b || !bom_editable($b)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM bom_import_rows WHERE bom_rev_id = ?', [$bid]); }
