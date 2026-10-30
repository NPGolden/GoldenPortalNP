<?php
/** Excel exports of the lists (contacts, vessels, projects) — same filters and scope as the page.   GAS-CP-R17-003 */
require_once __DIR__ . '/xlsxw.php';

function ex_contacts(array $f): void {
    require_cp_admin();
    $l = ct_list($f, 0); $rows = [];
    foreach ($l['rows'] as $r) $rows[] = [$r['full_name'], $r['company_name'], $r['customer_name'], ct_categories()[$r['category']] ?? $r['category'], $r['position'], $r['email'], $r['phone'], $r['mobile'], $r['vessel_list'],
        implode('; ', array_map(fn($k) => ct_channels()[$k] ?? $k, array_filter(explode(',', (string)($r['channels'] ?? ''))))), implode('; ', array_map(fn($k) => ct_subscriptions()[$k] ?? $k, array_filter(explode(',', (string)($r['subscriptions'] ?? ''))))),
        $r['birthday'] ? (ct_year_known($r['birthday']) ? date('d.m.Y', strtotime($r['birthday'])) : substr($r['birthday'], 8, 2) . '.' . substr($r['birthday'], 5, 2)) : '', $r['nameday_md'] ? substr($r['nameday_md'], 3, 2) . '.' . substr($r['nameday_md'], 0, 2) : '', $r['interests'], (int)$r['n_notes'], $r['is_active'] ? 'yes' : 'no'];
    xlsx_download('GAS-contacts-' . date('Ymd') . '.xlsx', [['name' => t('ct.title'), 'first' => true,
        'header' => ['Full name', 'Company', 'Customer', 'Category', 'Position', 'Email', 'Phone', 'Mobile', 'Vessels', 'Channels', 'Subscriptions', 'Birthday', 'Name day', 'Interests', 'Notes', 'Active'],
        'rows' => $rows, 'widths' => [26, 24, 22, 20, 22, 30, 18, 18, 36, 24, 24, 12, 10, 30, 8, 8], 'wrap' => [8, 13]]]);
}

function ex_vessels(array $g): void {
    $u = require_cp_admin(); $staff = true;
    $q = trim((string)($g['q'] ?? '')); $fCustomer = $staff ? (string)($g['customer'] ?? '') : ''; $fStatus = (string)($g['status'] ?? 'active');
    $w = []; $args = [];
    if ($staff) { if ($fCustomer === 'none') $w[] = 'v.customer_id IS NULL'; elseif ((int)$fCustomer > 0) { $w[] = 'v.customer_id = ?'; $args[] = (int)$fCustomer; } }
    else { $w[] = 'v.customer_id = ?'; $args[] = (int)$u['customer_id']; if ($fStatus === 'all') $fStatus = 'active'; }
    if ($fStatus !== 'all') { $w[] = 'v.is_active = ?'; $args[] = $fStatus === 'active' ? 1 : 0; }
    if ($q !== '') { $w[] = '(v.name LIKE ? OR v.imo LIKE ? OR v.yard LIKE ? OR c.name LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $q . '%')); }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $vs = all("SELECT v.*, c.name AS customer_name,
                 (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id) AS n_projects,
                 (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id AND p.status = 'active') AS n_active,
                 (SELECT GROUP_CONCAT(COALESCE(p.project_code, p.name) ORDER BY p.project_code SEPARATOR '; ') FROM projects p WHERE p.vessel_id = v.id) AS project_list
               FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id $where ORDER BY v.is_active DESC, c.name, v.name", $args);
    $hasTk = function_exists('tk_has') && tk_has(); $hasCt = function_exists('ct_has') && ct_has() && $staff;
    $rows = [];
    foreach ($vs as $v) {
        $row = [$v['name'], $v['imo'], $v['customer_name'], $v['yard'], (int)$v['n_projects'], (int)$v['n_active'], $v['project_list']];
        if ($hasTk) $row[] = (int)scalar("SELECT COUNT(*) FROM tickets WHERE vessel_id = ? AND status NOT IN ('closed','cancelled','resolved')", [$v['id']]);
        if ($hasCt) $row[] = (string)scalar("SELECT GROUP_CONCAT(CONCAT(ct.full_name, IF(cv.role IS NULL OR cv.role = '', '', CONCAT(' (', cv.role, ')'))) ORDER BY cv.is_primary DESC, ct.full_name SEPARATOR '; ') FROM contact_vessels cv JOIN contacts ct ON ct.id = cv.contact_id AND ct.is_active = 1 WHERE cv.vessel_id = ?", [$v['id']]);
        $row[] = $v['is_active'] ? 'yes' : 'no'; $row[] = $v['created_at'] ? substr($v['created_at'], 0, 10) : '';
        $rows[] = $row;
    }
    $header = ['Vessel', 'IMO', 'Customer', 'Yard', 'Projects', 'Active projects', 'Project codes']; $widths = [28, 12, 24, 22, 10, 12, 30];
    if ($hasTk) { $header[] = 'Open tickets'; $widths[] = 12; }
    if ($hasCt) { $header[] = 'Contacts'; $widths[] = 44; }
    array_push($header, 'Active', 'Created'); array_push($widths, 8, 12);
    xlsx_download('GAS-vessels-' . date('Ymd') . '.xlsx', [['name' => t('v.title'), 'first' => true, 'header' => $header, 'rows' => $rows, 'widths' => $widths, 'wrap' => [6, 8]]]);
}

function ex_projects(array $g): void {
    $u = require_cp_admin(); $staff = true;
    $allSt = pm_statuses();
    $st = array_values(array_intersect((array)($g['st'] ?? []), $allSt)); if (!$st && empty($g['f'])) $st = ['active', 'onhold'];
    $f = ['status' => $st, 'customer' => $staff ? (int)($g['customer'] ?? 0) : 0, 'vessel' => (int)($g['vessel'] ?? 0), 'pm' => trim((string)($g['pm'] ?? '')), 'q' => trim((string)($g['q'] ?? ''))];
    $res = project_tree_filtered($f, 100000, 0);
    $stages = stage_defs(); $stLabel = fn($s) => t('pr.' . $s);
    $hasOf = function_exists('of_has') && of_has();
    $pRows = []; $sRows = [];
    foreach ($res['rows'] as $p) {
        $subs = $p['subs']; $n = count($subs);
        $avg = $n ? (int)round(array_sum(array_map('overall_pct', $subs)) / $n) : 0;
        $row = [$p['project_code'], $p['erp_code'], $p['name'], $p['vessel'], $p['customer_name'], $stLabel($p['status']), $p['project_manager'], $p['category'] ?? '', $n, $avg, $p['deadline'] ?? '', $p['next_milestone'] ?? ''];
        if ($hasOf) { $os = of_project_summary((int)$p['id']); $row[] = (int)($os['sent'] ?? 0); $row[] = (float)($os['sent_amount'] ?? 0); $row[] = (int)($os['approved'] ?? 0); $row[] = (float)($os['approved_amount'] ?? 0); }
        $row[] = $p['updated_at'] ? substr($p['updated_at'], 0, 10) : '';
        $pRows[] = $row;
        foreach ($subs as $s) {
            $sr = [$p['project_code'], $p['name'], $p['vessel'], $s['code'], $s['name'], $s['system_name'], $stLabel($s['status'])];
            foreach ($stages as $d) $sr[] = (int)$s[$d['col']];
            $sr[] = overall_pct($s); $sr[] = $s['deadline'] ?? ''; $sr[] = $s['invoicing'] ?? ''; $sr[] = $s['invoiced_pct'] !== null ? (int)$s['invoiced_pct'] : ''; $sr[] = $s['updated_at'] ? substr($s['updated_at'], 0, 10) : '';
            $sRows[] = $sr;
        }
    }
    $pHeader = ['Project code', 'ERP code', 'Project', 'Vessel', 'Customer', 'Status', 'Project manager', 'Category', 'Sub-projects', 'Progress %', 'Deadline', 'Next milestone']; $pW = [16, 16, 34, 22, 22, 12, 22, 14, 12, 11, 12, 28];
    if ($hasOf) { array_push($pHeader, 'Offers sent', 'Amount sent', 'Offers approved', 'Amount approved'); array_push($pW, 12, 14, 14, 16); }
    $pHeader[] = 'Updated'; $pW[] = 12;
    $sHeader = ['Project code', 'Project', 'Vessel', 'Sub-project', 'Name', 'Vessel system', 'Status'];
    foreach ($stages as $slug => $d) $sHeader[] = t('st.ab.' . $slug) . ' %';
    array_push($sHeader, 'Overall %', 'Deadline', 'Invoicing', 'Invoiced %', 'Updated');
    $sW = [16, 30, 22, 12, 30, 24, 12, 8, 8, 8, 8, 8, 8, 8, 10, 12, 16, 11, 12];
    xlsx_download('GAS-projects-' . date('Ymd') . '.xlsx', [
        ['name' => t('pr.title'), 'first' => true, 'header' => $pHeader, 'rows' => $pRows, 'widths' => $pW],
        ['name' => t('sp.one') . 's', 'header' => $sHeader, 'rows' => $sRows, 'widths' => $sW],
    ]);
}

/** Portal accounts in the layout the accounts import reads (password column empty — set one to reset it).   R24-004 */
function ex_users(array $g): void {
    require_cp_admin();
    $hasTotp = (bool)one("SHOW COLUMNS FROM customer_users LIKE 'twofa_required'"); $hasDisc = ts_has();
    $hasCal = function_exists('wc_has') && wc_has(); $hasBr = function_exists('br_has') && br_has();
    $w = []; $b = [];
    if (($q = trim((string)($g['q'] ?? ''))) !== '') { $l = '%' . $q . '%'; $w[] = '(cu.full_name LIKE ? OR cu.email LIKE ? OR c.name LIKE ?)'; array_push($b, $l, $l, $l); }
    if (in_array($g['role'] ?? '', ['customer', 'staff', 'admin'], true)) { $w[] = 'cu.role = ?'; $b[] = $g['role']; }
    $rows = [];
    foreach (all('SELECT cu.*, c.name AS customer_name, r.name AS role_name, d.name AS disc_name, m.full_name AS manager_name FROM customer_users cu LEFT JOIN customers c ON c.id = cu.customer_id LEFT JOIN staff_roles r ON r.id = cu.staff_role_id'
                 . ($hasDisc ? ' LEFT JOIN disciplines d ON d.id = cu.discipline_id LEFT JOIN customer_users m ON m.id = cu.manager_user_id' : '') . ' ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY cu.role, c.name, cu.full_name', $b) as $r) {
        $row = [$r['full_name'], $r['email'], $r['role'], $r['customer_name'] ?? '', $r['role_name'] ?? '', $hasDisc ? ($r['disc_name'] ?? '') : '', $hasDisc ? ($r['manager_name'] ?? '') : '', $r['locale'], '', $hasTotp ? ((int)$r['twofa_required'] ? 'yes' : 'no') : '', (int)$r['is_active'] ? 'yes' : 'no'];
        if ($hasCal) $row[] = $r['role'] === 'customer' ? '' : ($r['work_calendar'] ?: 'GR');
        if ($hasBr) { $row[] = $r['role'] === 'customer' ? '' : br_get((int)($r['branch_id'] ?? 0) ?: null)['code']; $row[] = $r['role'] === 'customer' ? '' : (!empty($r['branch_scope']) ? 'yes' : 'no'); }
        if (function_exists('tm_has') && tm_has()) $row[] = $r['role'] === 'customer' ? '' : tm_user_codes((int)$r['id']);
        $row[] = $r['last_login_at'] ? substr((string)$r['last_login_at'], 0, 16) : ''; $row[] = substr((string)$r['created_at'], 0, 10);
        $rows[] = $row;
    }
    $header = ['Full name', 'Email', 'Role', 'Customer', 'Golden A/S role', 'Discipline', 'Manager', 'Language', 'Password', '2FA required', 'Active'];
    $widths = [26, 30, 10, 24, 20, 18, 22, 9, 12, 12, 8];
    if ($hasCal) { $header[] = 'Working calendar'; $widths[] = 10; }
    if ($hasBr) { $header[] = 'Branch'; $header[] = 'Branch scope'; $widths[] = 8; $widths[] = 10; }
    if (function_exists('tm_has') && tm_has()) { $header[] = 'Assigned sub-projects'; $widths[] = 40; }
    $header[] = 'Last login'; $header[] = 'Created'; $widths[] = 16; $widths[] = 12;
    xlsx_download('GAS-accounts-' . date('Ymd') . '.xlsx', [['name' => 'Accounts', 'first' => true, 'header' => $header, 'rows' => $rows, 'widths' => $widths]]);
}

function ex_companies(array $g): void {
    require_cp_admin();
    if (!co_has()) { http_response_code(404); exit; }
    $q = trim((string)($g['q'] ?? '')); $fk = (string)($g['kind'] ?? '');
    $w = []; $b = [];
    if ($q !== '') { $l = '%' . $q . '%'; $w[] = '(co.name LIKE ? OR co.country LIKE ? OR co.city LIKE ? OR c.name LIKE ?)'; array_push($b, $l, $l, $l, $l); }
    if ($fk !== '' && isset(co_kinds()[$fk])) { $w[] = 'co.kind = ?'; $b[] = $fk; }
    $rows = [];
    foreach (all('SELECT co.*, c.name AS customer_name, (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) AS n_contacts,
                         (SELECT COUNT(DISTINCT cv.vessel_id) FROM contact_vessels cv JOIN contacts ct ON ct.id = cv.contact_id WHERE ct.company_id = co.id) AS n_vessels
                  FROM companies co LEFT JOIN customers c ON c.id = co.customer_id ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY co.is_active DESC, co.name', $b) as $r)
        $rows[] = [$r['name'], co_kinds()[$r['kind']] ?? $r['kind'], $r['customer_name'], $r['country'], $r['city'], $r['website'], $r['email'], $r['phone'], (int)$r['n_contacts'], (int)$r['n_vessels'], $r['notes'], $r['is_active'] ? 'yes' : 'no', $r['created_at'] ? substr($r['created_at'], 0, 10) : ''];
    xlsx_download('GAS-companies-' . date('Ymd') . '.xlsx', [['name' => t('co.title'), 'first' => true,
        'header' => ['Company', 'Kind', 'Customer', 'Country', 'City', 'Website', 'Email', 'Phone', 'Contacts', 'Vessels', 'Notes', 'Active', 'Created'],
        'rows' => $rows, 'widths' => [30, 20, 24, 16, 16, 28, 28, 18, 10, 10, 40, 8, 12], 'wrap' => [10]]]);
}

/** Customers with their invoicing details — one row per billing identity (a customer without any = one row);
 *  the same layout the Customers import reads, so the file can be corrected and loaded back.   R19-001 */
function ex_customers(array $g): void {
    require_cp_admin();
    $q = trim((string)($g['q'] ?? '')); $st = (string)($g['status'] ?? 'all'); $ext = cu_cols_ok(); $ivOk = iv_has();
    $w = []; $b = [];
    if ($q !== '') { $l = '%' . $q . '%'; $w[] = '(c.name LIKE ? OR c.erp_code LIKE ? OR c.country LIKE ?' . ($ext ? ' OR c.vat_no LIKE ? OR c.city LIKE ?' : '') . ')'; array_push($b, $l, $l, $l); if ($ext) array_push($b, $l, $l); }
    if ($st === 'active' || $st === 'inactive') $w[] = 'c.is_active = ' . ($st === 'active' ? 1 : 0);
    $rows = [];
    foreach (all('SELECT c.* FROM customers c ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.is_active DESC, c.name', $b) as $c) {
        $base = [$c['name'], $c['erp_code'], $c['country'], $ext ? $c['city'] : null, $ext ? $c['postal_code'] : null, $ext ? $c['address'] : null, $ext ? $c['vat_no'] : null, $ext ? $c['email'] : null, $ext ? $c['phone'] : null, $ext ? $c['website'] : null, $ext ? $c['notes'] : null, $c['is_active'] ? 'Yes' : 'No'];
        $ivs = $ivOk ? iv_list('customer', (int)$c['id']) : [];
        if (!$ivs) { $rows[] = array_merge($base, array_fill(0, 19, null)); continue; }
        foreach ($ivs as $i) $rows[] = array_merge($base, [$i['label'], $i['legal_name'], $i['vat_no'], $i['tax_office'], $i['reg_no'], $i['attn'], $i['address'], $i['postal_code'], $i['city'], $i['country'], $i['email'], $i['phone'], $i['bank'], $i['iban'], $i['swift'], $i['currency'], $i['payment_terms'], $i['notes'], $i['is_default'] ? 'Yes' : 'No']);
    }
    xlsx_download('GAS-customers-' . date('Ymd') . '.xlsx', [['name' => t('cu.title'), 'first' => true,
        'header' => ['Customer', 'ERP code', 'Country', 'City', 'Postal code', 'Address', 'VAT', 'Email', 'Phone', 'Website', 'Notes', 'Active',
                     'Invoicing label', 'Legal name', 'Invoicing VAT', 'Tax office', 'Registration no', 'Attn', 'Invoicing address', 'Invoicing postal code', 'Invoicing city', 'Invoicing country', 'Invoicing email', 'Invoicing phone', 'Bank', 'IBAN', 'SWIFT', 'Currency', 'Payment terms', 'Invoicing notes', 'Default'],
        'rows' => $rows, 'widths' => [30, 12, 14, 14, 10, 28, 16, 26, 16, 22, 30, 8, 18, 32, 16, 16, 18, 18, 28, 10, 14, 14, 26, 16, 16, 30, 12, 9, 22, 30, 9], 'wrap' => [10, 29]]]);
}

