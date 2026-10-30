<?php
/**
 * Interface theme per account: "light" (Editorial) or "dark" (Glass).   GAS-CP-R16-001
 * Stored in customer_users.ui_theme (R16); before the migration it lives in the session.
 */
function theme_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) db()->query("SHOW COLUMNS FROM customer_users LIKE 'ui_theme'")->fetch(); } catch (Throwable $e) { $has = false; } }
    return $has;
}
function theme_valid(string $t): bool { return in_array($t, ['light', 'dark'], true); }
/** The theme to render: the account's choice, else the session's, else light. */
function theme_current(): string {
    $u = function_exists('current_user') ? current_user() : null;
    if ($u && theme_has() && !empty($u['ui_theme']) && theme_valid($u['ui_theme'])) return $u['ui_theme'];
    $s = (string)($_SESSION['ui_theme'] ?? ''); return theme_valid($s) ? $s : 'light';
}
function theme_set(string $t): void {
    if (!theme_valid($t)) return;
    $_SESSION['ui_theme'] = $t;
    $u = current_user();
    if ($u && theme_has()) q('UPDATE customer_users SET ui_theme = ? WHERE id = ?', [$t, (int)$u['id']]);
}
/** The ☾ / ☀ toggle in the top bar (a tiny POST form, so it works without JavaScript). */
function theme_toggle_html(string $active): string {
    $cur = theme_current(); $next = $cur === 'dark' ? 'light' : 'dark';
    return '<form method="post" action="' . url($active ?: 'dashboard') . '" class="themeform">' . csrf_field()
         . '<input type="hidden" name="action" value="theme_save"><input type="hidden" name="theme" value="' . $next . '">'
         . '<button type="submit" class="themebtn" title="' . tt('ui.switch_' . $next) . '" aria-label="' . tt('ui.switch_' . $next) . '">' . ($cur === 'dark' ? '☀' : '☾') . '</button></form>';
}
