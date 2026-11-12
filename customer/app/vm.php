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

