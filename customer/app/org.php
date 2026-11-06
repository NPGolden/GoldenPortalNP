<?php
/** Organisations — one register behind customers, companies and suppliers (GAS-CP-R85-001, step 1 of option A).
 *  Links each billing customer to its company of the Contacts module (companies.customer_id), finds the duplicates by VAT,
 *  ERP code or name, back-fills the master data both ways and derives the customer of every person from his organisation. */
declare(strict_types=1);

function org_has(): bool { return function_exists('co_has') && co_has(); }
function org_can(): bool { return org_has() && is_staff() && (ct_manage() || is_cp_admin()); }
/** Comparable key of an organisation name: lower case, no legal-form noise, no punctuation. */
function org_key(string $n): string {
    $n = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('.', '', mb_strtolower($n))) ?? '');   // "S.A." → "sa", "Α.Τ.Ε.Ε." → "ατεε" before the noise list
    $noise = ['sa', 'ae', 'ltd', 'limited', 'llc', 'inc', 'gmbh', 'bv', 'nv', 'srl', 'spa', 'epe', 'επε', 'αε', 'οε', 'ικε', 'atee', 'ατεε', 'co', 'company', 'group', 'the', 'ab', 'as', 'oy', 'plc', 'corp', 'corporation', 'holdings', 'holding', 'shipping', 'yachting', 'management'];
    $w = array_filter(explode(' ', $n), fn($t) => $t !== '' && !in_array($t, $noise, true));
    return implode(' ', $w) ?: $n;
}
function org_vat(?string $v): string { $d = preg_replace('/\D/', '', (string)$v) ?: ''; return (strlen($d) < 6 || preg_match('/^(\d)\1+$/', $d)) ? '' : $d; }   // placeholders like 000000000 / 999999999 never match

/** Every customer with its linked / suggested company. status: linked | match | none. */
function org_matches(): array {
    $cos = all('SELECT co.id, co.name, co.kind, co.is_supplier, co.customer_id, co.country, co.city, co.email, co.phone, co.website, ' . (co_roles_has() ? 'COALESCE(NULLIF(co.vat_no, \'\'), sd.vat_no) AS vat_no, COALESCE(co.erp_code, sd.erp_code) AS erp_code' : 'sd.vat_no, sd.erp_code') . ' FROM companies co LEFT JOIN supplier_details sd ON sd.company_id = co.id WHERE co.is_active = 1');
    $byCust = []; $byVat = []; $byErp = []; $byKey = [];
    foreach ($cos as $c) { if ($c['customer_id']) $byCust[(int)$c['customer_id']][] = $c; if ($v = org_vat($c['vat_no'])) $byVat[$v][] = $c; if ($c['erp_code']) $byErp[mb_strtolower((string)$c['erp_code'])][] = $c; $byKey[org_key($c['name'])][] = $c; }
    $out = ['linked' => [], 'match' => [], 'none' => []];
    foreach (all('SELECT * FROM customers WHERE is_active = 1 ORDER BY name') as $cu) {
        $cid = (int)$cu['id'];
        if (!empty($byCust[$cid])) { $out['linked'][] = ['customer' => $cu, 'company' => $byCust[$cid][0], 'how' => 'linked']; continue; }
        $hit = null; $how = '';
        if (($v = org_vat($cu['vat_no'])) && !empty($byVat[$v])) { $hit = $byVat[$v][0]; $how = 'vat'; }   // R85-003: VAT only — ERP code and name never propose a match (decision of 06/11/2026)
        if ($hit && $hit['customer_id'] && (int)$hit['customer_id'] !== $cid) $hit = null;   // already someone else's
        if ($hit) $out['match'][] = ['customer' => $cu, 'company' => $hit, 'how' => $how]; else $out['none'][] = ['customer' => $cu, 'company' => null, 'how' => ''];
    }
    return $out;
}
/** Link a company to a customer, back-fill the master data both ways, derive the customer of the company's people. */
function org_link(int $coId, int $cuId): void {
    if (!org_can()) throw new RuntimeException(t('err.noaccess'));
    $co = one('SELECT co.*, sd.vat_no, sd.erp_code, sd.address, sd.postal_code FROM companies co LEFT JOIN supplier_details sd ON sd.company_id = co.id WHERE co.id = ?', [$coId]); $cu = one('SELECT * FROM customers WHERE id = ?', [$cuId]);
    if (!$co || !$cu) throw new RuntimeException(t('err.notfound'));
    $other = scalar('SELECT id FROM companies WHERE customer_id = ? AND id <> ?', [$cuId, $coId]); if ($other) throw new RuntimeException(t('org.already_linked'));
    q('UPDATE companies SET customer_id = ?, country = COALESCE(NULLIF(country, \'\'), ?), city = COALESCE(NULLIF(city, \'\'), ?), website = COALESCE(NULLIF(website, \'\'), ?), email = COALESCE(NULLIF(email, \'\'), ?), phone = COALESCE(NULLIF(phone, \'\'), ?) WHERE id = ?',
      [$cuId, $cu['country'] ?: null, $cu['city'] ?: null, $cu['website'] ?: null, $cu['email'] ?: null, $cu['phone'] ?: null, $coId]);
    q('UPDATE customers SET country = COALESCE(NULLIF(country, \'\'), ?), city = COALESCE(NULLIF(city, \'\'), ?), website = COALESCE(NULLIF(website, \'\'), ?), email = COALESCE(NULLIF(email, \'\'), ?), phone = COALESCE(NULLIF(phone, \'\'), ?), vat_no = COALESCE(NULLIF(vat_no, \'\'), ?), erp_code = COALESCE(NULLIF(erp_code, \'\'), ?), address = COALESCE(NULLIF(address, \'\'), ?), postal_code = COALESCE(NULLIF(postal_code, \'\'), ?) WHERE id = ?',
      [$co['country'] ?: null, $co['city'] ?: null, $co['website'] ?: null, $co['email'] ?: null, $co['phone'] ?: null, $co['vat_no'] ?: null, $co['erp_code'] ?: null, $co['address'] ?: null, $co['postal_code'] ?: null, $cuId]);
    if (($cu['vat_no'] || $cu['erp_code'] || $cu['address']) && ($co['is_supplier'] || scalar('SELECT 1 FROM supplier_details WHERE company_id = ?', [$coId])))
        q('UPDATE supplier_details SET vat_no = COALESCE(NULLIF(vat_no, \'\'), ?), erp_code = COALESCE(erp_code, ?), address = COALESCE(NULLIF(address, \'\'), ?), postal_code = COALESCE(NULLIF(postal_code, \'\'), ?) WHERE company_id = ?', [$cu['vat_no'] ?: null, $cu['erp_code'] ?: null, $cu['address'] ?: null, $cu['postal_code'] ?: null, $coId]);
    q('UPDATE contacts SET customer_id = ? WHERE company_id = ? AND customer_id IS NULL', [$cuId, $coId]);
    q('UPDATE contacts SET company_id = ?, company = ? WHERE customer_id = ? AND company_id IS NULL', [$coId, $co['name'], $cuId]);
    if (co_roles_has()) q('UPDATE companies SET roles = TRIM(BOTH \',\' FROM CONCAT_WS(\',\', NULLIF(roles, \'\'), IF(FIND_IN_SET(\'customer\', roles), NULL, \'customer\'))), vat_no = COALESCE(NULLIF(vat_no, \'\'), ?), erp_code = COALESCE(erp_code, ?), address = COALESCE(address, ?), postal_code = COALESCE(postal_code, ?) WHERE id = ?', [$cu['vat_no'] ?: null, $cu['erp_code'] ?: null, $cu['address'] ?: null, $cu['postal_code'] ?: null, $coId]);   // R85-002
    if (function_exists('cc_has') && cc_has()) q('INSERT IGNORE INTO contact_companies (contact_id, company_id) SELECT id, company_id FROM contacts WHERE company_id = ?', [$coId]);
}
/** Create the company of a customer that has none (kind owner by default) and link it. */
function org_create_for_customer(int $cuId, string $kind = 'owner'): int {
    if (!org_can()) throw new RuntimeException(t('err.noaccess'));
    $cu = one('SELECT * FROM customers WHERE id = ?', [$cuId]); if (!$cu) throw new RuntimeException(t('err.notfound'));
    if ($id = scalar('SELECT id FROM companies WHERE customer_id = ?', [$cuId])) return (int)$id;
    $kind = isset(co_kinds()[$kind]) ? $kind : 'owner';
    if (($v = org_vat($cu['vat_no'])) && ($id = scalar('SELECT co.id FROM companies co LEFT JOIN supplier_details sd ON sd.company_id = co.id WHERE REGEXP_REPLACE(COALESCE(NULLIF(co.vat_no, \'\'), sd.vat_no, \'\'), \'[^0-9]\', \'\') = ? LIMIT 1', [$v]))) { org_link((int)$id, $cuId); return (int)$id; }   // R85-003: same VAT only
    q('INSERT INTO companies (name, kind, customer_id, country, city, website, email, phone, is_active' . (co_roles_has() ? ', roles, vat_no, erp_code, address, postal_code' : '') . ') VALUES (?,?,?,?,?,?,?,?,1' . (co_roles_has() ? ',?,?,?,?,?' : '') . ')', array_merge([mb_substr((string)$cu['name'], 0, 160), $kind, $cuId, $cu['country'] ?: null, $cu['city'] ?: null, $cu['website'] ?: null, $cu['email'] ?: null, $cu['phone'] ?: null], co_roles_has() ? [$kind . ',customer', $cu['vat_no'] ?: null, $cu['erp_code'] ?: null, $cu['address'] ?: null, $cu['postal_code'] ?: null] : []));
    $id = (int) db()->lastInsertId(); q('UPDATE contacts SET company_id = ?, company = ? WHERE customer_id = ? AND company_id IS NULL', [$id, $cu['name'], $cuId]); return $id;
}
/** Link every suggested match at once; returns the number linked. */
function org_link_all(): int { $n = 0; foreach (org_matches()['match'] as $m) { try { org_link((int)$m['company']['id'], (int)$m['customer']['id']); $n++; } catch (Throwable $e) {} } return $n; }
/** People without an organisation but with a customer, or without a customer but with a linked organisation — fixed in one go. */
function org_derive_people(): int {
    $n = (int) q('UPDATE contacts ct JOIN companies co ON co.id = ct.company_id SET ct.customer_id = co.customer_id WHERE ct.customer_id IS NULL AND co.customer_id IS NOT NULL')->rowCount();
    $n += (int) q('UPDATE contacts ct JOIN companies co ON co.customer_id = ct.customer_id SET ct.company_id = co.id, ct.company = co.name WHERE ct.company_id IS NULL AND ct.customer_id IS NOT NULL')->rowCount();
    return $n;
}
/** Counts for the Contacts header. */
function org_counts(): array {
    $m = org_matches(); return ['linked' => count($m['linked']), 'match' => count($m['match']), 'none' => count($m['none']),
        'people_fix' => (int) scalar('SELECT COUNT(*) FROM contacts ct JOIN companies co ON co.id = ct.company_id WHERE ct.customer_id IS NULL AND co.customer_id IS NOT NULL') + (int) scalar('SELECT COUNT(*) FROM contacts ct JOIN companies co ON co.customer_id = ct.customer_id WHERE ct.company_id IS NULL AND ct.customer_id IS NOT NULL')];
}

/** Companies that are duplicates of each other (same VAT, ERP code or normalised name): [[keep, drop], …]. The kept one is the linked / supplier / older record. */
function org_dupes(): array {
    $cos = all('SELECT co.id, co.name, co.kind, co.is_supplier, co.customer_id, ' . (co_roles_has() ? 'co.roles, COALESCE(NULLIF(co.vat_no, \'\'), sd.vat_no) AS vat_no, COALESCE(co.erp_code, sd.erp_code) AS erp_code' : 'sd.vat_no, sd.erp_code') . ', (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) AS n_people FROM companies co LEFT JOIN supplier_details sd ON sd.company_id = co.id WHERE co.is_active = 1 ORDER BY co.id');
    $groups = []; $seen = [];
    $add = function (string $p, string $k, array $c) use (&$groups) { if ($k === '' || ($p === 'v' && strlen($k) < 6) || ($p === 'n' && mb_strlen($k) < 4)) return; $groups[$p . ':' . $k][$c['id']] = $c; };
    foreach ($cos as $c) $add('v', org_vat($c['vat_no']), $c);   // R85-003: duplicates by VAT only
    $out = [];
    foreach ($groups as $k => $g) {
        if (count($g) < 2) continue; $g = array_values($g);
        usort($g, fn($a, $b) => [(int)!$a['customer_id'], (int)preg_match('/^old[_ -]/i', $a['name']), (int)!$a['is_supplier'], -(int)$a['n_people'], (int)$a['id']] <=> [(int)!$b['customer_id'], (int)preg_match('/^old[_ -]/i', $b['name']), (int)!$b['is_supplier'], -(int)$b['n_people'], (int)$b['id']]);   // keep: linked, not "OLD_", supplier, most people, oldest
        $keep = $g[0]; foreach (array_slice($g, 1) as $d) { if ($d['customer_id'] && $keep['customer_id'] && (int)$d['customer_id'] !== (int)$keep['customer_id']) continue; $pk = min($keep['id'], $d['id']) . '-' . max($keep['id'], $d['id']); if (isset($seen[$pk])) continue; $seen[$pk] = 1; $out[] = ['keep' => $keep, 'drop' => $d, 'how' => $k[0] === 'v' ? 'vat' : ($k[0] === 'e' ? 'erp' : 'name')]; }
    }
    return $out;
}
/** Merge company $drop into $keep: people, orders, invoices, prices, makers, production subcontracts and the supplier card move; the duplicate is deactivated and renamed. */
function org_merge(int $keep, int $drop): void {
    if (!org_can()) throw new RuntimeException(t('err.noaccess')); if ($keep === $drop) throw new RuntimeException(t('err.notfound'));
    $K = one('SELECT * FROM companies WHERE id = ?', [$keep]); $D = one('SELECT * FROM companies WHERE id = ?', [$drop]); if (!$K || !$D) throw new RuntimeException(t('err.notfound'));
    if ($D['customer_id'] && $K['customer_id'] && (int)$D['customer_id'] !== (int)$K['customer_id']) throw new RuntimeException(t('org.already_linked'));
    q('UPDATE contacts SET company_id = ?, company = ? WHERE company_id = ?', [$keep, $K['name'], $drop]);
    if (function_exists('cc_has') && cc_has()) { q('UPDATE IGNORE contact_companies SET company_id = ? WHERE company_id = ?', [$keep, $drop]); q('DELETE FROM contact_companies WHERE company_id = ?', [$drop]); }
    if (co_roles_has()) { $r = array_values(array_unique(array_merge(co_roles($K), co_roles($D)))); q('UPDATE companies SET roles = ?, vat_no = COALESCE(NULLIF(vat_no, \'\'), ?), erp_code = COALESCE(erp_code, ?), address = COALESCE(address, ?), postal_code = COALESCE(postal_code, ?) WHERE id = ?', [implode(',', $r), $D['vat_no'] ?? null, $D['erp_code'] ?? null, $D['address'] ?? null, $D['postal_code'] ?? null, $keep]); }
    foreach (['po_orders.supplier_id', 'si_invoices.supplier_id', 'si_requests.supplier_id', 'inv_prices.supplier_id', 'production_subs.company_id'] as $tc) { [$t, $c] = explode('.', $tc); try { q("UPDATE {$t} SET {$c} = ? WHERE {$c} = ?", [$keep, $drop]); } catch (Throwable $e) {} }
    try { q('UPDATE IGNORE supplier_makers SET supplier_id = ? WHERE supplier_id = ?', [$keep, $drop]); q('DELETE FROM supplier_makers WHERE supplier_id = ?', [$drop]); } catch (Throwable $e) {}
    if (scalar('SELECT 1 FROM supplier_details WHERE company_id = ?', [$drop])) { if (!scalar('SELECT 1 FROM supplier_details WHERE company_id = ?', [$keep])) q('UPDATE supplier_details SET company_id = ? WHERE company_id = ?', [$keep, $drop]); else { q('UPDATE supplier_details k JOIN supplier_details d ON d.company_id = ? SET k.vat_no = COALESCE(NULLIF(k.vat_no, \'\'), d.vat_no), k.erp_code = COALESCE(k.erp_code, d.erp_code), k.address = COALESCE(NULLIF(k.address, \'\'), d.address), k.postal_code = COALESCE(NULLIF(k.postal_code, \'\'), d.postal_code), k.terms_days = COALESCE(k.terms_days, d.terms_days), k.incoterms = COALESCE(k.incoterms, d.incoterms), k.lead_days = COALESCE(k.lead_days, d.lead_days) WHERE k.company_id = ?', [$drop, $keep]); q('DELETE FROM supplier_details WHERE company_id = ?', [$drop]); } }
    q('UPDATE companies SET customer_id = COALESCE(customer_id, ?), is_supplier = GREATEST(is_supplier, ?), country = COALESCE(NULLIF(country, \'\'), ?), city = COALESCE(NULLIF(city, \'\'), ?), website = COALESCE(NULLIF(website, \'\'), ?), email = COALESCE(NULLIF(email, \'\'), ?), phone = COALESCE(NULLIF(phone, \'\'), ?), notes = CONCAT_WS(\'\\n\', NULLIF(notes, \'\'), NULLIF(?, \'\')) WHERE id = ?',
      [$D['customer_id'] ?: null, (int)($D['is_supplier'] ?? 0), $D['country'] ?: null, $D['city'] ?: null, $D['website'] ?: null, $D['email'] ?: null, $D['phone'] ?: null, $D['notes'] ?: null, $keep]);
    q('UPDATE companies SET is_active = 0, customer_id = NULL, is_supplier = 0, name = CONCAT(name, \' [merged #\', id, \']\') WHERE id = ?', [$drop]);
    if ($K['customer_id'] || $D['customer_id']) q('UPDATE contacts SET customer_id = ? WHERE company_id = ? AND customer_id IS NULL', [(int)($K['customer_id'] ?: $D['customer_id']), $keep]);
    cache_bump_later('inv');
}

/* ---- R85-004: step 3 — the organisation behind every imported customer, and the organisations of a vessel */
/** Make sure a customer has its organisation: linked → master data and Customer role refreshed; same VAT → linked; else created (kind owner). Returns the company id. */
function org_ensure_for_customer(int $cuId): int {
    $cu = one('SELECT * FROM customers WHERE id = ?', [$cuId]); if (!$cu) return 0;
    $id = (int) (scalar('SELECT id FROM companies WHERE customer_id = ?', [$cuId]) ?: 0);
    if (!$id) return org_create_for_customer($cuId, 'owner');
    q('UPDATE companies SET name = ?, country = COALESCE(NULLIF(?, \'\'), country), city = COALESCE(NULLIF(?, \'\'), city), website = COALESCE(NULLIF(?, \'\'), website), email = COALESCE(NULLIF(?, \'\'), email), phone = COALESCE(NULLIF(?, \'\'), phone)' . (co_roles_has() ? ', vat_no = COALESCE(NULLIF(?, \'\'), vat_no), erp_code = COALESCE(?, erp_code), address = COALESCE(?, address), postal_code = COALESCE(?, postal_code), roles = TRIM(BOTH \',\' FROM CONCAT_WS(\',\', NULLIF(roles, \'\'), IF(FIND_IN_SET(\'customer\', roles), NULL, \'customer\')))' : '') . ' WHERE id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM companies WHERE LOWER(name) = LOWER(?) AND id <> ?) x)',
      array_merge([$cu['name'], $cu['country'], $cu['city'], $cu['website'], $cu['email'], $cu['phone']], co_roles_has() ? [$cu['vat_no'], $cu['erp_code'], $cu['address'], $cu['postal_code']] : [], [$id, $cu['name'], $id]));
    return $id;
}
