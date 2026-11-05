<?php
/** Part ordering from the apps (Service Engineer · Golden PM · Golden HR) — GAS-CP-R82-003.
 *  Read: my requisitions (and those of my projects for a PM, every open one for procurement), one requisition with lines and log, options (projects · sub-projects · purposes).
 *  Write: create (+ lines, + submit), cancel. Everything goes through app/prq.php, so the rules (who may request, budget check, reservation, notifications) are the portal's. */
declare(strict_types=1);

final class PortalParts {
    public static function can(): bool { return function_exists('prq_can') && prq_can(); }
    public static function requireCan(): void { if (!self::can()) throw new ApiException(403, 'Requisitions are not enabled for this account'); }
    private static function row(array $r, bool $priced): array {
        return ['id' => (int)$r['id'], 'pr_no' => $r['pr_no'], 'title' => $r['title'], 'status' => $r['status'], 'purpose' => $r['purpose'], 'project_id' => (int)$r['project_id'], 'project' => trim((string)($r['project_code'] ?? '') . ' ' . (string)($r['project_name'] ?? '')), 'subproject_id' => $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'subproject' => $r['sub_code'] ? trim($r['sub_code'] . ' ' . ($r['sub_name'] ?? '')) : null,
                'need_date' => $r['need_date'], 'n_lines' => (int)$r['n_lines'], 'n_to_buy' => (int)$r['n_to_buy'], 'estimate' => $priced && $r['est_total'] !== null ? (float)$r['est_total'] : null, 'currency' => $r['currency'], 'over_budget' => (bool)$r['over_budget'], 'requester' => $r['requester_name'], 'mine' => (int)$r['requester_id'] === (int)(current_user()['id'] ?? 0), 'submitted_at' => $r['submitted_at'], 'updated_at' => $r['updated_at'], 'url' => url('requisition', ['id' => (int)$r['id']])];
    }
    public static function list(string $status = 'open'): array {
        self::requireCan(); $priced = function_exists('stk_price_visible') && stk_price_visible();
        $rows = prq_list(['status' => $status === 'all' ? '' : $status]);
        return ['items' => array_map(fn($r) => self::row($r, $priced), array_slice($rows, 0, 200)), 'can_approve' => function_exists('pr_admin') && pr_admin(), 'priced' => $priced];
    }
    public static function one(int $id): array {
        self::requireCan(); $r = prq_load($id); if (!$r || !prq_can_view($r)) throw new ApiException(404, 'Not found'); $priced = function_exists('stk_price_visible') && stk_price_visible();
        $out = self::row($r, $priced); $out['note'] = $r['note']; $out['approval_note'] = $r['approval_note']; $out['mgmt_note'] = $r['mgmt_note']; $out['approved_name'] = $r['approved_name']; $out['mgmt_name'] = $r['mgmt_name'];
        $out['can_cancel'] = in_array($r['status'], ['draft', 'submitted'], true) && (int)$r['requester_id'] === (int)current_user()['id'];
        $out['lines'] = array_map(fn($l) => ['id' => (int)$l['id'], 'seq' => (int)$l['seq'], 'item_id' => $l['item_id'] ? (int)$l['item_id'] : null, 'code' => $l['item_code'], 'description' => $l['description'], 'maker' => $l['maker'], 'part_no' => $l['part_no'], 'unit' => $l['unit'], 'qty' => (float)$l['qty'], 'reserved' => (float)$l['qty_reserved'], 'ordered' => (float)$l['qty_ordered'], 'received' => (float)$l['qty_received'], 'issued' => (float)$l['qty_issued'], 'status' => $l['status'], 'need_date' => $l['need_date'], 'note' => $l['note'], 'est_price' => $priced && $l['est_price'] !== null ? (float)$l['est_price'] : null], prq_lines($id));
        $out['events'] = array_map(fn($e) => ['at' => $e['created_at'], 'who' => $e['user_name'], 'event' => $e['event'], 'note' => $e['note']], prq_events($id));
        return $out;
    }
    public static function options(): array {
        self::requireCan();
        $projects = all("SELECT id, project_code, name, pm_user_id FROM projects WHERE status IN ('active','quoted','onhold','planned') ORDER BY project_code DESC LIMIT 300");
        $subs = all("SELECT sp.id, sp.project_id, sp.code, sp.name FROM subprojects sp JOIN projects p ON p.id = sp.project_id WHERE sp.status IN ('active','quoted','onhold') AND p.status IN ('active','quoted','onhold','planned') ORDER BY sp.sort_order, sp.code LIMIT 1500");
        return ['projects' => array_map(fn($p) => ['id' => (int)$p['id'], 'code' => $p['project_code'], 'name' => $p['name']], $projects), 'subprojects' => array_map(fn($s) => ['id' => (int)$s['id'], 'project_id' => (int)$s['project_id'], 'code' => $s['code'], 'name' => $s['name']], $subs), 'purposes' => PRQ_PURPOSE, 'units' => STK_UNITS];
    }
    /** Create a requisition with its lines in one call; submit at once unless draft = true. */
    public static function create(array $b): array {
        self::requireCan(); $lines = (array)($b['lines'] ?? []); if (!$lines) throw new ApiException(422, 'No lines');
        $_POST = ['project_id' => (string)(int)($b['project_id'] ?? 0), 'subproject_id' => (string)(int)($b['subproject_id'] ?? 0), 'purpose' => (string)($b['purpose'] ?? 'project'), 'title' => (string)($b['title'] ?? ''), 'need_date' => (string)($b['need_date'] ?? ''), 'note' => (string)($b['note'] ?? '')];
        try { $rid = prq_save_from_post(); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        $n = 0;
        foreach (array_slice($lines, 0, 60) as $l) { $_POST = ['request_id' => (string)$rid, 'item_id' => (string)(int)($l['item_id'] ?? 0), 'description' => (string)($l['description'] ?? ''), 'maker' => (string)($l['maker'] ?? ''), 'part_no' => (string)($l['part_no'] ?? ''), 'unit' => (string)($l['unit'] ?? 'pcs'), 'qty' => (string)($l['qty'] ?? '1'), 'est_price' => isset($l['est_price']) ? (string)$l['est_price'] : '', 'need_date' => (string)($l['need_date'] ?? ''), 'note' => (string)($l['note'] ?? '')];
            try { prq_line_save_from_post(); $n++; } catch (RuntimeException $e) { throw new ApiException(422, 'Line ' . ($n + 1) . ': ' . $e->getMessage()); } }
        if (empty($b['draft'])) { try { prq_submit($rid); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } }
        $_POST = [];
        return self::one($rid);
    }
    public static function cancel(int $id, string $note): array { self::requireCan(); try { prq_cancel($id, $note); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return self::one($id); }
    /** Procurement in the apps: approve / reject a submitted requisition (same rules as the portal). */
    public static function decide(int $id, string $decision, string $note): array { if (!function_exists('pr_admin') || !pr_admin()) throw new ApiException(403, 'Procurement only'); try { prq_decide($id, $decision === 'reject' ? 'reject' : 'approve', $note); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return self::one($id); }
}
