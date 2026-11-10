<?php
/**
 * Billing party — GAS-CP-R95-003 (phase 1 of the parties / invoicing / collections plan, decisions of 10/11/2026).
 *
 *   The PARTY is the organisation (companies row) that owes the money for a job: by default the organisation of the project's
 *   customer, overridable on the project and again on the sub-project. The INVOICING IDENTITY (invoicing_details row) is the
 *   legal entity / address printed on proformas and invoices; it belongs to the party (owner_type company) or to the vessel (SPV).
 *   Every ledger row (proformas, kind proforma / invoice) stores party + identity + vessel + customer at creation.
 *
 *   pty_has()                       columns present (migrate_cp_r95_003)
 *   pty_org_of_customer($cid)       the customer's organisation (created when missing — every customer is an organisation)
 *   pty_project($pid)               ['org_id', 'invoicing_id'] of the project (stored or resolved)
 *   pty_sub($sp)                    party of a sub-project row (its own, else the project's)
 *   pty_candidates($pid, $vid, …)   organisations offered as party: customer's org, vessel-linked organisations by role, current
 *   pty_identities($orgId, $vid)    identities offered: the party's first, then the vessel's, then other vessel-linked organisations'
 *   pty_label($orgId)               "Name · role"
 */
declare(strict_types=1);

function pty_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('iv_has') && iv_has() && (bool) scalar("SHOW COLUMNS FROM projects LIKE 'billing_org_id'"); } catch (Throwable $e) { $h = false; } } return $h; }

function pty_org_of_customer(?int $cid): ?int {
    if (!$cid) return null;
    $id = (int) (scalar('SELECT id FROM companies WHERE customer_id = ? AND is_active = 1 ORDER BY id LIMIT 1', [$cid]) ?: 0);
    if (!$id && function_exists('org_ensure_for_customer')) { try { $id = org_ensure_for_customer($cid); } catch (Throwable $e) { $id = 0; } }
    return $id ?: null;
}
function pty_org(int $id): ?array { return $id ? one('SELECT id, name, kind, roles, is_supplier, customer_id, country, city FROM companies WHERE id = ?', [$id]) : null; }

/** Party and identity of a project: stored values, else the customer's organisation (stored back when the columns exist). */
function pty_project(int $pid): array {
    static $c = []; if (isset($c[$pid])) return $c[$pid];
    $p = one('SELECT id, customer_id, vessel_id' . (pty_has() ? ', billing_org_id, invoicing_id' : '') . ' FROM projects WHERE id = ?', [$pid]);
    if (!$p) return $c[$pid] = ['org_id' => null, 'invoicing_id' => null, 'customer_id' => null, 'vessel_id' => null];
    $org = (int)($p['billing_org_id'] ?? 0) ?: pty_org_of_customer($p['customer_id'] ? (int)$p['customer_id'] : null);
    if ($org && pty_has() && empty($p['billing_org_id'])) { try { q('UPDATE projects SET billing_org_id = ? WHERE id = ?', [$org, $pid]); } catch (Throwable $e) {} }
    return $c[$pid] = ['org_id' => $org ?: null, 'invoicing_id' => (int)($p['invoicing_id'] ?? 0) ?: null, 'customer_id' => (int)$p['customer_id'] ?: null, 'vessel_id' => (int)$p['vessel_id'] ?: null];
}
/** Party of a sub-project row (needs project_id; billing_org_id when loaded). */
function pty_sub(array $sp): ?int {
    if (!empty($sp['billing_org_id'])) return (int)$sp['billing_org_id'];
    return !empty($sp['project_id']) ? pty_project((int)$sp['project_id'])['org_id'] : null;
}
/** Organisations a project / sub-project may be billed to. [id => ['id','name','role','src']] — customer's organisation first. */
function pty_candidates(?int $pid, ?int $vid = null, ?int $cid = null, ?int $current = null): array {
    $out = [];
    if ($pid) { $P = pty_project($pid); $vid = $vid ?: $P['vessel_id']; $cid = $cid ?: $P['customer_id']; }
    $add = function (?int $id, string $role, string $src) use (&$out) { if (!$id || isset($out[$id])) return; $o = pty_org($id); if (!$o) return; $out[$id] = ['id' => $id, 'name' => (string)$o['name'], 'role' => $role, 'src' => $src, 'kind' => (string)$o['kind']]; };
    if ($cid) $add(pty_org_of_customer($cid), 'customer', 'customer');
    if ($vid && function_exists('vm_related')) foreach (vm_related($vid) as $r) { if (!empty($r['company_id'])) $add((int)$r['company_id'], (string)$r['role'], 'vessel'); elseif (!empty($r['customer_id'])) $add(pty_org_of_customer((int)$r['customer_id']), (string)$r['role'], 'vessel'); }
    if ($current) $add($current, 'current', 'current');
    return $out;
}
function pty_role_label(string $role): string { return $role === 'customer' ? t('rp.customer') : ($role === 'current' ? t('pty.chosen') : (function_exists('vm_role_label') ? vm_role_label($role) : $role)); }
function pty_label(?int $orgId, ?string $role = null): string { $o = $orgId ? pty_org($orgId) : null; if (!$o) return '—'; return (string)$o['name'] . ($role ? ' · ' . pty_role_label($role) : ''); }

/** Identities offered for a document: the party's (★, default first) and the vessel's (⚓ — an SPV of the yacht). Identities of the other
 *  organisations linked to the vessel are NOT offered: to invoice the yard or the manager, choose them as the billing party (Nikos, 10/11/2026). */
function pty_identities(?int $orgId, ?int $vid, ?int $pid = null): array {
    $out = []; $put = function (array $rows, string $src, ?int $oid, string $oname) use (&$out) { foreach ($rows as $r) if (!isset($out[(int)$r['id']])) $out[(int)$r['id']] = array_replace($r, ['src' => $src, 'org_id' => $oid, 'org_name' => $oname]); };
    if ($orgId) { $o = pty_org($orgId); $put(iv_list('company', $orgId, true), 'party', $orgId, (string)($o['name'] ?? '')); }
    if ($vid) $put(iv_list('vessel', $vid, true), 'vessel', null, (string) scalar('SELECT name FROM vessels WHERE id = ?', [$vid]));
    return $out;
}
function pty_identity_label(array $r): string { $p = ['party' => '★ ', 'vessel' => '⚓ ', 'other' => '🏢 '][$r['src'] ?? ''] ?? ''; return $p . iv_label($r) . ($r['src'] === 'other' && !empty($r['org_name']) ? ' — ' . $r['org_name'] : ''); }

/** Save party + identity of a project from its form (fields billing_org_id, invoicing_id). */
function pty_project_save(int $pid): void {
    if (!pty_has() || !array_key_exists('billing_org_id', $_POST)) return;
    $org = (int)($_POST['billing_org_id'] ?? 0) ?: null; $iv = (int)($_POST['invoicing_id'] ?? 0) ?: null;
    $p = one('SELECT customer_id, vessel_id FROM projects WHERE id = ?', [$pid]); if (!$p) return;
    if ($org && !isset(pty_candidates(null, (int)$p['vessel_id'] ?: null, (int)$p['customer_id'] ?: null, $org)[$org])) $org = null;
    if ($iv && !isset(pty_identities($org ?: pty_org_of_customer((int)$p['customer_id'] ?: null), (int)$p['vessel_id'] ?: null)[$iv])) $iv = null;
    q('UPDATE projects SET billing_org_id = ?, invoicing_id = ? WHERE id = ?', [$org ?: pty_org_of_customer((int)$p['customer_id'] ?: null), $iv, $pid]);
}
/** Save the party override of a sub-project (field billing_org_id; "" = the project's). */
function pty_sub_save(int $sid): void {
    if (!pty_has() || !array_key_exists('billing_org_id', $_POST)) return;
    $sp = one('SELECT id, project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) return;
    $org = (int)($_POST['billing_org_id'] ?? 0) ?: null; $P = pty_project((int)$sp['project_id']);
    if ($org && (int)$org === (int)$P['org_id']) $org = null;   // same as the project → no override
    if ($org && !isset(pty_candidates((int)$sp['project_id'], null, null, $org)[$org])) $org = null;
    q('UPDATE subprojects SET billing_org_id = ? WHERE id = ?', [$org, $sid]);
}
/** The party a ledger row belongs to, given its sub-project / project and chosen identity. */
function pty_for_doc(?int $pid, ?int $sid, ?int $ivId): ?int {
    if ($ivId && ($iv = iv_load($ivId)) && $iv['owner_type'] === 'company') return (int)$iv['owner_id'];
    if ($sid) { $sp = one('SELECT id, project_id' . (pty_has() ? ', billing_org_id' : '') . ' FROM subprojects WHERE id = ?', [$sid]); if ($sp) return pty_sub($sp); }
    return $pid ? pty_project($pid)['org_id'] : null;
}
