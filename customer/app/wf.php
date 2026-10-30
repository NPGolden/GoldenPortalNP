<?php
declare(strict_types=1);
/**
 * Decks & working areas per vessel · Weekly works forecast per project (GAS-CP-R31-001).
 *  vessel_decks   one photo / GA per deck
 *  vessel_areas   rectangles drawn on a deck (rx, ry, rw, rh in % of the image) or list-only areas (no rectangle), classified
 *  wf_forecasts   one per project and ISO week — draft until issued (GAS-WF-<project>-<week> R0, R1 …), rolling pre-fill from the previous week
 *  wf_items       areas of the week: Plan A / Plan B, works description, weekdays, sub-projects (wf_item_subs)
 *  wf_comments    the owner's replies — comments to change the schedule, delays, obstacles in spaces, painting — carried into the report
 */

const WF_CLASSES = ['interior' => '2F7D5C', 'exterior' => '1B4F8A', 'machinery' => '9B2C2C', 'technical' => '7A5A16', 'guest' => '8A3FA8', 'crew' => '4A5568'];
const WF_KINDS = ['comment', 'delay', 'obstacle', 'painting', 'other'];

/** Opacity of the deck photo under the rectangles, 10–100 % (Settings › Vessel systems › Deck photos). Default 60. */
function wf_photo_opacity(): int { $v = function_exists('cp_setting') ? (int) cp_setting('wf_photo_opacity', '60') : 60; return max(10, min(100, $v ?: 60)); }
function wf_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'wf_forecasts'"); } catch (Throwable $e) { $h = false; } } return $h; }
function wf_class_label(string $c): string { return t('wf.c_' . (isset(WF_CLASSES[$c]) ? $c : 'interior')); }
function wf_class_pill(string $c): string { return '<span class="pill wf-cls wf-cls--' . e($c) . '">' . e(wf_class_label($c)) . '</span>'; }
function wf_kind_label(string $k): string { return t('wf.k_' . (in_array($k, WF_KINDS, true) ? $k : 'comment')); }
/** Who may define decks and areas and write forecasts: administrators, Project Manager role, the PM of an active project of the vessel. */
function wf_can_edit(?int $vesselId = null, ?int $projectId = null): bool {
    if (!is_staff()) return false;
    if (is_cp_admin()) return true;
    $u = current_user();
    static $pmRole = []; $k = (int)$u['id'];
    if (!isset($pmRole[$k])) $pmRole[$k] = (bool) scalar("SELECT COUNT(*) FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.id = ? AND LOWER(r.name) LIKE '%project manager%'", [$k]);
    if ($pmRole[$k]) return true;
    if ($projectId && (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$projectId]) === (int)$u['id']) return true;
    if ($vesselId && scalar("SELECT COUNT(*) FROM projects WHERE vessel_id = ? AND status = 'active' AND pm_user_id = ?", [$vesselId, (int)$u['id']])) return true;
    return false;
}
function wf_week_start(string $week): string { if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) $week = iso_week(date('Y-m-d')); preg_match('/^(\d{4})-W(\d{2})$/', $week, $m); return (new DateTime())->setISODate((int)$m[1], (int)$m[2])->format('Y-m-d'); }
function wf_week_shift(string $week, int $n): string { return iso_week(date('Y-m-d', strtotime(wf_week_start($week) . " +{$n} week"))); }
function wf_doc_no(array $p, string $week): string { return (function_exists('br_prefix') ? br_prefix((int)$p['id']) : 'GAS') . '-WF-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', (string)($p['project_code'] ?: $p['name']))) . '-' . str_replace('-', '', $week); }

/* ------------------------------------------------------------ decks & areas */
function wf_decks(int $vid): array { return wf_has() ? all('SELECT * FROM vessel_decks WHERE vessel_id = ? ORDER BY sort_order, id', [$vid]) : []; }
function wf_areas(int $vid, bool $activeOnly = true): array {
    if (!wf_has()) return [];
    return all('SELECT a.*, d.name AS deck FROM vessel_areas a LEFT JOIN vessel_decks d ON d.id = a.deck_id WHERE a.vessel_id = ?' . ($activeOnly ? ' AND a.is_active = 1' : '') . ' ORDER BY d.sort_order, d.id, a.sort_order, a.name', [$vid]);
}
function wf_deck_dir(): string { $d = cp_storage() . '/decks'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function wf_deck_save(int $vid): int {
    if (!wf_can_edit($vid)) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80) ?: t('wf.deck_default');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id) q('UPDATE vessel_decks SET name = ?, sort_order = ? WHERE id = ? AND vessel_id = ?', [$name, $sort, $id, $vid]);
    else { q('INSERT INTO vessel_decks (vessel_id, name, sort_order) VALUES (?,?,?)', [$vid, $name, $sort]); $id = (int) db()->lastInsertId(); }
    $f = $_FILES['photo'] ?? null;
    if ($f && !empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
        $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException(t('wf.deck_img_only'));
        if ($f['size'] > 12 * 1024 * 1024) throw new RuntimeException(t('wf.deck_img_big'));
        $ext = image_type_to_extension($info[2], false); $stored = 'decks/' . $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        wf_deck_dir(); if (!move_uploaded_file($f['tmp_name'], cp_storage() . '/' . $stored)) throw new RuntimeException(t('err.upload'));
        $old = scalar('SELECT stored_name FROM vessel_decks WHERE id = ?', [$id]); if ($old && is_file(cp_storage() . '/' . $old)) @unlink(cp_storage() . '/' . $old);
        q('UPDATE vessel_decks SET stored_name = ?, original_name = ?, mime_type = ?, img_w = ?, img_h = ? WHERE id = ?', [$stored, mb_substr((string)$f['name'], 0, 255), $info['mime'], $info[0], $info[1], $id]);
    }
    return $id;
}
function wf_deck_delete(int $id, int $vid): void {
    if (!wf_can_edit($vid)) throw new RuntimeException(t('err.noaccess'));
    $d = one('SELECT * FROM vessel_decks WHERE id = ? AND vessel_id = ?', [$id, $vid]); if (!$d) return;
    if ($d['stored_name'] && is_file(cp_storage() . '/' . $d['stored_name'])) @unlink(cp_storage() . '/' . $d['stored_name']);
    q('UPDATE vessel_areas SET deck_id = NULL, rx = NULL, ry = NULL, rw = NULL, rh = NULL WHERE deck_id = ?', [$id]);
    q('DELETE FROM vessel_decks WHERE id = ?', [$id]);
}
/** Stream a deck photo (staff, or the vessel's customer). */
function wf_deck_stream(int $id): void {
    require_login(); $d = one('SELECT d.*, v.customer_id FROM vessel_decks d JOIN vessels v ON v.id = d.vessel_id WHERE d.id = ?', [$id]);
    if (!$d || !$d['stored_name']) { http_response_code(404); exit('Not found'); }
    if (!is_staff() && (int)$d['customer_id'] !== (int)current_user()['customer_id']) { http_response_code(403); exit(t('err.noaccess')); }
    $path = cp_storage() . '/' . $d['stored_name']; if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    header('Content-Type: ' . ($d['mime_type'] ?: 'image/jpeg')); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=3600'); readfile($path); exit;
}
function wf_area_save(int $vid): int {
    if (!wf_can_edit($vid)) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120); if ($name === '') throw new RuntimeException(t('wf.need_area_name'));
    $class = isset(WF_CLASSES[$_POST['class'] ?? '']) ? $_POST['class'] : 'interior';
    $deck = (int)($_POST['deck_id'] ?? 0) ?: null; if ($deck && !scalar('SELECT COUNT(*) FROM vessel_decks WHERE id = ? AND vessel_id = ?', [$deck, $vid])) $deck = null;
    $pct = fn(string $k) => ($_POST[$k] ?? '') === '' ? null : max(0, min(100, round((float)$_POST[$k], 3)));
    $rx = $pct('rx'); $ry = $pct('ry'); $rw = $pct('rw'); $rh = $pct('rh');
    if ($rw !== null && $rh !== null && ($rw < 0.5 || $rh < 0.5)) { $rx = $ry = $rw = $rh = null; }
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null; $sort = (int)($_POST['sort_order'] ?? 0); $act = isset($_POST['is_active']) ? (int)!!$_POST['is_active'] : 1;
    if ($id) q('UPDATE vessel_areas SET deck_id = ?, name = ?, class = ?, rx = ?, ry = ?, rw = ?, rh = ?, notes = ?, sort_order = ?, is_active = ? WHERE id = ? AND vessel_id = ?', [$deck, $name, $class, $rx, $ry, $rw, $rh, $notes, $sort, $act, $id, $vid]);
    else { q('INSERT INTO vessel_areas (vessel_id, deck_id, name, class, rx, ry, rw, rh, notes, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$vid, $deck, $name, $class, $rx, $ry, $rw, $rh, $notes, $sort, $act]); $id = (int) db()->lastInsertId(); }
    return $id;
}
function wf_area_rect(int $id, int $vid, ?float $rx, ?float $ry, ?float $rw, ?float $rh, ?int $deck = null): void {   // from the drawing tool (JSON)
    if (!wf_can_edit($vid)) throw new RuntimeException(t('err.noaccess'));
    $c = fn($v) => $v === null ? null : max(0, min(100, round($v, 3)));
    q('UPDATE vessel_areas SET rx = ?, ry = ?, rw = ?, rh = ?' . ($deck ? ', deck_id = ?' : '') . ' WHERE id = ? AND vessel_id = ?', array_merge([$c($rx), $c($ry), $c($rw), $c($rh)], $deck ? [$deck] : [], [$id, $vid]));
}
function wf_area_delete(int $id, int $vid): void {
    if (!wf_can_edit($vid)) throw new RuntimeException(t('err.noaccess'));
    if (scalar('SELECT COUNT(*) FROM wf_items WHERE area_id = ?', [$id])) { q('UPDATE vessel_areas SET is_active = 0 WHERE id = ? AND vessel_id = ?', [$id, $vid]); return; }   // used in a forecast: retire, keep history
    q('DELETE FROM vessel_areas WHERE id = ? AND vessel_id = ?', [$id, $vid]);
}
/** HTML of a deck with its rectangles (viewer; the editor adds its own handlers). $hl = [area_id => 'A'|'B'] shading for a forecast. */
function wf_deck_html(array $deck, array $areas, array $hl = [], bool $editable = false): string {
    $h = '<div class="wf-deck' . ($editable ? ' is-edit' : '') . '" data-deck="' . (int)$deck['id'] . '" style="aspect-ratio:' . max(1, (int)($deck['img_w'] ?: 4)) . ' / ' . max(1, (int)($deck['img_h'] ?: 2)) . ';">';
    $h .= $deck['stored_name'] ? '<img src="' . url('deckimg', ['id' => $deck['id']]) . '" alt="' . e($deck['name']) . '" draggable="false" style="opacity:' . (wf_photo_opacity() / 100) . ';">' : '<div class="wf-deck-empty">' . tt('wf.no_photo') . '</div>';
    foreach ($areas as $a) {
        if ((int)$a['deck_id'] !== (int)$deck['id'] || $a['rw'] === null) continue;
        $p = $hl[(int)$a['id']] ?? ''; $dim = $hl && !$p;
        $h .= '<div class="wf-rect wf-cls--' . e($a['class']) . ($p ? ' is-plan-' . $p : '') . ($dim ? ' is-dim' : '') . '" data-area="' . (int)$a['id'] . '" style="left:' . (float)$a['rx'] . '%;top:' . (float)$a['ry'] . '%;width:' . (float)$a['rw'] . '%;height:' . (float)$a['rh'] . '%;" title="' . e($a['name'] . ' · ' . wf_class_label($a['class'])) . '"><span>' . e($a['name']) . ($p ? ' · ' . $p : '') . '</span></div>';
    }
    return $h . '</div>';
}

/* ------------------------------------------------------------ forecasts */
function wf_get(int $id): ?array {
    if (!wf_has()) return null;
    $f = one('SELECT f.*, p.project_code, p.name AS project_name, p.vessel_id, p.customer_id, v.name AS vessel FROM wf_forecasts f JOIN projects p ON p.id = f.project_id LEFT JOIN vessels v ON v.id = p.vessel_id WHERE f.id = ?', [$id]);
    if (!$f) return null;
    if (!is_staff() && ((int)$f['customer_id'] !== (int)current_user()['customer_id'] || $f['status'] !== 'issued')) return null;   // owners see issued forecasts of their projects
    $f['items'] = all('SELECT i.*, a.name AS area, a.class, a.deck_id, d.name AS deck FROM wf_items i JOIN vessel_areas a ON a.id = i.area_id LEFT JOIN vessel_decks d ON d.id = a.deck_id WHERE i.forecast_id = ? ORDER BY i.plan, d.sort_order, i.sort_order, a.name', [$id]);
    $subs = []; foreach (all('SELECT s.item_id, sp.id, sp.code, sp.name FROM wf_item_subs s JOIN subprojects sp ON sp.id = s.subproject_id JOIN wf_items i ON i.id = s.item_id WHERE i.forecast_id = ? ORDER BY sp.sort_order, sp.code', [$id]) as $r) $subs[(int)$r['item_id']][] = $r;
    foreach ($f['items'] as &$it) $it['subs'] = $subs[(int)$it['id']] ?? []; unset($it);
    $f['comments'] = all('SELECT c.*, a.name AS area FROM wf_comments c LEFT JOIN vessel_areas a ON a.id = c.area_id WHERE c.forecast_id = ? ORDER BY c.created_at, c.id', [$id]);
    $f['doc_no'] = wf_doc_no(['id' => $f['project_id'], 'project_code' => $f['project_code'], 'name' => $f['project_name']], $f['week']);
    return $f;
}
function wf_find(int $pid, string $week): ?array { $id = wf_has() ? (int) scalar('SELECT id FROM wf_forecasts WHERE project_id = ? AND week = ?', [$pid, $week]) : 0; return $id ? wf_get($id) : null; }
function wf_list(int $pid): array { return wf_has() ? all('SELECT f.*, (SELECT COUNT(*) FROM wf_items i WHERE i.forecast_id = f.id AND i.plan = \'A\') AS n_a, (SELECT COUNT(*) FROM wf_items i WHERE i.forecast_id = f.id AND i.plan = \'B\') AS n_b, (SELECT COUNT(*) FROM wf_comments c WHERE c.forecast_id = f.id AND c.side = \'customer\') AS n_owner FROM wf_forecasts f WHERE f.project_id = ? ' . (is_staff() ? '' : "AND f.status = 'issued' ") . 'ORDER BY f.week DESC', [$pid]) : []; }
/** R35-001: the work packages of the week with a working area become Plan A items of a draft forecast (added once per area). */
function wf_sync_works(int $id, int $pid, string $week): void {
    if (!function_exists('wp_has') || !wp_has()) return;
        $ws = wf_week_start($week); $we = date('Y-m-d', strtotime($ws . ' +6 days'));
        foreach (all("SELECT w.* FROM sp_works w WHERE w.project_id = ? AND w.area_id IS NOT NULL AND w.status IN ('planned','assigned','in_progress') AND w.start_date <= ? AND COALESCE(w.end_date, w.start_date) >= ? ORDER BY w.priority, w.start_date", [$pid, $we, $ws]) as $wk) {
            if (scalar('SELECT COUNT(*) FROM wf_items WHERE forecast_id = ? AND area_id = ? AND plan = \'A\'', [$id, (int)$wk['area_id']])) continue;
            $days = []; for ($d = strtotime(max($ws, $wk['start_date'])); $d <= strtotime(min($we, $wk['end_date'] ?: $wk['start_date'])); $d += 86400) { $n = (int) date('N', $d); if ($n <= 5) $days[] = $n; }
            q('INSERT INTO wf_items (forecast_id, area_id, plan, description, days, sort_order) VALUES (?,?,\'A\',?,?,?)', [$id, (int)$wk['area_id'], mb_substr($wk['wp_no'] . ' · ' . $wk['title'], 0, 255), implode(',', $days ?: [1, 2, 3, 4, 5]), 50]);
            q('INSERT IGNORE INTO wf_item_subs (item_id, subproject_id) VALUES (?,?)', [(int) db()->lastInsertId(), (int)$wk['subproject_id']]);
        }
}
/** Open (or create as draft, pre-filled from the previous week's forecast — rolling) the forecast of a project week. */
function wf_open(int $pid, string $week): int {
    if (!wf_can_edit(null, $pid)) throw new RuntimeException(t('err.noaccess'));
    $id = (int) scalar('SELECT id FROM wf_forecasts WHERE project_id = ? AND week = ?', [$pid, $week]); if ($id) { if (scalar('SELECT status FROM wf_forecasts WHERE id = ?', [$id]) === 'draft') wf_sync_works($id, $pid, $week); return $id; }
    $u = current_user();
    q('INSERT INTO wf_forecasts (project_id, week, week_start, status, created_by) VALUES (?,?,?,\'draft\',?)', [$pid, $week, wf_week_start($week), (int)$u['id']]);
    $id = (int) db()->lastInsertId();
    $prev = one('SELECT id FROM wf_forecasts WHERE project_id = ? AND week < ? ORDER BY week DESC LIMIT 1', [$pid, $week]);
    if ($prev) {
        foreach (all('SELECT * FROM wf_items WHERE forecast_id = ? ORDER BY plan, sort_order', [(int)$prev['id']]) as $it) {
            q('INSERT INTO wf_items (forecast_id, area_id, plan, description, days, sort_order) VALUES (?,?,?,?,?,?)', [$id, $it['area_id'], $it['plan'], $it['description'], $it['days'], $it['sort_order']]);
            $nid = (int) db()->lastInsertId();
            foreach (all('SELECT subproject_id FROM wf_item_subs WHERE item_id = ?', [(int)$it['id']]) as $s) q('INSERT IGNORE INTO wf_item_subs (item_id, subproject_id) VALUES (?,?)', [$nid, $s['subproject_id']]);
        }
        q('UPDATE wf_forecasts SET notes = (SELECT notes FROM (SELECT notes FROM wf_forecasts WHERE id = ?) x) WHERE id = ?', [(int)$prev['id'], $id]);
    }
    wf_sync_works($id, $pid, $week);
    return $id;
}
/** Save the whole draft from the editor form: items[] with area_id, plan, description, days[], subs[]; notes. */
function wf_save_from_post(int $id): void {
    $f = wf_get($id); if (!$f || !wf_can_edit(null, (int)$f['project_id'])) throw new RuntimeException(t('err.noaccess'));
    if ($f['status'] === 'issued') throw new RuntimeException(t('wf.issued_locked'));
    $db = db(); $db->beginTransaction();
    try {
        q('UPDATE wf_forecasts SET notes = ?, owner_summary = ? WHERE id = ?', [mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 4000) ?: null, mb_substr(trim((string)($_POST['owner_summary'] ?? '')), 0, 4000) ?: null, $id]);
        q('DELETE s FROM wf_item_subs s JOIN wf_items i ON i.id = s.item_id WHERE i.forecast_id = ?', [$id]); q('DELETE FROM wf_items WHERE forecast_id = ?', [$id]);
        $areas = array_column(wf_areas((int)$f['vessel_id'], false), 'id'); $n = 0;
        foreach ((array)($_POST['items'] ?? []) as $row) {
            $aid = (int)($row['area_id'] ?? 0); if (!in_array($aid, array_map('intval', $areas), true)) continue;
            $plan = ($row['plan'] ?? 'A') === 'B' ? 'B' : 'A';
            $days = array_values(array_filter(array_map('intval', (array)($row['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)); sort($days);
            q('INSERT INTO wf_items (forecast_id, area_id, plan, description, days, sort_order) VALUES (?,?,?,?,?,?)', [$id, $aid, $plan, mb_substr(trim((string)($row['description'] ?? '')), 0, 500) ?: null, $days ? implode(',', $days) : null, ++$n]);
            $iid = (int) db()->lastInsertId();
            foreach (array_unique(array_map('intval', (array)($row['subs'] ?? []))) as $sid) if ($sid && scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, (int)$f['project_id']])) q('INSERT IGNORE INTO wf_item_subs (item_id, subproject_id) VALUES (?,?)', [$iid, $sid]);
        }
        foreach ((array)($_POST['in_report'] ?? []) as $cid => $v) q('UPDATE wf_comments SET in_report = ? WHERE id = ? AND forecast_id = ?', [(int)!!$v, (int)$cid, $id]);
        if (isset($_POST['in_report_all'])) foreach (all('SELECT id FROM wf_comments WHERE forecast_id = ?', [$id]) as $c) q('UPDATE wf_comments SET in_report = ? WHERE id = ?', [isset($_POST['in_report'][(int)$c['id']]) ? 1 : 0, (int)$c['id']]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
function wf_reopen(int $id): void {
    $f = wf_get($id); if (!$f || !wf_can_edit(null, (int)$f['project_id'])) throw new RuntimeException(t('err.noaccess'));
    q("UPDATE wf_forecasts SET status = 'draft' WHERE id = ?", [$id]);
}
function wf_delete(int $id): void {
    $f = wf_get($id); if (!$f || !wf_can_edit(null, (int)$f['project_id'])) throw new RuntimeException(t('err.noaccess'));
    if ($f['status'] === 'issued' && !is_cp_admin()) throw new RuntimeException(t('wf.issued_locked'));
    q('DELETE s FROM wf_item_subs s JOIN wf_items i ON i.id = s.item_id WHERE i.forecast_id = ?', [$id]); q('DELETE FROM wf_items WHERE forecast_id = ?', [$id]); q('DELETE FROM wf_comments WHERE forecast_id = ?', [$id]); q('DELETE FROM wf_forecasts WHERE id = ?', [$id]);
}
/** Issue: render PDF + Word, archive as GAS-WF-… R<n> (external), mark issued, notify the owner's users and the project staff. */
function wf_issue(int $id): array {
    $f = wf_get($id); if (!$f || !wf_can_edit(null, (int)$f['project_id'])) throw new RuntimeException(t('err.noaccess'));
    $u = current_user();
    $files = [[wf_render_pdf($f), 'pdf', 'application/pdf']];
    if (function_exists('wf_render_docx')) $files[] = [wf_render_docx($f), 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $r = ar_issue($f['doc_no'], (int)$f['project_id'], null, $files, 'external', t('wf.doc_title', ['w' => $f['week']]));
    q("UPDATE wf_forecasts SET status = 'issued', issued_at = NOW(), issued_by = ? WHERE id = ?", [(int)$u['id'], $id]);
    if (function_exists('nf_push')) {
        $url = url('wf', ['id' => $id]); $title = t('wf.nf_issued', ['n' => $f['doc_no'], 'r' => $r['revision'], 'v' => (string)$f['vessel']]);
        $owners = function_exists('nf_customer_users') && $f['customer_id'] ? nf_customer_users((int)$f['customer_id']) : [];
        nf_push($owners, 'wf:issued', 'wf:' . $id . ':' . $r['revision'], $title, $f['notes'], $url, (int)$u['id'], $u['full_name']);
        nf_push(array_diff(nf_project_staff((int)$f['project_id']), [(int)$u['id']]), 'wf:issued', 'wf:' . $id . ':' . $r['revision'] . ':s', $title, null, $url, (int)$u['id'], $u['full_name']);
    }
    return $r;
}
/** The owner (or staff) replies: a comment to change the schedule, or a delay / obstacle / painting forecast for an area and period. */
function wf_comment_add(int $id): int {
    $u = require_login(); $f = wf_get($id); if (!$f) throw new RuntimeException(t('err.notfound'));
    $kind = in_array($_POST['kind'] ?? '', WF_KINDS, true) ? $_POST['kind'] : 'comment';
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000); if ($body === '') throw new RuntimeException(t('wf.need_body'));
    $aid = (int)($_POST['area_id'] ?? 0) ?: null; if ($aid && !scalar('SELECT COUNT(*) FROM vessel_areas WHERE id = ? AND vessel_id = ?', [$aid, (int)$f['vessel_id']])) $aid = null;
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    q('INSERT INTO wf_comments (forecast_id, user_id, user_name, side, kind, area_id, from_date, to_date, body, in_report) VALUES (?,?,?,?,?,?,?,?,?,?)', [$id, (int)$u['id'], $u['full_name'], is_staff() ? 'golden' : 'customer', $kind, $aid, $d('from_date'), $d('to_date'), $body, $kind === 'comment' ? 0 : 1]);
    $cid = (int) db()->lastInsertId();
    if (function_exists('nf_push')) {
        $title = t('wf.nf_reply', ['n' => $f['doc_no'], 'k' => wf_kind_label($kind), 'u' => $u['full_name']]);
        $to = is_staff() ? (function_exists('nf_customer_users') && $f['customer_id'] ? nf_customer_users((int)$f['customer_id']) : []) : nf_project_staff((int)$f['project_id']);
        nf_push(array_diff($to, [(int)$u['id']]), 'wf:reply', 'wf:' . $id . ':c' . $cid, $title, $body, url('wf', ['id' => $id]) . '#wf-replies', (int)$u['id'], $u['full_name']);
        if (function_exists('mt_notify')) mt_notify($body, (string)$f['doc_no'], url('wf', ['id' => $id]) . '#wf-replies', 'wf:' . $id . ':c' . $cid);
    }
    return $cid;
}
function wf_comment_delete(int $cid): int {
    $u = require_login(); $c = one('SELECT * FROM wf_comments WHERE id = ?', [$cid]); if (!$c) throw new RuntimeException(t('err.notfound'));
    if (!is_cp_admin() && (int)$c['user_id'] !== (int)$u['id']) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM wf_comments WHERE id = ?', [$cid]); return (int)$c['forecast_id'];
}
function wf_day_labels(): array { return [1 => t('wf.d1'), 2 => t('wf.d2'), 3 => t('wf.d3'), 4 => t('wf.d4'), 5 => t('wf.d5'), 6 => t('wf.d6'), 7 => t('wf.d7')]; }
function wf_days_txt(?string $days): string { if (!$days) return t('wf.all_week'); $L = wf_day_labels(); return implode(' ', array_map(fn($d) => $L[(int)$d] ?? '', explode(',', $days))); }
function wf_item_subs_txt(array $it): string { return implode(', ', array_map(fn($s) => trim((string)$s['code']), $it['subs'])) ?: '—'; }

/* ------------------------------------------------------------ documents */
/** Deck image with the week's rectangles burnt in (GD) — the same picture in PDF, Word and the page. */
function wf_deck_image(array $deck, array $items, int $maxW = 1400): ?string {
    if (!$deck['stored_name'] || !is_file(cp_storage() . '/' . $deck['stored_name'])) return null;
    $im = @imagecreatefromstring((string) file_get_contents(cp_storage() . '/' . $deck['stored_name'])); if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im); $r = min(1, $maxW / max(1, $w)); $nw = max(1, (int) round($w * $r)); $nh = max(1, (int) round($h * $r));
    $out = imagecreatetruecolor($nw, $nh); $white = imagecolorallocate($out, 255, 255, 255); imagefill($out, 0, 0, $white); imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im);
    $op = wf_photo_opacity(); if ($op < 100) { $veil = imagecolorallocatealpha($out, 255, 255, 255, (int) round(127 * $op / 100)); imagefilledrectangle($out, 0, 0, $nw, $nh, $veil); }   // photo faded to the configured opacity so the areas stand out
    imagesetthickness($out, max(2, (int) round($nw / 400)));
    $font = __DIR__ . '/fonts/DejaVuSans-Bold.ttf'; $font = is_file($font) ? $font : null; $fs = max(9, (int) round($nw / 90));
    foreach ($items as $it) {
        if ((int)$it['deck_id'] !== (int)$deck['id'] || $it['rw'] === null) continue;
        $hex = WF_CLASSES[$it['class']] ?? '0B1E2D'; [$cr, $cg, $cb] = sscanf($hex, '%02x%02x%02x');
        $x1 = (int) round($nw * (float)$it['rx'] / 100); $y1 = (int) round($nh * (float)$it['ry'] / 100); $x2 = (int) round($nw * ((float)$it['rx'] + (float)$it['rw']) / 100); $y2 = (int) round($nh * ((float)$it['ry'] + (float)$it['rh']) / 100);
        $plan = $it['plan'] ?? '';
        if ($plan === 'A') { $fill = imagecolorallocatealpha($out, 200, 155, 60, 70); imagefilledrectangle($out, $x1, $y1, $x2, $y2, $fill); }
        elseif ($plan === 'B') { $fill = imagecolorallocatealpha($out, 200, 155, 60, 100); imagefilledrectangle($out, $x1, $y1, $x2, $y2, $fill); imagesetstyle($out, array_merge(array_fill(0, 8, imagecolorallocate($out, $cr, $cg, $cb)), array_fill(0, 6, IMG_COLOR_TRANSPARENT))); }
        $line = imagecolorallocate($out, $cr, $cg, $cb);
        $col = $plan === 'B' ? IMG_COLOR_STYLED : $line;
        imagerectangle($out, $x1, $y1, $x2, $y2, $col);
        $label = $it['name'] . ($plan ? ' · ' . $plan : '');
        if ($font) { $bb = imagettfbbox($fs, 0, $font, $label); $tw = abs($bb[2] - $bb[0]) + 8; $th = abs($bb[7] - $bb[1]) + 6; $bg = imagecolorallocatealpha($out, 255, 255, 255, 25); imagefilledrectangle($out, $x1 + 2, $y1 + 2, min($x2 - 2, $x1 + 2 + $tw), $y1 + 2 + $th, $bg); imagettftext($out, $fs, 0, $x1 + 6, $y1 + 2 + $th - 5, $line, $font, $label); }
        else imagestring($out, 3, $x1 + 4, $y1 + 4, $label, $line);
    }
    ob_start(); imagejpeg($out, null, 86); $jpg = (string) ob_get_clean(); imagedestroy($out);
    return $jpg;
}
/** Items of the forecast joined with the rectangle of their area, for the deck pictures. */
function wf_items_with_rects(array $f): array {
    $areas = []; foreach (wf_areas((int)$f['vessel_id'], false) as $a) $areas[(int)$a['id']] = $a;
    $out = []; foreach ($f['items'] as $it) { $a = $areas[(int)$it['area_id']] ?? null; if ($a) $out[] = $a + ['plan' => $it['plan']]; }
    return $out;
}
function wf_font_path(): ?string { foreach ([__DIR__ . '/fonts/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'] as $p) if (is_file($p)) return $p; return null; }

function wf_render_pdf(array $f): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0; $figNo = 0;
    $bd = function_exists('br_doc') ? br_doc((int)$f['project_id']) : ['logo' => null, 'company' => 'Golden Automation Systems', 'footer' => ''];
    $nIss = (int) scalar('SELECT COUNT(DISTINCT revision) FROM sp_files WHERE kind = ? AND doc_no = ? AND project_id = ?', ['issued', $f['doc_no'], (int)$f['project_id']]);
    $rev = 'R' . ($f['status'] === 'issued' && $nIss ? $nIss - 1 : $nIss) . ($f['status'] === 'issued' ? '' : ' · ' . t('wf.s_draft'));   // issued: the current revision; draft: the one Issue will create
    $we = date('Y-m-d', strtotime($f['week_start'] . ' +6 days'));
    $header = function () use ($pdf, &$y, $navy, $gold, $ink, $L, $R, $W, $f, $bd, $rev) {
        $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16);
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper(t('wf.doc_title_plain')));
        $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $f['doc_no'] . ' · ' . $rev . ' · ' . $f['week']);
        $pdf->setColor($gold); $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 34;
        if ($pdf->pageNo() === 1 && function_exists('rc_legend_pdf')) $y += rc_legend_pdf($pdf, $L, $y, $W);
    };
    $footer = function () use ($pdf, $L, $R, $f, $bd) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['company'] ?? 'Golden Automation Systems') . ' · ' . $f['doc_no'] . ' · ' . date('d.m.Y H:i')); $pdf->text($R, 290, t('rp.page') . ' ' . $pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 278) { $footer(); $header(); } };
    $h1 = function (string $s) use ($pdf, $navy, $gold, $L, $R, &$y, $need) { $y += 4; $need(24); $pdf->setColor($navy); $pdf->setFont('B', 11); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 7, $R, $y + 7); $y += 11; };
    $para = function (string $s, float $size = 8.5) use ($pdf, $ink, $L, $W, &$y, $need) { if ($s === '') return; $pdf->setFont('R', $size); $need($pdf->paragraphHeight($W, $s, 4) + 3); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $W, $s, 4) + 2; };
    $table = function (string $title, array $cols, array $rows) use ($pdf, $navy, $ink, $line, $head, $L, $W, &$y, &$tableNo, $need) {
        $cols = function_exists('rc_fit_cols') ? rc_fit_cols($cols, $W) : $cols; $tableNo++; $y += 2; $need(18);
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.2;
        $hrow = function () use ($pdf, $cols, $navy, $line, $head, $L, &$y) { $pdf->setFont('B', 7.2); $x = $L; $hh = 6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4.2, $lab); $x += $cw; } $y += $hh; };
        $hrow();
        if (!$rows) $rows = [['v' => [t('wf.none_row')]]];
        foreach ($rows as $r) {
            $pdf->setFont(!empty($r['b']) ? 'B' : 'R', 7.6); $lines = []; $n = 1;
            foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($r['v'][$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
            $hh = $n * 3.8 + 1.6; if ($y + $hh > 278) { $need(300); $hrow(); $pdf->setFont('R', 7.6); }
            $x = $L; if (!empty($r['tone']) && function_exists('rc_bar_pdf')) rc_bar_pdf($pdf, $r['tone'], $x, $y, $hh);
            foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 3.5 + 3.8 * $k, $ln, $al === 'R' ? 'R' : 'L', $al === 'R' ? $cw - 3 : 0); $x += $cw; }
            $y += $hh;
        }
        $y += 2;
    };
    $header(); $footer();
    // cover block
    $kv = [[t('pr.one'), trim(($f['project_code'] ?: '') . ' ' . $f['project_name'])], [t('v.one'), (string)$f['vessel']], [t('wf.week'), $f['week'] . ' · ' . fmt_date($f['week_start']) . ' – ' . fmt_date($we)], [t('th.status'), $f['status'] === 'issued' ? t('wf.s_issued') : t('wf.s_draft')], [t('wf.issued_by'), ($f['issued_by'] ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$f['issued_by']]) : (string)(current_user()['full_name'] ?? '')) . ' · ' . date('d.m.Y H:i')]];
    foreach ($kv as [$k, $v]) { $pdf->setColor($line); $pdf->rect($L, $y, $W, 7); $pdf->setColor($head); $pdf->rect($L, $y, 40, 7, 'F'); $pdf->setColor($navy); $pdf->setFont('B', 8); $pdf->text($L + 2, $y + 4.8, $k); $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->text($L + 43, $y + 4.8, $v); $y += 7; }
    $y += 4;
    // 1. deck maps
    $h1('1. ' . t('wf.sec_maps'));
    $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $W, t('wf.maps_legend'), 4) + 2;
    $rects = wf_items_with_rects($f); $decks = wf_decks((int)$f['vessel_id']); $any = false;
    foreach ($decks as $d) {
        $here = array_filter($rects, fn($r) => (int)$r['deck_id'] === (int)$d['id']); if (!$here || !$d['stored_name']) continue;
        $jpg = wf_deck_image($d, array_values($here)); if (!$jpg) continue;
        $ratio = max(1, (int)$d['img_h']) / max(1, (int)$d['img_w']); $ih = min(110, $W * $ratio); $need($ih + 12);
        $pdf->image($jpg, $L, $y, $W, $ih); $y += $ih + 1; $figNo++;
        $pdf->setFont('B', 7.5); $pdf->setColor($navy); $pdf->text($L, $y + 3.5, t('wf.figure') . ' ' . $figNo . ' — ' . $d['name'] . ': ' . implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['plan'] . ')', array_values($here)))); $y += 7; $any = true;
    }
    if (!$any) $para(t('wf.no_maps'));
    // 2. areas table (plan A then B)
    $h1('2. ' . t('wf.sec_areas'));
    foreach (['A', 'B'] as $plan) {
        $rows = []; foreach ($f['items'] as $it) if ($it['plan'] === $plan) $rows[] = ['v' => [$it['area'], (string)($it['deck'] ?: '—'), wf_class_label($it['class']), wf_item_subs_txt($it), (string)($it['description'] ?: '—'), wf_days_txt($it['days'])], 'tone' => $plan === 'A' ? 'green' : 'amber'];
        $table(t('wf.plan_' . $plan), [[t('wf.area'), 36, 'L'], [t('wf.deck'), 24, 'L'], [t('wf.class'), 22, 'L'], [t('wf.subs'), 24, 'L'], [t('wf.works'), 54, 'L'], [t('wf.days'), 20, 'L']], $rows);
    }
    // 3. week grid
    $h1('3. ' . t('wf.sec_grid'));
    $DL = wf_day_labels(); $cols = [[t('wf.area'), 48, 'L']]; foreach ($DL as $dl) $cols[] = [$dl, 18.8, 'L'];
    $rows = []; foreach ($f['items'] as $it) { $v = [$it['area'] . ' (' . $it['plan'] . ')']; $set = $it['days'] ? array_map('intval', explode(',', $it['days'])) : range(1, 5); foreach ($DL as $d => $dl) $v[] = in_array($d, $set, true) ? $it['plan'] . ($it['subs'] ? ' · ' . wf_item_subs_txt($it) : '') : ''; $rows[] = ['v' => $v, 'tone' => $it['plan'] === 'A' ? 'green' : 'amber']; }
    $table(t('wf.grid_title'), $cols, $rows);
    // 4. owner's replies carried into the report
    $h1('4. ' . t('wf.sec_owner'));
    $rows = []; foreach ($f['comments'] as $c) { if (!$c['in_report']) continue; $rows[] = ['v' => [wf_kind_label($c['kind']), (string)($c['area'] ?: '—'), ($c['from_date'] ? fmt_date($c['from_date']) : '—') . ($c['to_date'] ? ' – ' . fmt_date($c['to_date']) : ''), $c['body'], $c['user_name'] . ' · ' . fmt_date($c['created_at'])], 'tone' => in_array($c['kind'], ['delay', 'obstacle'], true) ? 'red' : ($c['kind'] === 'painting' ? 'amber' : null)]; }
    $table(t('wf.owner_table'), [[t('wf.kind'), 24, 'L'], [t('wf.area'), 32, 'L'], [t('wf.period'), 30, 'L'], [t('wf.remark'), 68, 'L'], [t('wv.by'), 26, 'L']], $rows);
    if ($f['owner_summary']) $para($f['owner_summary']);
    // 5. notes
    if ($f['notes']) { $h1('5. ' . t('wf.sec_notes')); $para((string)$f['notes']); }
    $footer();
    return $pdf->bytes($f['doc_no'] . ' ' . $rev);
}
function wf_render_docx(array $f): string {
    require_once __DIR__ . '/docx.php';
    $d = new DocxWriter(); $we = date('Y-m-d', strtotime($f['week_start'] . ' +6 days'));
    $d->heading(t('wf.doc_title_plain') . ' — ' . $f['doc_no'], 1);
    $d->kv(t('pr.one'), trim(($f['project_code'] ?: '') . ' ' . $f['project_name'])); $d->kv(t('v.one'), (string)$f['vessel']); $d->kv(t('wf.week'), $f['week'] . ' · ' . fmt_date($f['week_start']) . ' – ' . fmt_date($we));
    if (function_exists('rc_legend_docx')) rc_legend_docx($d);
    $d->heading('1. ' . t('wf.sec_maps'), 2); $d->para(t('wf.maps_legend'));
    $rects = wf_items_with_rects($f);
    foreach (wf_decks((int)$f['vessel_id']) as $dk) { $here = array_values(array_filter($rects, fn($r) => (int)$r['deck_id'] === (int)$dk['id'])); if (!$here || !$dk['stored_name']) continue; $jpg = wf_deck_image($dk, $here, 1200); if ($jpg) { $ratio = max(1, (int)$dk['img_h']) / max(1, (int)$dk['img_w']); $d->image($jpg, 16.5, min(12.0, 16.5 * $ratio)); $d->para($dk['name'] . ': ' . implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['plan'] . ')', $here))); } }
    $d->heading('2. ' . t('wf.sec_areas'), 2);
    foreach (['A', 'B'] as $plan) { $rows = []; foreach ($f['items'] as $it) if ($it['plan'] === $plan) $rows[] = [$it['area'], (string)($it['deck'] ?: '—'), wf_class_label($it['class']), wf_item_subs_txt($it), (string)($it['description'] ?: '—'), wf_days_txt($it['days']), '_tone' => $plan === 'A' ? 'green' : 'amber']; $d->table([t('wf.area'), t('wf.deck'), t('wf.class'), t('wf.subs'), t('wf.works'), t('wf.days')], $rows ?: [[t('wf.none_row'), '', '', '', '', '']], t('wf.plan_' . $plan)); }
    $d->heading('3. ' . t('wf.sec_grid'), 2); $DL = wf_day_labels(); $rows = [];
    foreach ($f['items'] as $it) { $v = [$it['area'] . ' (' . $it['plan'] . ')']; $set = $it['days'] ? array_map('intval', explode(',', $it['days'])) : range(1, 5); foreach ($DL as $dd => $dl) $v[] = in_array($dd, $set, true) ? $it['plan'] . ($it['subs'] ? ' · ' . wf_item_subs_txt($it) : '') : ''; $v['_tone'] = $it['plan'] === 'A' ? 'green' : 'amber'; $rows[] = $v; }
    $d->table(array_merge([t('wf.area')], array_values($DL)), $rows ?: [array_merge([t('wf.none_row')], array_fill(0, 7, ''))], t('wf.grid_title'));
    $d->heading('4. ' . t('wf.sec_owner'), 2); $rows = [];
    foreach ($f['comments'] as $c) if ($c['in_report']) $rows[] = [wf_kind_label($c['kind']), (string)($c['area'] ?: '—'), ($c['from_date'] ? fmt_date($c['from_date']) : '—') . ($c['to_date'] ? ' – ' . fmt_date($c['to_date']) : ''), $c['body'], $c['user_name'] . ' · ' . fmt_date($c['created_at']), '_tone' => in_array($c['kind'], ['delay', 'obstacle'], true) ? 'red' : ($c['kind'] === 'painting' ? 'amber' : null)];
    $d->table([t('wf.kind'), t('wf.area'), t('wf.period'), t('wf.remark'), t('wv.by')], $rows ?: [[t('wf.none_row'), '', '', '', '']], t('wf.owner_table'));
    if ($f['owner_summary']) $d->para((string)$f['owner_summary']);
    if ($f['notes']) { $d->heading('5. ' . t('wf.sec_notes'), 2); $d->para((string)$f['notes']); }
    return $d->bytes($f['doc_no'], 'Golden Automation Systems · ' . $f['doc_no']);
}
/** Next week's forecast of a vessel's projects for the weekly vessel report (compact rows). */
function wf_for_weekly(int $vesselId, string $week): array {
    if (!wf_has()) return [];
    $rows = [];
    foreach (all("SELECT f.*, p.project_code FROM wf_forecasts f JOIN projects p ON p.id = f.project_id WHERE p.vessel_id = ? AND f.week = ? AND f.status = 'issued'", [$vesselId, $week]) as $fc) {
        $f = wf_get((int)$fc['id']); if (!$f) continue;
        foreach ($f['items'] as $it) $rows[] = ['v' => [$f['project_code'], $it['plan'], $it['area'] . ($it['deck'] ? ' · ' . $it['deck'] : ''), wf_item_subs_txt($it), (string)($it['description'] ?: '—'), wf_days_txt($it['days'])], 'tone' => $it['plan'] === 'A' ? 'green' : 'amber'];
    }
    return $rows;
}
