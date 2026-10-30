<?php
/** Customer Portal languages — EN / EL / IT / UK / BG. Flat key/value
 *  files in lang/<code>.php; the drop-down in the header lists LOCALES. */
declare(strict_types=1);

/* Add a language: one line here + lang/<code>.php with the same keys as
 * lang/en.php (missing keys fall back to English). The selector is a
 * drop-down, so the header layout does not change.                  R12 */
const LOCALES = [
    'en' => ['native' => 'English',    'short' => 'EN'],
    'el' => ['native' => 'Ελληνικά',   'short' => 'GR'],
    'it' => ['native' => 'Italiano',   'short' => 'IT'],
    'uk' => ['native' => 'Українська', 'short' => 'UA'],
    'bg' => ['native' => 'Български',  'short' => 'BG'],
];
const DEFAULT_LOCALE = 'en';

function locale_valid(?string $c): bool { return $c !== null && isset(LOCALES[$c]); }

function lang_table(string $l): array {
    static $c = [];
    if (!isset($c[$l])) { $f = __DIR__ . '/../lang/' . $l . '.php'; $c[$l] = is_file($f) ? require $f : []; }
    return $c[$l];
}

function locale(?string $set = null): string {
    static $cur = DEFAULT_LOCALE;
    if ($set !== null && locale_valid($set)) $cur = $set;
    return $cur;
}

function i18n_boot(): void {
    if (!empty($_GET['lang']) && locale_valid((string)$_GET['lang'])) {
        $_SESSION['locale'] = (string)$_GET['lang'];
        if (!empty($_SESSION['cu_id'])) {
            try { q('UPDATE customer_users SET locale = ? WHERE id = ?', [$_SESSION['locale'], $_SESSION['cu_id']]); }
            catch (Throwable $e) {}
        }
    }
    if (!empty($_SESSION['locale']) && locale_valid($_SESSION['locale'])) { locale($_SESSION['locale']); return; }
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $c = substr(strtolower(trim(explode(';', $part)[0])), 0, 2);
        if (locale_valid($c)) { locale($c); return; }
    }
}

function t(string $key, array $vars = []): string {
    $line = lang_table(locale())[$key] ?? lang_table(DEFAULT_LOCALE)[$key] ?? $key;
    foreach ($vars as $k => $v) $line = str_replace('{' . $k . '}', (string)$v, $line);
    return $line;
}
function tt(string $key, array $vars = []): string { return e(t($key, $vars)); }
/** Same, in a given language (e-mail templates to a customer in his language).   R32-001 */
function t_in(string $key, string $locale, array $vars = []): string {
    $line = lang_table(isset(LOCALES[$locale]) ? $locale : DEFAULT_LOCALE)[$key] ?? lang_table(DEFAULT_LOCALE)[$key] ?? $key;
    foreach ($vars as $k => $v) $line = str_replace('{' . $k . '}', (string)$v, $line);
    return $line;
}

function lang_switch_url(string $code): string {
    $p = $_GET; $p['lang'] = $code;
    return 'index.php?' . http_build_query($p);
}

/** The language drop-down (header, login, 2FA): a <select> that navigates on
 *  change, with a no-script "Go" button. $dark = on the navy top bar. */
function lang_selector(bool $dark = true): string {
    $h = '<form method="get" action="index.php" class="langsel' . ($dark ? ' langsel--dark' : '') . '" aria-label="Language">';
    foreach ($_GET as $k => $v) if ($k !== 'lang' && is_scalar($v)) $h .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    $h .= '<span class="langsel-ico" aria-hidden="true">🌐</span><select name="lang" class="js-langsel" title="' . e(LOCALES[locale()]['native']) . '">';
    foreach (LOCALES as $code => $meta) $h .= '<option value="' . e($code) . '"' . ($code === locale() ? ' selected' : '') . ' title="' . e($meta['native']) . '">' . e($dark ? $meta['short'] : $meta['native']) . '</option>';   // top bar: two-letter codes keep the button small (GAS-CP-R36-002)
    return $h . '</select><noscript><button type="submit" class="btn btn--sm">OK</button></noscript></form>';
}
