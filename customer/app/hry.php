<?php
/** HR › The HR year (GAS-CP-R97-122) — one calendar for everybody:
 *  Jan  targets set · entitlements opened      Feb  training plan published      Apr / Jul / Oct  quarterly target review (Jul: summer leave groups)
 *  Nov  targets closed · managers draft the evaluations      Dec  HR reviews · company closures assigned      31 Jan  final evaluations acknowledged.
 *  Each step is measured from what is already in the portal (targets, entitlements, trainings, leave periods, evaluations); HR can also tick a
 *  step as done (or not applicable) by hand. A step not done after its date is late: shown on the HR dashboard, notified to the HR managers and
 *  listed in a weekly e-mail. The e-mail and the notifications carry counts only — never names or any personal data.
 *  Table: hr_year_steps (the ticks). Settings: hry_day, hry_weekly, hry_weekly_on. */
declare(strict_types=1);

/** key => [month of the cycle year (13 = January of the next year), language key, page to act on] — in calendar order. */
const HRY_STEPS = [
    'targets_set'   => [1,  'hr_targets'],
    'entitlements'  => [1,  'admin_hr'],
    'training_plan' => [2,  'hr_training'],
    'q1_review'     => [4,  'hr_targets'],
    'q2_review'     => [7,  'hr_targets'],
    'summer_groups' => [7,  'hr_leaves'],
    'q3_review'     => [10, 'hr_targets'],
    'targets_closed'=> [11, 'hr_targets'],
    'eval_drafts'   => [11, 'hr_eval'],
    'hr_review'     => [12, 'hr_eval'],
    'closures'      => [12, 'hr_leaves'],
    'eval_ack'      => [13, 'hr_eval'],
];
function hry_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('hr2_has') && hr2_has() && (bool) scalar("SHOW TABLES LIKE 'hr_year_steps'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hry_tbl(string $t): bool { static $c = []; if (!isset($c[$t])) { try { $c[$t] = (bool) scalar('SHOW TABLES LIKE ' . db()->quote($t)); } catch (Throwable $e) { $c[$t] = false; } } return $c[$t]; }
/** First and last day of a step's month. */
function hry_window(int $year, int $month): array { $y = $month > 12 ? $year + 1 : $year; $m = $month > 12 ? $month - 12 : $month; $from = sprintf('%04d-%02d-01', $y, $m); return [$from, date('Y-m-t', strtotime($from))]; }
/** The employees the cycle counts: in the registry, not contractors, hired on or before $asOf and not gone before it. */
function hry_staff(string $asOf): array {
    $o = []; foreach (hr_people(false) as $p) {
        if (function_exists('hr_is_contractor') && hr_is_contractor($p)) continue;
        if (!empty($p['hire_date']) && $p['hire_date'] > $asOf) continue;
        if (!$p['is_active'] && (empty($p['left_date']) || $p['left_date'] <= $asOf)) continue;
        $o[(int)$p['id']] = (string)$p['name_en'];
    }
    return $o;
}
/** What the portal holds for one step: ['n', 'of' (null = no denominator), 'missing' (names, dashboard only), 'auto' (complete by the figures)]. */
function hry_measure(int $year, string $key): array {
    [$month] = HRY_STEPS[$key]; [$from, $due] = hry_window($year, $month); $asOf = date('Y-m-d');   // measured against the people of today: a step still open has to cover them
    $staff = hry_staff($asOf); $ids = array_keys($staff); $N = count($ids);
    $miss = fn(array $have) => array_values(array_diff_key($staff, array_flip(array_map('intval', $have))));
    $out = ['n' => 0, 'of' => null, 'missing' => [], 'auto' => false, 'note' => '', 'unit' => ''];
    try {
        switch ($key) {
            case 'targets_set': {
                if (!hry_tbl('hr_targets')) break;
                $co = (int) scalar("SELECT COUNT(*) FROM hr_targets WHERE year = ? AND scope = 'company' AND status <> 'cancelled'", [$year]);
                $dp = (int) scalar("SELECT COUNT(DISTINCT department_id) FROM hr_targets WHERE year = ? AND scope = 'department' AND status <> 'cancelled'", [$year]);
                $have = array_column(all("SELECT DISTINCT person_id FROM hr_targets WHERE year = ? AND scope = 'person' AND status <> 'cancelled' AND person_id IS NOT NULL", [$year]), 'person_id');
                $have = array_values(array_intersect(array_map('intval', $have), $ids));
                $out = ['n' => count($have), 'of' => $N, 'missing' => $miss($have), 'auto' => $co > 0 && $N > 0 && count($have) >= $N, 'note' => t('hry.m_targets', ['c' => $co, 'd' => $dp]), 'unit' => t('hry.u_ptargets')]; break;
            }
            case 'entitlements': {
                $ann = function_exists('hr_annual_type_id') ? hr_annual_type_id() : null; if (!$ann) break;
                $have = array_values(array_intersect(array_map('intval', array_column(all('SELECT DISTINCT COALESCE(e.person_id, (SELECT p.id FROM hr_people p WHERE p.user_id = e.user_id LIMIT 1)) AS person_id FROM hr_entitlements e WHERE e.year = ? AND e.type_id = ?', [$year, $ann]), 'person_id')), $ids));
                $out = ['n' => count($have), 'of' => $N, 'missing' => $miss($have), 'auto' => $N > 0 && count($have) >= $N, 'note' => '', 'unit' => t('hry.u_people')]; break;
            }
            case 'training_plan': {
                if (!hry_tbl('hr_trainings')) break;
                $n = (int) scalar("SELECT COUNT(*) FROM hr_trainings WHERE status <> 'cancelled' AND date_from BETWEEN ? AND ?", [$year . '-01-01', $year . '-12-31']);
                $out = ['n' => $n, 'of' => null, 'missing' => [], 'auto' => false, 'note' => t('hry.m_trainings', ['n' => $n])]; break;   // "published" is a decision of HR — ticked by hand
            }
            case 'q1_review': case 'q2_review': case 'q3_review': {
                if (!hry_tbl('hr_targets')) break;
                $qEnd = date('Y-m-t', strtotime($from . ' +2 months'));   // a review written anywhere in the quarter counts
                $tot = (int) scalar("SELECT COUNT(*) FROM hr_targets WHERE year = ? AND status <> 'cancelled' AND created_at <= ?", [$year, $due . ' 23:59:59']);
                $rev = (int) scalar("SELECT COUNT(DISTINCT g.id) FROM hr_targets g JOIN hr_target_log l ON l.target_id = g.id WHERE g.year = ? AND g.status <> 'cancelled' AND g.created_at <= ? AND l.created_at BETWEEN ? AND ?", [$year, $due . ' 23:59:59', $from . ' 00:00:00', $qEnd . ' 23:59:59']);
                $out = ['n' => $rev, 'of' => $tot ?: null, 'missing' => [], 'auto' => $tot > 0 && $rev >= $tot, 'note' => $tot ? '' : t('hry.m_notargets'), 'unit' => t('hry.u_reviewed')]; break;
            }
            case 'summer_groups': {
                if (!hry_tbl('hr_leave_periods')) break;
                $n = (int) scalar('SELECT COUNT(*) FROM hr_leave_periods WHERE date_from <= ? AND date_to >= ?', [$year . '-09-15', $year . '-06-15']);
                $out = ['n' => $n, 'of' => null, 'missing' => [], 'auto' => $n > 0, 'note' => t('hry.m_periods', ['n' => $n])]; break;
            }
            case 'targets_closed': {
                if (!hry_tbl('hr_targets')) break;
                $tot = (int) scalar('SELECT COUNT(*) FROM hr_targets WHERE year = ?', [$year]);
                $cl = (int) scalar("SELECT COUNT(*) FROM hr_targets WHERE year = ? AND status IN ('achieved','missed','cancelled')", [$year]);
                $out = ['n' => $cl, 'of' => $tot ?: null, 'missing' => [], 'auto' => $tot > 0 && $cl >= $tot, 'note' => $tot ? '' : t('hry.m_notargets'), 'unit' => t('hry.u_closed')]; break;
            }
            case 'eval_drafts': case 'hr_review': case 'eval_ack': {
                if (!hry_tbl('hr_evaluations')) break;
                $st = $key === 'eval_drafts' ? "('draft','final','acknowledged')" : ($key === 'hr_review' ? "('final','acknowledged')" : "('acknowledged')");
                $have = array_values(array_intersect(array_map('intval', array_column(all("SELECT person_id FROM hr_evaluations WHERE year = ? AND status IN $st", [$year]), 'person_id')), $ids));
                $out = ['n' => count($have), 'of' => $N, 'missing' => $miss($have), 'auto' => $N > 0 && count($have) >= $N, 'note' => '', 'unit' => t('hry.u_people')]; break;
            }
            case 'closures': {
                if (!hry_tbl('hr_leave_periods')) break;
                $n = (int) scalar('SELECT COUNT(*) FROM hr_leave_periods WHERE mandatory = 1 AND date_from <= ? AND date_to >= ?', [($year + 1) . '-01-10', $year . '-12-15']);
                $ap = (int) scalar("SELECT COUNT(*) FROM hr_leave_periods WHERE mandatory = 1 AND status = 'applied' AND date_from <= ? AND date_to >= ?", [($year + 1) . '-01-10', $year . '-12-15']);
                $out = ['n' => $ap, 'of' => null, 'missing' => [], 'auto' => $n > 0 && $ap >= $n, 'note' => t('hry.m_closures', ['n' => $n, 'a' => $ap])]; break;
            }
        }
    } catch (Throwable $e) { $out['note'] = ''; }
    return $out + ['unit' => ''];
}
function hry_ticks(int $year): array { $o = []; if (hry_has()) foreach (all('SELECT * FROM hr_year_steps WHERE year = ?', [$year]) as $r) $o[$r['step_key']] = $r; return $o; }
/** The twelve steps of a cycle with their state: done · late (days) · due (this month) · soon (next month) · later. */
function hry_cycle(int $year): array {
    static $c = []; if (isset($c[$year])) return $c[$year];
    $today = date('Y-m-d'); $ticks = hry_ticks($year); $out = [];
    foreach (HRY_STEPS as $key => [$month, $page]) {
        [$from, $due] = hry_window($year, $month); $m = hry_measure($year, $key); $tk = $ticks[$key] ?? null;
        $done = $tk !== null || $m['auto'];
        $state = $done ? 'done' : ($today > $due ? 'late' : ($today >= $from ? 'due' : ($today >= date('Y-m-d', strtotime($from . ' -1 month')) ? 'soon' : 'later')));
        $out[$key] = ['key' => $key, 'year' => $year, 'month' => $month, 'from' => $from, 'due' => $due, 'page' => $page, 'label' => t('hry.s_' . $key), 'state' => $state, 'tick' => $tk, 'm' => $m,
                      'late_days' => $state === 'late' ? (int) floor((strtotime($today) - strtotime($due)) / 86400) : 0];
    }
    return $c[$year] = $out;
}
/** What HR has to look at now: late steps of this cycle and of the last one (its final step falls on 31 January), and the steps of this month. */
function hry_attention(): array {
    if (!hry_has()) return ['late' => [], 'due' => [], 'soon' => []]; $y = (int) date('Y'); $late = []; $due = []; $soon = [];
    foreach ([$y - 1, $y] as $yy) {
        if ($yy < $y && !hry_cycle_started($yy)) continue;   // a past year the company never ran in the portal is not reported as twelve delays
        foreach (hry_cycle($yy) as $s) { if ($s['state'] === 'late') $late[] = $s; elseif ($s['state'] === 'due') $due[] = $s; elseif ($s['state'] === 'soon') $soon[] = $s; }
    }
    return ['late' => $late, 'due' => $due, 'soon' => $soon];
}
/** A cycle counts as started once anything of that year exists: a tick, a target or an evaluation. */
function hry_cycle_started(int $year): bool {
    try {
        if (scalar('SELECT 1 FROM hr_year_steps WHERE year = ? LIMIT 1', [$year])) return true;
        if (hry_tbl('hr_targets') && scalar('SELECT 1 FROM hr_targets WHERE year = ? LIMIT 1', [$year])) return true;
        if (hry_tbl('hr_evaluations') && scalar('SELECT 1 FROM hr_evaluations WHERE year = ? LIMIT 1', [$year])) return true;
    } catch (Throwable $e) {}
    return false;
}
function hry_figure(array $s): string { $m = $s['m']; $f = $m['of'] !== null ? $m['n'] . ' / ' . $m['of'] . (($m['unit'] ?? '') !== '' ? ' ' . $m['unit'] : '') : ''; return trim($f . ($m['note'] !== '' ? ($f !== '' ? ' · ' : '') . $m['note'] : '')); }
function hry_month_label(int $month): string { return $month > 12 ? '31 ' . t('hry.mon_1') : t('hry.mon_' . $month); }
function hry_step_url(array $s): string { return url($s['page'], $s['page'] === 'admin_hr' ? ['tab' => 'ent', 'year' => $s['year']] : ['year' => $s['year']]); }

/* ------------------------------------------------------------------ ticks (HR) */
function hry_tick_from_post(): void {
    if (!hry_has() || !function_exists('hr_can') || !hr_can()) throw new RuntimeException(t('err.noaccess'));
    $year = (int)($_POST['year'] ?? 0); $key = (string)($_POST['step'] ?? ''); if ($year < 2020 || $year > 2100 || !isset(HRY_STEPS[$key])) throw new RuntimeException(t('err.noaccess'));
    if (!empty($_POST['undo'])) { q('DELETE FROM hr_year_steps WHERE year = ? AND step_key = ?', [$year, $key]); return; }
    $u = current_user(); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null;
    q('REPLACE INTO hr_year_steps (year, step_key, done_at, done_by, done_name, note) VALUES (?,?,NOW(),?,?,?)', [$year, $key, (int)$u['id'], (string)$u['full_name'], $note]);
}

/* ------------------------------------------------------------------ warnings: notifications and the weekly e-mail */
/** The HR managers: active accounts whose staff role has the HR scope; when there is none, the Owner / CEO. */
function hry_recipients(): array {
    try {
        $r = all("SELECT cu.id, cu.email, cu.full_name FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND r.scope = 'hr'");
        if (!$r) $r = all("SELECT cu.id, cu.email, cu.full_name FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (r.scope = 'owner' OR LOWER(TRIM(r.name)) = 'ceo')");
        return $r;
    } catch (Throwable $e) { return []; }
}
/** Subject and text of the weekly e-mail, or null when nothing is late and nothing is due this month. Counts only. */
function hry_weekly_build(): ?array {
    $A = hry_attention(); if (!$A['late'] && !$A['due']) return null;
    $base = function_exists('nl_base') ? nl_base() : ''; $link = ($base !== '' ? $base . '/' : '') . url('hr_dashboard') . '#hr-year';
    $line = fn(array $s) => '  • ' . hry_month_label($s['month']) . ' ' . ($s['month'] > 12 ? $s['year'] + 1 : $s['year']) . ' — ' . $s['label'] . (hry_figure($s) !== '' ? ' — ' . hry_figure($s) : '');
    $L = [t('hry.wk_intro'), ''];
    if ($A['late']) { $L[] = mb_strtoupper(t('hry.wk_late')) . ' — ' . count($A['late']); foreach ($A['late'] as $s) $L[] = $line($s) . ' — ' . t('hry.late_days', ['n' => $s['late_days']]); $L[] = ''; }
    if ($A['due'])  { $L[] = mb_strtoupper(t('hry.wk_due')) . ' — ' . count($A['due']); foreach ($A['due'] as $s) $L[] = $line($s) . ' — ' . t('hry.due_by', ['d' => fmt_date($s['due'])]); $L[] = ''; }
    if ($A['soon']) { $L[] = mb_strtoupper(t('hry.wk_soon')); foreach ($A['soon'] as $s) $L[] = $line($s); $L[] = ''; }
    $L[] = $link; $L[] = ''; $L[] = t('hry.wk_foot');
    return [t('hry.wk_subject', ['a' => count($A['late']), 'b' => count($A['due'])]), implode("\n", $L), count($A['late']), count($A['due'])];
}
/** Sent once a week (the first house-keeping run of the ISO week); $force sends now. Returns a short log text. */
function hry_weekly(bool $force = false): string {
    if (!hry_has()) return 'hr year off'; $wk = date('o-W');
    if (!$force && cp_setting('hry_weekly', '') === $wk) return 'hr year mail done';
    if (!$force && cp_setting('hry_weekly_on', '1') !== '1') return 'hr year mail off';
    cp_setting_set('hry_weekly', $wk); $m = hry_weekly_build(); if (!$m) return 'hr year mail: nothing late or due';
    $n = 0; $to = hry_recipients();
    foreach ($to as $r) { try { if ((string)$r['email'] !== '' && function_exists('cp_mail') && cp_mail((string)$r['email'], $m[0], $m[1])) $n++; } catch (Throwable $e) { error_log('[hry_weekly] ' . $e->getMessage()); } }
    return 'hr year mail ' . $n . '/' . count($to) . ' (' . $m[2] . ' late, ' . $m[3] . ' due)';
}
/** Daily: one notification when a step enters its month, one when it becomes late and then once a week while it stays late; the weekly e-mail. */
function hry_sweep(): int {
    if (!hry_has() || !function_exists('cp_setting')) return 0; $day = date('Y-m-d'); if (cp_setting('hry_day', '') === $day) return 0; cp_setting_set('hry_day', $day);
    $A = hry_attention(); $to = array_map(fn($r) => (int)$r['id'], hry_recipients()); $n = 0; $wk = date('o-W'); $u = url('hr_dashboard') . '#hr-year';
    if ($to && function_exists('nf_push')) {
        if ($A['late']) { try {   // one notification a week for everything that is late — not one per step
            $one = count($A['late']) === 1 ? $A['late'][0] : null;
            $n += nf_push($to, 'hry', 'hry:late:' . $wk . ':' . count($A['late']), $one ? t('hry.nf_late', ['s' => $one['label'], 'n' => $one['late_days']]) : t('hry.nf_late_n', ['n' => count($A['late'])]), mb_substr(implode(' · ', array_map(fn($s) => $s['label'], $A['late'])), 0, 380), $u, null, null);
        } catch (Throwable $e) {} }
        foreach ($A['due'] as $s)  { try { $n += nf_push($to, 'hry', 'hry:' . $s['year'] . ':' . $s['key'] . ':due', t('hry.nf_due', ['s' => $s['label'], 'd' => fmt_date($s['due'])]), hry_figure($s) ?: null, $u, null, null); } catch (Throwable $e) {} }
    }
    try { hry_weekly(); } catch (Throwable $e) { error_log('[hry] ' . $e->getMessage()); }
    return $n;
}

/* ------------------------------------------------------------------ the picture */
/** The calendar of the cycle as a card: month line on top, the steps under it. $manage = HR (figures, who is missing, ticks); otherwise the plain calendar. */
function hry_card_html(int $year, bool $manage, string $back = ''): string {
    if (!hry_has()) return ''; $C = hry_cycle($year); $byM = []; foreach ($C as $s) $byM[$s['month']][] = $s;
    $tone = ['done' => 'green', 'late' => 'red', 'due' => 'gold', 'soon' => 'navy', 'later' => 'mute'];
    $rank = ['late' => 4, 'due' => 3, 'soon' => 2, 'later' => 1, 'done' => 0]; $nowM = (int) date('Y') === $year ? (int) date('n') : ((int) date('Y') === $year + 1 && (int) date('n') === 1 ? 13 : 0);
    $h = '<div class="card" id="hr-year"><h2>🗓 ' . e(t('hry.title')) . ' ' . $year . '</h2><p class="hint">' . e(t($manage ? 'hry.lede_hr' : 'hry.lede')) . '</p>';
    $h .= '<div class="hry-line">';
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13] as $m) {
        $st = 'none'; foreach ($byM[$m] ?? [] as $s) { $x = $manage ? $s['state'] : ($m === $nowM ? 'due' : 'later'); if ($st === 'none' || $rank[$x] > $rank[$st]) $st = $x; }
        $h .= '<div class="hry-m hry-' . $st . ($m === $nowM ? ' is-now' : '') . '"><i></i><b>' . e(hry_month_label($m)) . '</b></div>';
    }
    $h .= '</div><div class="table-scroll"><table class="tbl hry-tbl" data-nosort><tbody>';
    foreach ($C as $s) {
        $st = $manage ? $s['state'] : ($s['month'] === $nowM ? 'due' : 'later'); $fig = $manage ? hry_figure($s) : '';
        $h .= '<tr class="hry-r-' . $st . '"><td class="hry-when"><b>' . e(hry_month_label($s['month'])) . '</b></td><td>' . ($manage ? '<a href="' . e(hry_step_url($s)) . '">' . e($s['label']) . '</a>' : e($s['label']));
        if ($manage && $fig !== '') $h .= '<br><small class="hint">' . e($fig) . '</small>';
        if ($manage && $s['state'] !== 'done' && $s['m']['missing']) { $ms = $s['m']['missing']; $h .= '<details><summary class="hint">' . e(t('hry.missing', ['n' => count($ms)])) . '</summary><small class="hint">' . e(implode(', ', array_slice($ms, 0, 60))) . (count($ms) > 60 ? ' …' : '') . '</small></details>'; }
        if ($manage && $s['tick']) $h .= '<br><small class="hint">' . e(t('hry.ticked', ['u' => (string)$s['tick']['done_name'], 'd' => fmt_date(substr((string)$s['tick']['done_at'], 0, 10))])) . ($s['tick']['note'] ? ' — ' . e((string)$s['tick']['note']) : '') . '</small>';
        $h .= '</td>';
        if ($manage) {
            $h .= '<td>' . pill($tone[$s['state']], $s['state'] === 'late' ? t('hry.late_days', ['n' => $s['late_days']]) : ($s['state'] === 'due' ? t('hry.due_by', ['d' => fmt_date($s['due'])]) : t('hry.st_' . $s['state']))) . '</td><td class="row-actions">';
            $f = '<form method="post" action="' . e($back) . '" class="inline" data-lock>' . csrf_field() . '<input type="hidden" name="action" value="hry_tick"><input type="hidden" name="year" value="' . $year . '"><input type="hidden" name="step" value="' . e($s['key']) . '">';
            if ($s['tick']) $h .= $f . '<input type="hidden" name="undo" value="1"><button class="btn btn--ghost btn--sm" type="submit">' . e(t('hry.untick')) . '</button></form>';
            elseif (!$s['m']['auto']) $h .= $f . '<input name="note" maxlength="300" placeholder="' . e(t('hry.note_ph')) . '" style="max-width:190px;"> <button class="btn btn--sm" type="submit">✓ ' . e(t('hry.tick')) . '</button></form>';
            $h .= '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    if ($manage) {
        $on = cp_setting('hry_weekly_on', '1') === '1'; $to = hry_recipients();
        $h .= '<p class="hint" style="margin-top:8px;">' . e(t('hry.mail_to', ['n' => count($to)])) . ' ' . e(implode(', ', array_map(fn($r) => (string)$r['full_name'], array_slice($to, 0, 6)))) . '. ' . e(t($on ? 'hry.mail_on' : 'hry.mail_off')) . '</p><div class="btn-row">'
            . '<form method="post" action="' . e($back) . '" class="inline" data-lock>' . csrf_field() . '<input type="hidden" name="action" value="hry_mail"><input type="hidden" name="set" value="' . ($on ? '0' : '1') . '"><button class="btn btn--ghost btn--sm" type="submit">' . e(t($on ? 'hry.mail_stop' : 'hry.mail_start')) . '</button></form>'
            . '<form method="post" action="' . e($back) . '" class="inline" data-lock>' . csrf_field() . '<input type="hidden" name="action" value="hry_mail"><input type="hidden" name="now" value="1"><button class="btn btn--ghost btn--sm" type="submit">✉ ' . e(t('hry.mail_now')) . '</button></form></div>';
    }
    return $h . '</div>';
}

/* ------------------------------------------------------------------ actions */
function hry_handle_action(string $action): bool {
    if (!str_starts_with($action, 'hry_')) return false;
    if (!hry_has() || !function_exists('hr_can') || !hr_can()) throw new RuntimeException(t('err.noaccess'));
    $year = (int)($_POST['year'] ?? date('Y'));
    switch ($action) {
        case 'hry_tick': hry_tick_from_post(); flash('good', t('hry.saved')); redirect('hr_dashboard', ['year' => $year, '_r' => uniqid()], '#hr-year');
        case 'hry_mail': {
            if (!empty($_POST['now'])) { $m = hry_weekly_build(); if (!$m) { flash('warn', t('hry.mail_nothing')); } else { $r = hry_weekly(true); flash('good', t('hry.mail_sent') . ' (' . $r . ')'); } }
            else { cp_setting_set('hry_weekly_on', !empty($_POST['set']) ? '1' : '0'); flash('good', t('hry.saved')); }
            redirect('hr_dashboard', ['_r' => uniqid()], '#hr-year');
        }
    }
    throw new RuntimeException(t('err.noaccess'));
}
