<?php
/**
 * Wellness (GAS-CP-R97-194) — small helps for people who work in the portal for hours: focus sounds, a focus timer,
 * a breathing minute and stretches, reminders (break, eyes, water, posture, end of day, In / Out) and an anonymous
 * daily check-in. Everything is a personal setting; nothing here is reported per person, to anyone.
 *
 * The page work is done in the browser by assets/wellness.js from the data of wl_state(); the server keeps only the
 * settings (wl_prefs) and the anonymous answers (wl_mood + wl_mood_seen, which holds who answered on which day and
 * not what).
 */
const WL_DEFAULTS = ['breaks' => 1, 'break_min' => 50, 'eyes' => 1, 'hydrate' => 1, 'posture' => 1, 'eod' => 1, 'io_remind' => 1, 'mood' => 1,
                     'desktop' => 0, 'timer_focus' => 25, 'timer_break' => 5, 'sound' => 'rain', 'volume' => 40];
const WL_BOOLS  = ['breaks', 'eyes', 'hydrate', 'posture', 'eod', 'io_remind', 'mood', 'desktop'];
const WL_INTS   = ['break_min' => [20, 120], 'timer_focus' => [5, 90], 'timer_break' => [1, 30], 'volume' => [0, 100]];
const WL_SOUNDS = ['rain', 'sea', 'wind', 'brown', 'pink', 'white'];
const WL_MOOD_MIN = 5;                                  // a week is shown to HR only with at least this many answers

function wl_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'wl_prefs'"); } catch (Throwable $e) { $h = false; } } return $h; }
function wl_can(): bool { return wl_has() && function_exists('is_staff') && is_staff() && (int)(current_user()['id'] ?? 0) > 0; }
function wl_trend_can(): bool { return wl_can() && ((function_exists('hr_can') && hr_can()) || (function_exists('mgmt_can') && mgmt_can())); }

function wl_prefs(int $uid): array {
    $p = WL_DEFAULTS; if (!wl_has() || $uid <= 0) return $p;
    $j = json_decode((string)(scalar('SELECT prefs FROM wl_prefs WHERE user_id = ?', [$uid]) ?: ''), true);
    if (is_array($j)) foreach ($j as $k => $v) if (array_key_exists($k, WL_DEFAULTS)) $p[$k] = $v;
    return wl_clean($p);
}
/** Keep only known keys with values inside their limits. */
function wl_clean(array $in): array {
    $p = WL_DEFAULTS;
    foreach (WL_BOOLS as $k) if (array_key_exists($k, $in)) $p[$k] = empty($in[$k]) ? 0 : 1;
    foreach (WL_INTS as $k => [$lo, $hi]) if (isset($in[$k]) && is_numeric($in[$k])) $p[$k] = max($lo, min($hi, (int)$in[$k]));
    if (isset($in['sound']) && in_array((string)$in['sound'], WL_SOUNDS, true)) $p['sound'] = (string)$in['sound'];
    return $p;
}
/** Save the settings of the current user. $full: a posted form (an unticked box is "off"); otherwise only the keys given change. */
function wl_save(array $in, bool $full = false): array {
    if (!wl_can()) throw new RuntimeException(t('err.noaccess')); $uid = (int) current_user()['id'];
    $cur = wl_prefs($uid); if ($full) foreach (WL_BOOLS as $k) $in[$k] = empty($in[$k]) ? 0 : 1;
    $p = wl_clean(array_intersect_key($in, WL_DEFAULTS) + $cur);
    q('INSERT INTO wl_prefs (user_id, prefs, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs), updated_at = NOW()', [$uid, json_encode($p)]);
    unset($_SESSION['wl_state']); return $p;
}

/** Today for the current user: working day, hours, leave, In / Out — what the reminders need. Cached in the session for a minute. */
function wl_today(int $uid): array {
    $day = date('Y-m-d'); $o = ['day' => $day, 'workday' => false, 'start' => null, 'end' => null, 'leave' => false, 'io' => false, 'in' => false, 'open' => false];
    try {
        $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $d = function_exists('wc_day') ? wc_day($cal, $day) : ['workday' => date('N') < 6, 'rule' => null];
        $o['workday'] = (bool)$d['workday']; if (!empty($d['rule']['start_time'])) { $o['start'] = substr((string)$d['rule']['start_time'], 0, 5); $o['end'] = substr((string)$d['rule']['end_time'], 0, 5); }
        if ($o['workday'] && !$o['start']) { $o['start'] = '09:00'; $o['end'] = '17:00'; }
        $p = function_exists('hr_person_of_user') ? hr_person_of_user($uid) : null;      // approved leave, sick leave or absence of whole days: no reminders at all
        if ($p) $o['leave'] = (bool) scalar('SELECT COUNT(*) FROM hr_requests r JOIN hr_leave_types t ON t.id = r.type_id WHERE r.person_id = ? AND r.status = "approved" AND t.unit = "day" AND ? BETWEEN r.date_from AND r.date_to', [(int)$p['id'], $day]);
    } catch (Throwable $e) {}
    try {
        if (function_exists('io_has') && io_has() && function_exists('io_consent') && io_consent($uid)) {
            $o['io'] = true; $last = null;
            foreach (all("SELECT kind FROM io_entries WHERE user_id = ? AND local_day = ? AND status <> 'rejected' ORDER BY at_utc, id", [$uid, $day]) as $r) { if ($r['kind'] === 'in') $o['in'] = true; $last = $r['kind']; }
            $o['open'] = $last === 'in';
        }
    } catch (Throwable $e) {}
    return $o;
}
function wl_mood_done(int $uid): bool { try { return (bool) scalar('SELECT COUNT(*) FROM wl_mood_seen WHERE user_id = ? AND day = CURDATE()', [$uid]); } catch (Throwable $e) { return true; } }
/** Everything the browser needs. */
function wl_state(bool $fresh = false): array {
    $uid = (int)(current_user()['id'] ?? 0); $c = $_SESSION['wl_state'] ?? null;
    if (!$fresh && is_array($c) && ($c['uid'] ?? 0) === $uid && ($c['at'] ?? 0) > time() - 60) return $c['s'] + ['now' => (int) date('G') * 60 + (int) date('i')];
    $s = ['prefs' => wl_prefs($uid), 'today' => wl_today($uid)]; $s['mood_done'] = wl_mood_done($uid);
    $_SESSION['wl_state'] = ['uid' => $uid, 'at' => time(), 's' => $s];
    return $s + ['now' => (int) date('G') * 60 + (int) date('i')];
}

/** The anonymous answer of the day (1–5). Who answered is kept apart from what was answered. */
function wl_mood_save(int $mood): void {
    if (!wl_can()) throw new RuntimeException(t('err.noaccess')); if ($mood < 1 || $mood > 5) throw new RuntimeException(t('wl.mood_bad')); $uid = (int) current_user()['id'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        q('INSERT INTO wl_mood_seen (user_id, day) VALUES (?, CURDATE())', [$uid]);          // fails on a second answer of the same day
        q('INSERT INTO wl_mood (day, mood) VALUES (CURDATE(), ?)', [$mood]); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($e instanceof PDOException && $e->getCode() === '23000') throw new RuntimeException(t('wl.mood_twice')); throw $e; }
    unset($_SESSION['wl_state']);
}
/** The trend for HR and management: per week the number of answers and the average — a week with fewer than WL_MOOD_MIN answers shows no average. */
function wl_mood_trend(int $weeks = 12): array {
    if (!wl_trend_can()) throw new RuntimeException(t('err.noaccess')); $out = [];
    foreach (all('SELECT YEARWEEK(day, 3) yw, MIN(day) d0, COUNT(*) n, AVG(mood) a FROM wl_mood WHERE day >= CURDATE() - INTERVAL ? DAY GROUP BY YEARWEEK(day, 3) ORDER BY yw DESC', [max(1, min(52, $weeks)) * 7]) as $r)
        $out[] = ['from' => date('Y-m-d', strtotime((string)$r['d0'] . ' -' . ((int) date('N', strtotime((string)$r['d0'])) - 1) . ' days')), 'n' => (int)$r['n'], 'avg' => (int)$r['n'] >= WL_MOOD_MIN ? round((float)$r['a'], 1) : null];
    return $out;
}
/** Daily: who answered on which day is not needed after the day itself. */
function wl_daily(): string { if (!wl_has()) return 'wl —'; return 'wl ' . q('DELETE FROM wl_mood_seen WHERE day < CURDATE() - INTERVAL 1 DAY')->rowCount(); }

/** The texts of assets/wellness.js. */
function wl_texts(): array { $o = []; foreach (['title', 'sounds', 'snd_rain', 'snd_sea', 'snd_wind', 'snd_brown', 'snd_pink', 'snd_white', 'volume', 'timer', 'tm_start', 'tm_pause', 'tm_reset', 'tm_focus', 'tm_break',
    'tm_done_focus', 'tm_done_break', 'breathe', 'br_in', 'br_hold', 'br_out', 'br_done', 'stretch', 'st_1', 'st_2', 'st_3', 'st_4', 'settings', 'close', 'ok', 'snooze', 'n_break_t', 'n_break', 'n_eyes_t', 'n_eyes', 'n_water_t', 'n_water',
    'n_posture_t', 'n_posture', 'n_eod_t', 'n_eod', 'n_in_t', 'n_in', 'n_out_t', 'n_out', 'n_open', 'mood_t', 'mood_q', 'mood_anon', 'mood_thanks', 'mood_1', 'mood_2', 'mood_3', 'mood_4', 'mood_5', 'later'] as $k) $o[$k] = t('wl.' . $k);
    return $o; }
/** The button in the top bar and the data of the page. */
function wl_head_html(): string {
    if (!wl_can()) return '';
    return '<button type="button" class="wlbtn" id="wl-btn" title="' . e(t('wl.title')) . '" aria-label="' . e(t('wl.title')) . '" aria-expanded="false"><span aria-hidden="true">🌿</span><b id="wl-badge" hidden></b></button>';
}
function wl_foot_html(): string {
    if (!wl_can()) return ''; $d = ['s' => wl_state(), 't' => wl_texts(), 'csrf' => csrf_token(), 'u' => (int) current_user()['id'], 'urlState' => url('wl_state'), 'urlIo' => url('inout'), 'urlSet' => url('wellness'), 'post' => url('wellness')];
    $v = @filemtime(dirname(__DIR__, 2) . '/assets/wellness.js') ?: 1;
    return '<script type="application/json" id="wl-data">' . json_encode($d, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
         . '<link rel="stylesheet" href="../assets/wellness.css?v=' . (@filemtime(dirname(__DIR__, 2) . '/assets/wellness.css') ?: 1) . '">'
         . '<script nonce="' . csp_nonce() . '" src="../assets/wellness.js?v=' . $v . '" defer></script>';
}

function wl_handle_action(string $action): bool {
    if (!in_array($action, ['wl_save', 'wl_set', 'wl_mood'], true)) return false; require_login();
    $json = function (array $o, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($o); exit; };
    try {
        if ($action === 'wl_save') { wl_save($_POST, true); flash('good', t('wl.saved')); redirect('wellness'); }
        if ($action === 'wl_set')  { $json(['ok' => true, 'prefs' => wl_save($_POST)]); }
        if ($action === 'wl_mood') { wl_mood_save((int)($_POST['mood'] ?? 0)); $json(['ok' => true]); }
    } catch (RuntimeException $e) { if ($action === 'wl_save') { flash('bad', $e->getMessage()); redirect('wellness'); } $json(['ok' => false, 'error' => $e->getMessage()], 400); }
    return true;
}
