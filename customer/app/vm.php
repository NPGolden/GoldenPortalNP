<?php
declare(strict_types=1);
/**
 * Vessel unification (GAS-CP-R30-001): one record per hull.
 *  - vm_norm()      the matching key of a name: prefix, brackets, punctuation, case and Greek look-alikes ignored
 *  - vm_find()      how every import looks a vessel up: IMO → alias → normalised name; never creates a duplicate
 *  - vm_merge()     folds duplicate records into a master: every reference is re-pointed in one transaction,
 *                   the duplicate's name becomes an alias, the duplicate is retired (merged_into)
 *  - related companies (vessel_companies): the management company, yard, agent, contractor of a vessel
 */

const VM_PREFIXES = ['M/Y', 'S/Y', 'M/V', 'M/T', 'P/S', 'HSC', 'M/S', 'C/F', 'F/B', 'R/V', 'TUG'];
const VM_ROLES    = ['owner', 'manager', 'yard', 'class', 'agent', 'contractor', 'charterer', 'other'];   // R85-004: + class

function vm_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'vessel_aliases'"); } catch (Throwable $e) { $h = false; } } return $h; }

/** Latin-ise the Greek capitals that look like Latin ones (Μ/Υ typed on a Greek keyboard) and collapse spacing. */
function vm_latin(string $s): string {
    static $map = ['Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X'];
    return strtr(mb_strtoupper(trim($s)), $map);
}
/** The prefix of a name (M/Y, S/Y …) in canonical form, or ''. */
function vm_prefix(string $name): string {
    $u = vm_latin($name);
    if (preg_match('~^(M/Y|M\.Y\.|MY|S/Y|S\.Y\.|SY|M/V|M\.V\.|MV|M/T|M\.T\.|MT|P/S|HSC|M/S|C/F|F/B|R/V|TUG|MOTOR YACHT|SAILING YACHT)\s+~u', $u, $m)) {
        $p = $m[1];
        return ['MY' => 'M/Y', 'M.Y.' => 'M/Y', 'MOTOR YACHT' => 'M/Y', 'SY' => 'S/Y', 'S.Y.' => 'S/Y', 'SAILING YACHT' => 'S/Y', 'MV' => 'M/V', 'M.V.' => 'M/V', 'MT' => 'M/T', 'M.T.' => 'M/T'][$p] ?? $p;
    }
    return '';
}
/** Matching key: no prefix, no brackets, letters and digits only, single spaces. */
function vm_norm(string $name): string {
    $u = vm_latin($name);
    $u = preg_replace('~^(M/Y|M\.Y\.|MY|S/Y|S\.Y\.|SY|M/V|M\.V\.|MV|M/T|M\.T\.|MT|P/S|HSC|M/S|C/F|F/B|R/V|TUG|MOTOR YACHT|SAILING YACHT)\s+~u', '', $u);
    $u = preg_replace('~\(.*?\)~u', ' ', $u);
    $u = preg_replace('~\b(EX|OLD|NEW)\b~u', ' ', $u);
    $u = preg_replace('~[^A-Z0-9]+~u', ' ', $u);
    return trim(preg_replace('~\s+~', ' ', $u));
}
/** Former name written in brackets — "M/Y AXIOS (ex ETHOS)" → "ETHOS". */
function vm_ex_name(string $name): string {
    if (preg_match('~\((?:ex\.?|former(?:ly)?|old)?\s*([^)]+)\)~iu', $name, $m)) { $x = trim($m[1]); return preg_match('~^(old|new)$~i', $x) ? '' : $x; }
    return '';
}
/** Canonical display name: "<prefix> <NAME>" — prefix normalised, brackets removed, one space, capitals. */
function vm_canonical(string $name, string $defaultPrefix = ''): string {
    $p = vm_prefix($name) ?: $defaultPrefix;
    $core = vm_latin($name);
    $core = preg_replace('~^(M/Y|M\.Y\.|MY|S/Y|S\.Y\.|SY|M/V|M\.V\.|MV|M/T|M\.T\.|MT|P/S|HSC|M/S|C/F|F/B|R/V|TUG|MOTOR YACHT|SAILING YACHT)\s+~u', '', $core);
    $core = trim(preg_replace('~\s+~', ' ', preg_replace('~\(.*?\)~u', ' ', $core)));
    return trim(($p ? $p . ' ' : '') . $core);
}

/** Find the one vessel a name / IMO refers to. Never creates. Retired (merged) records resolve to their master. */
function vm_find(string $name, ?string $imo = null, ?int $customerId = null): ?int {
    $imo = trim((string)$imo);
    if ($imo !== '' && preg_match('~^\d{7}$~', $imo)) { $id = scalar('SELECT id FROM vessels WHERE imo = ? ORDER BY merged_into IS NULL DESC, is_active DESC, id LIMIT 1', [$imo]); if ($id) return vm_resolve((int)$id); }
    $k = vm_norm($name);
    if ($k === '') return null;
    if (vm_has()) { $id = scalar('SELECT vessel_id FROM vessel_aliases WHERE alias_norm = ? LIMIT 1', [$k]); if ($id) return vm_resolve((int)$id); }
    $rows = all('SELECT id, name, customer_id, is_active, imo' . (vm_has() ? ', merged_into' : ', NULL AS merged_into') . ' FROM vessels');
    $hits = [];
    foreach ($rows as $r) if (vm_norm($r['name']) === $k) $hits[] = $r;
    if (!$hits) {   // the bracketed former name of a registered vessel
        foreach ($rows as $r) { $x = vm_ex_name($r['name']); if ($x !== '' && vm_norm($x) === $k) $hits[] = $r; }
    }
    if (!$hits) return null;
    usort($hits, fn($a, $b) => [(int)($a['merged_into'] !== null), -(int)$a['is_active'], -(int)($customerId && (int)$a['customer_id'] === $customerId), -(int)!empty($a['imo']), (int)$a['id']]
                           <=> [(int)($b['merged_into'] !== null), -(int)$b['is_active'], -(int)($customerId && (int)$b['customer_id'] === $customerId), -(int)!empty($b['imo']), (int)$b['id']]);
    return vm_resolve((int)$hits[0]['id']);
}
/** Follow merged_into to the surviving record. */
function vm_resolve(int $id): int {
    if (!vm_has()) return $id;
    for ($i = 0; $i < 5; $i++) { $m = scalar('SELECT merged_into FROM vessels WHERE id = ?', [$id]); if (!$m) return $id; $id = (int)$m; }
    return $id;
}
/** Register an alias (another tool's spelling) of a vessel. Silently ignored when the spelling already belongs to any vessel. */
function vm_alias_add(int $vid, string $alias, string $source = 'manual'): void {
    if (!vm_has()) return;
    $alias = trim($alias); $k = vm_norm($alias);
    if ($k === '' || $alias === '') return;
    $own = all('SELECT name FROM vessels WHERE id = ?', [$vid]);
    if ($own && vm_norm($own[0]['name']) === $k) return;   // the vessel's own name needs no alias
    try { q('INSERT IGNORE INTO vessel_aliases (vessel_id, alias, alias_norm, source) VALUES (?,?,?,?)', [$vid, mb_substr($alias, 0, 120), mb_substr($k, 0, 120), mb_substr($source, 0, 40)]); } catch (Throwable $e) { /* duplicate spelling */ }
}
function vm_aliases(int $vid): array { return vm_has() ? all('SELECT id, alias, source, created_at FROM vessel_aliases WHERE vessel_id = ? ORDER BY alias', [$vid]) : []; }
function vm_alias_remove(int $id): void { if (vm_has()) q('DELETE FROM vessel_aliases WHERE id = ?', [$id]); }

/** Related companies of a vessel (management company, yard, agent …) — customer records with a role. */
function vm_orgs_has(): bool { static $h = null; if ($h === null) { try { $h = vm_has() && (bool) db()->query("SHOW COLUMNS FROM vessel_companies LIKE 'company_id'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** R85-004: the related companies are organisations of the Contacts module (old rows that only carry a customer are shown through the customer's organisation or name). */
function vm_related(int $vid): array {
    if (!vm_has()) return [];
    if (!vm_orgs_has()) return all('SELECT vc.id, vc.customer_id, vc.role, vc.note, c.name FROM vessel_companies vc JOIN customers c ON c.id = vc.customer_id WHERE vc.vessel_id = ? ORDER BY FIELD(vc.role, ' . implode(',', array_map(fn($r) => "'$r'", VM_ROLES)) . '), c.name', [$vid]);
    return all('SELECT vc.id, vc.customer_id, vc.role, vc.note, COALESCE(co.id, co2.id) AS company_id, COALESCE(co.name, co2.name, c.name) AS name, COALESCE(co.kind, co2.kind) AS kind, COALESCE(co.roles, co2.roles) AS roles, COALESCE(co.is_supplier, co2.is_supplier) AS is_supplier, COALESCE(co.customer_id, co2.customer_id, vc.customer_id) AS org_customer_id, COALESCE(co.email, co2.email) AS email, COALESCE(co.city, co2.city) AS city, COALESCE(co.country, co2.country) AS country
                FROM vessel_companies vc LEFT JOIN companies co ON co.id = vc.company_id LEFT JOIN companies co2 ON vc.company_id IS NULL AND co2.customer_id = vc.customer_id LEFT JOIN customers c ON c.id = vc.customer_id
                WHERE vc.vessel_id = ? ORDER BY FIELD(vc.role, ' . implode(',', array_map(fn($r) => "'$r'", VM_ROLES)) . '), name', [$vid]);
}
/** $who = company id (organisations) — or, before the R85-004 migration, a customer id. */
function vm_related_set(int $vid, int $who, string $role, ?string $note = null): void {
    if (!vm_has() || !$who) return;
    $role = in_array($role, VM_ROLES, true) ? $role : 'other'; $note = $note !== null ? mb_substr($note, 0, 255) : null;
    if (!vm_orgs_has()) { q('INSERT INTO vessel_companies (vessel_id, customer_id, role, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role), note = COALESCE(VALUES(note), note)', [$vid, $who, $role, $note]); return; }
    $cu = scalar('SELECT customer_id FROM companies WHERE id = ?', [$who]);
    if ($x = scalar('SELECT id FROM vessel_companies WHERE vessel_id = ? AND company_id = ?', [$vid, $who])) q('UPDATE vessel_companies SET role = ?, note = COALESCE(?, note) WHERE id = ?', [$role, $note, (int)$x]);
    else q('INSERT INTO vessel_companies (vessel_id, customer_id, company_id, role, note) VALUES (?,?,?,?,?)', [$vid, $cu ?: null, $who, $role, $note]);
}
/** From an import that knows only the customer: resolve the organisation (creating it) and link. */
function vm_related_set_customer(int $vid, int $customerId, string $role, ?string $note = null): void {
    if (vm_orgs_has() && function_exists('org_ensure_for_customer') && ($co = org_ensure_for_customer($customerId))) { vm_related_set($vid, $co, $role, $note); return; }
    vm_related_set($vid, $customerId, $role, $note);
}
function vm_related_remove(int $id): void { if (vm_has()) q('DELETE FROM vessel_companies WHERE id = ?', [$id]); }
function vm_role_label(string $r): string { return t('vm.role_' . (in_array($r, VM_ROLES, true) ? $r : 'other')); }

/** What hangs on a vessel — the counts shown before a merge. */
function vm_counts(int $vid): array {
    $c = ['projects' => 'SELECT COUNT(*) FROM projects WHERE vessel_id = ?', 'tickets' => 'SELECT COUNT(*) FROM tickets WHERE vessel_id = ?', 'contacts' => 'SELECT COUNT(*) FROM contact_vessels WHERE vessel_id = ?',
          'equipment' => 'SELECT COUNT(*) FROM equipment WHERE vessel_id = ?', 'documents' => 'SELECT COUNT(*) FROM documents WHERE vessel_id = ?', 'tasks' => 'SELECT COUNT(*) FROM tasks WHERE vessel_id = ?', 'planning' => 'SELECT COUNT(*) FROM plan_entries WHERE vessel_id = ?'];
    $out = [];
    foreach ($c as $k => $sql) { try { $out[$k] = (int) scalar($sql, [$vid]); } catch (Throwable $e) { $out[$k] = 0; } }
    return $out;
}

/** Every column of the database that points at vessels.id — found once, so a new table joins the merge by itself. */
function vm_ref_columns(): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $rows = all("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'vessel_id' AND table_name <> 'vessels'");
    $cols = array_map(fn($r) => [$r['t'], $r['c']], $rows);
    return $cols;
}

/** Merge duplicates into the master. Returns ['moved' => [table => n], 'aliases' => n]. One transaction; throws on any error. */
function vm_merge(int $masterId, array $dupIds, string $by = ''): array {
    if (!vm_has()) throw new RuntimeException('vessel unification tables missing');
    $master = one('SELECT * FROM vessels WHERE id = ?', [$masterId]);
    if (!$master) throw new RuntimeException(t('err.notfound'));
    if ($master['merged_into']) throw new RuntimeException(t('vm.master_retired'));
    $dupIds = array_values(array_unique(array_filter(array_map('intval', $dupIds), fn($d) => $d > 0 && $d !== $masterId)));
    if (!$dupIds) throw new RuntimeException(t('vm.nothing_selected'));
    $moved = []; $aliases = 0;
    $db = db(); $db->beginTransaction();
    try {
        foreach ($dupIds as $dupId) {
            $dup = one('SELECT * FROM vessels WHERE id = ?', [$dupId]);
            if (!$dup) continue;
            if ($dup['merged_into']) continue;   // already retired
            // 1. every vessel_id column
            foreach (vm_ref_columns() as [$t, $c]) {
                $n = 0;
                if ($t === 'contact_vessels') {                     // unique (contact_id, vessel_id): move what does not clash, drop the rest
                    $n = q('UPDATE IGNORE contact_vessels SET vessel_id = ? WHERE vessel_id = ?', [$masterId, $dupId])->rowCount();
                    q('DELETE FROM contact_vessels WHERE vessel_id = ?', [$dupId]);
                } elseif ($t === 'vessel_companies') {
                    $n = q('UPDATE IGNORE vessel_companies SET vessel_id = ? WHERE vessel_id = ?', [$masterId, $dupId])->rowCount();
                    q('DELETE FROM vessel_companies WHERE vessel_id = ?', [$dupId]);
                } elseif ($t === 'vessel_aliases') {
                    $n = q('UPDATE vessel_aliases SET vessel_id = ? WHERE vessel_id = ?', [$masterId, $dupId])->rowCount();
                } else {
                    $n = q("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?", [$masterId, $dupId])->rowCount();
                }
                if ($n) $moved[$t] = ($moved[$t] ?? 0) + $n;
            }
            // 2. report headers carry the vessel's name as text
            foreach (['fsr_reports', 'isr_reports'] as $t) { try { $n = q("UPDATE `$t` SET vessel_name = ? WHERE vessel_name = ?", [$master['name'], $dup['name']])->rowCount(); if ($n) $moved[$t] = ($moved[$t] ?? 0) + $n; } catch (Throwable $e) {} }
            // 3. the duplicate's customer becomes a related company when it differs from the master's
            if ($dup['customer_id'] && (int)$dup['customer_id'] !== (int)$master['customer_id']) {
                if (!$master['customer_id']) { q('UPDATE vessels SET customer_id = ? WHERE id = ?', [$dup['customer_id'], $masterId]); $master['customer_id'] = $dup['customer_id']; }
                else vm_related_set($masterId, (int)$dup['customer_id'], 'other', t('vm.from_merge', ['n' => $dup['name']]));
            }
            // 4. fill what the master lacks; keep the former name
            q('UPDATE vessels SET imo = COALESCE(NULLIF(imo, ""), ?), yard = COALESCE(NULLIF(yard, ""), ?) WHERE id = ?', [$dup['imo'] ?: null, $dup['yard'] ?: null, $masterId]);
            $ex = vm_ex_name($dup['name']); $fn = array_filter(array_map('trim', explode(';', (string)($master['former_names'] ?? ''))));
            foreach (array_filter([$ex, vm_ex_name($master['name'])]) as $x) if (!in_array($x, $fn, true)) $fn[] = $x;
            if ($fn) { q('UPDATE vessels SET former_names = ? WHERE id = ?', [mb_substr(implode('; ', $fn), 0, 255), $masterId]); $master['former_names'] = implode('; ', $fn); }
            // 5. the duplicate's spelling becomes an alias, the record is retired
            vm_alias_add($masterId, $dup['name'], 'merge'); $aliases++;
            foreach (all('SELECT alias FROM vessel_aliases WHERE vessel_id = ?', [$dupId]) as $a) vm_alias_add($masterId, $a['alias'], 'merge');
            q('UPDATE vessels SET is_active = 0, merged_into = ? WHERE id = ?', [$masterId, $dupId]);
            if (function_exists('audit')) { try { audit('vessel_merge', 'vessel', $masterId, $dup['name'] . ' → ' . $master['name']); } catch (Throwable $e) {} }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return ['moved' => $moved, 'aliases' => $aliases];
}

/** Suggested duplicate groups among live vessels: same matching key, or a bracketed former name equal to another vessel's key. */
function vm_groups(): array {
    $rows = all('SELECT id, name, imo, customer_id, yard, is_active, created_at, (SELECT name FROM customers c WHERE c.id = v.customer_id) AS customer FROM vessels v WHERE ' . (vm_has() ? 'merged_into IS NULL' : '1=1') . ' ORDER BY name');
    $g = [];
    foreach ($rows as $r) { $k = vm_norm($r['name']); if ($k === '') continue; $g[$k][] = $r; }
    foreach ($rows as $r) { $x = vm_ex_name($r['name']); if ($x === '') continue; $k = vm_norm($x); if (isset($g[$k]) && !in_array($r, $g[$k], true)) $g[$k][] = $r; }
    $out = [];
    foreach ($g as $k => $list) {
        if (count($list) < 2) continue;
        $list = array_values(array_unique($list, SORT_REGULAR));
        if (count($list) < 2) continue;
        foreach ($list as &$r) { $r['counts'] = vm_counts((int)$r['id']); $r['weight'] = ($r['imo'] ? 3 : 0) + 2 * $r['counts']['projects'] + ($r['counts']['contacts'] ? 1 : 0) + ($r['counts']['tickets'] ? 1 : 0) + ($r['customer_id'] ? 1 : 0) + $r['counts']['equipment']; }
        unset($r);
        usort($list, fn($a, $b) => $b['weight'] <=> $a['weight'] ?: $a['id'] <=> $b['id']);
        $pf = ''; foreach ($list as $r) if (($p = vm_prefix($r['name'])) !== '') { $pf = $p; break; }
        $out[] = ['key' => $k, 'canonical' => vm_canonical($list[0]['name'], $pf ?: 'M/Y'), 'rows' => $list];
    }
    usort($out, fn($a, $b) => strcmp($a['key'], $b['key']));
    return $out;
}

/** Apply a merge map (Master name · Duplicate record) from the unification workbook. */
function vm_merge_map(string $path, bool $dry): array {
    $res = ['rows' => 0, 'merged' => 0, 'skipped' => [], 'errors' => [], 'groups' => 0];
    require_once __DIR__ . '/xlsx.php';
    $rows = xlsx_rows($path, 'Merge map (for import)') ?: xlsx_rows($path);
    if (!$rows) throw new RuntimeException(t('vm.map_empty'));
    $h = pm_import_header($rows, ['master' => ['master name', 'master'], 'dup' => ['duplicate record', 'duplicate', 'record']]);
    if (!$h) throw new RuntimeException(t('vm.map_headers'));
    [$hi, $map] = $h; $plan = [];
    foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
        $m = trim((string)($r[$map['master']] ?? '')); $d = trim((string)($r[$map['dup']] ?? ''));
        if ($m === '' || $d === '') continue;
        $res['rows']++;
        $mid = vm_find($m); $did = null;
        foreach (all('SELECT id, name FROM vessels WHERE merged_into IS NULL') as $v) if ($v['name'] === $d) { $did = (int)$v['id']; break; }
        if (!$mid) { $res['errors'][] = [$i + 1, t('vm.map_no_master', ['n' => $m])]; continue; }
        if (!$did) { $res['skipped'][] = [$i + 1, t('vm.map_no_dup', ['n' => $d])]; continue; }
        if ($did === $mid) continue;   // the master row itself
        $plan[$mid][] = $did;
    }
    $res['groups'] = count($plan);
    if ($dry) { $res['plan'] = $plan; return $res; }
    foreach ($plan as $mid => $dups) { $r = vm_merge((int)$mid, $dups); $res['merged'] += count($dups); }
    return $res;
}

/* ───────────── R97-015: vessels of a customer, managed from the customer's own page ───────────── */

/** The vessels a customer has: registered under it (vessels.customer_id), and linked to it or to its organisations as a related party. */
function vm_customer_vessels(int $cid): array {
    if ($cid <= 0) return []; $out = [];
    foreach (all('SELECT id, name, imo, is_active FROM vessels WHERE customer_id = ? ORDER BY name', [$cid]) as $v) $out[(int)$v['id']] = $v + ['own' => true, 'links' => []];
    if (vm_has()) {
        $orgs = vm_orgs_has();
        $rows = all('SELECT vc.id AS link_id, vc.role, vc.note, v.id, v.name, v.imo, v.is_active' . ($orgs ? ', co.name AS org_name' : ", NULL AS org_name") . ' FROM vessel_companies vc JOIN vessels v ON v.id = vc.vessel_id' . ($orgs ? ' LEFT JOIN companies co ON co.id = vc.company_id' : '')
                  . ' WHERE vc.customer_id = ?' . ($orgs ? ' OR vc.company_id IN (SELECT id FROM companies WHERE customer_id = ?)' : '') . ' ORDER BY v.name', $orgs ? [$cid, $cid] : [$cid]);
        foreach ($rows as $r) { $k = (int)$r['id']; if (!isset($out[$k])) $out[$k] = ['id' => $k, 'name' => $r['name'], 'imo' => $r['imo'], 'is_active' => $r['is_active'], 'own' => false, 'links' => []]; $out[$k]['links'][] = ['id' => (int)$r['link_id'], 'role' => (string)$r['role'], 'note' => (string)$r['note'], 'org' => (string)$r['org_name']]; }
    }
    uasort($out, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name'])); return array_values($out);
}
/**
 * Give a customer a vessel: the vessel gets the customer's organisation as a related party with the chosen role; a vessel that hangs
 * under no customer yet is also registered under this one. An existing customer of the vessel is never replaced here.
 */
function vm_customer_vessel_link(int $cid, int $vid, string $role, ?string $note = null): void {
    if (!vm_has()) throw new RuntimeException(t('err.noaccess'));
    $v = one('SELECT id, customer_id FROM vessels WHERE id = ?', [$vid]); if (!$v || !scalar('SELECT id FROM customers WHERE id = ?', [$cid])) throw new RuntimeException(t('err.notfound'));
    if (!(int)$v['customer_id']) q('UPDATE vessels SET customer_id = ? WHERE id = ?', [$cid, $vid]);
    vm_related_set_customer($vid, $cid, $role, $note);
}
/** Remove one related-party link — only a link that belongs to this customer (or to one of its organisations). */
function vm_customer_vessel_unlink(int $cid, int $linkId): void {
    if (!vm_has()) return; $orgs = vm_orgs_has();
    $ok = scalar('SELECT vc.id FROM vessel_companies vc WHERE vc.id = ? AND (vc.customer_id = ?' . ($orgs ? ' OR vc.company_id IN (SELECT id FROM companies WHERE customer_id = ?)' : '') . ')', $orgs ? [$linkId, $cid, $cid] : [$linkId, $cid]);
    if (!$ok) throw new RuntimeException(t('err.notfound')); vm_related_remove($linkId);
}

/* ───────────── R97-016: a web link to information about the vessel kept online ───────────── */
function vm_url_col(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM vessels LIKE 'info_url'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** A web address as it may be stored: http(s) only (a bare www… gets https://), no spaces or control characters, at most 500 characters; '' when empty; null when it is not acceptable. */
function vm_url_clean(string $u): ?string {
    $u = trim($u); if ($u === '') return ''; if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $u)) $u = 'https://' . ltrim($u, '/');
    if (strlen($u) > 500 || preg_match('/[\x00-\x20<>"\'`\\\\]/', $u) || !preg_match('~^https?://[^/]+\.[^/]+~i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) return null;
    return $u;
}

/* ───────────── R97-017: preview of a vessel's web link ───────────── */
function vm_prev_col(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM vessels LIKE 'info_title'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Public address? Every address the host resolves to must be a routable public one — never this server, the local network or a metadata address. Returns the address to connect to, or null. */
function vm_public_ip(string $host): ?string {
    $host = trim($host, '[]'); if ($host === '' || strcasecmp($host, 'localhost') === 0) return null;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) @gethostbynamel($host); if (!$ips) return null;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    return (string)$ips[0];
}
/** Title and description out of a page's HTML (Open Graph first, then <title> / meta description), as short plain text. */
function vm_url_meta(string $buf, string $ct = ''): ?array {
    if (preg_match('/charset=["\']?([\w-]+)/i', $ct . ' ' . substr($buf, 0, 2000), $m) && strcasecmp($m[1], 'utf-8') !== 0) { try { $conv = @mb_convert_encoding($buf, 'UTF-8', $m[1]); if (is_string($conv) && $conv !== '') $buf = $conv; } catch (\Throwable $e) { /* unknown character set: keep the bytes */ } }
    if (!mb_check_encoding($buf, 'UTF-8')) $buf = mb_convert_encoding($buf, 'UTF-8', 'ISO-8859-1');
    $txt = function (?string $v, int $len): string { $v = html_entity_decode(strip_tags((string)$v), ENT_QUOTES | ENT_HTML5, 'UTF-8'); $v = trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $v) ?? ''); return mb_substr($v, 0, $len); };
    $meta = function (string $name) use ($buf): ?string { foreach (['~<meta[^>]+(?:property|name)\s*=\s*["\']' . preg_quote($name, '~') . '["\'][^>]*content\s*=\s*["\']([^"\']*)["\']~i', '~<meta[^>]+content\s*=\s*["\']([^"\']*)["\'][^>]*(?:property|name)\s*=\s*["\']' . preg_quote($name, '~') . '["\']~i'] as $rx) if (preg_match($rx, $buf, $m)) return $m[1]; return null; };
    $title = $txt($meta('og:title') ?? (preg_match('~<title[^>]*>(.*?)</title>~is', $buf, $m) ? $m[1] : ''), 200); $desc = $txt($meta('og:description') ?? $meta('description') ?? '', 600);
    return $title === '' && $desc === '' ? null : ['title' => $title, 'desc' => $desc];
}
/**
 * Read the title and the description of a public web page for the preview: http(s) on the standard ports only, the connection is pinned
 * to the address that was checked, at most 3 redirects (each checked again), 5 s, 300 KB, HTML only. Nothing from the page is executed or
 * stored except two short plain texts. Returns ['title' => …, 'desc' => …] or null.
 */
function vm_url_preview(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url); $sch = strtolower((string)($p['scheme'] ?? '')); $host = (string)($p['host'] ?? ''); $port = (int)($p['port'] ?? ($sch === 'https' ? 443 : 80));
        if (!in_array($sch, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true) || isset($p['user'])) return null;
        $ip = vm_public_ip($host); if ($ip === null) return null;
        $buf = ''; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip], CURLOPT_USERAGENT => 'GoldenAS-Portal link preview', CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en'], CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 300000 ? 0 : strlen($d); }]);
        curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Vessel link preview'); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code < 200 || $code >= 300 || $buf === '' || ($ct !== '' && stripos($ct, 'html') === false)) return null;
        return vm_url_meta($buf, $ct);
    }
    return null;
}
/** Read the preview of a vessel's link again and store it (empty when the page cannot be read — the link itself stays). */
function vm_url_preview_store(int $vid, bool $clear = false): bool {
    if (!vm_url_col() || !vm_prev_col()) return false; $u = (string) scalar('SELECT info_url FROM vessels WHERE id = ?', [$vid]);
    $pv = $u !== '' ? vm_url_preview($u) : null;
    if ($pv !== null) q('UPDATE vessels SET info_title = ?, info_desc = ?, info_checked_at = NOW() WHERE id = ?', [$pv['title'] !== '' ? $pv['title'] : null, $pv['desc'] !== '' ? $pv['desc'] : null, $vid]);
    elseif ($clear || $u === '') q('UPDATE vessels SET info_title = NULL, info_desc = NULL, info_checked_at = NOW() WHERE id = ?', [$vid]);   // a new link whose page cannot be read must not keep the old page's text
    else q('UPDATE vessels SET info_checked_at = NOW() WHERE id = ?', [$vid]);                                                              // a refresh that fails keeps what was read before
    return $pv !== null;
}
/** The preview block of a vessel's link for staff pages: site, title, description and the button — nothing is loaded from the other site by the browser. */
function vm_url_block(array $v, bool $refresh = false): string {
    $u = (string)($v['info_url'] ?? ''); if ($u === '' || !preg_match('~^https?://~i', $u)) return '';
    $host = (string)(parse_url($u, PHP_URL_HOST) ?: ''); $t = trim((string)($v['info_title'] ?? '')); $d = trim((string)($v['info_desc'] ?? ''));
    $o = '<div class="vlink"><div class="vlink-txt"><span class="vlink-host">🌐 ' . e(mb_strimwidth($host, 0, 60, '…')) . '</span>';
    $o .= '<a class="vlink-title" href="' . e($u) . '" target="_blank" rel="noopener noreferrer">' . e($t !== '' ? $t : t('v.url_open')) . '</a>';
    if ($d !== '') $o .= '<span class="vlink-desc">' . e(mb_strimwidth($d, 0, 320, '…')) . '</span>';
    elseif ($t === '') $o .= '<span class="vlink-desc hint">' . e(t('v.url_noprev')) . '</span>';
    $o .= '</div><div class="vlink-act"><a class="btn btn--gold btn--sm" href="' . e($u) . '" target="_blank" rel="noopener noreferrer">' . e(t('v.url_go')) . ' ↗</a>';
    if ($refresh && vm_prev_col()) $o .= '<form method="post" action="' . url('vessel', ['id' => (int)$v['id']]) . '" class="inline" data-lock>' . csrf_field() . '<input type="hidden" name="action" value="vessel_link_refresh"><input type="hidden" name="id" value="' . (int)$v['id'] . '"><button class="btn btn--ghost btn--sm" type="submit" title="' . e(t('v.url_refresh')) . '">↻</button></form>';
    return $o . '</div></div>';
}


/* ───────────── R97-025: class and flag of a vessel; import of particulars (IMO, class, flag, yard, web link) with review ───────────── */
function vm_cf_col(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM vessels LIKE 'class_society'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** A valid IMO number: 7 digits whose last digit is the check digit (digits 1–6 × 7…2, sum mod 10). */
function vm_imo_ok(string $imo): bool {
    if (!preg_match('/^\d{7}$/', $imo)) return false; $s = 0; for ($i = 0; $i < 6; $i++) $s += (int)$imo[$i] * (7 - $i); return $s % 10 === (int)$imo[6];
}
const VM_IMP_MAX = 3 * 1048576;   // the particulars workbook
const VM_IMP_ROWS = 2000;
const VM_IMP_FIELDS = ['imo' => 16, 'class_society' => 80, 'flag' => 80, 'yard' => 120, 'info_url' => 500];
function vm_imp_dir(): string { $d = cp_storage() . '/vmimp'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function vm_imp_path(string $token): ?string { if (!preg_match('/^[a-f0-9]{32}$/', $token) || empty($_SESSION['vm_imp'][$token])) return null; $p = vm_imp_dir() . '/' . $token . '.xlsx'; return is_file($p) ? $p : null; }
/** Keep the uploaded workbook for the review step (administrators; session-bound random name; files older than a day are removed). */
function vm_imp_stash(array $file): string {
    require_cp_admin();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException(t('vi.nofile'));
    if ((int)$file['size'] > VM_IMP_MAX || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException(t('vi.badfile'));
    $fh = fopen((string)$file['tmp_name'], 'rb'); $sig = $fh ? (string) fread($fh, 4) : ''; if ($fh) fclose($fh); if ($sig !== "PK\x03\x04") throw new RuntimeException(t('vi.badfile'));
    foreach ((array) @glob(vm_imp_dir() . '/*.xlsx') as $old) if (@filemtime($old) < time() - 86400) @unlink($old);
    $token = bin2hex(random_bytes(16)); if (!move_uploaded_file((string)$file['tmp_name'], vm_imp_dir() . '/' . $token . '.xlsx')) throw new RuntimeException(t('err.upload'));
    $_SESSION['vm_imp'] = [$token => mb_substr(basename((string)$file['name']), 0, 200)]; return $token;
}
function vm_imp_drop(string $token): void { $p = vm_imp_path($token); if ($p) @unlink($p); unset($_SESSION['vm_imp'][$token]); }
/** A cell of the workbook as a clean value: dashes and "not found" wording count as empty. */
function vm_imp_val($v, int $max): string {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v)); if ($v === '' || preg_match('/^(—|–|-|n\/?a|none|none found|not found|unknown|\?)$/iu', $v)) return ''; return mb_substr($v, 0, $max);
}
/**
 * Read the workbook and compare it with the vessel register. Columns are found by their header (any order): Vessel / Our name / Name ·
 * IMO · Class · Flag · Yard / Builder · Link / URL / Web link · Confidence · Note. A value is PROPOSED only where the vessel's field is
 * empty — nothing a person entered is ever replaced. Returns ['rows' => [...], 'n' => counts, 'cols' => found columns].
 */
function vm_imp_plan(string $path): array {
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($path); if (!$rows) throw new RuntimeException(t('vi.empty'));
    $hdr = array_map(fn($h) => mb_strtolower(trim((string)$h)), (array) array_shift($rows)); $col = [];
    foreach ($hdr as $i => $h) {
        if ($h === '') continue;
        if (!isset($col['name']) && preg_match('/^(our name|vessel|name|vessel name)\b/u', $h)) $col['name'] = $i;
        elseif (!isset($col['imo']) && preg_match('/^imo\b/u', $h)) $col['imo'] = $i;
        elseif (!isset($col['class_society']) && preg_match('/^class/u', $h)) $col['class_society'] = $i;
        elseif (!isset($col['flag']) && preg_match('/^flag/u', $h)) $col['flag'] = $i;
        elseif (!isset($col['yard']) && preg_match('/^(yard|builder|shipyard)/u', $h)) $col['yard'] = $i;
        elseif (!isset($col['info_url']) && preg_match('/^(link|url|web ?link|web)/u', $h)) $col['info_url'] = $i;
        elseif (!isset($col['conf']) && preg_match('/^confidence/u', $h)) $col['conf'] = $i;
        elseif (!isset($col['note']) && preg_match('/^(note|remark)/u', $h)) $col['note'] = $i;
    }
    if (!isset($col['name'])) throw new RuntimeException(t('vi.no_name_col'));
    if (count($rows) > VM_IMP_ROWS) throw new RuntimeException(t('vi.too_many', ['n' => VM_IMP_ROWS]));
    $cf = vm_cf_col(); $uc = vm_url_col(); $byName = []; $byNorm = [];
    foreach (all('SELECT id, name, imo, yard, ' . ($cf ? 'class_society, flag' : 'NULL AS class_society, NULL AS flag') . ', ' . ($uc ? 'info_url' : 'NULL AS info_url') . ' FROM vessels' . (vm_has() ? ' WHERE merged_into IS NULL' : '')) as $v) {
        $byName[mb_strtolower(trim((string)$v['name']))] = $v; $k = function_exists('vm_norm') ? vm_norm((string)$v['name']) : ''; if ($k !== '') $byNorm[$k][] = $v;
    }
    $out = []; $n = ['rows' => 0, 'matched' => 0, 'unmatched' => 0, 'fill' => 0, 'nothing' => 0, 'bad' => 0]; $seen = [];
    foreach ($rows as $ri => $r) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($r[$col['name']] ?? ''))); if ($name === '') continue; $n['rows']++;
        $v = $byName[mb_strtolower($name)] ?? null;
        if (!$v && function_exists('vm_norm')) { $c = $byNorm[vm_norm($name)] ?? []; if (count($c) === 1) $v = $c[0]; }
        $conf = strtoupper(vm_imp_val($r[$col['conf'] ?? -1] ?? '', 20)); $note = vm_imp_val($r[$col['note'] ?? -1] ?? '', 300);
        $row = ['i' => $ri, 'name' => $name, 'vessel' => $v, 'conf' => $conf, 'note' => $note, 'set' => [], 'warn' => []];
        if (!$v) { $n['unmatched']++; $out[] = $row; continue; }
        $n['matched']++; if (isset($seen[(int)$v['id']])) { $row['warn'][] = t('vi.w_twice'); $out[] = $row; continue; } $seen[(int)$v['id']] = 1;
        foreach (VM_IMP_FIELDS as $f => $max) {
            if (!isset($col[$f]) || (!$cf && ($f === 'class_society' || $f === 'flag')) || (!$uc && $f === 'info_url')) continue;
            $new = vm_imp_val($r[$col[$f]] ?? '', $max); if ($new === '') continue; $cur = trim((string)($v[$f] ?? ''));
            if ($f === 'imo') { $new = preg_replace('/\D/', '', $new); if (!vm_imo_ok($new)) { $row['warn'][] = t('vi.w_imo', ['v' => mb_substr((string)($r[$col[$f]] ?? ''), 0, 20)]); $n['bad']++; continue; } }
            if ($f === 'info_url') { $u = vm_url_clean($new); if ($u === null || $u === '') { $row['warn'][] = t('vi.w_url'); $n['bad']++; continue; } $new = $u; }
            if ($cur !== '') { if ($f === 'imo' && $cur !== $new) $row['warn'][] = t('vi.w_imo_diff', ['a' => $cur, 'b' => $new]); continue; }   // filled fields stay
            $row['set'][$f] = $new;
        }
        if ($row['set']) $n['fill']++; else $n['nothing']++;
        $out[] = $row;
    }
    return ['rows' => $out, 'n' => $n, 'cols' => array_keys($col)];
}
/** Apply the ticked rows of a reviewed workbook: empty fields only (checked again at write time). Returns [vessels changed, fields filled]. */
function vm_imp_apply(string $token, array $pick): array {
    $u = require_cp_admin(); $path = vm_imp_path($token); if (!$path) throw new RuntimeException(t('vi.expired'));
    $P = vm_imp_plan($path); $pick = array_flip(array_map('intval', $pick)); $nv = 0; $nf = 0; $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ($P['rows'] as $r) {
            if (!$r['vessel'] || !$r['set'] || !isset($pick[(int)$r['i']])) continue; $vid = (int)$r['vessel']['id']; $did = 0;
            foreach ($r['set'] as $f => $val) {
                if (!isset(VM_IMP_FIELDS[$f])) continue;
                if ($f === 'imo' && scalar('SELECT COUNT(*) FROM vessels WHERE imo = ? AND id <> ?', [$val, $vid])) continue;   // one hull, one IMO
                $did += q("UPDATE vessels SET `$f` = ? WHERE id = ? AND (`$f` IS NULL OR `$f` = '')", [$val, $vid])->rowCount();
            }
            if ($did) { $nv++; $nf += $did; }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    error_log('[vm_imp] ' . (int)$u['id'] . ' filled ' . $nf . ' fields on ' . $nv . ' vessels from ' . (string)($_SESSION['vm_imp'][$token] ?? ''));
    vm_imp_drop($token); return [$nv, $nf];
}
