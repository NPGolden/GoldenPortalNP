<?php
/**
 * GAS-POMS — languages.
 *
 * Supported: English, Ελληνικά, Български, Русский, Italiano.
 *
 * Resolution order for the active language:
 *   1. ?lang=xx on the query string (also remembered in the session)
 *   2. the signed-in user's saved preference
 *   3. the browser's Accept-Language header
 *   4. English
 *
 * Strings live in lang/<code>.php as flat arrays. A missing key falls back
 * to English, and then to the key itself, so a half-finished translation
 * never blanks the interface.
 */

declare(strict_types=1);

const LOCALES = [
    'en' => ['name' => 'English',   'native' => 'English',   'dir' => 'ltr'],
    'el' => ['name' => 'Greek',     'native' => 'Ελληνικά',  'dir' => 'ltr'],
    'bg' => ['name' => 'Bulgarian', 'native' => 'Български', 'dir' => 'ltr'],
    'ru' => ['name' => 'Russian',   'native' => 'Русский',   'dir' => 'ltr'],
    'it' => ['name' => 'Italian',   'native' => 'Italiano',  'dir' => 'ltr'],
];

const DEFAULT_LOCALE = 'en';

function locale_valid(?string $code): bool {
    return $code !== null && isset(LOCALES[$code]);
}

/** Load and cache a language file. */
function lang_table(string $locale): array {
    static $cache = [];
    if (isset($cache[$locale])) return $cache[$locale];

    $file = __DIR__ . '/../lang/' . $locale . '.php';
    $cache[$locale] = is_file($file) ? (require $file) : [];
    return $cache[$locale];
}

/** The active language for this request. Set once by i18n_boot(). */
function locale(?string $set = null): string {
    static $current = DEFAULT_LOCALE;
    if ($set !== null && locale_valid($set)) $current = $set;
    return $current;
}

function i18n_boot(): void {
    // 1. explicit choice
    if (!empty($_GET['lang']) && locale_valid((string)$_GET['lang'])) {
        $_SESSION['locale'] = (string)$_GET['lang'];
        if (!empty($_SESSION['uid'])) {
            try { q('UPDATE users SET locale = ? WHERE id = ?', [$_SESSION['locale'], $_SESSION['uid']]); }
            catch (Throwable $e) { /* column may predate the migration */ }
        }
    }
    if (!empty($_SESSION['locale']) && locale_valid($_SESSION['locale'])) {
        locale($_SESSION['locale']);
        return;
    }

    // 2. saved preference
    if (!empty($_SESSION['uid'])) {
        try {
            $saved = (string) scalar('SELECT locale FROM users WHERE id = ?', [$_SESSION['uid']]);
            if (locale_valid($saved)) { $_SESSION['locale'] = $saved; locale($saved); return; }
        } catch (Throwable $e) { /* ignore */ }
    }

    // 3. browser
    $guess = locale_from_header((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($guess) { locale($guess); return; }

    // 4. English
    locale(DEFAULT_LOCALE);
}

function locale_from_header(string $header): ?string {
    foreach (explode(',', $header) as $part) {
        $code = strtolower(trim(explode(';', $part)[0]));
        $code = substr($code, 0, 2);
        // Greek browsers send "el", Bulgarian "bg", Russian "ru", Italian "it".
        if (locale_valid($code)) return $code;
    }
    return null;
}

/**
 * Translate. Placeholders are written {like_this} and replaced from $vars.
 *
 *   t('order.items_of', ['n' => 3, 'max' => 20])
 */
function t(string $key, array $vars = [], ?string $forceLocale = null): string {
    $loc  = $forceLocale && locale_valid($forceLocale) ? $forceLocale : locale();
    $line = lang_table($loc)[$key] ?? lang_table(DEFAULT_LOCALE)[$key] ?? $key;

    if ($vars) {
        foreach ($vars as $k => $v) {
            $line = str_replace('{' . $k . '}', (string)$v, $line);
        }
    }
    return $line;
}

/** Translate then escape for HTML. Use this in views. */
function tt(string $key, array $vars = []): string {
    return e(t($key, $vars));
}

/** A translated string for a specific person, used when writing notifications. */
function t_for(string $locale, string $key, array $vars = []): string {
    return t($key, $vars, $locale);
}

function locale_of_user(int $userId): string {
    try {
        $l = (string) scalar('SELECT locale FROM users WHERE id = ?', [$userId]);
        return locale_valid($l) ? $l : DEFAULT_LOCALE;
    } catch (Throwable $e) {
        return DEFAULT_LOCALE;
    }
}

/* ------------------------------------------------------------ dates */

/** Month abbreviations come from the language file, so dates read naturally. */
function locale_month(int $m, ?string $forceLocale = null): string {
    return t('month.' . $m, [], $forceLocale);
}

/** Keep the current query string, swap the language. */
function lang_switch_url(string $code): string {
    $params = $_GET;
    $params['lang'] = $code;
    return 'index.php?' . http_build_query($params);
}
