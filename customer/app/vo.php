<?php
/**
 * Vessel ownership periods — GAS-CP-R95-005 (phase 3 of the parties / invoicing / collections plan).
 *
 *   A resale used to overwrite vessels.customer_id. Now every owner is a period in vessel_owners (organisation, from, to);
 *   the open period is the current owner and keeps vessels.customer_id in step. Projects and ledger rows are never
 *   re-pointed: they keep the billing party they were issued to, so a statement for the old owner stays the old owner's.
 *
 *   vo_has()                 table present (migrate_cp_r95_005)
 *   vo_can()                 who may change the owner: administrators, management, accounting, contacts managers
 *   vo_list($vid)            periods, newest first, with the organisation's name
 *   vo_at($vid, $date)       the period in force on a date
 *   vo_change($vid, $org, $from, $note)   new owner from a date: closes the open period the day before
 *   vo_delete($id)           remove the latest period (re-opens the previous one) — a correction, administrators only
 *   vo_sync($vid)            after the vessel form changed the customer: record it as a period starting today
 */
declare(strict_types=1);

function vo_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'vessel_owners'"); } catch (Throwable $e) { $h = false; } } return $h; }
function vo_can(): bool { return is_staff() && vo_has() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('acc_is_accounting') && acc_is_accounting()) || (function_exists('org_can') && org_can())); }

function vo_list(int $vid): array {
    if (!vo_has()) return [];
    return all('SELECT o.*, COALESCE(co.name, c.name) AS owner_name, co.vat_no AS owner_vat, cu.full_name AS by_name FROM vessel_owners o LEFT JOIN companies co ON co.id = o.company_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN customer_users cu ON cu.id = o.created_by
                WHERE o.vessel_id = ? ORDER BY (o.date_to IS NULL) DESC, o.date_to DESC, o.id DESC', [$vid]);
}
function vo_at(int $vid, ?string $date = null): ?array {
    if (!vo_has()) return null; $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    return one('SELECT o.*, COALESCE(co.name, c.name) AS owner_name FROM vessel_owners o LEFT JOIN companies co ON co.id = o.company_id LEFT JOIN customers c ON c.id = o.customer_id
                WHERE o.vessel_id = ? AND (o.date_from IS NULL OR o.date_from <= ?) AND (o.date_to IS NULL OR o.date_to >= ?) ORDER BY (o.date_from IS NULL), o.date_from DESC, o.id DESC LIMIT 1', [$vid, $date, $date]) ?: null;
}
/** The client account of an organisation — created from the organisation's data when it has none (an owner must be a customer). */
function vo_org_customer(int $orgId): int {
    $co = one('SELECT * FROM companies WHERE id = ?', [$orgId]); if (!$co) throw new RuntimeException(t('err.notfound'));
    if (!empty($co['customer_id'])) return (int)$co['customer_id'];
    if (!empty($co['is_supplier']) && strpos(',' . (string)($co['roles'] ?? '') . ',', ',customer,') === false) throw new RuntimeException(t('vo.is_supplier'));   // a pure supplier is never turned into a customer silently
    q('INSERT INTO customers (name, country, address, city, postal_code, vat_no, email, phone, website, is_active) VALUES (?,?,?,?,?,?,?,?,?,1)', [(string)$co['name'], $co['country'] ?? null, $co['address'] ?? null, $co['city'] ?? null, $co['postal_code'] ?? null, $co['vat_no'] ?? null, $co['email'] ?? null, $co['phone'] ?? null, $co['website'] ?? null]);
    $cid = (int) db()->lastInsertId();
    q('UPDATE companies SET customer_id = ? WHERE id = ?', [$cid, $orgId]);
    if (function_exists('co_roles_has') && co_roles_has() && strpos(',' . (string)($co['roles'] ?? '') . ',', ',customer,') === false) q("UPDATE companies SET roles = TRIM(BOTH ',' FROM CONCAT(roles, ',customer')) WHERE id = ?", [$orgId]);
    q('UPDATE contacts SET customer_id = ? WHERE company_id = ? AND customer_id IS NULL', [$cid, $orgId]);
    if (function_exists('org_find_reset')) org_find_reset();
    return $cid;
}
function vo_change(int $vid, int $orgId, string $from, ?string $note = null): void {
    if (!vo_can()) throw new RuntimeException(t('err.noaccess'));
    $v = one('SELECT id, customer_id FROM vessels WHERE id = ?', [$vid]); if (!$v || !$orgId) throw new RuntimeException(t('err.notfound'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) throw new RuntimeException(t('vo.need_date'));
    if ($from > date('Y-m-d', strtotime('+1 year'))) throw new RuntimeException(t('vo.need_date'));
    $cur = one('SELECT * FROM vessel_owners WHERE vessel_id = ? AND date_to IS NULL ORDER BY id DESC LIMIT 1', [$vid]);
    if ($cur && (int)$cur['company_id'] === $orgId) throw new RuntimeException(t('vo.same_owner'));
    $last = scalar('SELECT MAX(COALESCE(date_to, date_from)) FROM vessel_owners WHERE vessel_id = ?', [$vid]);
    if ($cur && $cur['date_from'] && $from <= $cur['date_from']) throw new RuntimeException(t('vo.before_current', ['d' => fmt_date((string)$cur['date_from'])]));
    if (!$cur && $last && $from <= $last) throw new RuntimeException(t('vo.before_current', ['d' => fmt_date((string)$last)]));
    $cid = vo_org_customer($orgId);
    db()->beginTransaction();
    try {
        if ($cur) q('UPDATE vessel_owners SET date_to = ? WHERE id = ?', [date('Y-m-d', strtotime($from . ' -1 day')), (int)$cur['id']]);
        q('INSERT INTO vessel_owners (vessel_id, company_id, customer_id, date_from, note, created_by) VALUES (?,?,?,?,?,?)', [$vid, $orgId, $cid, $from, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, (int)(current_user()['id'] ?? 0) ?: null]);
        q('UPDATE vessels SET customer_id = ? WHERE id = ?', [$cid, $vid]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    if (function_exists('cache_bump_later')) cache_bump_later('fs');
}
function vo_delete(int $id): int {
    if (!vo_has() || !is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    $o = one('SELECT * FROM vessel_owners WHERE id = ?', [$id]); if (!$o) throw new RuntimeException(t('err.notfound')); $vid = (int)$o['vessel_id'];
    if ($o['date_to'] !== null || (int) scalar('SELECT COUNT(*) FROM vessel_owners WHERE vessel_id = ?', [$vid]) < 2) throw new RuntimeException(t('vo.only_latest'));
    $prev = one('SELECT * FROM vessel_owners WHERE vessel_id = ? AND id <> ? ORDER BY date_to DESC, id DESC LIMIT 1', [$vid, $id]);
    db()->beginTransaction();
    try {
        q('DELETE FROM vessel_owners WHERE id = ?', [$id]);
        if ($prev) { q('UPDATE vessel_owners SET date_to = NULL WHERE id = ?', [(int)$prev['id']]); if ($prev['customer_id']) q('UPDATE vessels SET customer_id = ? WHERE id = ?', [(int)$prev['customer_id'], $vid]); }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $vid;
}
/** Called after the vessel form: when the customer changed there, the history follows (period from today). */
function vo_sync(int $vid): void {
    if (!vo_has()) return;
    try {
        $cid = (int) (scalar('SELECT customer_id FROM vessels WHERE id = ?', [$vid]) ?: 0);
        $cur = one('SELECT * FROM vessel_owners WHERE vessel_id = ? AND date_to IS NULL ORDER BY id DESC LIMIT 1', [$vid]);
        if (!$cid) { return; }
        if ($cur && (int)$cur['customer_id'] === $cid) return;
        $org = (int) (scalar('SELECT MIN(id) FROM companies WHERE customer_id = ?', [$cid]) ?: 0) ?: null;
        $today = date('Y-m-d'); $uid = (int)(current_user()['id'] ?? 0) ?: null;
        if (!$cur) { q('INSERT INTO vessel_owners (vessel_id, company_id, customer_id, date_from, created_by) VALUES (?,?,?,?,?)', [$vid, $org, $cid, (int) scalar('SELECT COUNT(*) FROM vessel_owners WHERE vessel_id = ?', [$vid]) ? $today : null, $uid]); return; }
        if ($cur['date_from'] === $today) { q('UPDATE vessel_owners SET company_id = ?, customer_id = ? WHERE id = ?', [$org, $cid, (int)$cur['id']]); return; }   // corrected the same day
        q('UPDATE vessel_owners SET date_to = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), (int)$cur['id']]);
        q('INSERT INTO vessel_owners (vessel_id, company_id, customer_id, date_from, note, created_by) VALUES (?,?,?,?,?,?)', [$vid, $org, $cid, $today, t('vo.from_form'), $uid]);
    } catch (Throwable $e) { error_log('[vo_sync] ' . $e->getMessage()); }
}
