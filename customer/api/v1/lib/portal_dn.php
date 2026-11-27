<?php
/** Delivery notes for the receiver in the Service app — GAS-CP-R97-280.
 *  A member of staff sees only the notes that name him, and the transfers to a warehouse he holds. He reads the goods, opens the PDF and answers:
 *  accepted (with the signature drawn on the phone) or not received as listed (with a remark). Rules live in app/dn.php (dn_for_user, dn_user_decide). No prices. */
declare(strict_types=1);

final class PortalDN {
    private static function on(): bool { return function_exists('dn_x2_has') && dn_x2_has(); }
    private static function row(array $d): array {
        return ['id' => (int)$d['id'], 'no' => (string)$d['dn_no'], 'kind' => (string)$d['kind'], 'date' => (string)$d['issued_at'], 'delivery' => (string)$d['delivery'], 'waits' => dn_waits($d) && $d['delivery'] !== 'disputed',
                'from' => trim((string)($d['wh_code'] ?? '') . ' ' . (string)($d['warehouse'] ?? '')), 'to' => $d['kind'] === 'transfer' ? trim((string)($d['to_wh_code'] ?? '') . ' ' . (string)($d['to_warehouse'] ?? '')) : '',
                'project' => trim((string)($d['project_code'] ?? '') . ' ' . (string)($d['project_name'] ?? '')), 'subproject' => trim((string)($d['sub_code'] ?? '') . ' ' . (string)($d['sub_name'] ?? '')), 'vessel' => (string)($d['vessel'] ?? ''), 'company' => (string)($d['company'] ?? ''),
                'by' => (string)($d['user_name'] ?? ''), 'lines' => (int)$d['n_lines'], 'answered_at' => $d['accepted_at'], 'answered_by' => $d['accepted_name']];
    }
    private static function mine(int $id): array {
        if (!self::on()) throw new ApiException(404, 'Not found'); $u = current_user(); $d = dn_load($id);
        if (!$d || !$u || $d['status'] !== 'issued' || !dn_is_for($d, (int)$u['id'])) throw new ApiException(404, 'Not found');
        return $d;
    }
    public static function list(): array {
        if (!self::on()) return ['enabled' => false, 'waiting' => [], 'recent' => []]; $u = current_user(); $L = dn_for_user((int)($u['id'] ?? 0));
        return ['enabled' => true, 'waiting' => array_map([self::class, 'row'], $L['waiting']), 'recent' => array_map([self::class, 'row'], $L['recent'])];
    }
    public static function one(int $id): array {
        $d = self::mine($id); $out = self::row($d); $L = dn_lines($id); $sn = function_exists('sn_of_moves') ? sn_of_moves(array_filter(array_column($L, 'move_id'))) : [];
        $out += ['ref' => (string)($d['ref_no'] ?? ''), 'carrier' => (string)($d['carrier'] ?? ''), 'attention' => (string)($d['attention'] ?? ''), 'address' => (string)($d['address'] ?? ''), 'note' => (string)($d['note'] ?? ''), 'remark' => (string)($d['accepted_remark'] ?? ''), 'can_answer' => dn_waits($d)];
        $out['items'] = array_map(fn($l) => ['seq' => (int)$l['seq'], 'code' => (string)($l['item_code'] ?? ''), 'description' => (string)$l['description'], 'maker' => trim((string)($l['maker'] ?? '') . ' ' . (string)($l['factory_code'] ?? '')), 'qty' => (float)$l['qty'], 'unit' => (string)$l['unit'], 'note' => (string)($l['note'] ?? ''), 'serials' => $l['move_id'] ? ($sn[(int)$l['move_id']] ?? []) : []], $L);
        $out['totals'] = array_map(fn($u, $q) => ['unit' => (string)$u, 'qty' => (float)$q], array_keys(dn_qty_totals($L)), array_values(dn_qty_totals($L)));
        return $out;
    }
    public static function decide(int $id, array $p): array {
        self::mine($id);
        try { $state = dn_user_decide($id, (string)($p['decision'] ?? ''), (string)($p['remark'] ?? ''), (string)($p['signature'] ?? ''), 'app'); }
        catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['status' => 'accepted', 'state' => $state, 'note' => self::one($id)];
    }
    public static function pdf(int $id): void {
        $d = self::mine($id); $b = dn_pdf($d);
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$d['dn_no']) . '.pdf"'); header('Content-Length: ' . strlen($b)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); echo $b; exit;
    }
}
