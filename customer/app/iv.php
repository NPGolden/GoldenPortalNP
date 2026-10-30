<?php
/**
 * Customers master data and invoicing details.                 GAS-CP-R19-001
 *
 * Customers (the owners whose users sign in) were only ever created by the
 * ERP / Excel imports; Settings › Customers now lets administrators add and
 * edit them. Invoicing details are kept in their own table so that one
 * customer, company or contact can carry several billing identities
 * (head office, management company, owning SPV …) with one marked default.
 *
 *   iv_has()                        tables present (after migrate_cp_r19_customers_invoicing.sql)
 *   iv_list($type, $id)             rows for one owner (default first)
 *   iv_default($type, $id)          the default row or null
 *   iv_save_from_post() / iv_delete($id) / iv_set_default($id)
 *   iv_section($type, $id, $back)   renders the card (list + form) — views/_invoicing.php
 *   cu_load($id) / cu_save_from_post($id) / cu_delete($id)   customers
 */
declare(strict_types=1);

function iv_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) scalar("SHOW TABLES LIKE 'invoicing_details'"); } catch (Throwable $e) { $has = false; } }
    return $has;
}
const IV_TYPES = ['customer', 'company', 'contact', 'vessel'];   // vessel since R43-002
const IV_FIELDS = ['label' => 80, 'legal_name' => 200, 'vat_no' => 40, 'tax_office' => 120, 'reg_no' => 60, 'address' => 200, 'city' => 80, 'postal_code' => 20, 'country' => 80,
                   'email' => 190, 'phone' => 60, 'attn' => 120, 'bank' => 120, 'iban' => 48, 'swift' => 16, 'payment_terms' => 120, 'notes' => 4000];

function iv_type_ok(string $t): bool { return in_array($t, IV_TYPES, true); }
function iv_owner_exists(string $type, int $id): bool {
    $tbl = ['customer' => 'customers', 'company' => 'companies', 'contact' => 'contacts', 'vessel' => 'vessels'][$type] ?? null;
    return $tbl && $id > 0 && (bool) scalar("SELECT COUNT(*) FROM {$tbl} WHERE id = ?", [$id]);
}
function iv_list(string $type, int $id, bool $activeOnly = false): array {
    if (!iv_has() || !iv_type_ok($type)) return [];
    return all('SELECT * FROM invoicing_details WHERE owner_type = ? AND owner_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY is_default DESC, is_active DESC, label', [$type, $id]);
}
function iv_default(string $type, int $id): ?array {
    $rows = iv_list($type, $id, true);
    return $rows ? $rows[0] : null;
}
function iv_count(string $type, int $id): int {
    return iv_has() ? (int) scalar('SELECT COUNT(*) FROM invoicing_details WHERE owner_type = ? AND owner_id = ? AND is_active = 1', [$type, $id]) : 0;
}
function iv_load(int $id): ?array { return iv_has() && $id ? one('SELECT * FROM invoicing_details WHERE id = ?', [$id]) : null; }

function iv_save_from_post(): array {
    require_cp_admin();
    $u = current_user();
    $id = (int)($_POST['id'] ?? 0);
    $type = (string)($_POST['owner_type'] ?? ''); $oid = (int)($_POST['owner_id'] ?? 0);
    if ($id) { $ex = iv_load($id); if (!$ex) throw new RuntimeException(t('err.notfound')); $type = $ex['owner_type']; $oid = (int)$ex['owner_id']; }
    if (!iv_type_ok($type) || !iv_owner_exists($type, $oid)) throw new RuntimeException(t('err.notfound'));
    $v = [];
    foreach (IV_FIELDS as $k => $max) { $s = trim((string)($_POST[$k] ?? '')); $v[$k] = $s === '' ? null : mb_substr($s, 0, $max); }
    if (!$v['legal_name']) throw new RuntimeException(t('iv.need_legal'));
    if (!$v['label']) $v['label'] = $v['legal_name'];
    if ($v['iban']) $v['iban'] = strtoupper(preg_replace('/\s+/', '', $v['iban']));
    if ($v['swift']) $v['swift'] = strtoupper($v['swift']);
    if ($v['vat_no']) $v['vat_no'] = strtoupper(preg_replace('/\s+/', '', $v['vat_no']));
    if ($v['email'] && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('iv.bad_email'));
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR';
    $active = $id ? (int)!empty($_POST['is_active']) : 1;
    $cols = array_keys($v); $vals = array_values($v); $vals[] = $cur; $vals[] = $active;
    if ($id) { $vals[] = $id; q('UPDATE invoicing_details SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ', currency = ?, is_active = ? WHERE id = ?', $vals); }
    else {
        $vals[] = $type; $vals[] = $oid; $vals[] = (int)($u['id'] ?? 0) ?: null;
        q('INSERT INTO invoicing_details (' . implode(', ', $cols) . ', currency, is_active, owner_type, owner_id, created_by) VALUES (' . implode(',', array_fill(0, count($cols) + 5, '?')) . ')', $vals);
        $id = (int) db()->lastInsertId();
    }
    if (!empty($_POST['is_default']) || !scalar('SELECT COUNT(*) FROM invoicing_details WHERE owner_type = ? AND owner_id = ? AND is_default = 1 AND is_active = 1', [$type, $oid])) iv_set_default($id);
    if (function_exists('iv_vessel_has') && iv_vessel_has() && array_key_exists('vat_pct', $_POST)) { $vp = trim((string)$_POST['vat_pct']); $newId = $id ?: (int) db()->lastInsertId(); q('UPDATE invoicing_details SET vat_pct = ? WHERE id = ?', [$vp === '' ? null : max(0, min(100, (float) str_replace(',', '.', $vp))), $newId]); }   // R43-002
    return ['id' => $id, 'owner_type' => $type, 'owner_id' => $oid];
}
function iv_set_default(int $id): void {
    require_cp_admin();
    $r = iv_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    q('UPDATE invoicing_details SET is_default = 0 WHERE owner_type = ? AND owner_id = ?', [$r['owner_type'], $r['owner_id']]);
    q('UPDATE invoicing_details SET is_default = 1, is_active = 1 WHERE id = ?', [$id]);
}
function iv_delete(int $id): array {
    require_cp_admin();
    $r = iv_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM invoicing_details WHERE id = ?', [$id]);
    if ($r['is_default']) { $n = one('SELECT id FROM invoicing_details WHERE owner_type = ? AND owner_id = ? AND is_active = 1 ORDER BY id LIMIT 1', [$r['owner_type'], $r['owner_id']]); if ($n) q('UPDATE invoicing_details SET is_default = 1 WHERE id = ?', [$n['id']]); }
    return $r;
}
/** One-line summary for lists: legal name · VAT · city. */
function iv_summary(array $r): string {
    return implode(' · ', array_filter([$r['legal_name'], $r['vat_no'] ? 'VAT ' . $r['vat_no'] : null, implode(' ', array_filter([$r['postal_code'], $r['city']])) ?: null, $r['country']]));
}
/** Postal block, one address per line. */
function iv_address_lines(array $r): array {
    return array_values(array_filter([$r['legal_name'], $r['attn'] ? t('iv.attn') . ' ' . $r['attn'] : null, $r['address'], trim(($r['postal_code'] ?? '') . ' ' . ($r['city'] ?? '')) ?: null, $r['country'],
                                      $r['vat_no'] ? t('iv.vat_no') . ': ' . $r['vat_no'] . ($r['tax_office'] ? ' · ' . t('iv.tax_office') . ': ' . $r['tax_office'] : '') : null, $r['reg_no'] ? t('iv.reg_no') . ': ' . $r['reg_no'] : null]));
}
/** The card: list of billing identities + add/edit form (administrators) or read-only list (staff). */
function iv_section(string $type, int $ownerId, string $back, ?int $editId = null): void {
    if (!iv_has() || !iv_type_ok($type)) return;
    $ivType = $type; $ivOwner = $ownerId; $ivBack = $back; $ivEdit = $editId ? iv_load($editId) : null;
    if ($ivEdit && ($ivEdit['owner_type'] !== $type || (int)$ivEdit['owner_id'] !== $ownerId)) $ivEdit = null;
    include __DIR__ . '/../views/_invoicing.php';
}

/* --------------------------------------------------------- customers */

function cu_cols_ok(): bool {
    static $ok = null;
    if ($ok === null) { try { $ok = (bool) scalar("SHOW COLUMNS FROM customers LIKE 'vat_no'"); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
function cu_load(int $id): ?array {
    if (!$id) return null;
    return one('SELECT c.*, (SELECT COUNT(*) FROM vessels v WHERE v.customer_id = c.id) AS n_vessels, (SELECT COUNT(*) FROM projects p WHERE p.customer_id = c.id) AS n_projects,
                       (SELECT COUNT(*) FROM customer_users u WHERE u.customer_id = c.id) AS n_users FROM customers c WHERE c.id = ?', [$id]);
}
function cu_save_from_post(int $id): int {
    require_cp_admin();
    $str = fn(string $k, int $max) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $name = $str('name', 160); if (!$name) throw new RuntimeException(t('cu.need_name'));
    $dup = scalar('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $id]); if ($dup) throw new RuntimeException(t('cu.dup', ['v' => $name]));
    $erp = $str('erp_code', 32);
    if ($erp && scalar('SELECT id FROM customers WHERE erp_code = ? AND id <> ?', [$erp, $id])) throw new RuntimeException(t('cu.dup_erp', ['v' => $erp]));
    $active = $id ? (int)!empty($_POST['is_active']) : 1;
    if ($cu_ext = cu_cols_ok()) {
        $vals = [$name, $erp, $str('country', 80), $str('address', 200), $str('city', 80), $str('postal_code', 20), $str('vat_no', 40), $str('email', 190), $str('phone', 60), $str('website', 190), $str('notes', 4000), $active];
        if ($id) { $vals[] = $id; q('UPDATE customers SET name = ?, erp_code = ?, country = ?, address = ?, city = ?, postal_code = ?, vat_no = ?, email = ?, phone = ?, website = ?, notes = ?, is_active = ? WHERE id = ?', $vals); }
        else { q('INSERT INTO customers (name, erp_code, country, address, city, postal_code, vat_no, email, phone, website, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $vals); $id = (int) db()->lastInsertId(); }
    } else {
        $vals = [$name, $erp, $str('country', 80), $active];
        if ($id) { $vals[] = $id; q('UPDATE customers SET name = ?, erp_code = ?, country = ?, is_active = ? WHERE id = ?', $vals); }
        else { q('INSERT INTO customers (name, erp_code, country, is_active) VALUES (?,?,?,?)', $vals); $id = (int) db()->lastInsertId(); }
    }
    return $id;
}
/** Only a customer nobody refers to can go. */
function cu_delete(int $id): void {
    require_cp_admin();
    $c = cu_load($id); if (!$c) throw new RuntimeException(t('err.notfound'));
    $refs = (int)$c['n_vessels'] + (int)$c['n_projects'] + (int)$c['n_users'];
    try { $refs += (int) scalar('SELECT COUNT(*) FROM contacts WHERE customer_id = ?', [$id]); } catch (Throwable $e) {}
    try { $refs += (int) scalar('SELECT COUNT(*) FROM companies WHERE customer_id = ?', [$id]); } catch (Throwable $e) {}
    if ($refs) throw new RuntimeException(t('cu.in_use'));
    if (iv_has()) q("DELETE FROM invoicing_details WHERE owner_type = 'customer' AND owner_id = ?", [$id]);
    q('DELETE FROM customers WHERE id = ?', [$id]);
}

/* ------------------------------------------------------ Excel import */

/** Columns the customers workbook may carry (header aliases, case-insensitive). One row per customer,
 *  or one row per invoicing identity with the customer repeated. */
function cu_import_columns(): array {
    return [
        'name' => ['Customer', 'Customer name', 'Name', 'Owner'], 'erp' => ['ERP code', 'ERP', 'SoftOne code', 'Code', 'Customer code'],
        'country' => ['Country'], 'city' => ['City', 'Town'], 'postal_code' => ['Postal code', 'Post code', 'ZIP', 'ZIP code'], 'address' => ['Address', 'Street'],
        'vat_no' => ['VAT', 'VAT number', 'VAT no', 'AFM', 'ΑΦΜ', 'Tax ID'], 'email' => ['Email', 'E-mail'], 'phone' => ['Phone', 'Telephone', 'Tel'], 'website' => ['Website', 'Web', 'URL'],
        'notes' => ['Notes', 'Remarks', 'Comments'], 'active' => ['Active', 'Status'],
        'iv_label' => ['Invoicing label', 'Billing label', 'Inv label', 'Invoicing'], 'iv_legal_name' => ['Legal name', 'Invoicing legal name', 'Billing name', 'Bill to'],
        'iv_vat_no' => ['Invoicing VAT', 'Inv VAT', 'Billing VAT', 'Legal VAT'], 'iv_tax_office' => ['Tax office', 'DOY', 'ΔΟΥ'], 'iv_reg_no' => ['Registration no', 'Registration number', 'Reg no', 'GEMI', 'ΓΕΜΗ'],
        'iv_attn' => ['Attn', 'Attention', 'For the attention of'], 'iv_address' => ['Invoicing address', 'Inv address', 'Billing address'], 'iv_postal_code' => ['Invoicing postal code', 'Inv postal code', 'Billing postal code', 'Inv ZIP'],
        'iv_city' => ['Invoicing city', 'Inv city', 'Billing city'], 'iv_country' => ['Invoicing country', 'Inv country', 'Billing country'], 'iv_email' => ['Invoicing email', 'Invoice email', 'Inv email', 'Billing email', 'Invoices to'],
        'iv_phone' => ['Invoicing phone', 'Inv phone', 'Billing phone'], 'iv_bank' => ['Bank'], 'iv_iban' => ['IBAN'], 'iv_swift' => ['SWIFT', 'BIC', 'SWIFT/BIC', 'SWIFT / BIC'],
        'iv_currency' => ['Currency', 'CCY'], 'iv_payment_terms' => ['Payment terms', 'Terms'], 'iv_notes' => ['Invoicing notes', 'Inv notes', 'Billing notes'], 'iv_default' => ['Default', 'Default invoicing', 'Is default'],
    ];
}
function cu_import(string $path, bool $dry): array {
    require_cp_admin();
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'customers_new' => 0, 'customers_upd' => 0, 'invoicing_new' => 0, 'invoicing_upd' => 0, 'skipped' => [], 'warnings' => []];
    $cols = cu_import_columns();
    $hdr = pm_import_header($rows, ['name' => $cols['name']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Customer']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], array_diff_key($cols, ['name' => 1]));
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $ext = cu_cols_ok(); $ivOk = iv_has();
    $yes = fn(string $v) => in_array(mb_strtolower($v), ['1', 'yes', 'y', 'x', 'true', 'ναι', 'так', 'да', 'si', 'sì'], true);
    $no  = fn(string $v) => in_array(mb_strtolower($v), ['0', 'no', 'n', 'false', 'inactive', 'disabled', 'όχι', 'ні', 'не'], true);
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $ext, $ivOk, $yes, $no) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $name = mb_substr($g($r, 'name'), 0, 160); $erp = mb_substr($g($r, 'erp'), 0, 32);
            if ($name === '' && $erp === '') { $res['skipped'][] = [$line, t('cu.im_noname')]; continue; }
            $id = null;
            if ($erp !== '') $id = scalar('SELECT id FROM customers WHERE erp_code = ? LIMIT 1', [$erp]);
            if (!$id && $name !== '') $id = scalar('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) ORDER BY is_active DESC LIMIT 1', [$name]);
            if (!$id && $name === '') { $res['skipped'][] = [$line, t('cu.im_unknown_erp', ['v' => $erp])]; continue; }
            $activeRaw = $g($r, 'active'); $active = $activeRaw === '' ? null : ($no($activeRaw) ? 0 : 1);
            $f = ['erp_code' => $erp ?: null, 'country' => mb_substr($g($r, 'country'), 0, 80) ?: null, 'is_active' => $active];
            if ($ext) $f += ['city' => mb_substr($g($r, 'city'), 0, 80) ?: null, 'postal_code' => mb_substr($g($r, 'postal_code'), 0, 20) ?: null, 'address' => mb_substr($g($r, 'address'), 0, 200) ?: null,
                             'vat_no' => strtoupper(preg_replace('/\s+/', '', mb_substr($g($r, 'vat_no'), 0, 40))) ?: null, 'email' => mb_substr($g($r, 'email'), 0, 190) ?: null, 'phone' => mb_substr($g($r, 'phone'), 0, 60) ?: null,
                             'website' => mb_substr($g($r, 'website'), 0, 190) ?: null, 'notes' => mb_substr($g($r, 'notes'), 0, 4000) ?: null];
            if ($f['email'] ?? null) { if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $res['warnings'][] = [$line, t('iv.bad_email')]; $f['email'] = null; } }
            if ($id) {
                if ($erp !== '' && ($dup = scalar('SELECT id FROM customers WHERE erp_code = ? AND id <> ?', [$erp, $id]))) { $res['warnings'][] = [$line, t('cu.dup_erp', ['v' => $erp])]; $f['erp_code'] = null; }
                $sets = []; $vals = [];
                if ($name !== '') { $sets[] = 'name = ?'; $vals[] = $name; }
                foreach ($f as $k => $v) if ($v !== null) { $sets[] = "$k = ?"; $vals[] = $v; }
                if ($sets) { $vals[] = $id; q('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals); }
                $res['customers_upd']++;
            } else {
                $f['is_active'] = $active ?? 1; $f = ['name' => $name] + $f;
                q('INSERT INTO customers (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
                $id = (int) db()->lastInsertId(); $res['customers_new']++;
            }
            $id = (int)$id;
            /* invoicing identity on the same row */
            $legal = mb_substr($g($r, 'iv_legal_name'), 0, 200);
            if ($legal === '') continue;
            if (!$ivOk) { $res['warnings'][] = [$line, t('cu.need_r19')]; continue; }
            $iv = ['label' => mb_substr($g($r, 'iv_label'), 0, 80) ?: $legal, 'legal_name' => $legal, 'vat_no' => strtoupper(preg_replace('/\s+/', '', mb_substr($g($r, 'iv_vat_no'), 0, 40))) ?: null,
                   'tax_office' => mb_substr($g($r, 'iv_tax_office'), 0, 120) ?: null, 'reg_no' => mb_substr($g($r, 'iv_reg_no'), 0, 60) ?: null, 'attn' => mb_substr($g($r, 'iv_attn'), 0, 120) ?: null,
                   'address' => mb_substr($g($r, 'iv_address'), 0, 200) ?: null, 'postal_code' => mb_substr($g($r, 'iv_postal_code'), 0, 20) ?: null, 'city' => mb_substr($g($r, 'iv_city'), 0, 80) ?: null, 'country' => mb_substr($g($r, 'iv_country'), 0, 80) ?: null,
                   'email' => mb_substr($g($r, 'iv_email'), 0, 190) ?: null, 'phone' => mb_substr($g($r, 'iv_phone'), 0, 60) ?: null, 'bank' => mb_substr($g($r, 'iv_bank'), 0, 120) ?: null,
                   'iban' => strtoupper(preg_replace('/\s+/', '', mb_substr($g($r, 'iv_iban'), 0, 48))) ?: null, 'swift' => strtoupper(mb_substr($g($r, 'iv_swift'), 0, 16)) ?: null,
                   'currency' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $g($r, 'iv_currency')), 0, 3)) ?: null, 'payment_terms' => mb_substr($g($r, 'iv_payment_terms'), 0, 120) ?: null, 'notes' => mb_substr($g($r, 'iv_notes'), 0, 4000) ?: null];
            if ($iv['email'] && !filter_var($iv['email'], FILTER_VALIDATE_EMAIL)) { $res['warnings'][] = [$line, t('iv.bad_email')]; $iv['email'] = null; }
            $defRaw = $g($r, 'iv_default'); $def = $defRaw === '' ? null : ($yes($defRaw) ? 1 : 0);
            $ivId = scalar("SELECT id FROM invoicing_details WHERE owner_type = 'customer' AND owner_id = ? AND (LOWER(legal_name) = LOWER(?) OR LOWER(label) = LOWER(?)) ORDER BY (LOWER(legal_name) = LOWER(?)) DESC LIMIT 1", [$id, $legal, $iv['label'], $legal]);
            if ($ivId) {
                $sets = []; $vals = [];
                foreach ($iv as $k => $v) if ($v !== null) { $sets[] = "$k = ?"; $vals[] = $v; }
                $vals[] = $ivId; q('UPDATE invoicing_details SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals); $res['invoicing_upd']++;
            } else {
                $iv['currency'] = $iv['currency'] ?: 'EUR';
                q('INSERT INTO invoicing_details (' . implode(', ', array_keys($iv)) . ", owner_type, owner_id, is_active, created_by) VALUES (" . implode(',', array_fill(0, count($iv), '?')) . ", 'customer', ?, 1, ?)", array_merge(array_values($iv), [$id, (int)(current_user()['id'] ?? 0) ?: null]));
                $ivId = (int) db()->lastInsertId(); $res['invoicing_new']++;
            }
            if ($def === 1 || !scalar("SELECT COUNT(*) FROM invoicing_details WHERE owner_type = 'customer' AND owner_id = ? AND is_default = 1", [$id])) {
                q("UPDATE invoicing_details SET is_default = 0 WHERE owner_type = 'customer' AND owner_id = ?", [$id]);
                q('UPDATE invoicing_details SET is_default = 1 WHERE id = ?', [$ivId]);
            }
        }
        return $res;
    });
}
