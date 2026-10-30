<?php
/**
 * Freshdesk ticket history import.                                                           GAS-CP-R28-002
 *
 * The XML exports of the old Freshdesk helpdesk (Tickets0.xml … TicketsN.xml, 20–100 MB each) are placed by FTP in
 * customer/storage/import/freshdesk/ (small files can also be uploaded from the page). Settings › Freshdesk import
 * lists them; "Check" scans a file and reports how the vessels, projects, agents and statuses would map; "Import"
 * writes the tickets in batches of 100 per request (the page continues by itself), so a 100 MB file never hits the
 * server's time limit. A ticket already imported (tickets.legacy_id) is skipped, so a file can be re-run safely.
 *
 * Mapping: ticket_no FD-<display id>; vessel from the custom field "Vessel" matched to the fleet (M/Y, S/Y, P/S …
 * prefixes ignored, "(ex NAME)" tried too), optionally created; project from "Prj No" (PRJ.000064[.Y] → project_code);
 * category from the ticket type; status / priority mapped; requester = created by (side from the e-mail domain);
 * responder = assigned (matched to a portal staff account by name); every conversation (replies, notes, forwarded
 * mails, forms, summaries) becomes a ticket comment with its own date, author and visibility (private = internal);
 * attachment names are listed under the text (the files themselves stay in Freshdesk's storage); the custom fields
 * Important notes, Deadline, Service date, S/N, Warranty are kept as lines of the description; "S/N" units found in
 * the equipment register get a history record. Deleted / spam tickets are skipped.
 */
declare(strict_types=1);

const FD_BATCH = 100;
const FD_BUILD = '2026-10-20d';

function fd_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = tk_has() && (bool) scalar("SHOW COLUMNS FROM tickets LIKE 'legacy_id'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** The import folders: customer/storage/import/freshdesk (documented, FTP) and <storage_path>/import/freshdesk (uploads). */
function fd_dirs(): array {
    $a = realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage'); $a .= '/import/freshdesk';
    $b = cp_storage() . '/import/freshdesk';
    foreach ([$a, $b] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
    return array_values(array_unique([$a, $b]));
}
function fd_dir(): string { return fd_dirs()[0]; }
/** XML files waiting in the import folder, with what the session knows about them. */
function fd_files(): array {
    $out = []; $files = [];
    foreach (fd_dirs() as $d) foreach (array_merge(glob($d . '/*.xml') ?: [], glob($d . '/*.XML') ?: []) as $f) $files[basename($f)] = $f;
    foreach ($files as $f) {
        $n = basename($f); $st = $_SESSION['fd_jobs'][$n] ?? [];
        $out[] = ['name' => $n, 'size' => filesize($f), 'mtime' => filemtime($f), 'job' => $st];
    }
    usort($out, fn($a, $b) => strnatcmp($a['name'], $b['name']));
    return $out;
}
function fd_path(string $name): string {
    $name = basename($name); if (!preg_match('/^[\w.\-]+\.xml$/i', $name)) throw new RuntimeException(t('err.notfound'));
    foreach (fd_dirs() as $d) if (is_file($d . '/' . $name)) return $d . '/' . $name;
    throw new RuntimeException(t('err.notfound'));
}
function fd_delete_file(string $name): void { require_cp_admin(); @unlink(fd_path($name)); unset($_SESSION['fd_jobs'][basename($name)]); }
function fd_upload(array $file): string {
    require_cp_admin();
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('err.upload'));
    $name = preg_replace('/[^\w.\-]+/', '_', (string)$file['name']); if (!preg_match('/\.xml$/i', $name)) throw new RuntimeException(t('fd.need_xml'));
    if (!move_uploaded_file($file['tmp_name'], fd_dir() . '/' . $name)) throw new RuntimeException(t('err.upload'));
    return $name;
}

/* ------------------------------------------------------------ text helpers */

function fd_text(?string $s): string {
    $s = (string)$s; if ($s === '') return '';
    if (preg_match('/<\s*(html|div|p|br|table|span|body)\b/i', $s)) {                       // a mail body that came through as HTML
        $s = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/li)\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\s*(style|script|head)\b.*?<\s*\/\s*\1\s*>/is', '', $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $s = str_replace("\r", '', (string)$s);
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/\n[ \t]*(\n[ \t]*)+/', "\n\n", $s);
    return trim($s);
}
function fd_dt(?string $s): ?string { $s = trim((string)$s); if ($s === '') return null; try { return (new DateTime($s))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; } }
function fd_norm(string $s): string {
    $s = mb_strtoupper(trim($s));
    $s = preg_replace('/\s*\((?:EX|FORMER|FORMERLY)\.?\s+[^)]*\)\s*/u', ' ', $s);                      // "(ex ILLUSION IX)"
    $s = preg_replace('/^(M\/Y|MY|S\/Y|SY|M\/V|MV|M\/T|MT|P\/S|PS|M\/S|MS|R\/V|RV|C\/S|CS|Y\/N)\s+/u', '', $s);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]/u', ' ', $s)));
}
/** The "(ex NAME)" part, normalised, if any. */
function fd_ex_name(string $s): ?string { return preg_match('/\((?:ex|former|formerly)\.?\s+([^)]+)\)/iu', $s, $m) ? fd_norm($m[1]) : null; }
/** Values of the Vessel field that are not a vessel at all. */
function fd_is_no_vessel(string $v): bool { $u = mb_strtoupper(trim($v)); return $u === '' || in_array($u, ['UNKNOWN', 'IT TICKETS', 'N/A', 'NA', '-', 'NONE', 'OTHER', 'GENERAL', 'OFFICE', 'GOLDEN A/S', 'GOLDEN AS'], true); }
/** Does the Vessel field hold a project / hull code rather than a name ("HAMER FIFI62", "GY85-2", "PRJ.000064")? */
function fd_looks_like_code(string $v): bool { return (bool) preg_match('/^(PRJ\.?\s*\d|[A-Z]{1,6}\s?\d{2,}[\-\/]?\d*$|[A-Z]+\s+[A-Z]*\d{2,}$)/i', trim($v)); }

/* ------------------------------------------------------------ lookups (cached per request) */

function &fd_vessel_map(): array {
    static $m = null;
    if ($m === null) { $m = []; foreach (all('SELECT id, name FROM vessels') as $v) { $k = fd_norm($v['name']); if ($k !== '' && !isset($m[$k])) $m[$k] = (int)$v['id']; if (($x = fd_ex_name($v['name'])) && !isset($m[$x])) $m[$x] = (int)$v['id']; } }
    return $m;
}
function fd_vessel_id(string $name, bool $create, array &$rep): ?int {
    if (fd_is_no_vessel($name) || fd_looks_like_code($name)) { if ($name !== '') $rep['no_vessel'][$name] = ($rep['no_vessel'][$name] ?? 0) + 1; return null; }
    $m = &fd_vessel_map(); $k = fd_norm($name); if ($k === '') return null;
    if (function_exists('vm_has') && vm_has()) { $vid = vm_find($name); if ($vid) { $m[$k] = $vid; return $vid; } }   // R30-001: IMO / alias / normalised name first
    if (isset($m[$k])) return $m[$k];
    if (($x = fd_ex_name($name)) && isset($m[$x])) { $m[$k] = $m[$x]; return $m[$x]; }
    $nos = str_replace(' ', '', $k); foreach ($m as $kk => $id) if (str_replace(' ', '', (string)$kk) === $nos) { $m[$k] = $id; return $id; }
    if (!$create) { $rep['vessels_missing'][$name] = ($rep['vessels_missing'][$name] ?? 0) + 1; return null; }
    if (function_exists('vm_prefix') && vm_prefix($name) === '' && !preg_match('/^(GY|HULL)\s*\d/i', trim($name))) { $rep['not_vessel'][$name] = ($rep['not_vessel'][$name] ?? 0) + 1; return null; }   // R30-001: a company name is not a vessel
    q('INSERT INTO vessels (name, is_active) VALUES (?, 1)', [mb_substr(function_exists('vm_canonical') ? vm_canonical(trim($name)) : trim($name), 0, 120)]);
    $id = (int) db()->lastInsertId(); $m[$k] = $id; $rep['vessels_new'][$name] = $id; return $id;
}
function fd_project_map(): array {
    static $m = null;
    if ($m === null) { $m = []; foreach (all('SELECT id, project_code, vessel_id FROM projects') as $p) { $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$p['project_code'])); if ($c !== '') $m[$c] = $p; } }
    return $m;
}
/** "PRJ.000616.Y.002 /2" → [project row, subproject id|null]. */
function fd_project_sub(string $code): array {
    $p = fd_project($code); if (!$p) return [null, null];
    if (preg_match('/\.\s*([A-Z]{1,3})\.?\s*(\d{1,3})\b/i', preg_replace('/^PRJ\.?\s*\d+/i', '', trim($code)), $m)) {
        $sub = one('SELECT id FROM subprojects WHERE project_id = ? AND (code = ? OR code = ? OR code = ?)', [(int)$p['id'], strtoupper($m[1]) . '.' . $m[2], strtoupper($m[1]) . '.' . sprintf('%02d', (int)$m[2]), strtoupper($m[1]) . '.' . sprintf('%03d', (int)$m[2])]);
        return [$p, $sub ? (int)$sub['id'] : null];
    }
    return [$p, null];
}
function fd_project(string $code): ?array {
    $code = trim($code); if ($code === '') return null;
    $m = fd_project_map(); $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    if (preg_match('/^(PRJ\d{6})/', $c, $mm)) $c = $mm[1];                                   // "PRJ000616Y0022" → PRJ000616
    foreach ([$c, preg_replace('/[A-Z]\d*$/', '', $c), preg_replace('/^PRJ0*/', 'PRJ', $c)] as $try) if ($try !== '' && isset($m[$try])) return $m[$try];
    foreach ($m as $k => $p) if (str_ends_with((string)$k, ltrim($c, 'PRJ0')) && strlen(ltrim($c, 'PRJ0')) >= 3) return $p;
    return null;
}
function fd_staff_map(): array {
    static $m = null;
    if ($m === null) { $m = []; foreach (all("SELECT id, full_name, email FROM customer_users WHERE role IN ('staff','admin')") as $u) $m[] = $u; }
    return $m;
}
/** Portal staff account for a Freshdesk agent name ("Nikolaos Polykandriotis", "Gerasimos Kourtis(Deactivated)"). */
function fd_staff_id(string $name): ?int {
    $name = trim(preg_replace('/\s*\(.*\)\s*$/', '', $name)); if ($name === '' || strcasecmp($name, 'No Agent') === 0) return null;
    static $c = []; if (array_key_exists($name, $c)) return $c[$name];
    $n = mb_strtolower($name); $parts = preg_split('/\s+/', $n); $last = end($parts); $first = $parts[0];
    $hit = null;
    foreach (fd_staff_map() as $u) { $un = mb_strtolower($u['full_name']); if ($un === $n) { $hit = (int)$u['id']; break; } }
    if ($hit === null) foreach (fd_staff_map() as $u) { $up = preg_split('/\s+/', mb_strtolower($u['full_name'])); if (end($up) === $last && mb_substr($up[0], 0, 3) === mb_substr($first, 0, 3)) { $hit = (int)$u['id']; break; } }
    return $c[$name] = $hit;
}
function fd_is_golden_mail(string $email): bool { return (bool) preg_match('/@(goldenas\.(com\.gr|gr|com)|golden-as\.|goldenautomation)/i', $email); }

/* ------------------------------------------------------------ value maps */

function fd_status(string $name, string $code): string {
    $n = mb_strtolower(trim($name));
    return match (true) {
        $n === 'closed' => 'closed', $n === 'resolved' => 'resolved', $n === 'open' => 'open',
        str_contains($n, 'waiting on customer') || str_contains($n, 'pending') => 'waiting_customer',
        $code === '5' => 'closed', $code === '4' => 'resolved', $code === '2' => 'open', $code === '3' => 'waiting_customer',
        default => 'in_progress',                                                          // In Progress, SQ Sent, Schedule Attendance, Waiting on Third Party …
    };
}
function fd_priority(string $code): string { return ['1' => 'low', '2' => 'normal', '3' => 'high', '4' => 'urgent'][$code] ?? 'normal'; }
function fd_category(string $type, string $subject): string {
    $t = mb_strtolower(trim($type));
    if ($t !== '') return match (true) { str_contains($t, 'service') => 'service', str_contains($t, 'part') => 'spare_parts', str_contains($t, 'problem') || str_contains($t, 'incident') => 'fault', str_contains($t, 'question') => 'question', str_contains($t, 'warranty') => 'warranty', default => 'other' };
    $s = mb_strtolower($subject);
    return match (true) { preg_match('/spare|part|quotation|offer|sq\b|price/', $s) === 1 => 'spare_parts', preg_match('/fault|alarm|fail|not work|trip|breakdown|problem|issue/', $s) === 1 => 'fault', preg_match('/service|attendance|visit|maintenance|commission|install/', $s) === 1 => 'service', preg_match('/warranty/', $s) === 1 => 'warranty', default => 'other' };
}

/* ------------------------------------------------------------ XML streaming */

/** Iterate the <helpdesk-ticket> elements of a file as SimpleXML, from the $offset-th one, at most $limit. */
function fd_each(string $path, int $offset, int $limit, callable $fn): int {
    $r = new XMLReader(); if (!$r->open($path)) throw new RuntimeException(t('fd.bad_file'));
    $i = 0; $seen = 0;
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'helpdesk-ticket') continue;
        if ($i++ < $offset) { $r->next(); continue; }
        $x = simplexml_load_string($r->readOuterXml()); if ($x !== false) $fn($x);
        $seen++; if ($seen >= $limit) break;
        $r->next();
    }
    $r->close(); return $seen;
}
function fd_count(string $path): int { $r = new XMLReader(); if (!$r->open($path)) return 0; $n = 0; while ($r->read()) if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'helpdesk-ticket') { $n++; $r->next(); } $r->close(); return $n; }
/** Users (id → name / e-mail) learnt from a file: requesters and responders. Kept in storage so later files and notes find the names. */
function fd_users_load(): array { $f = fd_dir() . '/users.json'; if (!is_file($f)) foreach (fd_dirs() as $d) if (is_file($d . '/users.json')) { $f = $d . '/users.json'; break; } return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
function fd_users_save(array $u): void { file_put_contents(fd_dir() . '/users.json', json_encode($u, JSON_UNESCAPED_UNICODE)); }
function fd_learn_users(SimpleXMLElement $t, array &$users): void {
    $rq = $t->requester; if ($rq && (string)$rq->id !== '') $users[(string)$rq->id] = ['name' => trim((string)$rq->name), 'email' => trim((string)$rq->email), 'agent' => (string)$rq->{'helpdesk-agent'} === 'true'];
    $rid = trim((string)$t->{'responder-id'}); $rn = trim((string)$t->{'responder-name'});
    if ($rid !== '' && $rn !== '' && strcasecmp($rn, 'No Agent') !== 0) $users[$rid] = ['name' => $rn, 'email' => '', 'agent' => true];
}

/* ------------------------------------------------------------ check (dry run) */

function fd_check(string $name): array {
    require_cp_admin(); if (!fd_has()) throw new RuntimeException(t('fd.need_db'));
    $path = fd_path($name); $users = fd_users_load(); $rep = ['file' => $name, 'tickets' => 0, 'skipped' => 0, 'already' => 0, 'notes' => 0, 'attachments' => 0, 'vessels_ok' => 0, 'vessels_missing' => [], 'no_vessel' => [], 'projects_ok' => 0, 'projects_missing' => [], 'agents' => [], 'statuses' => [], 'types' => [], 'from' => null, 'to' => null];
    $have = array_flip(array_map('strval', array_column(all('SELECT legacy_id FROM tickets WHERE legacy_id IS NOT NULL'), 'legacy_id')));
    fd_each($path, 0, PHP_INT_MAX, function (SimpleXMLElement $t) use (&$rep, &$users, $have) {
        fd_learn_users($t, $users);
        $rep['tickets']++;
        if ((string)$t->deleted === 'true' || (string)$t->spam === 'true') { $rep['skipped']++; return; }
        if (isset($have[(string)$t->id])) $rep['already']++;
        $rep['notes'] += count($t->notes->{'helpdesk-note'} ?? []); $rep['attachments'] += count($t->attachments->attachment ?? []);
        $cf = $t->custom_field; $v = trim((string)($cf->cf_vesseldpd_3796008 ?? '')); $pr = trim((string)($cf->cf_prj_no_3796008 ?? ''));
        $vid = fd_vessel_id($v, false, $rep); if ($vid) $rep['vessels_ok']++;
        if ($pr !== '') { if (fd_project($pr)) $rep['projects_ok']++; else $rep['projects_missing'][$pr] = ($rep['projects_missing'][$pr] ?? 0) + 1; }
        $ag = trim((string)$t->{'responder-name'}); if ($ag !== '' && strcasecmp($ag, 'No Agent') !== 0) { $k = $ag . (fd_staff_id($ag) ? '' : ' ✕'); $rep['agents'][$k] = ($rep['agents'][$k] ?? 0) + 1; }
        $st = (string)$t->{'status-name'} . ' → ' . fd_status((string)$t->{'status-name'}, (string)$t->status); $rep['statuses'][$st] = ($rep['statuses'][$st] ?? 0) + 1;
        $ty = ((string)$t->{'ticket-type'} ?: '—') . ' → ' . fd_category((string)$t->{'ticket-type'}, (string)$t->subject); $rep['types'][$ty] = ($rep['types'][$ty] ?? 0) + 1;
        $d = substr((string)$t->{'created-at'}, 0, 10); if (!$rep['from'] || $d < $rep['from']) $rep['from'] = $d; if (!$rep['to'] || $d > $rep['to']) $rep['to'] = $d;
    });
    fd_users_save($users);
    arsort($rep['vessels_missing']); arsort($rep['no_vessel']); arsort($rep['projects_missing']); arsort($rep['agents']); arsort($rep['statuses']); arsort($rep['types']);
    $_SESSION['fd_jobs'][$name] = ['total' => $rep['tickets'], 'offset' => 0, 'done' => false, 'imported' => 0, 'skipped' => 0, 'comments' => 0, 'checked' => true];
    return $rep;
}

/* ------------------------------------------------------------ import (one batch per request) */

function fd_import_batch(string $name, bool $createVessels): array {
    require_cp_admin(); if (!fd_has()) throw new RuntimeException(t('fd.need_db'));
    @set_time_limit(300); $path = fd_path($name);
    $job = $_SESSION['fd_jobs'][$name] ?? ['total' => fd_count($path), 'offset' => 0, 'done' => false, 'imported' => 0, 'skipped' => 0, 'comments' => 0];
    if (empty($job['total'])) $job['total'] = fd_count($path);
    $users = fd_users_load(); $rep = []; $me = current_user();
    $have = array_flip(array_map('strval', array_column(all('SELECT legacy_id FROM tickets WHERE legacy_id IS NOT NULL'), 'legacy_id')));
    $_SESSION['fd_current'] = $name;
    $n = fd_each($path, (int)$job['offset'], FD_BATCH, function (SimpleXMLElement $t) use (&$job, &$users, &$rep, $have, $createVessels, $me) {
        fd_learn_users($t, $users);
        $lid = (string)$t->id;
        if ((string)$t->deleted === 'true' || (string)$t->spam === 'true' || isset($have[$lid])) { $job['skipped']++; return; }
        $cf = $t->custom_field; $g = fn($k) => trim((string)($cf->{$k} ?? ''));
        $vName = $g('cf_vesseldpd_3796008'); $vid = fd_vessel_id($vName, $createVessels, $rep);
        $cust = $vid ? (int) scalar('SELECT customer_id FROM vessels WHERE id = ?', [$vid]) ?: null : null;
        [$pr, $sid] = fd_project_sub($g('cf_prj_no_3796008')); $pid = $pr ? (int)$pr['id'] : null;
        if (!$vid && $pr && $pr['vessel_id']) { $vid = (int)$pr['vessel_id']; $cust = (int) scalar('SELECT customer_id FROM vessels WHERE id = ?', [$vid]) ?: null; }
        $subject = mb_substr(fd_text((string)$t->subject) ?: '(no subject)', 0, 160);
        $desc = fd_text((string)$t->description);
        $extra = [];
        foreach (['cf_important_notes_3796008' => 'fd.f_important', 'cf_deadline_3796008' => 'fd.f_deadline', 'cf_service_date_3796008' => 'fd.f_service_date', 'cf_sn_3796008' => 'fd.f_sn', 'cf_warranty_3796008' => 'fd.f_warranty', 'cf_mnr_3796008' => 'fd.f_mnr', 'cf_reference_number_3796008' => 'fd.f_ref', 'cf_company_3796008' => 'fd.f_company', 'cf_it_type_3796008' => 'fd.f_it_type'] as $k => $lab)
            if ($g($k) !== '') $extra[] = t($lab) . ': ' . (in_array($k, ['cf_deadline_3796008', 'cf_service_date_3796008'], true) && ($dd = fd_dt($g($k))) ? fmt_date(substr($dd, 0, 10)) : fd_text($g($k)));
        if (!$vid && $vName !== '' && !fd_is_no_vessel($vName)) $extra[] = t('fd.f_vessel') . ': ' . $vName;
        $atts = []; foreach ($t->attachments->attachment ?? [] as $a) $atts[] = trim((string)$a->{'content-file-name'}) . ' (' . number_format(((int)$a->{'content-file-size'}) / 1024, 0) . ' KB)';
        if ($atts) $extra[] = t('fd.f_attachments') . ': ' . implode(' · ', $atts);
        $tags = []; foreach ($t->tags->tag ?? [] as $tg) if (trim((string)$tg->name) !== '') $tags[] = trim((string)$tg->name);
        if ($tags) $extra[] = t('fd.f_tags') . ': ' . implode(', ', $tags);
        $head = t('fd.f_source', ['no' => (string)$t->{'display-id'}, 'src' => (string)$t->{'source-name'}, 'type' => (string)$t->{'ticket-type'} ?: '—', 'st' => (string)$t->{'status-name'}]);
        $description = mb_substr(trim($head . "\n" . ($extra ? implode("\n", $extra) . "\n" : '') . "\n" . $desc), 0, 60000);
        $rq = $t->requester; $rqName = trim((string)$rq->name) ?: trim((string)$t->{'requester-name'}) ?: '—'; $rqMail = trim((string)$rq->email);
        $side = ((string)$rq->{'helpdesk-agent'} === 'true' || fd_is_golden_mail($rqMail)) ? 'golden' : 'customer';
        $agName = trim(preg_replace('/\s*\(.*\)\s*$/', '', (string)$t->{'responder-name'})); if (strcasecmp($agName, 'No Agent') === 0) $agName = '';
        $agId = $agName !== '' ? fd_staff_id($agName) : null;
        $status = fd_status((string)$t->{'status-name'}, (string)$t->status);
        $st = $t->{'ticket-states'}; $created = fd_dt((string)$t->{'created-at'}) ?: date('Y-m-d H:i:s'); $updated = fd_dt((string)$t->{'updated-at'}) ?: $created;
        $resolvedAt = fd_dt((string)($st->{'resolved-at'} ?? '')) ?: (in_array($status, ['resolved', 'closed'], true) ? fd_dt((string)($st->{'closed-at'} ?? '')) ?: $updated : null);
        $closedAt = fd_dt((string)($st->{'closed-at'} ?? '')) ?: ($status === 'closed' ? $updated : null);
        $no = 'FD-' . (int)$t->{'display-id'}; $k = 0; $base = $no;
        while (scalar('SELECT COUNT(*) FROM tickets WHERE ticket_no = ?', [$no])) $no = $base . chr(97 + $k++);
        q('INSERT INTO tickets (legacy_id, legacy_no, legacy_type, legacy_vessel, ticket_no, customer_id, vessel_id, project_id, subproject_id, title, description, category, priority, status, created_side, created_by, created_name, assigned_to, assigned_name, resolved_at, resolved_name, closed_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)',
          [(int)$lid, (int)$t->{'display-id'}, mb_substr((string)$t->{'ticket-type'}, 0, 40) ?: null, mb_substr($vName, 0, 160) ?: null, $no, $cust, $vid, $pid, $sid, $subject, $description,
           fd_category((string)$t->{'ticket-type'}, $subject), fd_priority((string)$t->priority), $status, $side, mb_substr($rqName, 0, 120), $agId, $agName !== '' ? mb_substr($agName, 0, 120) : null,
           $resolvedAt, $resolvedAt ? ($agName ?: null) : null, $closedAt, $created, $updated]);
        $tid = (int) db()->lastInsertId();
        q('INSERT INTO ticket_events (ticket_id, event, user_id, user_name, note, created_at) VALUES (?,?,?,?,?,?)', [$tid, 'imported', (int)$me['id'], $me['full_name'], t('fd.ev_note', ['no' => (string)$t->{'display-id'}, 'f' => basename((string)($_SESSION['fd_current'] ?? ''))]), $created]);
        // conversations → comments
        $notes = [];
        foreach ($t->notes->{'helpdesk-note'} ?? [] as $nn) {
            $src = (string)$nn->source; if ((string)$nn->deleted === 'true' || in_array($src, ['4', '15'], true)) continue;        // 4 = system meta, 15 = automated mails
            $body = fd_text((string)$nn->body); if ($body === '' && (string)$nn->{'body-html'} !== '') $body = fd_text((string)$nn->{'body-html'});
            $na = []; foreach ($nn->attachments->attachment ?? [] as $a) $na[] = trim((string)$a->{'content-file-name'});
            if ($na) $body = trim($body . "\n\n" . t('fd.f_attachments') . ': ' . implode(' · ', $na));
            if ($body === '') continue;
            $uid = (string)$nn->{'user-id'}; $u = $users[$uid] ?? null; $incoming = (string)$nn->incoming === 'true';
            $author = $u['name'] ?? ($incoming ? $rqName : ($agName ?: 'Golden A/S'));
            $nside = $u ? (($u['agent'] ?? false) || fd_is_golden_mail($u['email'] ?? '') ? 'golden' : 'customer') : ($incoming ? 'customer' : 'golden');
            $vis = (string)$nn->private === 'true' ? 'internal' : 'external';
            if ($src === '13') { $body = t('fd.f_summary') . ":\n" . $body; $vis = 'internal'; }
            $notes[] = [$tid, (int)$nn->id ?: null, $nside === 'golden' && $u && ($u['agent'] ?? false) ? fd_staff_id($author) : null, mb_substr($author, 0, 120), $nside, $vis, mb_substr($body, 0, 8000), fd_dt((string)$nn->{'created-at'}) ?: $created];
        }
        usort($notes, fn($a, $b) => strcmp($a[7], $b[7]));
        foreach ($notes as $c) { q('INSERT INTO ticket_comments (ticket_id, legacy_id, user_id, author, side, visibility, body, created_at) VALUES (?,?,?,?,?,?,?,?)', $c); $job['comments']++; }
        // S/N → equipment history
        if (function_exists('eq_has') && eq_has() && $g('cf_sn_3796008') !== '') {
            foreach (eq_expand_set($g('cf_sn_3796008')) as $sn) {
                $e = one('SELECT id FROM equipment WHERE serial_no = ? LIMIT 1', [$sn]); if (!$e) continue;
                q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, ticket_id, engineer, body, created_by) VALUES (?,?,?,'ticket',?,?,?,?)", [(int)$e['id'], substr($created, 0, 10), eq_guess_kind($subject . ' ' . $desc), $tid, $agName ?: null, mb_substr($no . ' — ' . $subject . ($desc ? "\n" . mb_substr($desc, 0, 1500) : ''), 0, 8000), (int)$me['id']]);
                $rep['equipment'] = ($rep['equipment'] ?? 0) + 1;
            }
        }
        $job['imported']++;
    });
    fd_users_save($users);
    $job['offset'] += $n; $job['done'] = $n < FD_BATCH || $job['offset'] >= $job['total'];
    $job['vessels_new'] = array_merge($job['vessels_new'] ?? [], array_keys($rep['vessels_new'] ?? []));
    $job['vessels_missing'] = array_merge($job['vessels_missing'] ?? [], array_keys($rep['vessels_missing'] ?? []));
    $job['equipment'] = ($job['equipment'] ?? 0) + ($rep['equipment'] ?? 0);
    $_SESSION['fd_jobs'][$name] = $job;
    return $job;
}
