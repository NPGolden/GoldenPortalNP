<?php
/**
 * HR — process flow of a person: onboarding and handover (leaving).   GAS-CP-R97-125 (v1.189.0)
 *
 * Nothing is typed here: every step is read from what the portal already holds — the employment, the account, the job
 * description and its confirmation, the equipment lists, the checklist "My first weeks", the release of contract.
 * A step is  done · run (started, not finished) · todo · na (does not apply).
 *   hrf_onboarding($p) / hrf_handover($p)  → steps [key, label, state, note, url]
 *   hrf_html($steps)                       → the flow as a row of boxes joined by straight lines
 *   hrf_overview()                         → who is in onboarding / in handover now (HR dashboard)
 */
declare(strict_types=1);

function hrf_step(string $k, string $state, string $note, string $url): array { return ['key' => $k, 'label' => t('hrf.s_' . $k), 'state' => $state, 'note' => $note, 'url' => $url]; }
function hrf_progress(array $steps): array { $a = array_filter($steps, fn($s) => $s['state'] !== 'na'); $d = count(array_filter($a, fn($s) => $s['state'] === 'done')); return ['done' => $d, 'total' => count($a), 'complete' => $a && $d === count($a)]; }

/** Onboarding of a person: card and employment → account → job description → equipment → first weeks. */
function hrf_onboarding(array $p): array {
    $pid = (int)$p['id']; $uid = (int)($p['user_id'] ?? 0); $card = fn(string $tab, string $h = '') => url('hr_person', ['id' => $pid, 'tab' => $tab]) . $h; $S = [];
    $hire = null; try { $hire = scalar("SELECT MIN(date_from) FROM hr_employments WHERE person_id = ? AND kind IN ('hire','rehire')", [$pid]) ?: null; } catch (Throwable $e) {}
    if (!$hire && !empty($p['hire_date'])) $hire = (string)$p['hire_date'];   // cards from before the employment periods carry the date on the card
    $S[] = hrf_step('card', $hire ? 'done' : 'run', $hire ? fmt_date((string)$hire) : t('hrf.n_no_hire'), $card('emp'));
    $S[] = hrf_step('account', $uid ? (!empty($p['account_active']) ? 'done' : 'run') : 'todo', $uid ? (string)($p['account_name'] ?? '') . (empty($p['account_active']) ? ' — ' . t('hrf.n_acc_closed') : '') : t('hrf.n_no_account'), $card('data'));
    $st = 'na'; $note = '';
    if (function_exists('jd_has') && jd_has()) { $jid = (int)($p['jd_id'] ?? 0); $st = 'todo'; $note = t('hrf.n_no_jd');
        if ($jid) { try { $j = one('SELECT code, rev, status FROM hr_jds WHERE id = ?', [$jid]); $ack = $j ? scalar('SELECT MAX(acked_at) FROM hr_jd_acks WHERE jd_id = ? AND person_id = ?', [$jid, $pid]) : null;
            if ($j) { $st = $ack ? 'done' : 'run'; $note = $j['code'] . ' — ' . ($ack ? t('hrf.n_confirmed', ['d' => fmt_date(substr((string)$ack, 0, 10))]) : t('hrf.n_not_confirmed')); } } catch (Throwable $e) {} } }
    $S[] = hrf_step('jd', $st, $note, $card('emp', '#hr-jd'));
    $st = 'na'; $note = '';
    if (function_exists('hre_has') && hre_has()) { try { $c = one("SELECT SUM(status = 'draft') AS d, SUM(status = 'pending') AS p, SUM(status IN ('held','returned','missing','writeoff')) AS h FROM hr_eq_items WHERE person_id = ? AND status <> 'followed'", [$pid]) ?: [];
        $d = (int)($c['d'] ?? 0); $pe = (int)($c['p'] ?? 0); $h = (int)($c['h'] ?? 0);
        if ($d + $pe + $h === 0) { $st = 'todo'; $note = t('hrf.n_no_eq'); } elseif ($d || $pe) { $st = 'run'; $note = trim(($d ? t('hrf.n_eq_draft', ['n' => $d]) . ' ' : '') . ($pe ? t('hrf.n_eq_pending', ['n' => $pe]) : '')); } else { $st = 'done'; $note = t('hrf.n_eq_done', ['n' => $h]); } } catch (Throwable $e) {} }
    $S[] = hrf_step('equipment', $st, $note, $card('eq'));
    $st = 'na'; $note = '';
    if (function_exists('soc_has') && soc_has()) { if (!$uid) { $st = 'na'; $note = t('hrf.n_no_account'); } else { try { $o = one('SELECT done, started_at, finished_at FROM soc_onboard WHERE user_id = ?', [$uid]);
        if (!$o) { $st = 'todo'; $note = t('hrf.n_weeks_no'); } elseif ($o['finished_at']) { $st = 'done'; $note = fmt_date(substr((string)$o['finished_at'], 0, 10)); } else { $st = 'run'; $note = count(array_filter(explode(',', (string)$o['done']))) . ' / ' . (defined('SOC_ONBOARD_ITEMS') ? count(SOC_ONBOARD_ITEMS) : '?'); } } catch (Throwable $e) {} } }
    $S[] = hrf_step('weeks', $st, $note, url('community') . '#onboard');
    return $S;
}
/** Handover of a person who leaves: end recorded → work handed over → equipment handed over → accounts closed → release of contract. */
function hrf_handover(array $p): array {
    $pid = (int)$p['id']; $card = fn(string $tab, string $h = '') => url('hr_person', ['id' => $pid, 'tab' => $tab]) . $h; $S = [];
    $end = null; try { $end = one("SELECT id, date_from, reason FROM hr_employments WHERE person_id = ? AND kind = 'end' ORDER BY date_from DESC, id DESC LIMIT 1", [$pid]) ?: null; } catch (Throwable $e) {}
    $S[] = hrf_step('end', $end ? 'done' : 'todo', $end ? fmt_date((string)$end['date_from']) : t('hrf.n_no_end'), $card('emp'));
    $holds = function_exists('hr_leaver_holds') ? hr_leaver_holds($p) : []; $eqLbl = t('hr.lv_equip'); $admLbl = t('hr.lv_admin');
    $work = array_values(array_filter($holds, fn($h) => $h[0] !== $eqLbl && $h[0] !== $admLbl));
    $S[] = hrf_step('work', $work ? 'run' : 'done', $work ? implode(' · ', array_map(fn($h) => $h[0] . ': ' . $h[1], $work)) : t('hrf.n_nothing'), $card('emp', '#hr-left'));
    $st = 'na'; $note = '';
    if (function_exists('hre_has') && hre_has()) { try { $with = hre_with_count($pid); $open = (int) scalar("SELECT COUNT(*) FROM hr_eq_docs WHERE person_id = ? AND kind = 'return' AND status = 'open'", [$pid]); $ever = (int) scalar("SELECT COUNT(*) FROM hr_eq_items WHERE person_id = ? AND status <> 'draft'", [$pid]);
        if (!$with) { $st = 'done'; $note = $ever ? t('hrf.n_eq_back') : t('hrf.n_eq_never'); } else { $st = $open ? 'run' : 'todo'; $note = t('hrf.n_eq_with', ['n' => $with]) . ($open ? ' — ' . t('hrf.n_eq_list') : ''); } } catch (Throwable $e) {} }
    $S[] = hrf_step('return', $st, $note, $card('eq'));
    $uid = (int)($p['user_id'] ?? 0);
    $S[] = hrf_step('accounts', !$uid ? 'na' : (empty($p['account_active']) ? 'done' : 'todo'), !$uid ? t('hrf.n_no_account') : (empty($p['account_active']) ? t('hrf.n_acc_closed') : t('hrf.n_acc_open')), $card('emp', '#hr-left'));
    $st = 'na'; $note = '';
    if ($end && function_exists('hr_off_has') && hr_off_has()) { $pr = hr_off_progress((int)$end['id']); if ($pr['total']) { $st = $pr['complete'] ? 'done' : ($pr['done'] ? 'run' : 'todo'); $note = $pr['done'] . ' / ' . $pr['total']; } }
    $S[] = hrf_step('release', $st, $note, $card('emp', '#hr-off'));
    return $S;
}
/** The flow drawn: boxes of equal width joined by a straight line; green = done, gold = in progress, grey = to do, dashed = does not apply. */
function hrf_html(array $steps, bool $compact = false): string {
    $col = ['done' => ['#2E7D32', '#E8F3E9', '✓'], 'run' => ['#C9A227', '#FBF4DC', '…'], 'todo' => ['#8A94A0', '#F2F4F6', ''], 'na' => ['#B8C0C8', 'transparent', '–'], 'bad' => ['#B3261E', '#FBE9E7', '!']];
    if ($compact) { $h = '<span style="display:inline-flex;align-items:center;white-space:nowrap;">'; foreach ($steps as $i => $s) { [$c, $bg, $ic] = $col[$s['state']];
        $h .= ($i ? '<span style="display:inline-block;width:14px;height:2px;background:' . ($s['state'] === 'done' ? '#2E7D32' : '#C8CED6') . ';"></span>' : '')
            . '<a href="' . e($s['url']) . '" title="' . e($s['label'] . ' — ' . t('hrf.st_' . $s['state']) . ($s['note'] !== '' ? ' · ' . $s['note'] : '')) . '" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;border:2px ' . ($s['state'] === 'na' ? 'dashed' : 'solid') . ' ' . $c . ';background:' . $bg . ';color:' . $c . ';font-size:12px;font-weight:700;text-decoration:none;">' . ($ic !== '' ? $ic : (string)($i + 1)) . '</a>'; }
        return $h . '</span>'; }
    $n = max(1, count($steps)); $h = '<div class="table-scroll"><div style="display:grid;grid-template-columns:repeat(' . $n . ',minmax(130px,1fr));gap:0;min-width:' . ($n * 130) . 'px;">';
    foreach ($steps as $i => $s) { [$c, $bg, $ic] = $col[$s['state']]; $prev = $i ? $steps[$i - 1]['state'] : null;
        $h .= '<div style="position:relative;padding:0 8px;text-align:center;">'
            . ($i ? '<span style="position:absolute;left:0;right:50%;top:17px;height:2px;background:' . ($s['state'] === 'done' && $prev === 'done' ? '#2E7D32' : '#C8CED6') . ';"></span>' : '')
            . ($i < $n - 1 ? '<span style="position:absolute;left:50%;right:0;top:17px;height:2px;background:' . ($s['state'] === 'done' && $steps[$i + 1]['state'] === 'done' ? '#2E7D32' : '#C8CED6') . ';"></span>' : '')
            . '<a href="' . e($s['url']) . '" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:2px ' . ($s['state'] === 'na' ? 'dashed' : 'solid') . ' ' . $c . ';background:' . ($bg === 'transparent' ? 'var(--cp-card,#fff)' : $bg) . ';color:' . $c . ';font-weight:700;text-decoration:none;">' . ($ic !== '' ? $ic : (string)($i + 1)) . '</a>'
            . '<div style="margin-top:6px;font-weight:600;font-size:13px;"><a href="' . e($s['url']) . '" style="color:inherit;text-decoration:none;">' . e($s['label']) . '</a></div>'
            . '<div class="hint" style="font-size:12px;">' . e(t('hrf.st_' . $s['state'])) . ($s['note'] !== '' ? '<br>' . e($s['note']) : '') . '</div></div>'; }
    return $h . '</div></div>';
}
/** HR dashboard: people in onboarding (hired in the last 120 days, or a flow started and not finished within a year) and in handover
 *  (left in the last 12 months with something open, or an end recorded ahead). */
function hrf_overview(): array {
    $o = ['on' => [], 'off' => []]; if (!function_exists('hr2_has') || !hr2_has()) return $o;
    try {
        $ends = array_flip(array_map('intval', array_column(all("SELECT DISTINCT person_id FROM hr_employments e WHERE kind = 'end' AND date_from >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) AND NOT EXISTS (SELECT 1 FROM hr_employments x WHERE x.person_id = e.person_id AND x.kind IN ('hire','rehire') AND x.date_from > e.date_from)"), 'person_id')));
        foreach (hr_people(false) as $p) { $pid = (int)$p['id'];
            if ($p['is_active'] && !isset($ends[$pid])) { $hd = (string)($p['hire_date'] ?? ''); if ($hd === '' || $hd < date('Y-m-d', strtotime('-365 days'))) continue;
                $S = hrf_onboarding($p); $pr = hrf_progress($S); if ($pr['complete']) continue; if ($hd < date('Y-m-d', strtotime('-120 days')) && !array_filter($S, fn($s) => $s['state'] === 'run')) continue;
                $o['on'][] = ['p' => $p, 'steps' => $S, 'pr' => $pr, 'date' => $hd]; }
            elseif (isset($ends[$pid]) || (!$p['is_active'] && (string)($p['left_date'] ?? '') >= date('Y-m-d', strtotime('-365 days')))) { $S = hrf_handover($p); $pr = hrf_progress($S); if ($pr['complete']) continue; $o['off'][] = ['p' => $p, 'steps' => $S, 'pr' => $pr, 'date' => (string)($p['left_date'] ?? '')]; }
        }
        usort($o['on'], fn($a, $b) => strcmp($b['date'], $a['date'])); usort($o['off'], fn($a, $b) => strcmp($b['date'], $a['date']));
    } catch (Throwable $e) {}
    return $o;
}
