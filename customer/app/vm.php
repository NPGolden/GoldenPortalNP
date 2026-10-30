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
const VM_ROLES    = ['owner', 'manager', 'yard', 'agent', 'contractor', 'charterer', 'other'];

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
function vm_related(int $vid): array {
    if (!vm_has()) return [];
    return all('SELECT vc.id, vc.customer_id, vc.role, vc.note, c.name FROM vessel_companies vc JOIN customers c ON c.id = vc.customer_id WHERE vc.vessel_id = ? ORDER BY FIELD(vc.role, ' . implode(',', array_map(fn($r) => "'$r'", VM_ROLES)) . '), c.name', [$vid]);
}
function vm_related_set(int $vid, int $customerId, string $role, ?string $note = null): void {
    if (!vm_has() || !$customerId) return;
    $role = in_array($role, VM_ROLES, true) ? $role : 'other';
    q('INSERT INTO vessel_companies (vessel_id, customer_id, role, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role), note = COALESCE(VALUES(note), note)', [$vid, $customerId, $role, $note !== null ? mb_substr($note, 0, 255) : null]);
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
