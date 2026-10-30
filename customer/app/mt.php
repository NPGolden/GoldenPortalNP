<?php
/**
 * @mentions in notes and comments (GAS-CP-R31-007).
 * Type @ followed by a colleague's handle (the part of the e-mail before @ — @np, @ib, @kostas) in a ticket
 * comment, an offer internal note, a sub-project weekly note or comment, an owner-approval comment, a weekly
 * works forecast reply or a contact note: the colleague gets a notification ("… mentioned you in …") that
 * opens the note, and the handle is highlighted in the text. Only Golden A/S staff can be mentioned; anybody
 * (owners included) may mention them. The form offers the handles while typing.
 */

/** Active Golden A/S users: id, name, handle (lower-case e-mail local part, a-z 0-9 . _ -). */
function mt_staff(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach (all("SELECT id, full_name, email FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name") as $u) {
            $h = strtolower(trim((string) strstr((string)$u['email'], '@', true)));
            $h = preg_replace('/[^a-z0-9._-]/', '', $h);
            if ($h === '') continue;
            $c[] = ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'handle' => $h];
        }
    } catch (Throwable $e) { $c = []; }
    return $c;
}
/** handle => user id. */
function mt_handles(): array { static $m = null; if ($m === null) { $m = []; foreach (mt_staff() as $s) $m[$s['handle']] = $s['id']; } return $m; }
/** Ids of the staff mentioned in a text (@handle, case-insensitive, handle ends at a character outside a-z 0-9 . _ -; a trailing dot does not count). */
function mt_parse(string $body): array {
    if ($body === '' || !str_contains($body, '@')) return [];
    $h = mt_handles(); $ids = [];
    if (preg_match_all('/(?<![\w@.])@([a-z0-9][a-z0-9._-]*)/iu', $body, $m)) {
        foreach ($m[1] as $x) { $x = rtrim(strtolower($x), '.'); if (isset($h[$x])) $ids[$h[$x]] = true; }
    }
    return array_map('intval', array_keys($ids));
}
/** Notify the mentioned staff — one entry per person, grouped under Work, titled "<actor> mentioned you in <where>".
 *  $where: a short reference (TK-2026-012, GAS-OF-2026-003 R1, PRJ.000550 › Y.006, a contact name). Returns the number notified. */
function mt_notify(string $body, string $where, string $url, string $refKey, ?array $only = null): int {
    if (!function_exists('nf_push')) return 0;
    $ids = mt_parse($body); if (!$ids) return 0;
    if ($only !== null) $ids = array_values(array_intersect($ids, array_map('intval', $only)));
    $u = current_user(); if (!$u) return 0;
    $ids = array_values(array_diff($ids, [(int)$u['id']])); if (!$ids) return 0;
    return nf_push($ids, 'mt:new', 'mt:' . $refKey, t('mt.nf_title', ['u' => (string)$u['full_name'], 'w' => $where]), $body, $url, (int)$u['id'], (string)$u['full_name']);
}
/** Escaped, line-broken HTML of a note with the known @handles highlighted (unknown ones stay plain text). */
function mt_html(?string $body): string {
    $hook = null;                                                                   // R37-001: rich text (bold, lists) + @mentions on each line
    $h = function_exists('mt_handles') ? mt_handles() : [];
    if ($h && str_contains((string)$body, '@')) {
        $names = []; foreach (mt_staff() as $x) $names[$x['handle']] = $x['name'];
        $hook = fn(string $e) => preg_replace_callback('/(?<![\w@.])@([a-z0-9][a-z0-9._-]*)/iu', function ($m) use ($h, $names) {
            $k = rtrim(strtolower($m[1]), '.'); if (!isset($h[$k])) return $m[0];
            $tail = substr($m[1], strlen($k));
            return '<b class="mt" title="' . e($names[$k]) . '">@' . e(substr($m[1], 0, strlen($k))) . '</b>' . $tail;
        }, $e);
    }
    return function_exists('rt_html') ? rt_html($body, $hook) : nl2br(e((string)$body));
}
/** JSON for the autocomplete (the <html data-mt-users> attribute), only for signed-in users. */
function mt_users_json(): string {
    if (!current_user()) return '';
    return json_encode(array_map(fn($s) => ['h' => $s['handle'], 'n' => $s['name']], mt_staff()), JSON_UNESCAPED_UNICODE);
}
