<?php
/**
 * E-mail templates (GAS-CP-R32-001) — Settings › Lists & categories › E-mail templates.
 * A template = kind (offer, weekly, fsr, isr, wf, contacts, signature) + subject + body, optionally bound to a
 * language, a branch and/or a customer. When the E-mail card (em.php) or an "E-mail all" button opens, the most
 * specific active template wins: customer + branch + language > customer + branch > customer + language > customer >
 * branch + language > branch > language > any; a template bound to a different customer / branch / language is never
 * used. Without any template the built-in texts of the language files apply. Placeholders: {no} {title} {vessel}
 * {customer} {project} {week} {doc} {user} {branch} {date} {signature}.
 */

const ET_KINDS = ['offer', 'weekly', 'fsr', 'isr', 'wf', 'contacts', 'welcome', 'accounting', 'proforma', 'signature'];
const ET_PLACEHOLDERS = ['no', 'title', 'vessel', 'customer', 'project', 'week', 'doc', 'user', 'branch', 'date', 'name', 'email', 'url', 'signature'];   // name / email / url: the account the welcome e-mail is for (GAS-CP-R36-001)

function et_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'email_templates'")->fetch(); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function et_kind_label(string $k): string { return t('et.k_' . $k); }

/** Built-in subject / body of a kind in the given language (what applies when no template matches). */
function et_builtin(string $kind, string $locale): array {
    $tr = fn(string $k) => t_in($k, $locale);
    $subject = match ($kind) {
        'offer' => $tr('et.d_s_offer'), 'weekly' => $tr('et.d_s_weekly'), 'fsr' => $tr('et.d_s_fsr'), 'isr' => $tr('et.d_s_isr'), 'wf' => $tr('et.d_s_wf'), 'contacts' => $tr('et.d_s_contacts'), 'welcome' => $tr('et.d_s_welcome'), 'accounting' => $tr('et.d_s_accounting'), 'proforma' => $tr('et.d_s_proforma'), default => '' };
    $body = $kind === 'signature' ? $tr('et.d_signature') : ($kind === 'contacts' ? $tr('et.d_b_contacts') : ($kind === 'welcome' ? $tr('et.d_b_welcome') : ($kind === 'accounting' ? $tr('et.d_b_accounting') : ($kind === 'proforma' ? $tr('et.d_b_proforma') : $tr('et.d_body')))));
    return ['subject' => $subject, 'body' => $body];
}

/** All templates (admin list). */
function et_all(): array {
    if (!et_has()) return [];
    return all('SELECT t.*, b.code AS branch_code, c.name AS customer_name, u.full_name AS updated_name FROM email_templates t LEFT JOIN branches b ON b.id = t.branch_id LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN customer_users u ON u.id = t.updated_by ORDER BY FIELD(t.kind, ' . implode(',', array_map(fn($k) => "'$k'", ET_KINDS)) . '), t.customer_id IS NULL, t.branch_id IS NULL, t.locale, t.name');
}
function et_get(int $id): ?array { return et_has() ? one('SELECT * FROM email_templates WHERE id = ?', [$id]) : null; }

/** The template that applies, or null. */
function et_resolve(string $kind, string $locale, ?int $branchId, ?int $customerId): ?array {
    if (!et_has()) return null;
    $best = null; $bs = -1;
    foreach (all('SELECT * FROM email_templates WHERE kind = ? AND is_active = 1', [$kind]) as $t) {
        $s = 0;
        if ($t['customer_id'] !== null) { if ((int)$t['customer_id'] !== (int)$customerId) continue; $s += 4; }
        if ($t['branch_id'] !== null)   { if ((int)$t['branch_id'] !== (int)$branchId) continue; $s += 2; }
        if ($t['locale'] !== '')        { if ($t['locale'] !== $locale) continue; $s += 1; }
        if ($s > $bs) { $bs = $s; $best = $t; }
    }
    return $best;
}

/** Subject and body of a kind for a context, placeholders filled. $ctx keys = ET_PLACEHOLDERS (without signature) + 'locale', 'branch_id', 'customer_id'. */
function et_render(string $kind, array $ctx): array {
    $locale = $ctx['locale'] ?? locale(); $bid = $ctx['branch_id'] ?? null; $cid = $ctx['customer_id'] ?? null;
    $t = et_resolve($kind, $locale, $bid, $cid) ?: et_builtin($kind, $locale);
    $sig = et_resolve('signature', $locale, $bid, $cid); $sig = $sig ? $sig['body'] : et_builtin('signature', $locale)['body'];
    $vars = []; foreach (ET_PLACEHOLDERS as $k) $vars['{' . $k . '}'] = (string)($ctx[$k] ?? '');
    $vars['{date}'] = $vars['{date}'] !== '' ? $vars['{date}'] : fmt_date(date('Y-m-d'));
    $vars['{signature}'] = strtr($sig, array_diff_key($vars, ['{signature}' => 1]));
    $body = (string)$t['body'];
    if ($kind !== 'signature' && !str_contains($body, '{signature}')) $body = rtrim($body) . "\n\n{signature}";
    return ['subject' => strtr((string)$t['subject'], $vars), 'body' => strtr($body, $vars), 'template_id' => isset($t['id']) ? (int)$t['id'] : null];
}

/** Sample context for the preview in Settings. */
function et_sample(string $locale): array {
    $u = current_user();
    return ['no' => 'GAS-OF-2026-012 R1', 'title' => 'MSB retrofit — additional breakers', 'vessel' => 'M/Y EXAMPLE', 'customer' => 'Example Yachting Ltd', 'project' => 'PRJ.000550',
            'week' => '2026-W43', 'doc' => t_in('et.sample_doc', $locale), 'user' => (string)($u['full_name'] ?? ''), 'branch' => 'Golden A/S', 'locale' => $locale,
            'name' => 'Maria Example', 'email' => 'maria@example.com', 'url' => et_portal_url()];
}

/** Absolute address of the portal sign-in page, for the welcome e-mail (config app.base_url, else derived from the request).   GAS-CP-R36-001 */
function et_portal_url(): string {
    $b = trim((string) cfg('app.base_url', ''));
    if ($b === '' || str_contains($b, '127.0.0.1') || str_contains($b, 'localhost')) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'goldensas.com'));
        $b = ($https ? 'https' : 'http') . '://' . $host . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/customer/index.php')), '/');
    }
    return rtrim($b, '/') . '/';
}

/** The welcome / first-connection e-mail for one portal account (mailto:, To = the account's e-mail, in the account's language).   GAS-CP-R36-001 */
function et_welcome_mailto(array $x): string {
    $u = current_user(); $b = function_exists('br_get') && function_exists('br_has') && br_has() ? br_get((int)($x['branch_id'] ?? 0) ?: null) : [];
    $ctx = ['customer_id' => (int)($x['customer_id'] ?? 0) ?: null, 'branch_id' => (int)($b['id'] ?? 0) ?: null, 'locale' => isset(LOCALES[(string)($x['locale'] ?? '')]) ? (string)$x['locale'] : locale(),
            'user' => (string)($u['full_name'] ?? ''), 'branch' => (string)(($b['trade_name'] ?? '') ?: ($b['name'] ?? 'Golden A/S')), 'customer' => (string)($x['customer_name'] ?? ''),
            'name' => (string)$x['full_name'], 'email' => (string)$x['email'], 'url' => et_portal_url()];
    $t = et_render('welcome', $ctx);
    return 'mailto:' . rawurlencode((string)$x['email']) . '?subject=' . rawurlencode($t['subject']) . '&body=' . rawurlencode($t['body']);
}

function et_save_from_post(): int {
    require_cp_admin(); if (!et_has()) throw new RuntimeException(t('et.need_db'));
    $u = current_user(); $id = (int)($_POST['id'] ?? 0);
    $kind = in_array($_POST['kind'] ?? '', ET_KINDS, true) ? $_POST['kind'] : 'offer';
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: et_kind_label($kind);
    $locale = isset(LOCALES[(string)($_POST['locale'] ?? '')]) ? (string)$_POST['locale'] : '';
    $bid = (int)($_POST['branch_id'] ?? 0) ?: null; $cid = (int)($_POST['customer_id'] ?? 0) ?: null;
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255); $body = mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 20000);
    if ($kind !== 'signature' && $subject === '') throw new RuntimeException(t('et.need_subject'));
    if (trim($body) === '') throw new RuntimeException(t('et.need_body'));
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($id) q('UPDATE email_templates SET kind=?, name=?, locale=?, branch_id=?, customer_id=?, subject=?, body=?, is_active=?, updated_by=? WHERE id=?', [$kind, $name, $locale, $bid, $cid, $subject, $body, $active, (int)$u['id'], $id]);
    else { q('INSERT INTO email_templates (kind, name, locale, branch_id, customer_id, subject, body, is_active, updated_by) VALUES (?,?,?,?,?,?,?,?,?)', [$kind, $name, $locale, $bid, $cid, $subject, $body, $active, (int)$u['id']]); $id = (int) db()->lastInsertId(); }
    return $id;
}
function et_delete(int $id): void { require_cp_admin(); if (et_has()) q('DELETE FROM email_templates WHERE id = ?', [$id]); }
function et_duplicate(int $id): int {
    require_cp_admin(); $t = et_get($id); if (!$t) throw new RuntimeException(t('err.notfound'));
    q('INSERT INTO email_templates (kind, name, locale, branch_id, customer_id, subject, body, is_active, updated_by) VALUES (?,?,?,?,?,?,?,0,?)', [$t['kind'], mb_substr($t['name'] . ' (2)', 0, 120), $t['locale'], $t['branch_id'], $t['customer_id'], $t['subject'], $t['body'], (int)current_user()['id']]);
    return (int) db()->lastInsertId();
}

/** The language a message to this customer should use: the customer's portal accounts' most common language, else the sender's. */
function et_customer_locale(?int $customerId): string {
    if ($customerId) { try { $l = scalar("SELECT locale FROM customer_users WHERE customer_id = ? AND role = 'customer' AND is_active = 1 AND locale IS NOT NULL AND locale <> '' GROUP BY locale ORDER BY COUNT(*) DESC LIMIT 1", [$customerId]); if ($l && isset(LOCALES[$l])) return (string)$l; } catch (Throwable $e) {} }
    return locale();
}
