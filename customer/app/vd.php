<?php
declare(strict_types=1);
/**
 * Vessel documents — every file the portal holds for a vessel, across all its projects, grouped the way the
 * project archive groups them (file-kind archive tab) plus ticket attachments and delivery notes.   GAS-CP-R93-005
 * Customers see external files of their own vessel only; staff see everything (archived files on request).
 */

const VD_GROUPS = ['reports', 'service-reports', 'inspections', 'drawings', 'owner-approvals', 'class', 'tests', 'weekly-notes', 'offers', 'files', 'tickets', 'delivery-notes'];

function vd_group_label(string $g): string {
    return match ($g) { 'offers' => t('vd.g_offers'), 'tickets' => t('vd.g_tickets'), 'delivery-notes' => t('vd.g_delivery_notes'), default => t('fkc.tab_' . str_replace('-', '_', $g)) };
}
function vd_group_icon(string $g): string {
    return ['reports' => '📑', 'service-reports' => '🛠', 'inspections' => '🔍', 'drawings' => '📐', 'owner-approvals' => '✍', 'class' => '⚓', 'tests' => '🧪', 'weekly-notes' => '🗓', 'offers' => '💶', 'files' => '📁', 'tickets' => '🎫', 'delivery-notes' => '📦'][$g] ?? '📁';
}
function vd_group_of_kind(string $kind): string {
    if ($kind === 'offer') return 'offers';
    if ($kind === 'ga') return 'drawings';
    $t = function_exists('fk_tab') ? fk_tab($kind) : 'files';
    return in_array($t, VD_GROUPS, true) ? $t : 'files';
}

/**
 * Collect. $f: project (int), group, q, year.
 * Returns ['items' => [...], 'groups' => [group => count], 'years' => [...], 'projects' => [...]].
 */
function vd_collect(int $vesselId, bool $staff, array $f = []): array {
    $items = [];
    $vis = $staff ? '1 = 1' : "f.visibility = 'external'";
    $pw = ''; $pa = [$vesselId];
    if (!empty($f['project'])) { $pw = ' AND p.id = ?'; $pa[] = (int)$f['project']; }
    // 1. project files (includes generated report PDFs, FSR / ISR documents, offer PDFs, drawings, test reports, photos)
    $rows = all("SELECT f.id, f.kind, f.title, f.doc_no, f.revision, f.original_name, f.mime_type, f.size_bytes, f.visibility, f.archived_at, f.created_at, f.fsr_id, f.isr_id, f.offer_id, f.note_id,
                        p.id AS project_id, p.project_code, p.name AS project_name, sp.code AS sub_code, sp.name AS sub_name, cu.full_name AS by_name
                 FROM sp_files f JOIN projects p ON p.id = f.project_id LEFT JOIN subprojects sp ON sp.id = f.subproject_id LEFT JOIN customer_users cu ON cu.id = f.uploaded_by
                 WHERE p.vessel_id = ? AND $vis $pw ORDER BY f.created_at DESC", $pa);
    foreach ($rows as $r) {
        if (!$staff && in_array($r['kind'], ['fsr_sign'], true)) continue;
        $items[] = ['group' => vd_group_of_kind((string)$r['kind']), 'kind' => fk_label((string)$r['kind']), 'title' => (string)($r['title'] ?: $r['original_name']), 'file' => (string)$r['original_name'],
            'doc_no' => trim((string)$r['doc_no'] . ($r['revision'] !== null && $r['revision'] !== '' ? ' ' . (preg_match('/^R/i', (string)$r['revision']) ? $r['revision'] : 'R' . $r['revision']) : '')), 'size' => (int)$r['size_bytes'], 'date' => (string)$r['created_at'],
            'project' => (string)($r['project_code'] ?: $r['project_name']), 'project_id' => (int)$r['project_id'], 'sub' => trim((string)$r['sub_code'] . ' ' . (string)$r['sub_name']), 'by' => (string)($r['by_name'] ?? ''),
            'url' => url('file', ['id' => $r['id']]), 'visibility' => (string)$r['visibility'], 'archived' => false,
            'ctx' => $r['fsr_id'] ? url('fsr', ['id' => $r['fsr_id']]) : ($r['isr_id'] ? url('isr', ['id' => $r['isr_id']]) : ($r['offer_id'] ? url('offer', ['id' => $r['offer_id']]) : ''))];
    }
    // 2. ticket attachments
    $tw = $staff ? '' : " AND t.customer_id = " . (int)(current_user()['customer_id'] ?? 0);
    $rows = all("SELECT tf.id, tf.original_name, tf.mime_type, tf.size_bytes, tf.created_at, tf.uploaded_name, t.id AS tid, t.ticket_no, t.title, p.project_code, p.id AS project_id
                 FROM ticket_files tf JOIN tickets t ON t.id = tf.ticket_id LEFT JOIN projects p ON p.id = t.project_id
                 WHERE t.vessel_id = ? $tw " . ($pw ? str_replace('p.id', 'p.id', $pw) : '') . " ORDER BY tf.created_at DESC", $pa);
    foreach ($rows as $r) $items[] = ['group' => 'tickets', 'kind' => t('vd.k_ticket_file'), 'title' => (string)$r['original_name'], 'file' => (string)$r['original_name'], 'doc_no' => (string)$r['ticket_no'], 'size' => (int)$r['size_bytes'], 'date' => (string)$r['created_at'],
        'project' => (string)($r['project_code'] ?? ''), 'project_id' => (int)($r['project_id'] ?? 0), 'sub' => (string)$r['title'], 'by' => (string)$r['uploaded_name'], 'url' => url('tfile', ['id' => $r['id']]), 'visibility' => 'external', 'archived' => false, 'ctx' => url('ticket', ['id' => $r['tid']])];
    // 3. delivery notes (staff)
    if ($staff && function_exists('dn_can') && dn_can()) {
        $rows = all("SELECT d.id, d.dn_no, d.kind, d.issued_at, d.user_name, d.created_at, p.project_code, p.id AS project_id, sp.code AS sub_code FROM inv_delivery_notes d LEFT JOIN projects p ON p.id = d.project_id LEFT JOIN subprojects sp ON sp.id = d.subproject_id
                     WHERE (d.vessel_id = ? OR p.vessel_id = ?) AND d.status <> 'cancelled' $pw ORDER BY d.created_at DESC", array_merge([$vesselId], $pa));
        foreach ($rows as $r) $items[] = ['group' => 'delivery-notes', 'kind' => t('vd.k_dn'), 'title' => (string)$r['dn_no'], 'file' => $r['dn_no'] . '.pdf', 'doc_no' => (string)$r['dn_no'], 'size' => 0, 'date' => (string)($r['issued_at'] ?: $r['created_at']),
            'project' => (string)($r['project_code'] ?? ''), 'project_id' => (int)($r['project_id'] ?? 0), 'sub' => (string)($r['sub_code'] ?? ''), 'by' => (string)$r['user_name'], 'url' => url('delivery_note', ['id' => $r['id'], 'format' => 'pdf']), 'visibility' => 'internal', 'archived' => false, 'ctx' => url('delivery_note', ['id' => $r['id']])];
    }
    // filters
    $q = mb_strtolower(trim((string)($f['q'] ?? '')));
    if ($q !== '') $items = array_values(array_filter($items, fn($i) => str_contains(mb_strtolower($i['title'] . ' ' . $i['file'] . ' ' . $i['doc_no'] . ' ' . $i['kind'] . ' ' . $i['sub'] . ' ' . $i['project']), $q)));
    if (!empty($f['year'])) $items = array_values(array_filter($items, fn($i) => substr($i['date'], 0, 4) === (string)$f['year']));
    $groups = array_fill_keys(VD_GROUPS, 0); $years = [];
    foreach ($items as $i) { $groups[$i['group']]++; $y = substr($i['date'], 0, 4); $years[$y] = ($years[$y] ?? 0) + 1; }
    krsort($years);
    if (!empty($f['group'])) $items = array_values(array_filter($items, fn($i) => $i['group'] === $f['group']));
    usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
    return ['items' => $items, 'groups' => array_filter($groups), 'years' => $years, 'total' => array_sum($groups)];
}
