<?php
/**
 * Rate cards register (GAS-CP-R33-001) — the service price list of each branch, by revision.
 * Imported from the master file GAS-PL-<year>_R<n>-service-rates.xlsx (sheets "Hourly rate cards", "Remote support &
 * tokens", "Fixed service prices"): one rate list per branch column pair found (GAS Local / GAS Out of Attica …,
 * GIT Local / GIT Overseas …); an empty branch column means "same as GAS" and no list is created for it. Importing a
 * new revision for a branch supersedes the previous active list; offers keep the list they were priced from.
 */

function rl_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'rate_items'")->fetch(); } catch (Throwable $e) { $h = false; } }
    return $h;
}
const RL_SECTIONS = ['hourly', 'remote', 'fixed'];
function rl_section_label(string $s): string { return t('rl.sec_' . $s); }

function rl_lists(): array {
    if (!rl_has()) return [];
    return all('SELECT l.*, (SELECT COUNT(*) FROM rate_items i WHERE i.rate_list_id = l.id) AS n_items, u.full_name AS created_name FROM rate_lists l LEFT JOIN customer_users u ON u.id = l.created_by ORDER BY l.status = "superseded", l.branch_code, l.rev_date DESC, l.id DESC');
}
function rl_get(int $id): ?array { return rl_has() ? one('SELECT * FROM rate_lists WHERE id = ?', [$id]) : null; }
/** The active list of a branch (by branch id, else by code), falling back to the default branch's, else any active. */
function rl_current(?int $branchId = null, ?string $code = null): ?array {
    if (!rl_has()) return null;
    if ($branchId && ($l = one("SELECT * FROM rate_lists WHERE status = 'active' AND branch_id = ? ORDER BY rev_date DESC, id DESC LIMIT 1", [$branchId]))) return $l;
    if ($code && ($l = one("SELECT * FROM rate_lists WHERE status = 'active' AND branch_code = ? ORDER BY rev_date DESC, id DESC LIMIT 1", [$code]))) return $l;
    return one("SELECT * FROM rate_lists WHERE status = 'active' ORDER BY branch_code = 'GAS' DESC, rev_date DESC, id DESC LIMIT 1");
}
function rl_items(int $listId, ?string $section = null, ?string $card = null): array {
    if (!rl_has()) return [];
    $w = ['rate_list_id = ?']; $b = [$listId];
    if ($section) { $w[] = 'section = ?'; $b[] = $section; }
    if ($card !== null) { $w[] = 'card = ?'; $b[] = $card; }
    return all('SELECT * FROM rate_items WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(section, "hourly", "remote", "fixed"), sort_order, id', $b);
}
function rl_item(int $id): ?array { return rl_has() ? one('SELECT i.*, l.currency FROM rate_items i JOIN rate_lists l ON l.id = i.rate_list_id WHERE i.id = ?', [$id]) : null; }
/** Cards of a list: card => name, in order. */
function rl_cards(int $listId): array {
    $c = [];
    foreach (all('SELECT section, card, card_name, MIN(sort_order) o FROM rate_items WHERE rate_list_id = ? GROUP BY section, card, card_name ORDER BY FIELD(section, "hourly", "remote", "fixed"), o', [$listId]) as $r) $c[$r['section'] . ':' . $r['card']] = ['section' => $r['section'], 'card' => $r['card'], 'name' => $r['card_name']];
    return $c;
}
function rl_price(array $i, string $zone): ?float {
    $v = $zone === 'overseas' ? ($i['price_overseas'] ?? $i['price_local']) : ($i['price_local'] ?? $i['price_overseas']);
    return $v === null ? null : (float)$v;
}
function rl_delete(int $id): void { require_cp_admin(); if (!rl_has()) return; q('DELETE FROM rate_items WHERE rate_list_id = ?', [$id]); q('DELETE FROM rate_lists WHERE id = ?', [$id]); }
function rl_set_status(int $id, string $st): void { require_cp_admin(); if (rl_has() && in_array($st, ['active', 'superseded'], true)) q('UPDATE rate_lists SET status = ? WHERE id = ?', [$st, $id]); }

/** Parse a price cell: "80", "80.00", "2.500,00", "@ cost +20%" → null. */
function rl_num(string $s): ?float {
    $s = trim($s); if ($s === '' || !preg_match('/\d/', $s) || preg_match('/^@|request|upon/i', $s)) return null;
    $s = preg_replace('/[^\d.,-]/', '', $s);
    if (preg_match('/,\d{1,2}$/', $s)) $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s);
    if (substr_count($s, '.') > 1) $s = preg_replace('/\.(?=.*\.)/', '', $s);
    return is_numeric($s) ? round((float)$s, 2) : null;
}

/** Import the master file. Returns the report; $dry only parses. */
function rl_import(string $path, string $sourceName, bool $dry): array {
    require_cp_admin(); if (!rl_has()) throw new RuntimeException(t('rl.need_db'));
    require_once __DIR__ . '/xlsx.php';
    $res = ['doc_ref' => '', 'revision' => '', 'rev_date' => null, 'branches' => [], 'items' => 0, 'skipped' => [], 'lists' => []];
    // Document sheet
    $meta = [];
    try { foreach (xlsx_rows($path, 'Document') as $r) if (($r[0] ?? '') !== '') $meta[strtolower(trim((string)$r[0]))] = trim((string)($r[1] ?? '')); } catch (Throwable $e) {}
    $res['doc_ref'] = $meta['document reference'] ?? 'GAS-PL-' . date('Y'); $res['revision'] = $meta['revision'] ?? 'R0';
    $rd = $meta['revision date'] ?? ''; $res['rev_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) ? $rd : (($t = strtotime($rd)) ? date('Y-m-d', $t) : date('Y-m-d'));
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($meta['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR';
    // branch columns: headers like "GAS Local (EUR)", "GAS Out of Attica / Overseas (EUR)", "GIT Local (EUR)", "GIT (EUR)"
    $perBranch = [];   // code => rows
    $sheets = [['Hourly rate cards', 'hourly'], ['Remote support & tokens', 'remote'], ['Fixed service prices', 'fixed']];
    $order = 0;
    foreach ($sheets as [$sheet, $section]) {
        try { $rows = xlsx_rows($path, $sheet); } catch (Throwable $e) { $res['skipped'][] = $sheet . ': ' . $e->getMessage(); continue; }
        if (!$rows) continue;
        $hdr = array_map(fn($h) => trim((string)$h), $rows[0]); $col = fn(string $re) => (function () use ($hdr, $re) { foreach ($hdr as $i => $h) if (preg_match($re, $h)) return $i; return null; })();
        $cCard = $col('/^rate card$/i'); $cName = $col('/^card name$/i'); $cErp = $col('/^(erp )?code$/i'); $cDesc = $col('/^description/i'); $cUnit = $col('/^unit$/i'); $cNotes = $col('/^notes/i');
        $bcols = [];
        foreach ($hdr as $i => $h) {
            if (preg_match('/^([A-Z]{2,5})\s+(Local|Out of Attica|Overseas)?\s*(\/\s*Overseas)?\s*\(?[A-Z]{3}\)?$/i', $h, $m) || preg_match('/^([A-Z]{2,5})\s*\([A-Z]{3}\)$/', $h, $m)) {
                $code = strtoupper($m[1]); $zone = isset($m[2]) && preg_match('/attica|overseas/i', $m[2]) ? 'overseas' : 'local';
                $bcols[$code][$zone] = $i;
            }
        }
        if ($cDesc === null || !$bcols) { $res['skipped'][] = $sheet . ': ' . t('rl.no_columns'); continue; }
        foreach (array_slice($rows, 1) as $r) {
            $desc = trim((string)($r[$cDesc] ?? '')); if ($desc === '') continue;
            $order++;
            foreach ($bcols as $code => $z) {
                $pl = isset($z['local']) ? rl_num((string)($r[$z['local']] ?? '')) : null; $po = isset($z['overseas']) ? rl_num((string)($r[$z['overseas']] ?? '')) : null;
                $hasAny = ($pl !== null || $po !== null);
                // a branch column that is entirely empty on the sheet is "same as GAS": skip the branch when it has no price anywhere
                $perBranch[$code]['any'] = ($perBranch[$code]['any'] ?? false) || $hasAny;
                $perBranch[$code]['rows'][] = ['section' => $section, 'card' => $cCard !== null ? trim((string)($r[$cCard] ?? '')) : '', 'card_name' => $cName !== null ? trim((string)($r[$cName] ?? '')) : '',
                    'erp_code' => $cErp !== null ? trim((string)($r[$cErp] ?? '')) : '', 'description' => mb_substr($desc, 0, 400), 'unit' => $cUnit !== null ? (trim((string)($r[$cUnit] ?? '')) ?: 'hour') : 'fixed',
                    'price_local' => $pl, 'price_overseas' => $po, 'rule' => $cNotes !== null ? (trim((string)($r[$cNotes] ?? '')) ?: null) : null, 'sort_order' => $order, 'has' => $hasAny];
            }
        }
    }
    foreach ($perBranch as $code => $b) {
        if (empty($b['any'])) { $res['skipped'][] = t('rl.branch_empty', ['b' => $code]); continue; }
        $res['branches'][$code] = count($b['rows']);
    }
    if (!$res['branches']) throw new RuntimeException(t('rl.nothing'));
    if ($dry) return $res;
    $u = current_user(); $branches = function_exists('br_all') ? br_all() : [];
    foreach ($res['branches'] as $code => $n) {
        $bid = null; foreach ($branches as $br) if (strtoupper((string)$br['code']) === $code && !empty($br['id'])) $bid = (int)$br['id'];
        q("UPDATE rate_lists SET status = 'superseded' WHERE branch_code = ? AND status = 'active'", [$code]);
        q('INSERT INTO rate_lists (doc_ref, revision, rev_date, branch_code, branch_id, currency, status, source_name, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$res['doc_ref'], $res['revision'], $res['rev_date'], $code, $bid, $cur, 'active', mb_substr($sourceName, 0, 255), (int)$u['id']]);
        $lid = (int) db()->lastInsertId(); $res['lists'][] = $lid;
        foreach ($perBranch[$code]['rows'] as $x) {
            q('INSERT INTO rate_items (rate_list_id, section, card, card_name, erp_code, description, unit, price_local, price_overseas, rule, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$lid, $x['section'], mb_substr($x['card'], 0, 12), mb_substr($x['card_name'], 0, 80), mb_substr($x['erp_code'], 0, 30), $x['description'], mb_substr($x['unit'], 0, 20), $x['price_local'], $x['price_overseas'], $x['rule'] ? mb_substr($x['rule'], 0, 255) : null, $x['sort_order']]);
            $res['items']++;
        }
    }
    return $res;
}
