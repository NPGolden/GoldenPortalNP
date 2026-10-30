<?php
/**
 * Customer contacts (Golden A/S staff only) — people at owners, management
 * companies, yards, class and suppliers, with the vessels they belong to,
 * dated notes, birthday / name day / interests, and Excel import.
 * Also the Excel import of portal accounts.                 GAS-CP-R17-001
 */

function ct_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) one("SHOW TABLES LIKE 'contacts'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function ct_categories(): array {
    $out = [];
    foreach (['owner', 'owner_rep', 'management', 'captain', 'chief_eng', 'eto', 'crew', 'yard', 'class', 'supplier', 'consultant', 'other'] as $k) $out[$k] = t('ct.cat_' . $k);
    return $out;
}
function ct_cat_pill(string $cat): string {
    $tone = ['owner' => 'gold', 'owner_rep' => 'gold', 'management' => 'navy', 'captain' => 'blue', 'chief_eng' => 'blue', 'eto' => 'blue', 'crew' => 'blue', 'yard' => 'green', 'class' => 'green', 'supplier' => 'mute', 'consultant' => 'mute'][$cat] ?? 'mute';
    return pill($tone, ct_categories()[$cat] ?? $cat);
}
/** Normalise a free-text category (English or the portal language) to a key. */
function ct_norm_category(string $v): ?string {
    $n = mb_strtolower(trim($v)); if ($n === '') return 'other';
    foreach (ct_categories() as $k => $l) if ($n === $k || $n === mb_strtolower($l) || $n === str_replace('_', ' ', $k)) return $k;
    foreach (['en', 'el', 'it', 'uk', 'bg'] as $loc) { $tbl = lang_table($loc); foreach (array_keys(ct_categories()) as $k) if (isset($tbl['ct.cat_' . $k]) && mb_strtolower($tbl['ct.cat_' . $k]) === $n) return $k; }
    $alias = ['owners rep' => 'owner_rep', 'owner representative' => 'owner_rep', "owner's representative" => 'owner_rep', 'representative' => 'owner_rep', 'master' => 'captain', 'chief engineer' => 'chief_eng', 'chief' => 'chief_eng', 'ceng' => 'chief_eng', 'electro-technical officer' => 'eto', 'management company' => 'management', 'manager' => 'management', 'shipyard' => 'yard', 'classification' => 'class', 'class society' => 'class', 'vendor' => 'supplier', 'surveyor' => 'class'];
    return $alias[$n] ?? null;
}

/* ------------------------------------------------------------ companies, channels, subscriptions (R17-002) */
function co_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) one("SHOW TABLES LIKE 'companies'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function co_kinds(): array {
    $out = [];
    foreach (['owner', 'management', 'yard', 'class', 'supplier', 'consultant', 'broker', 'agent', 'other'] as $k) $out[$k] = t('co.kind_' . $k);
    return $out;
}
function co_kind_pill(string $k): string {
    $tone = ['owner' => 'gold', 'management' => 'navy', 'yard' => 'green', 'class' => 'green', 'supplier' => 'mute', 'consultant' => 'mute', 'broker' => 'blue', 'agent' => 'blue'][$k] ?? 'mute';
    return pill($tone, co_kinds()[$k] ?? $k);
}
function co_norm_kind(string $v): ?string {
    $n = mb_strtolower(trim($v)); if ($n === '') return null;
    foreach (co_kinds() as $k => $l) if ($n === $k || $n === mb_strtolower($l)) return $k;
    foreach (['en', 'el', 'it', 'uk', 'bg'] as $loc) { $tbl = lang_table($loc); foreach (array_keys(co_kinds()) as $k) if (isset($tbl['co.kind_' . $k]) && mb_strtolower($tbl['co.kind_' . $k]) === $n) return $k; }
    return ['customer' => 'owner', 'shipowner' => 'owner', 'owners' => 'owner', 'shipyard' => 'yard', 'classification' => 'class', 'class society' => 'class', 'vendor' => 'supplier', 'manufacturer' => 'supplier', 'management company' => 'management', 'manager' => 'management', 'designer' => 'consultant', 'naval architect' => 'consultant', 'brokerage' => 'broker', 'port agent' => 'agent'][$n] ?? null;
}
/** All companies (active first) for pick-lists. */
function co_all(bool $activeOnly = true): array {
    if (!co_has()) return [];
    return all('SELECT co.*, c.name AS customer_name FROM companies co LEFT JOIN customers c ON c.id = co.customer_id ' . ($activeOnly ? 'WHERE co.is_active = 1 ' : '') . 'ORDER BY co.is_active DESC, co.name');
}
/** Company by name (case-insensitive, also by the linked customer's name or ERP code); optionally created. */
function co_find(string $name, ?string $kind = null, bool $create = false, ?array &$res = null): ?int {
    $name = trim($name); if ($name === '' || !co_has()) return null;
    $id = scalar('SELECT id FROM companies WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);
    if (!$id) { $cid = scalar('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) OR erp_code = ? LIMIT 1', [$name, $name]); if ($cid) $id = scalar('SELECT id FROM companies WHERE customer_id = ? LIMIT 1', [$cid]); }
    if ($id) return (int)$id;
    if (!$create) return null;
    q('INSERT INTO companies (name, kind) VALUES (?, ?)', [mb_substr($name, 0, 160), $kind ?: 'other']);
    if ($res !== null) $res['companies_new'] = ($res['companies_new'] ?? 0) + 1;
    return (int) db()->lastInsertId();
}
function co_load(int $id): ?array {
    $c = one('SELECT co.*, c.name AS customer_name FROM companies co LEFT JOIN customers c ON c.id = co.customer_id WHERE co.id = ?', [$id]);
    if ($c) $c['n_contacts'] = (int)scalar('SELECT COUNT(*) FROM contacts WHERE company_id = ?', [$id]);
    return $c ?: null;
}
function co_save_from_post(int $id): int {
    require_cp_admin();
    $str = fn(string $k, int $max) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $name = $str('name', 160); if (!$name) throw new RuntimeException(t('co.need_name'));
    $dup = scalar('SELECT id FROM companies WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $id]); if ($dup) throw new RuntimeException(t('co.dup', ['v' => $name]));
    $kind = co_norm_kind((string)($_POST['kind'] ?? '')) ?? 'other';
    $cid = (int)($_POST['customer_id'] ?? 0) ?: null;
    if ($cid && !scalar('SELECT COUNT(*) FROM customers WHERE id = ?', [$cid])) $cid = null;
    $vals = [$name, $kind, $cid, $str('country', 80), $str('city', 80), $str('website', 190), $str('email', 190), $str('phone', 60), $str('notes', 4000), isset($_POST['is_active']) ? (int)!empty($_POST['is_active']) : 1];
    if ($id) { $vals[] = $id; q('UPDATE companies SET name = ?, kind = ?, customer_id = ?, country = ?, city = ?, website = ?, email = ?, phone = ?, notes = ?, is_active = ? WHERE id = ?', $vals); }
    else { q('INSERT INTO companies (name, kind, customer_id, country, city, website, email, phone, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)', $vals); $id = (int) db()->lastInsertId(); }
    if ($cid) q('UPDATE contacts SET customer_id = ? WHERE company_id = ? AND customer_id IS NULL', [$cid, $id]);
    return $id;
}
function co_delete(int $id): void {
    require_cp_admin();
    q('UPDATE contacts SET company_id = NULL WHERE company_id = ?', [$id]);
    q('DELETE FROM companies WHERE id = ?', [$id]);
}
function ct_channels(): array {
    $out = [];
    foreach (['email', 'phone', 'mobile', 'whatsapp', 'viber', 'sms', 'teams', 'linkedin', 'post'] as $k) $out[$k] = t('ct.ch_' . $k);
    return $out;
}
function ct_subscriptions(): array {
    $out = [];
    foreach (['newsletter', 'campaigns', 'events', 'greetings'] as $k) $out[$k] = t('ct.sub_' . $k);
    return $out;
}
/** "email; WhatsApp, Viber" → "email,whatsapp,viber" (keys only; aliases in any portal language). */
function ct_norm_set(string $v, array $dict, string $prefix): array {
    $out = [];
    foreach (preg_split('/\s*[;,|\n]\s*/u', $v) as $tok) {
        $n = mb_strtolower(trim($tok)); if ($n === '') continue;
        $k = null;
        foreach ($dict as $key => $label) if ($n === $key || $n === mb_strtolower($label)) { $k = $key; break; }
        if ($k === null) foreach (['en', 'el', 'it', 'uk', 'bg'] as $loc) { $tbl = lang_table($loc); foreach (array_keys($dict) as $key) if (isset($tbl[$prefix . $key]) && mb_strtolower($tbl[$prefix . $key]) === $n) { $k = $key; break 2; } }
        if ($k === null) $k = ['e-mail' => 'email', 'mail' => 'email', 'tel' => 'phone', 'telephone' => 'phone', 'cell' => 'mobile', 'gsm' => 'mobile', 'wa' => 'whatsapp', 'text' => 'sms', 'letter' => 'post', 'mail post' => 'post', 'ms teams' => 'teams', 'news' => 'newsletter', 'campaign' => 'campaigns', 'event' => 'events', 'invitations' => 'events', 'wishes' => 'greetings', 'cards' => 'greetings', 'all' => '*'][$n] ?? null;
        if ($k === '*') return array_keys($dict);
        if ($k !== null && isset($dict[$k])) $out[$k] = true;
    }
    return array_keys($out);
}
function ct_set_pills(?string $csv, array $dict, string $tone = 'mute'): string {
    if (!$csv) return '<span class="hint">—</span>';
    $h = [];
    foreach (explode(',', $csv) as $k) if (isset($dict[$k])) $h[] = pill($tone, $dict[$k]);
    return implode(' ', $h);
}

/* ------------------------------------------------------------ dates */
/** "15.03", "15/3", "15 March", "2026-03-15", "15.03.2026" or an Excel serial → "MM-DD" (name days have no year). */
function ct_parse_md(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (preg_match('#^(\d{1,2})[./\-](\d{1,2})(?:[./\-](\d{2,4}))?$#', $v, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; }
    elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $v, $m)) { $mo = (int)$m[2]; $d = (int)$m[3]; }
    elseif (preg_match('/^\d{4,6}(\.0+)?$/', $v)) { $ts = (int)(((float)$v - 25569) * 86400); $mo = (int)date('n', $ts); $d = (int)date('j', $ts); }
    elseif (preg_match('/^(\d{1,2})\s+([A-Za-zΑ-Ωα-ωά-ώ]+)/u', $v, $m) && ($mo = ct_month_no($m[2]))) { $d = (int)$m[1]; }
    else return null;
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
    return sprintf('%02d-%02d', $mo, $d);
}
function ct_month_no(string $name): int {
    $n = mb_strtolower($name);
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
               'ιαν' => 1, 'φεβ' => 2, 'μαρ' => 3, 'απρ' => 4, 'μαι' => 5, 'μάι' => 5, 'ιουν' => 6, 'ιούν' => 6, 'ιουλ' => 7, 'ιούλ' => 7, 'αυγ' => 8, 'σεπ' => 9, 'οκτ' => 10, 'νοε' => 11, 'δεκ' => 12];
    foreach ($months as $k => $no) if (str_starts_with($n, $k)) return $no;
    return 0;
}
/** Birthday: a full date, or a day+month without year (stored with year 1900 = unknown). */
function ct_parse_birthday(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (preg_match('#^(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $v, $m)) return substr($v, 0, 10);
    if (preg_match('/^\d{4,6}(\.0+)?$/', $v)) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));     // Excel serial
    $md = ct_parse_md($v);
    return $md ? '1900-' . $md : null;
}
function ct_year_known(?string $date): bool { return $date && !str_starts_with($date, '1900-'); }
/** Next occurrence of "MM-DD" from today → ['date' => Y-m-d, 'days' => n]. */
function ct_next(?string $md): ?array {
    if (!$md) return null;
    $y = (int)date('Y'); $today = strtotime(date('Y-m-d'));
    $ts = strtotime("$y-$md") ?: null; if (!$ts) return null;
    if ($ts < $today) $ts = strtotime(($y + 1) . "-$md");
    return ['date' => date('Y-m-d', $ts), 'days' => (int)round(($ts - $today) / 86400)];
}
function ct_fmt_md(?string $md): string {
    if (!$md) return '—';
    $ts = strtotime(date('Y') . '-' . $md); return $ts ? date('j M', $ts) : $md;
}
function ct_fmt_birthday(?string $d): string {
    if (!$d) return '—';
    return ct_year_known($d) ? fmt_date($d) : ct_fmt_md(substr($d, 5));
}
function ct_age(?string $d): ?int { return ct_year_known($d) ? (int)date_diff(date_create($d), date_create('today'))->y : null; }

/* ------------------------------------------------------------ data */
function ct_load(int $id): ?array {
    if (!ct_has()) return null;
    $c = one('SELECT ct.*, c.name AS customer_name, u.full_name AS user_name, u.email AS user_email' . (co_has() ? ', co.name AS company_name, co.kind AS company_kind' : ', ct.company AS company_name, NULL AS company_kind') . ' FROM contacts ct LEFT JOIN customers c ON c.id = ct.customer_id LEFT JOIN customer_users u ON u.id = ct.user_id' . (co_has() ? ' LEFT JOIN companies co ON co.id = ct.company_id' : '') . ' WHERE ct.id = ?', [$id]);
    if (!$c) return null;
    $c['vessels'] = ct_vessels($id);
    $c['notes'] = all('SELECT n.*, u.full_name AS author FROM contact_notes n LEFT JOIN customer_users u ON u.id = n.author_id WHERE n.contact_id = ? ORDER BY n.created_at DESC, n.id DESC', [$id]);
    return $c;
}
function ct_vessels(int $cid): array {
    return all('SELECT cv.*, v.name, v.imo, c.name AS customer_name FROM contact_vessels cv JOIN vessels v ON v.id = cv.vessel_id LEFT JOIN customers c ON c.id = v.customer_id WHERE cv.contact_id = ? ORDER BY cv.is_primary DESC, v.name', [$cid]);
}
/** Contacts of one vessel (vessel page card). */
function ct_for_vessel(int $vid): array {
    if (!ct_has()) return [];
    return all('SELECT ct.*, cv.role AS vessel_role, cv.is_primary, ' . (co_has() ? 'co.name' : 'ct.company') . ' AS company_name FROM contact_vessels cv JOIN contacts ct ON ct.id = cv.contact_id ' . (co_has() ? 'LEFT JOIN companies co ON co.id = ct.company_id ' : '') . 'WHERE cv.vessel_id = ? AND ct.is_active = 1 ORDER BY cv.is_primary DESC, ct.full_name', [$vid]);
}
/** Filtered, paged list. $f: q, customer, vessel, category, status, soon, page */
function ct_list(array $f, int $per = 50): array {
    $w = []; $b = [];
    $co = co_has();
    if (($f['q'] ?? '') !== '') { $l = '%' . $f['q'] . '%'; $w[] = '(ct.full_name LIKE ? OR ct.email LIKE ? OR ' . ($co ? 'co.name' : 'ct.company') . ' LIKE ? OR ct.position LIKE ? OR c.name LIKE ? OR ct.interests LIKE ? OR ct.phone LIKE ? OR ct.mobile LIKE ?)'; array_push($b, $l, $l, $l, $l, $l, $l, $l, $l); }
    if ($co && ctype_digit((string)($f['company'] ?? ''))) { $w[] = 'ct.company_id = ?'; $b[] = (int)$f['company']; } elseif ($co && ($f['company'] ?? '') === 'none') $w[] = 'ct.company_id IS NULL';
    if ($co && ($f['channel'] ?? '') !== '' && isset(ct_channels()[$f['channel']])) { $w[] = 'FIND_IN_SET(?, ct.channels)'; $b[] = $f['channel']; }
    if ($co && ($f['sub'] ?? '') !== '' && isset(ct_subscriptions()[$f['sub']])) { $w[] = 'FIND_IN_SET(?, ct.subscriptions)'; $b[] = $f['sub']; }
    if (!empty($f['ids'])) { $ids = array_values(array_filter(array_map('intval', (array)$f['ids']))); if ($ids) { $w[] = 'ct.id IN (' . implode(',', $ids) . ')'; } else $w[] = '0'; }
    if (($f['customer'] ?? '') === 'none') $w[] = 'ct.customer_id IS NULL'; elseif (ctype_digit((string)($f['customer'] ?? ''))) { $w[] = 'ct.customer_id = ?'; $b[] = (int)$f['customer']; }
    if (ctype_digit((string)($f['vessel'] ?? ''))) { $w[] = 'EXISTS (SELECT 1 FROM contact_vessels x WHERE x.contact_id = ct.id AND x.vessel_id = ?)'; $b[] = (int)$f['vessel']; }
    elseif (($f['vessel'] ?? '') === 'none') $w[] = 'NOT EXISTS (SELECT 1 FROM contact_vessels x WHERE x.contact_id = ct.id)';
    if (($f['category'] ?? '') !== '' && isset(ct_categories()[$f['category']])) { $w[] = 'ct.category = ?'; $b[] = $f['category']; }
    if (($f['status'] ?? 'active') === 'active') $w[] = 'ct.is_active = 1'; elseif (($f['status'] ?? '') === 'inactive') $w[] = 'ct.is_active = 0';
    if (($f['soon'] ?? '') === 'birthday') $w[] = "ct.birthday IS NOT NULL AND DATEDIFF(DATE_ADD(ct.birthday, INTERVAL YEAR(CURDATE()) - YEAR(ct.birthday) + IF(DATE_FORMAT(ct.birthday,'%m-%d') < DATE_FORMAT(CURDATE(),'%m-%d'), 1, 0) YEAR), CURDATE()) BETWEEN 0 AND 30";
    elseif (($f['soon'] ?? '') === 'nameday') $w[] = "ct.nameday_md IS NOT NULL AND DATEDIFF(STR_TO_DATE(CONCAT(YEAR(CURDATE()) + IF(ct.nameday_md < DATE_FORMAT(CURDATE(),'%m-%d'), 1, 0), '-', ct.nameday_md), '%Y-%m-%d'), CURDATE()) BETWEEN 0 AND 30";
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $from = "FROM contacts ct LEFT JOIN customers c ON c.id = ct.customer_id " . ($co ? "LEFT JOIN companies co ON co.id = ct.company_id " : "") . $where;
    $total = (int)scalar("SELECT COUNT(*) $from", $b);
    if ($per <= 0) { $per = max(1, $total); }
    $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($f['page'] ?? 1)));
    $rows = all("SELECT ct.*, c.name AS customer_name, " . ($co ? "co.name AS company_name, co.kind AS company_kind" : "ct.company AS company_name, NULL AS company_kind") . ", (SELECT COUNT(*) FROM contact_notes n WHERE n.contact_id = ct.id) AS n_notes,
                 (SELECT GROUP_CONCAT(CONCAT(v.name, IF(cv.role IS NULL OR cv.role = '', '', CONCAT(' (', cv.role, ')'))) ORDER BY cv.is_primary DESC, v.name SEPARATOR '; ') FROM contact_vessels cv JOIN vessels v ON v.id = cv.vessel_id WHERE cv.contact_id = ct.id) AS vessel_list
                 $from ORDER BY c.name, " . ($co ? "co.name" : "ct.company") . ", ct.full_name LIMIT $per OFFSET " . (($page - 1) * $per), $b);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
}
/** Birthdays and name days in the next $days days, soonest first. */
function ct_upcoming(int $days = 30): array {
    if (!ct_has()) return [];
    $rows = all('SELECT ct.id, ct.full_name, ct.company, ct.birthday, ct.nameday_md, c.name AS customer_name FROM contacts ct LEFT JOIN customers c ON c.id = ct.customer_id WHERE ct.is_active = 1 AND (ct.birthday IS NOT NULL OR ct.nameday_md IS NOT NULL)');
    $out = [];
    foreach ($rows as $r) {
        if ($r['birthday'] && ($n = ct_next(substr($r['birthday'], 5))) && $n['days'] <= $days) $out[] = ['kind' => 'birthday', 'contact' => $r, 'days' => $n['days'], 'date' => $n['date'], 'age' => ct_year_known($r['birthday']) ? (int)substr($n['date'], 0, 4) - (int)substr($r['birthday'], 0, 4) : null];
        if ($r['nameday_md'] && ($n = ct_next($r['nameday_md'])) && $n['days'] <= $days) $out[] = ['kind' => 'nameday', 'contact' => $r, 'days' => $n['days'], 'date' => $n['date'], 'age' => null];
    }
    usort($out, fn($a, $b) => [$a['days'], $a['contact']['full_name']] <=> [$b['days'], $b['contact']['full_name']]);
    return $out;
}
/** The list filters as they arrive in a GET or POST. */
function ct_filters_from_request(array $src): array {
    return ['q' => trim((string)($src['q'] ?? '')), 'customer' => (string)($src['customer'] ?? ''), 'company' => (string)($src['company'] ?? ''), 'vessel' => (string)($src['vessel'] ?? ''), 'category' => (string)($src['category'] ?? ''),
            'status' => (string)($src['status'] ?? 'active'), 'soon' => (string)($src['soon'] ?? ''), 'channel' => (string)($src['channel'] ?? ''), 'sub' => (string)($src['sub'] ?? ''), 'page' => (int)($src['page'] ?? 1)];
}
/** Ids of every contact matching the filters (bulk actions). */
function ct_ids(array $f): array {
    $l = ct_list($f, 0);
    return array_map(fn($r) => (int)$r['id'], $l['rows']);
}
function ct_delete_many(array $ids): int {
    require_cp_admin();
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return 0;
    $in = implode(',', $ids);
    q("DELETE FROM contact_notes WHERE contact_id IN ($in)"); q("DELETE FROM contact_vessels WHERE contact_id IN ($in)");
    q("DELETE FROM contacts WHERE id IN ($in)");
    return count($ids);
}
function ct_set_many(array $ids, string $field, ?string $value): int {
    require_staff();
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids || !in_array($field, ['is_active', 'add_sub', 'remove_sub'], true)) return 0;
    $in = implode(',', $ids);
    if ($field === 'is_active') q("UPDATE contacts SET is_active = ? WHERE id IN ($in)", [(int)$value]);
    elseif (isset(ct_subscriptions()[(string)$value])) {
        foreach (all("SELECT id, subscriptions FROM contacts WHERE id IN ($in)") as $r) {
            $set = array_filter(explode(',', (string)$r['subscriptions']));
            $set = $field === 'add_sub' ? array_unique(array_merge($set, [$value])) : array_diff($set, [$value]);
            q('UPDATE contacts SET subscriptions = ? WHERE id = ?', [$set ? implode(',', $set) : null, $r['id']]);
        }
    }
    return count($ids);
}
/** Download the filtered list as a UTF-8 CSV (opens in Excel) — for campaigns, newsletters, events. */
function ct_export_csv(array $f): void {
    $l = ct_list($f, 0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GAS-contacts-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Full name', 'Company', 'Customer', 'Category', 'Position', 'Email', 'Phone', 'Mobile', 'Vessels', 'Channels', 'Subscriptions', 'Birthday', 'Name day', 'Interests', 'Active'], ';');
    foreach ($l['rows'] as $r) fputcsv($out, [$r['full_name'], $r['company_name'], $r['customer_name'], ct_categories()[$r['category']] ?? $r['category'], $r['position'], $r['email'], $r['phone'], $r['mobile'], $r['vessel_list'],
        implode('; ', array_map(fn($k) => ct_channels()[$k] ?? $k, array_filter(explode(',', (string)($r['channels'] ?? ''))))), implode('; ', array_map(fn($k) => ct_subscriptions()[$k] ?? $k, array_filter(explode(',', (string)($r['subscriptions'] ?? ''))))),
        $r['birthday'] ? (ct_year_known($r['birthday']) ? date('d.m.Y', strtotime($r['birthday'])) : substr($r['birthday'], 8, 2) . '.' . substr($r['birthday'], 5, 2)) : '', $r['nameday_md'] ? substr($r['nameday_md'], 3, 2) . '.' . substr($r['nameday_md'], 0, 2) : '', $r['interests'], $r['is_active'] ? 'yes' : 'no'], ';');
    fclose($out); exit;
}
function ct_summary(): array {
    if (!ct_has()) return ['n' => 0, 'companies' => 0, 'vessels' => 0, 'birthdays' => 0, 'namedays' => 0];
    $s = one('SELECT COUNT(*) n, COUNT(DISTINCT COALESCE(customer_id, company)) companies FROM contacts WHERE is_active = 1') ?: [];
    $s['vessels'] = (int)scalar('SELECT COUNT(DISTINCT vessel_id) FROM contact_vessels cv JOIN contacts ct ON ct.id = cv.contact_id AND ct.is_active = 1');
    $up = ct_upcoming(30);
    $s['birthdays'] = count(array_filter($up, fn($x) => $x['kind'] === 'birthday'));
    $s['namedays'] = count(array_filter($up, fn($x) => $x['kind'] === 'nameday'));
    return $s;
}

/* ------------------------------------------------------------ save */
function ct_save_from_post(int $id): int {
    require_staff(); $u = current_user();
    $str = fn(string $k, int $max) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $name = $str('full_name', 120); if (!$name) throw new RuntimeException(t('ct.need_name'));
    $cid = (int)($_POST['customer_id'] ?? 0) ?: null;
    if ($cid && !scalar('SELECT COUNT(*) FROM customers WHERE id = ?', [$cid])) $cid = null;
    $coid = null; $coName = null;
    if (co_has()) {
        $coid = (int)($_POST['company_id'] ?? 0) ?: null;
        if ($coid) { $co = co_load($coid); if (!$co) $coid = null; else { $coName = $co['name']; if (!$cid && $co['customer_id']) $cid = (int)$co['customer_id']; } }
        if (!$coid && $cid) { $coid = scalar('SELECT id FROM companies WHERE customer_id = ? LIMIT 1', [$cid]) ?: null; if ($coid) $coName = scalar('SELECT name FROM companies WHERE id = ?', [$coid]); }
    } else $coName = $str('company', 160);
    $channels = co_has() ? implode(',', array_intersect(array_keys(ct_channels()), (array)($_POST['channels'] ?? []))) : null;
    $subs = co_has() ? implode(',', array_intersect(array_keys(ct_subscriptions()), (array)($_POST['subscriptions'] ?? []))) : null;
    $cat = ct_norm_category((string)($_POST['category'] ?? '')) ?? 'other';
    $email = $str('email', 190); if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('ct.bad_email'));
    $bday = ct_parse_birthday((string)($_POST['birthday'] ?? ''));
    if (($bd = trim((string)($_POST['birthday_md'] ?? ''))) !== '' && !$bday) { $md = ct_parse_md($bd); $bday = $md ? '1900-' . $md : null; }
    $nd = ct_parse_md((string)($_POST['nameday'] ?? ''));
    $uid = (int)($_POST['user_id'] ?? 0) ?: null;
    $vals = [$cid, $coName, $name, $str('position', 120), $cat, $email ? strtolower($email) : null, $str('phone', 60), $str('mobile', 60), $bday, $nd, $str('interests', 4000), $uid, isset($_POST['is_active']) ? (int)!empty($_POST['is_active']) : 1];
    if ($id) {
        if (!one('SELECT id FROM contacts WHERE id = ?', [$id])) throw new RuntimeException(t('err.notfound'));
        $vals[] = $id;
        q('UPDATE contacts SET customer_id = ?, company = ?, full_name = ?, position = ?, category = ?, email = ?, phone = ?, mobile = ?, birthday = ?, nameday_md = ?, interests = ?, user_id = ?, is_active = ? WHERE id = ?', $vals);
    } else {
        $vals[] = (int)$u['id'];
        q('INSERT INTO contacts (customer_id, company, full_name, position, category, email, phone, mobile, birthday, nameday_md, interests, user_id, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
        $id = (int) db()->lastInsertId();
    }
    if (co_has()) q('UPDATE contacts SET company_id = ?, channels = ?, subscriptions = ? WHERE id = ?', [$coid, $channels ?: null, $subs ?: null, $id]);
    // vessels: vessel[] with role[vid] and primary
    if (isset($_POST['vessel'])) {
        q('DELETE FROM contact_vessels WHERE contact_id = ?', [$id]);
        $primary = (int)($_POST['primary_vessel'] ?? 0);
        foreach ((array)$_POST['vessel'] as $vid) {
            $vid = (int)$vid; if (!$vid || !scalar('SELECT COUNT(*) FROM vessels WHERE id = ?', [$vid])) continue;
            $role = mb_substr(trim((string)($_POST['vrole'][$vid] ?? '')), 0, 80) ?: null;
            q('INSERT IGNORE INTO contact_vessels (contact_id, vessel_id, role, is_primary) VALUES (?,?,?,?)', [$id, $vid, $role, (int)($vid === $primary)]);
        }
    }
    $note = trim((string)($_POST['note'] ?? ''));
    if ($note !== '') ct_note_add($id, $note);
    return $id;
}
function ct_delete(int $id): void {
    require_cp_admin();
    q('DELETE FROM contact_notes WHERE contact_id = ?', [$id]);
    q('DELETE FROM contact_vessels WHERE contact_id = ?', [$id]);
    q('DELETE FROM contacts WHERE id = ?', [$id]);
}
function ct_note_add(int $cid, string $body): int {
    $u = current_user();
    q('INSERT INTO contact_notes (contact_id, author_id, body) VALUES (?,?,?)', [$cid, (int)$u['id'], mb_substr($body, 0, 8000)]);
    return (int) db()->lastInsertId();
}
function ct_note_delete(int $nid): void {
    $u = current_user();
    $n = one('SELECT * FROM contact_notes WHERE id = ?', [$nid]); if (!$n) return;
    if ((int)$n['author_id'] !== (int)$u['id'] && !is_cp_admin()) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM contact_notes WHERE id = ?', [$nid]);
}

/** Vessel by name, IMO or a loose spelling ("MY GY-85-02" = "MY-GY-85-02" = "gy8502"). */
function ct_find_vessel(string $name): ?int {
    static $idx = null;
    $norm = fn(string $s) => preg_replace('/^(my|sy|mv|ms)/', '', preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)));
    if ($idx === null) { $idx = []; foreach (all('SELECT id, name, imo FROM vessels') as $v) { $idx[$norm($v['name'])] = (int)$v['id']; if ($v['imo']) $idx[$norm((string)$v['imo'])] = (int)$v['id']; } }
    if (function_exists('vm_has') && vm_has()) { $vid = vm_find($name, preg_match('/^\d{7}$/', trim($name)) ? trim($name) : null); if ($vid) return $vid; }   // R30-001
    $k = $norm($name);
    return $k !== '' && isset($idx[$k]) ? $idx[$k] : null;
}

/* ------------------------------------------------------------ Excel import: contacts */
function ct_import(string $path, bool $dry): array {
    if (!ct_has()) throw new RuntimeException(t('ct.need_r17'));
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'contacts_new' => 0, 'contacts_upd' => 0, 'companies_new' => 0, 'links_new' => 0, 'notes_new' => 0, 'skipped' => [], 'warnings' => []];
    $hdr = pm_import_header($rows, ['name' => ['Full name', 'Name', 'Contact', 'Full Name', 'Person']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Full name']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['company' => ['Company', 'Customer', 'Organisation', 'Organization', 'Owner', 'Firm'], 'category' => ['Category', 'Type', 'Kind', 'Group'], 'position' => ['Position', 'Title', 'Job title', 'Role', 'Function'],
                                         'email' => ['Email', 'E-mail', 'Mail'], 'phone' => ['Phone', 'Telephone', 'Tel', 'Office phone'], 'mobile' => ['Mobile', 'Cell', 'GSM', 'Mobile phone'],
                                         'vessels' => ['Vessels', 'Vessel', 'Yachts', 'Yacht', 'Ships'], 'birthday' => ['Birthday', 'Date of birth', 'DOB', 'Birth date', 'Born'], 'nameday' => ['Name day', 'Nameday', 'Name-day', 'Saint day'],
                                         'interests' => ['Interests', 'Hobbies', 'Likes'], 'notes' => ['Notes', 'Note', 'Remarks', 'Comments', 'Comment'], 'active' => ['Active', 'Status'],
                                         'channels' => ['Channels', 'Communication', 'Communication methods', 'Contact by', 'Preferred channels'], 'subscriptions' => ['Subscriptions', 'Lists', 'Mailing lists', 'Opt-in', 'Campaigns']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $u = current_user();
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $u) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $name = mb_substr($g($r, 'name'), 0, 120);
            if ($name === '') { $res['skipped'][] = [$line, t('ct.im_noname')]; continue; }
            $company = mb_substr($g($r, 'company'), 0, 160);
            $cid = $company !== '' ? scalar('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) OR erp_code = ? ORDER BY is_active DESC LIMIT 1', [$company, $company]) : null;
            $cid = $cid ? (int)$cid : null;
            $coid = null;
            if (co_has() && $company !== '') {
                $before = $res['companies_new'] ?? 0;
                $coid = co_find($company, null, true, $res);
                if (($res['companies_new'] ?? 0) > $before) $res['warnings'][] = [$line, t('co.im_created', ['v' => $company])];
                if (!$cid && $coid) { $cc = scalar('SELECT customer_id FROM companies WHERE id = ?', [$coid]); if ($cc) $cid = (int)$cc; }
            }
            $chRaw = $g($r, 'channels'); $channels = $chRaw === '' ? null : implode(',', ct_norm_set($chRaw, ct_channels(), 'ct.ch_'));
            $subRaw = $g($r, 'subscriptions'); $subs = $subRaw === '' ? null : implode(',', ct_norm_set($subRaw, ct_subscriptions(), 'ct.sub_'));
            $catRaw = $g($r, 'category'); $cat = $catRaw === '' ? null : ct_norm_category($catRaw);
            if ($catRaw !== '' && $cat === null) { $res['warnings'][] = [$line, t('ct.im_badcat', ['v' => $catRaw])]; $cat = 'other'; }
            $email = strtolower(mb_substr($g($r, 'email'), 0, 190)) ?: null;
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['skipped'][] = [$line, t('ct.im_bademail', ['v' => $email])]; continue; }
            $bRaw = $g($r, 'birthday'); $bday = $bRaw === '' ? null : ct_parse_birthday($bRaw);
            if ($bRaw !== '' && !$bday) $res['warnings'][] = [$line, t('ct.im_baddate', ['v' => $bRaw])];
            $nRaw = $g($r, 'nameday'); $nd = $nRaw === '' ? null : ct_parse_md($nRaw);
            if ($nRaw !== '' && !$nd) $res['warnings'][] = [$line, t('ct.im_baddate', ['v' => $nRaw])];
            $activeRaw = mb_strtolower($g($r, 'active')); $active = $activeRaw === '' ? null : (in_array($activeRaw, ['0', 'no', 'n', 'false', 'inactive', 'όχι', 'ne', 'ні', 'не'], true) ? 0 : 1);
            $fields = ['company' => $company ?: null, 'position' => mb_substr($g($r, 'position'), 0, 120) ?: null, 'category' => $cat, 'email' => $email, 'phone' => mb_substr($g($r, 'phone'), 0, 60) ?: null,
                       'mobile' => mb_substr($g($r, 'mobile'), 0, 60) ?: null, 'birthday' => $bday, 'nameday_md' => $nd, 'interests' => mb_substr($g($r, 'interests'), 0, 4000) ?: null, 'is_active' => $active];
            // match: e-mail, else name + company (or name alone when unique)
            $c = $email ? one('SELECT * FROM contacts WHERE email = ? LIMIT 1', [$email]) : null;
            if (!$c) $c = one('SELECT * FROM contacts WHERE LOWER(full_name) = LOWER(?) AND (customer_id <=> ? OR LOWER(COALESCE(company, "")) = LOWER(?)' . (co_has() ? ' OR company_id <=> ?' : '') . ') LIMIT 1', co_has() ? [$name, $cid, $company, $coid] : [$name, $cid, $company]);
            if (!$c && $company === '') { $cands = all('SELECT * FROM contacts WHERE LOWER(full_name) = LOWER(?)', [$name]); if (count($cands) === 1) $c = $cands[0]; }
            if ($c) {
                $sets = ['full_name = ?']; $vals = [$name];
                if ($cid) { $sets[] = 'customer_id = ?'; $vals[] = $cid; }
                if (co_has()) { if ($coid) { $sets[] = 'company_id = ?'; $vals[] = $coid; } if ($channels !== null) { $sets[] = 'channels = ?'; $vals[] = $channels ?: null; } if ($subs !== null) { $sets[] = 'subscriptions = ?'; $vals[] = $subs ?: null; } }
                foreach ($fields as $k => $v) if ($v !== null) { $sets[] = "$k = ?"; $vals[] = $v; }
                $vals[] = $c['id'];
                q('UPDATE contacts SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
                $id = (int)$c['id']; $res['contacts_upd']++;
            } else {
                q('INSERT INTO contacts (customer_id, company, full_name, position, category, email, phone, mobile, birthday, nameday_md, interests, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$cid, $fields['company'], $name, $fields['position'], $cat ?: 'other', $email, $fields['phone'], $fields['mobile'], $bday, $nd, $fields['interests'], $active ?? 1, (int)$u['id']]);
                $id = (int) db()->lastInsertId(); $res['contacts_new']++;
                if (co_has()) q('UPDATE contacts SET company_id = ?, channels = ?, subscriptions = ? WHERE id = ?', [$coid, $channels ?: ($email ? 'email' : null), $subs ?: null, $id]);
            }
            // vessels: "MY ALPHA (Captain); MY BETA (Owner's rep)" — existing vessels only
            $vs = $g($r, 'vessels');
            if ($vs !== '') {
                foreach (preg_split('/\s*[;\n|]\s*/u', $vs) as $one) {
                    if ($one === '') continue;
                    $role = null; $vname = $one;
                    if (preg_match('/^(.*?)\s*[\(\[]\s*(.+?)\s*[\)\]]\s*$/u', $one, $m)) { $vname = $m[1]; $role = mb_substr($m[2], 0, 80); }
                    $vid = ct_find_vessel($vname);
                    if (!$vid) { $res['warnings'][] = [$line, t('ct.im_badvessel', ['v' => $vname])]; continue; }
                    $ex = one('SELECT * FROM contact_vessels WHERE contact_id = ? AND vessel_id = ?', [$id, $vid]);
                    if ($ex) { if ($role) q('UPDATE contact_vessels SET role = ? WHERE contact_id = ? AND vessel_id = ?', [$role, $id, $vid]); }
                    else { q('INSERT INTO contact_vessels (contact_id, vessel_id, role, is_primary) VALUES (?,?,?,0)', [$id, $vid, $role]); $res['links_new']++; }
                }
            }
            $note = $g($r, 'notes');
            if ($note !== '' && !scalar('SELECT COUNT(*) FROM contact_notes WHERE contact_id = ? AND body = ?', [$id, mb_substr($note, 0, 8000)])) { ct_note_add($id, $note); $res['notes_new']++; }
        }
        return $res;
    });
}

/* ------------------------------------------------------------ Excel import: portal accounts (administrators) */
function au_import(string $path, bool $dry): array {
    require_cp_admin();
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'users_new' => 0, 'users_upd' => 0, 'customers_new' => 0, 'skipped' => [], 'warnings' => [], 'generated' => []];
    $hdr = pm_import_header($rows, ['name' => ['Full name', 'Name', 'Full Name'], 'email' => ['Email', 'E-mail', 'Mail', 'Login']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Full name, Email']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['role' => ['Role', 'Access', 'Access role', 'Type'], 'customer' => ['Customer', 'Company', 'Owner', 'Organisation', 'Organization'], 'grole' => ['Golden A/S role', 'Golden role', 'Staff role', 'Position', 'Title'],
                                         'disc' => ['Discipline', 'Department'], 'calendar' => ['Working calendar', 'Calendar', 'Work calendar'], 'branch' => ['Branch', 'Home branch'], 'scope' => ['Branch scope', 'Scope'], 'subs' => ['Assigned sub-projects', 'Sub-projects', 'Assignments', 'Team'], 'locale' => ['Language', 'Locale', 'Lang'], 'password' => ['Password', 'Initial password', 'Pass'], 'twofa' => ['2FA', '2FA required', 'Two-factor', 'TOTP'], 'active' => ['Active', 'Status', 'Enabled'], 'manager' => ['Manager', 'Personal manager', 'Approver']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $hasTotp = (bool)one("SHOW COLUMNS FROM customer_users LIKE 'twofa_required'"); $hasDisc = ts_has();
    $yes = fn(string $v) => in_array(mb_strtolower($v), ['1', 'yes', 'y', 'true', 'x', 'ναι', 'si', 'sì', 'так', 'да', 'on', 'required', 'active', 'enabled'], true);
    $no  = fn(string $v) => in_array(mb_strtolower($v), ['0', 'no', 'n', 'false', 'όχι', 'ні', 'не', 'off', 'exempt', 'inactive', 'disabled'], true);
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $hasTotp, $hasDisc, $yes, $no) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $name = mb_substr($g($r, 'name'), 0, 120); $email = strtolower(mb_substr($g($r, 'email'), 0, 190));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['skipped'][] = [$line, t('au.im_bademail', ['v' => $email])]; continue; }
            $x = one('SELECT * FROM customer_users WHERE email = ?', [$email]);
            if (!$x && $name === '') { $res['skipped'][] = [$line, t('au.im_noname')]; continue; }
            $roleRaw = mb_strtolower($g($r, 'role'));
            $role = match (true) { $roleRaw === '' => $x['role'] ?? 'customer', in_array($roleRaw, ['admin', 'administrator', 'διαχειριστής', 'amministratore'], true) => 'admin', in_array($roleRaw, ['staff', 'golden', 'golden a/s', 'engineer', 'employee', 'προσωπικό'], true) => 'staff', in_array($roleRaw, ['customer', 'owner', 'client', 'πελάτης', 'cliente'], true) => 'customer', default => null };
            if ($role === null) { $res['skipped'][] = [$line, t('au.im_badrole', ['v' => $roleRaw])]; continue; }
            $cid = $x['customer_id'] ?? null;
            if ($role === 'customer') {
                $cn = $g($r, 'customer');
                if ($cn !== '') $cid = pm_find_customer($cn, $res);
                if (!$cid) { $res['skipped'][] = [$line, t('au.need_customer')]; continue; }
            } else $cid = null;
            $srole = $x['staff_role_id'] ?? null;
            if ($role !== 'customer' && ($gr = $g($r, 'grole')) !== '') { $srole = scalar('SELECT id FROM staff_roles WHERE LOWER(name) = LOWER(?) LIMIT 1', [$gr]); if (!$srole) { q('INSERT INTO staff_roles (name, is_active, sort_order) VALUES (?, 1, 99)', [mb_substr($gr, 0, 120)]); $srole = (int) db()->lastInsertId(); $res['warnings'][] = [$line, t('au.im_newrole', ['v' => $gr])]; } }
            if ($role === 'customer') $srole = null;
            $locRaw = strtolower($g($r, 'locale')); $loc = $locRaw === '' ? ($x['locale'] ?? 'en') : (locale_valid($locRaw) ? $locRaw : (['greek' => 'el', 'ελληνικά' => 'el', 'english' => 'en', 'italian' => 'it', 'italiano' => 'it', 'ukrainian' => 'uk', 'bulgarian' => 'bg', 'gr' => 'el'][$locRaw] ?? null));
            if ($loc === null) { $res['warnings'][] = [$line, t('au.im_badlocale', ['v' => $locRaw])]; $loc = $x['locale'] ?? 'en'; }
            $pass = $g($r, 'password');
            if ($pass !== '' && strlen($pass) < 10) { $res['skipped'][] = [$line, t('a.pass_len')]; continue; }
            $twofaRaw = $g($r, 'twofa'); $twofa = $twofaRaw === '' ? null : ($yes($twofaRaw) ? 1 : ($no($twofaRaw) ? 0 : null));
            $activeRaw = $g($r, 'active'); $active = $activeRaw === '' ? null : ($yes($activeRaw) ? 1 : ($no($activeRaw) ? 0 : null));
            if ($x) {
                q('UPDATE customer_users SET full_name = ?, role = ?, customer_id = ?, staff_role_id = ?, locale = ? WHERE id = ?', [$name ?: $x['full_name'], $role, $cid, $srole, $loc, $x['id']]);
                if ($pass !== '') q('UPDATE customer_users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $x['id']]);
                if ($active !== null) q('UPDATE customer_users SET is_active = ? WHERE id = ?', [$active, $x['id']]);
                $uid = (int)$x['id']; $res['users_upd']++;
            } else {
                $generated = false;
                if ($pass === '') { $pass = au_generate_password(); $generated = true; }
                q('INSERT INTO customer_users (customer_id, role, staff_role_id, full_name, email, password_hash, locale, is_active) VALUES (?,?,?,?,?,?,?,?)', [$cid, $role, $srole, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $loc, $active ?? 1]);
                $uid = (int) db()->lastInsertId(); $res['users_new']++;
                if ($generated) $res['generated'][] = [$line, $name, $email, $pass];
                if ($twofa === null) $twofa = 1;
            }
            if ($hasTotp && $twofa !== null) q('UPDATE customer_users SET twofa_required = ? WHERE id = ?', [$twofa, $uid]);
            if ($hasDisc && $role !== 'customer') {
                $dn = $g($r, 'disc'); $mn = $g($r, 'manager');
                if ($dn !== '') { $did = scalar('SELECT id FROM disciplines WHERE LOWER(name) = LOWER(?) LIMIT 1', [$dn]); if ($did) q('UPDATE customer_users SET discipline_id = ? WHERE id = ?', [$did, $uid]); else $res['warnings'][] = [$line, t('au.im_baddisc', ['v' => $dn])]; }
                if ($mn !== '') { $mid = scalar("SELECT id FROM customer_users WHERE role IN ('staff','admin') AND (LOWER(email) = LOWER(?) OR LOWER(full_name) = LOWER(?)) AND id <> ? LIMIT 1", [$mn, $mn, $uid]); if ($mid) q('UPDATE customer_users SET manager_user_id = ? WHERE id = ?', [$mid, $uid]); else $res['warnings'][] = [$line, t('au.im_badmanager', ['v' => $mn])]; }
            } elseif ($hasDisc) q('UPDATE customer_users SET discipline_id = NULL, manager_user_id = NULL WHERE id = ?', [$uid]);
            if ($role !== 'customer' && function_exists('wc_has') && wc_has() && ($cal = strtoupper($g($r, 'calendar'))) !== '') { if (isset(wc_calendars()[$cal])) q('UPDATE customer_users SET work_calendar = ? WHERE id = ?', [$cal, $uid]); else $res['warnings'][] = [$line, t('au.im_badcal', ['v' => $cal])]; }   // R24-004
            if ($role !== 'customer' && function_exists('br_has') && br_has()) {
                if (($bc = strtoupper($g($r, 'branch'))) !== '') { $bid = null; foreach (br_all() as $bb) if (strtoupper($bb['code']) === $bc) $bid = (int)$bb['id']; if ($bid) q('UPDATE customer_users SET branch_id = ? WHERE id = ?', [$bid, $uid]); else $res['warnings'][] = [$line, t('au.im_badbranch', ['v' => $bc])]; }
                if (($sc = $g($r, 'scope')) !== '') q('UPDATE customer_users SET branch_scope = ? WHERE id = ?', [$yes($sc) ? 1 : 0, $uid]);
            }
            if ($role !== 'customer' && function_exists('tm_has') && tm_has() && ($map['subs'] ?? null) !== null) {          // R25-001: "GY-85-02 Y.01, GY-85-02 Y.03" — replaces the assignments
                $ids = [];
                foreach (array_filter(array_map('trim', preg_split('/[,;\n]+/', $g($r, 'subs')))) as $tok) {
                    $sid = scalar("SELECT s.id FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE CONCAT(p.project_code, ' ', s.code) = ? OR CONCAT(p.erp_code, '.', s.code) = ? OR CONCAT(p.project_code, '.', s.code) = ? LIMIT 1", [$tok, $tok, $tok]);
                    if ($sid) $ids[] = (int)$sid; else $res['warnings'][] = [$line, t('au.im_badsub', ['v' => $tok])];
                }
                q('DELETE FROM sp_team WHERE user_id = ?', [$uid]);
                foreach (array_unique($ids) as $sid) q('INSERT IGNORE INTO sp_team (subproject_id, user_id, added_by) VALUES (?,?,?)', [$sid, $uid, (int)(current_user()['id'] ?? 0) ?: null]);
            }
            q('UPDATE projects SET project_manager = ? WHERE pm_user_id = ?', [$name ?: ($x['full_name'] ?? ''), $uid]);
        }
        return $res;
    });
}
/** 14 characters, unambiguous letters and digits plus one symbol. */
function au_generate_password(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; $s = '';
    for ($i = 0; $i < 13; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return substr($s, 0, 5) . '-' . substr($s, 5, 4) . '-' . substr($s, 9) . '!';
}

/* ------------------------------------------------------------ Excel import: companies (administrators) */
function co_import(string $path, bool $dry): array {
    require_cp_admin();
    if (!co_has()) throw new RuntimeException(t('co.need_r17b'));
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'companies_new' => 0, 'companies_upd' => 0, 'customers_new' => 0, 'skipped' => [], 'warnings' => []];
    $hdr = pm_import_header($rows, ['name' => ['Company', 'Name', 'Company name', 'Organisation', 'Organization']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Company']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['kind' => ['Kind', 'Type', 'Category'], 'customer' => ['Customer', 'Portal customer', 'Linked customer', 'ERP code'], 'country' => ['Country'], 'city' => ['City', 'Town'], 'website' => ['Website', 'Web', 'URL'],
                                         'email' => ['Email', 'E-mail', 'General email'], 'phone' => ['Phone', 'Telephone', 'Tel'], 'notes' => ['Notes', 'Remarks', 'Comments'], 'active' => ['Active', 'Status']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $name = mb_substr($g($r, 'name'), 0, 160);
            if ($name === '') { $res['skipped'][] = [$line, t('co.im_noname')]; continue; }
            $kindRaw = $g($r, 'kind'); $kind = $kindRaw === '' ? null : co_norm_kind($kindRaw);
            if ($kindRaw !== '' && $kind === null) { $res['warnings'][] = [$line, t('co.im_badkind', ['v' => $kindRaw])]; $kind = 'other'; }
            $cid = null; $cn = $g($r, 'customer');
            if (mb_strtolower($cn) === 'yes' || mb_strtolower($cn) === 'x') $cn = $name;
            if ($cn !== '' && !in_array(mb_strtolower($cn), ['no', 'n', '-'], true)) $cid = pm_find_customer($cn, $res);
            $activeRaw = mb_strtolower($g($r, 'active')); $active = $activeRaw === '' ? null : (in_array($activeRaw, ['0', 'no', 'n', 'false', 'inactive', 'όχι', 'ні', 'не'], true) ? 0 : 1);
            $fields = ['kind' => $kind, 'customer_id' => $cid, 'country' => mb_substr($g($r, 'country'), 0, 80) ?: null, 'city' => mb_substr($g($r, 'city'), 0, 80) ?: null, 'website' => mb_substr($g($r, 'website'), 0, 190) ?: null,
                       'email' => mb_substr($g($r, 'email'), 0, 190) ?: null, 'phone' => mb_substr($g($r, 'phone'), 0, 60) ?: null, 'notes' => mb_substr($g($r, 'notes'), 0, 4000) ?: null, 'is_active' => $active];
            $id = co_find($name);
            if ($id) {
                $sets = ['name = ?']; $vals = [$name];
                foreach ($fields as $k => $v) if ($v !== null) { $sets[] = "$k = ?"; $vals[] = $v; }
                $vals[] = $id; q('UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals); $res['companies_upd']++;
            } else {
                q('INSERT INTO companies (name, kind, customer_id, country, city, website, email, phone, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [$name, $kind ?: ($cid ? 'owner' : 'other'), $cid, $fields['country'], $fields['city'], $fields['website'], $fields['email'], $fields['phone'], $fields['notes'], $active ?? 1]);
                $id = (int) db()->lastInsertId(); $res['companies_new']++;
            }
            if ($cid) q('UPDATE contacts SET customer_id = ? WHERE company_id = ? AND customer_id IS NULL', [$cid, $id]);
        }
        return $res;
    });
}
