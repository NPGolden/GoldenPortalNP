<?php
/** «My tools» in the Service, PM and Employee apps — GAS-CP-R97-291.
 *  What the signed-in person holds (equipment lines with the calibration of instruments), issue lists to answer line by line, tools handed to him on a project,
 *  and — for a supervisor or project manager — the tools on his projects with hand-out and take-back. Rules live in app/hre.php, app/eqp.php, app/ptl.php.
 *  No prices; a person reads only the own record, a supervisor only the own projects. */
declare(strict_types=1);

final class PortalTools {
    private static function guard(callable $fn) { try { return $fn(); } catch (ApiException $e) { throw $e; } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } }
    private static function lang(): string { $l = strtolower(substr((string)($_GET['lang'] ?? 'en'), 0, 2)); return in_array($l, ['en', 'el', 'it', 'bg', 'uk', 'ka'], true) ? $l : 'en'; }
    private static function tr(string $k): string { return function_exists('t_in') ? (string) t_in($k, self::lang()) : t($k); }
    private static function cal(?array $c, $until): ?array { return $c ? ['state' => $c[0], 'tone' => $c[1], 'label' => self::tr('qh.cal_' . $c[0]), 'until' => $c[0] === 'na' ? null : ($until ?: null)] : null; }
    private static function unit(array $u): array {
        $f = ptl_flag($u); return ['id' => (int)$u['id'], 'no' => (string)($u['asset_tag'] ?: $u['serial_no']), 'description' => (string)$u['description'], 'maker' => trim((string)$u['maker'] . ' ' . (string)$u['factory_code']), 'flag' => $f,
                'with' => (string)($u['d_person'] ?? ''), 'since' => $u['d_given_at'] ?: null, 'supervisor' => (string)($u['site_user'] ?? ''), 'due' => $u['site_due'] ?: null, 'can' => ptl_can_site($u), 'wait' => ptl_waits($u)];
    }
    public static function mine(): array {
        if (!function_exists('hre_has') || !hre_has()) return ['enabled' => false, 'lists' => [], 'items' => [], 'handed' => [], 'site' => [], 'people' => []];
        $u = current_user(); $P = hre_my_person(); $lists = []; $items = []; $handed = []; $site = []; $people = [];
        if ($P) { $pid = (int)$P['id']; $units = []; if (function_exists('eqp_of_person')) foreach (eqp_of_person($pid) as $x) if ($x['eq_item_id']) $units[(int)$x['eq_item_id']] = $x;
            foreach (hre_docs($pid) as $d) { if ($d['kind'] !== 'issue' || !in_array($d['status'], ['sent', 'disputed'], true)) continue; $L = [];
                foreach (hre_doc_items($d) as $i) if ($i['status'] === 'pending') $L[] = ['id' => (int)$i['id'], 'category' => hre_cat_icon((string)$i['category']) . ' ' . self::tr('hre.c_' . (in_array($i['category'], HRE_CATS, true) ? $i['category'] : 'other')), 'description' => (string)$i['description'], 'serial' => (string)$i['serial_no'], 'tag' => (string)$i['asset_tag'], 'qty' => (float)$i['qty'], 'cond' => self::tr('hre.k_' . $i['cond_out']), 'note' => (string)$i['note']];
                if ($L) $lists[] = ['id' => (int)$d['id'], 'no' => (string)$d['doc_no'], 'date' => (string)$d['doc_date'], 'by' => (string)$d['created_name'], 'note' => (string)$d['note'], 'lines' => $L]; }
            foreach (hre_items($pid, HRE_WITH) as $i) { $c = null; $until = null; $x = $units[(int)$i['id']] ?? null;
                if ($x && function_exists('eqp_cal')) { $c = eqp_cal($x); $until = $x['ism_until']; }
                elseif (!empty($i['src_id']) && function_exists('ism_get') && ($r = ism_get((int)$i['src_id'])) && $r['reg'] === 'qequip') { $c = ism_cal_state($r); $until = $r['review_date']; }
                $items[] = ['id' => (int)$i['id'], 'category' => hre_cat_icon((string)$i['category']) . ' ' . self::tr('hre.c_' . (in_array($i['category'], HRE_CATS, true) ? $i['category'] : 'other')), 'description' => (string)$i['description'], 'serial' => (string)$i['serial_no'], 'tag' => (string)$i['asset_tag'], 'qty' => (float)$i['qty'], 'status' => (string)$i['status'], 'status_label' => self::tr('hre.st_' . $i['status']), 'issued_on' => $i['issued_on'] ?: null, 'cal' => self::cal($c, $until)]; }
            if (function_exists('ptl_with_person')) foreach (ptl_with_person($pid) as $x) $handed[] = ['id' => (int)$x['daily_id'], 'wait' => ptl_waits($x), 'no' => (string)($x['asset_tag'] ?: $x['serial_no']), 'description' => (string)$x['description'], 'project' => trim((string)$x['project_code'] . ' ' . (string)$x['project_name']), 'since' => (string)$x['d_given_at'], 'by' => (string)$x['d_given_by']];
        }
        if (function_exists('ptl_has') && ptl_has()) { foreach (ptl_projects(null, (int)($u['id'] ?? 0)) as $pid => $G) $site[] = ['project' => ['id' => $pid, 'code' => $G['p']['code'], 'name' => $G['p']['name'], 'done' => $G['p']['status'] === 'completed'], 'n' => $G['n'], 'units' => array_map([self::class, 'unit'], $G['units'])];
            if ($site) foreach (hr_people(true) as $p) $people[] = ['id' => (int)$p['id'], 'name' => (string)$p['name_en']]; }
        return ['enabled' => true, 'person' => (bool)$P, 'lists' => $lists, 'items' => $items, 'handed' => $handed, 'site' => $site, 'people' => $people];
    }
    /** {agree: true, no: {"<line id>": "<reason>"}} — every other line of the list is accepted. */
    public static function answer(int $docId, array $p): array {
        $no = []; foreach ((array)($p['no'] ?? []) as $k => $v) $no[(int)$k] = (string)$v; $keep = $_POST;
        try { $_POST = ['agree' => !empty($p['agree']) ? 1 : 0]; $r = self::guard(fn() => hre_accept_lines($docId, $no)); } finally { $_POST = $keep; }
        return ['status' => 'accepted', 'accepted' => (int)$r['ok'], 'refused' => (int)$r['no'], 'mine' => self::mine()];
    }
    /** {ok: true|false, note} — the person confirms a tool handed to him, or says it was not received. */
    public static function ack(int $dailyId, array $p): array { if (!function_exists('ptl_ack')) throw new ApiException(404, 'Not found'); self::guard(fn() => ptl_ack($dailyId, !empty($p['ok']), (string)($p['note'] ?? ''), 'app')); return ['status' => 'accepted', 'mine' => self::mine()]; }
    public static function give(int $unitId, array $p): array { if (!function_exists('ptl_has') || !ptl_has()) throw new ApiException(404, 'Not found'); self::guard(fn() => ptl_give($unitId, (int)($p['person_id'] ?? 0), (string)($p['name'] ?? ''))); return ['status' => 'accepted', 'mine' => self::mine()]; }
    public static function back(int $unitId, array $p): array { if (!function_exists('ptl_has') || !ptl_has()) throw new ApiException(404, 'Not found'); self::guard(fn() => ptl_back($unitId, (string)($p['result'] ?? 'ok'), (string)($p['note'] ?? ''))); return ['status' => 'accepted', 'mine' => self::mine()]; }
}
