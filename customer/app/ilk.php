<?php
/** Inventory extras — GAS-CP-R88-001
 *  1. Document web links on an item (datasheet, manual, drawing, certificate, software download, supplier page …): table inv_item_links.
 *  2. Software as inventory: inv_items.item_type = software — every purchase is a licence record (inv_licences: product, version,
 *     licence key / serial, seats, kind, expiry, supplier, PO line, assigned to a project / vessel / user / organisation).
 *     A goods receipt of a software item creates a "pending" licence per receipt line instead of a stock movement; the licence
 *     keys are shown only to procurement administrators (masked for everyone else); expiring licences appear in the Shortages console.
 */
declare(strict_types=1);

const ILK_KINDS = ['datasheet', 'manual', 'drawing', 'certificate', 'software', 'supplier', 'other'];
const SWL_KINDS = ['perpetual', 'subscription', 'trial', 'oem'];
const SWL_STATES = ['pending', 'active', 'expired', 'retired'];
const SWL_ASSIGN = ['none', 'project', 'vessel', 'user', 'company'];
const SWL_WARN_DAYS = 60;
const ITEM_TYPES = ['part', 'software', 'service'];

function ilk_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) db()->query("SHOW TABLES LIKE 'inv_item_links'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function swl_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) db()->query("SHOW TABLES LIKE 'inv_licences'")->fetch() && (bool) db()->query("SHOW COLUMNS FROM inv_items LIKE 'item_type'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function swl_keys_visible(): bool { return function_exists('pr_admin') && pr_admin(); }

/* ------------------------------------------------------------------ links */
function ilk_of(int $itemId): array { return ilk_has() ? all('SELECT * FROM inv_item_links WHERE item_id = ? ORDER BY kind, title', [$itemId]) : []; }
/** Only http(s) URLs, no credentials in the authority part, no control characters. */
function ilk_url_ok(string $u): bool {
    if ($u === '' || strlen($u) > 500 || preg_match('/[\x00-\x20<>"\']/', $u)) return false;
    $p = parse_url($u); if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    return (bool) filter_var($u, FILTER_VALIDATE_URL);
}
function ilk_add_from_post(): int {
    require_pr_admin(); $iid = (int)($_POST['item_id'] ?? 0); if (!stk_item($iid)) throw new RuntimeException(t('err.notfound'));
    $url = trim((string)($_POST['url'] ?? '')); if (!preg_match('~^https?://~i', $url) && $url !== '' && !str_contains($url, '://')) $url = 'https://' . $url;
    if (!ilk_url_ok($url)) throw new RuntimeException(t('ilk.bad_url'));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120); if ($title === '') $title = mb_substr((string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH), 0, 120);
    $kind = in_array($_POST['kind'] ?? '', ILK_KINDS, true) ? $_POST['kind'] : 'other'; $u = current_user();
    q('INSERT INTO inv_item_links (item_id, title, url, kind, user_id, user_name) VALUES (?,?,?,?,?,?)', [$iid, $title, $url, $kind, (int)$u['id'], $u['full_name']]);
    return (int) db()->lastInsertId();
}
function ilk_delete(int $itemId, int $id): void { require_pr_admin(); q('DELETE FROM inv_item_links WHERE id = ? AND item_id = ?', [$id, $itemId]); }
function ilk_icon(string $kind): string { return ['datasheet' => '📄', 'manual' => '📘', 'drawing' => '📐', 'certificate' => '🏅', 'software' => '💾', 'supplier' => '🏬', 'other' => '🔗'][$kind] ?? '🔗'; }

/* ------------------------------------------------------------------ licences */
function swl_mask(?string $k): string { $k = (string)$k; if ($k === '') return '—'; $n = mb_strlen($k); return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', min(12, $n - 4)) . mb_substr($k, -4); }
const SWL_SELECT = "SELECT lc.*, i.code AS item_code, i.description AS item_desc, i.maker, co.name AS supplier,
                    CASE WHEN lc.expires_at IS NULL THEN NULL ELSE DATEDIFF(lc.expires_at, CURDATE()) END AS days_left
                    FROM inv_licences lc JOIN inv_items i ON i.id = lc.item_id LEFT JOIN companies co ON co.id = lc.supplier_id";
function swl_of(int $itemId): array { return swl_has() ? all(SWL_SELECT . ' WHERE lc.item_id = ? ORDER BY FIELD(lc.status, \'pending\', \'active\', \'expired\', \'retired\'), lc.expires_at IS NULL, lc.expires_at, lc.id DESC', [$itemId]) : []; }
function swl_load(int $id): ?array { return swl_has() && $id ? one(SWL_SELECT . ' WHERE lc.id = ?', [$id]) ?: null : null; }
/** The register: all licences, filtered by text / status / "expiring within n days" / assignment. */
function swl_list(array $f = []): array {
    if (!swl_has()) return []; $w = ['1=1']; $a = [];
    if (($f['status'] ?? '') !== '' && in_array($f['status'], SWL_STATES, true)) { $w[] = 'lc.status = ?'; $a[] = $f['status']; }
    elseif (($f['status'] ?? '') === '') { $w[] = "lc.status <> 'retired'"; }
    if (!empty($f['q'])) { $l = '%' . $f['q'] . '%'; $w[] = '(i.code LIKE ? OR i.description LIKE ? OR lc.product LIKE ? OR lc.serial_no LIKE ? OR lc.assigned_label LIKE ? OR i.maker LIKE ?)'; array_push($a, $l, $l, $l, $l, $l, $l); }
    if (!empty($f['expiring'])) { $w[] = 'lc.expires_at IS NOT NULL AND lc.expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'; $a[] = (int)$f['expiring']; }
    if (!empty($f['assigned_kind'])) { $w[] = 'lc.assigned_kind = ?'; $a[] = $f['assigned_kind']; if (!empty($f['assigned_id'])) { $w[] = 'lc.assigned_id = ?'; $a[] = (int)$f['assigned_id']; } }
    return all(SWL_SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY lc.expires_at IS NULL, lc.expires_at, i.code, lc.id DESC LIMIT 1000', $a);
}
/** Expiring (within SWL_WARN_DAYS) or already expired but still active — for the Shortages console and the dashboard badge. */
function swl_expiring(int $days = SWL_WARN_DAYS): array { return swl_has() ? all(SWL_SELECT . " WHERE lc.status IN ('active','pending') AND lc.expires_at IS NOT NULL AND lc.expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY lc.expires_at", [$days]) : []; }
function swl_expiring_count(): int { static $n = null; if ($n === null) { try { $n = count(swl_expiring()); } catch (Throwable $e) { $n = 0; } } return $n; }
function swl_tone(array $l): string { if ($l['status'] === 'retired') return 'mute'; if ($l['status'] === 'pending') return 'gold'; if ($l['status'] === 'expired' || ($l['days_left'] !== null && (int)$l['days_left'] < 0)) return 'bad'; if ($l['days_left'] !== null && (int)$l['days_left'] <= SWL_WARN_DAYS) return 'gold'; return 'ok'; }
/** Label of the assignment target, resolved once on save. */
function swl_assigned_label(string $kind, int $id): ?string {
    try { return match ($kind) {
        'project' => ($r = one('SELECT project_code, name FROM projects WHERE id = ?', [$id])) ? trim(($r['project_code'] ?: '') . ' ' . $r['name']) : null,
        'vessel' => scalar('SELECT name FROM vessels WHERE id = ?', [$id]) ?: null,
        'user' => scalar('SELECT full_name FROM customer_users WHERE id = ?', [$id]) ?: null,
        'company' => scalar('SELECT name FROM companies WHERE id = ?', [$id]) ?: null,
        default => null }; } catch (Throwable $e) { return null; }
}
function swl_save_from_post(): int {
    require_pr_admin(); $id = (int)($_POST['id'] ?? 0); $iid = (int)($_POST['item_id'] ?? 0); $it = stk_item($iid); if (!$it) throw new RuntimeException(t('err.notfound'));
    $s = fn(string $k, int $max) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max); $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $kind = in_array($_POST['kind'] ?? '', SWL_KINDS, true) ? $_POST['kind'] : 'perpetual'; $status = in_array($_POST['status'] ?? '', SWL_STATES, true) ? $_POST['status'] : 'active';
    $ak = in_array($_POST['assigned_kind'] ?? '', SWL_ASSIGN, true) ? $_POST['assigned_kind'] : 'none'; $aid = $ak === 'none' ? null : ((int)($_POST['assigned_id'] ?? 0) ?: null); if ($ak !== 'none' && !$aid) $ak = 'none';
    $label = $aid ? swl_assigned_label($ak, $aid) : null; if ($aid && $label === null) throw new RuntimeException(t('err.notfound'));
    $exp = $d('expires_at'); if ($kind === 'perpetual' && $exp === null) $exp = null; if ($status === 'active' && $exp !== null && $exp < date('Y-m-d')) $status = 'expired';
    $seats = max(0.0, (float) str_replace(',', '.', (string)($_POST['seats'] ?? '1'))) ?: 1.0; $sup = (int)($_POST['supplier_id'] ?? 0) ?: null; $u = current_user();
    $key = array_key_exists('licence_key', $_POST) ? $s('licence_key', 255) : false;   // the key field is absent for non-admin forms → keep
    $vals = [$iid, $s('product', 160) ?: $it['description'], $s('version', 60), $s('serial_no', 120), $seats, $kind, $d('purchased_at'), $exp, $sup, $ak, $aid, $label, $status, $s('note', 400)];
    if ($id) { $cur = swl_load($id); if (!$cur || (int)$cur['item_id'] !== $iid) throw new RuntimeException(t('err.notfound'));
        q('UPDATE inv_licences SET item_id=?, product=?, version=?, serial_no=?, seats=?, kind=?, purchased_at=?, expires_at=?, supplier_id=?, assigned_kind=?, assigned_id=?, assigned_label=?, status=?, note=?' . ($key !== false ? ', licence_key=?' : '') . ' WHERE id=?', array_merge($vals, $key !== false ? [$key] : [], [$id])); }
    else { q('INSERT INTO inv_licences (item_id, product, version, serial_no, seats, kind, purchased_at, expires_at, supplier_id, assigned_kind, assigned_id, assigned_label, status, note, licence_key, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$key !== false ? $key : null, (int)$u['id'], $u['full_name']])); $id = (int) db()->lastInsertId(); }
    cache_bump_later('inv'); return $id;
}
function swl_delete(int $itemId, int $id): void { require_pr_admin(); q('DELETE FROM inv_licences WHERE id = ? AND item_id = ?', [$id, $itemId]); cache_bump_later('inv'); }
/** Goods receipt of a software item: one pending licence per receipt line (seats = accepted qty) — the administrator fills the key later. */
function swl_from_receipt(array $line, float $qa, array $o, int $receiptId): ?int {
    if (!swl_has() || $qa <= 0 || empty($line['item_id'])) return null; $it = stk_item((int)$line['item_id']); if (!$it || ($it['item_type'] ?? 'part') !== 'software') return null;
    $u = current_user();
    q('INSERT INTO inv_licences (item_id, product, seats, kind, purchased_at, supplier_id, po_line_id, receipt_id, assigned_kind, assigned_id, assigned_label, status, note, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [(int)$it['id'], $line['description'] ?: $it['description'], $qa, 'perpetual', date('Y-m-d'), (int)$o['supplier_id'], (int)$line['id'], $receiptId, $o['project_id'] ? 'project' : 'none', $o['project_id'] ? (int)$o['project_id'] : null, $o['project_id'] ? swl_assigned_label('project', (int)$o['project_id']) : null, 'pending', $o['po_no'], (int)$u['id'], $u['full_name']]);
    return (int) db()->lastInsertId();
}
function swl_item_is_software(array $it): bool { return swl_has() && ($it['item_type'] ?? 'part') === 'software'; }
/** Mark expired licences (runs cheaply on the register / shortages views). */
function swl_sweep(): void { if (swl_has()) { try { q("UPDATE inv_licences SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < CURDATE()"); } catch (Throwable $e) {} } }
