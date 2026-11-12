<?php
/**
 * Marine intelligence (GAS-CP-R97-027) — Commercial › Web Sales, third tab.
 * The portal reads public news feeds (RSS, Atom, Google-News sitemaps; a web page that announces its feed is followed once) and files
 * each headline under one of five groups. A feed may be bound to a group (every item goes there) or left open (the headline's words
 * decide; headlines that fit no group are dropped). Only headline, link, source, date and a short plain-text extract are kept — the
 * article itself is opened on the publisher's site. Read once a day from the notification sweep and on demand.
 */
const MI_GROUPS = ['newbuild', 'refit', 'ess', 'hybrid', 'tech'];
const MI_MAX_BYTES = 1500000;   // one feed
const MI_KEEP_DAYS = 180;       // items older than this are removed
const MI_FRESH_DAYS = 45;       // older items of a feed are not taken in
function mi_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'mi_items'"); } catch (Throwable $e) { $h = false; } } return $h; }
function mi_can_manage(): bool { return is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('pi_is_sales') && pi_is_sales()); }
/** The feeds a new installation starts with. Group-bound searches first (they always return something), then the trade titles. */
function mi_default_feeds(): array {
    $g = fn(string $q) => 'https://news.google.com/rss/search?q=' . rawurlencode($q . ' when:8d') . '&hl=en-GB&gl=GB&ceid=GB:en';
    return [
        ['News search — new-build yachts, Europe & Middle East', $g('superyacht (launched OR launch OR delivered OR "new build" OR "keel laying" OR "sold") (Italy OR Netherlands OR Germany OR Turkey OR Greece OR UAE OR Feadship OR Lurssen OR Benetti OR Sanlorenzo OR Oceanco OR Heesen)'), 'newbuild', 'yacht'],
        ['News search — yacht refit, Europe', $g('superyacht refit (shipyard OR yard) (Europe OR Spain OR Italy OR France OR Netherlands OR Greece OR Turkey OR Croatia OR Montenegro)'), 'refit', 'yacht'],
        ['News search — marine energy storage', $g('("energy storage" OR "battery system" OR "marine battery") (vessel OR ferry OR yacht OR ship)'), 'ess', 'marine'],
        ['News search — hybrid vessels', $g('("hybrid propulsion" OR "hybrid vessel" OR "hybrid yacht" OR "diesel-electric" OR "electric ferry")'), 'hybrid', 'marine'],
        ['News search — yachting technology', $g('(superyacht OR yacht) (technology OR navigation OR automation OR "integrated bridge" OR stabiliser OR connectivity OR "monitoring system")'), 'tech', 'yacht'],
        ['SuperYacht Times (news search)', $g('site:superyachttimes.com'), null, 'yacht'],
        ['BOAT International (news search)', $g('site:boatinternational.com superyacht'), null, 'yacht'],
        ['Superyacht News', 'https://www.superyachtnews.com/sitemapnews.xml', null, 'yacht'],
        ['Megayacht News', 'https://megayachtnews.com/feed/', null, 'yacht'],
        ['Superyacht Investor', 'https://www.superyachtinvestor.com/feed/', null, 'yacht'],
        ['Superyacht Content', 'https://www.superyachtcontent.com/feed/', null, 'yacht'],
        ['CharterWorld news', 'https://www.charterworld.com/news/feed', null, 'yacht'],
        ['Marine Industry News', 'https://marineindustrynews.co.uk/feed/', null, 'marine'],
        ['Electric & Hybrid Marine Technology', 'https://www.electrichybridmarinetechnology.com/feed/', null, 'marine'],
        ['The Maritime Executive', 'https://maritime-executive.com/articles.rss', null, 'marine'],
        ['Splash247', 'https://splash247.com/feed/', null, 'marine'],
        ['Marine Log', 'https://www.marinelog.com/feed/', null, 'marine'],
        ['MarineLink', 'https://www.marinelink.com/news/rss', null, 'marine'],
        ['Offshore Energy', 'https://www.offshore-energy.biz/feed/', null, 'marine'],
    ];
}
function mi_seed(): void {
    if (!mi_has() || (int) scalar('SELECT COUNT(*) FROM mi_feeds') > 0 || cp_setting('mi_seeded', '') === '1') return;
    foreach (mi_default_feeds() as [$n, $u, $g, $k]) q('INSERT INTO mi_feeds (name, url, grp, kind) VALUES (?,?,?,?)', [$n, $u, $g, $k]);
    cp_setting_set('mi_seeded', '1');
}
/** Does a headline fit a group? The word tests behind both the open feeds and the check on group-bound searches. */
function mi_fits(string $grp, string $t, bool $yachtTitle): bool {
    $yacht = $yachtTitle || preg_match('/\b(super|mega|giga)?yachts?\b|\bm\/y\b|\bs\/y\b|\bmotor ?yacht|\bcatamaran\b/u', $t);
    $sea = $yacht || preg_match('/\b(vessels?|ships?|ferr(y|ies)|marine|maritime|boats?|tugs?|tugboat|carrier|tanker|cruise|offshore|shipyard|shipbuild\w*|shipping|port of|harbou?r|workboat|crew transfer|\bpsv\b|\bro-?pax\b)\b/u', $t);
    switch ($grp) {
        case 'ess':    return $sea && preg_match('/\b(energy storage|battery system|battery pack|battery bank|battery[- ]powered|battery[- ]electric|batter(y|ies)|lithium|li-ion|supercapacitor|\bbess\b|\bess\b|\bkwh\b|\bmwh\b)/u', $t);
        case 'hybrid': return $sea && preg_match('/\b(hybrid|diesel[- ]electric|electric propulsion|all[- ]electric|fully electric|electric ferr|fuel cells?|hydrogen[- ]powered|methanol[- ](powered|fuel|ready)|zero[- ]emission|shore power|cold ironing)/u', $t);
        case 'refit':  return $yacht && preg_match('/\b(refit|refitted|refits|rebuild|rebuilt|conversion|converted|refurbish\w*|yard period|repaint\w*|lengthen\w*)\b/u', $t);
        case 'newbuild':
            if (!$yacht || preg_match('/\b(westport|burger boat|delta marine|christensen|trinity yachts|hargrave|viking yachts|horizon yachts|ocean alexander|cheoy lee|heysea|kingship|silver yachts|echo yachts|mcconaghy|taiwan|china|chinese|australia\w*|new zealand|florida|usa|u\.s\.|american|brazil\w*)\b/u', $t)) return false;
            return (bool) preg_match('/\b(launch(es|ed)?|deliver(s|ed|y)|keel[- ]lay\w*|keel laid|hull\b.{0,40}\b(joined|arriv\w*|turned)|new[- ]?build\w*|under construction|construction (begins|starts|update)|sea trials?|technical launch|order(ed)? (for|placed)|contract signed|in[- ]build|splash(es|ed)?|first hull|hull no|\d{4} delivery)\b/u', $t);
        case 'tech':   return $yacht && preg_match('/\b(technolog\w*|systems?|software|navigation|radar|autopilot|automation|bridge|stabili[sz]\w*|gyro|satcom|starlink|connectivity|sensors?|digital|monitoring|cyber\w*|lighting|av\/it|propulsion|engines?|generators?|thrusters?|azipod|pods?|patent\w*|innovation\w*|electronics?)\b/u', $t);
    }
    return false;
}
/** Headlines that are not news: market-research blurbs, share-price notes, section pages. */
function mi_junk(string $title, string $source): bool {
    $t = mb_strtolower($title);
    return mb_strlen($title) < 22 || preg_match('/^news\b|\b(market (size|share|report|outlook|analysis|to (reach|hit|grow))|cagr|forecast (to|period)|\d{4}\s*[-–]\s*\d{4}|stock (takes|rises|falls|jumps)|share price|shares (rise|fall|jump))\b/u', $t)
        || preg_match('/\b(openpr|indexbox|ad-hoc-news|marketwatch|globenewswire|einpresswire|prnewswire|business wire|yahoo finance|the cool down)\b/iu', $source);
}
/** Group of a headline by its words. $kind: 'yacht' = a yachting title, 'marine' = general shipping (energy storage and hybrid only, unless the headline itself is about a yacht). */
function mi_classify(string $title, string $summary, string $kind): ?string {
    $t = ' ' . mb_strtolower($title . ' ' . $summary) . ' ';
    foreach (['ess', 'hybrid', 'refit', 'newbuild', 'tech'] as $g) if (mi_fits($g, $t, $kind === 'yacht')) return $g;
    return null;
}
/** Fetch a public http(s) address safely (same fences as the vessel link preview): returns [body, content type, error]. */
function mi_http_get(string $url): array {
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url); $host = strtolower((string)($p['host'] ?? '')); $scheme = strtolower((string)($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true))) return ['', '', 'address refused'];
        $ip = function_exists('vm_public_ip') ? vm_public_ip($host) : null; if ($ip === null) return ['', '', 'host refused'];
        $port = $scheme === 'https' ? 443 : 80; $buf = ''; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 9, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'GoldenAS-Portal/1.0 (news reader; +https://www.goldenas.com.gr)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.8'],
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > MI_MAX_BYTES ? 0 : strlen($d); }]);
        curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200) return ['', $ct, 'HTTP ' . $code];
        return [$buf, $ct, strlen($buf) > MI_MAX_BYTES ? 'too large' : ''];
    }
    return ['', '', 'too many redirects'];
}
function mi_text($v, int $max): string { $s = html_entity_decode(strip_tags(html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); $s = trim(preg_replace('/\s+/u', ' ', $s)); if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1'); return mb_substr($s, 0, $max); }
/** Items of a feed document: [[title, link, summary, timestamp, source], …]. RSS 2.0, Atom and Google-News sitemaps. Null = not a feed. */
function mi_parse(string $xml): ?array {
    if ($xml === '' || stripos($xml, '<!ENTITY') !== false) return null;
    $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml, 1);
    $prev = libxml_use_internal_errors(true); $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT); libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$x) return null; $out = []; $root = strtolower($x->getName());
    if ($root === 'rss' || $root === 'rdf') {
        $items = $root === 'rss' ? ($x->channel->item ?? []) : ($x->item ?? []);
        foreach ($items as $it) { $src = isset($it->source) ? (string)$it->source : ''; $d = (string)($it->pubDate ?? ''); if ($d === '') { $dc = $it->children('http://purl.org/dc/elements/1.1/'); $d = (string)($dc->date ?? ''); }
            $out[] = [(string)$it->title, (string)$it->link, (string)$it->description, strtotime($d) ?: 0, $src]; }
    } elseif ($root === 'feed') {
        foreach ($x->entry as $en) { $link = ''; foreach ($en->link as $l) { $rel = (string)($l['rel'] ?? 'alternate'); if ($rel === 'alternate' || $link === '') $link = (string)$l['href']; }
            $out[] = [(string)$en->title, $link, (string)($en->summary ?? $en->content ?? ''), strtotime((string)($en->published ?? $en->updated ?? '')) ?: 0, '']; }
    } elseif ($root === 'urlset') {
        foreach ($x->url as $u) { $n = $u->children('http://www.google.com/schemas/sitemap-news/0.9'); if (!isset($n->news)) continue;
            $out[] = [(string)$n->news->title, (string)$u->loc, '', strtotime((string)$n->news->publication_date) ?: 0, (string)($n->news->publication->name ?? '')]; }
    } else return null;
    return $out;
}
/** A web page that announces its feed: the first <link rel="alternate" type="application/rss+xml|atom+xml" href>. */
function mi_discover(string $html, string $base): ?string {
    if (!preg_match_all('/<link\b[^>]*>/i', substr($html, 0, 300000), $m)) return null;
    foreach ($m[0] as $tag) {
        if (!preg_match('/type\s*=\s*["\']application\/(rss|atom)\+xml["\']/i', $tag) || !preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) continue;
        $u = html_entity_decode($h[1], ENT_QUOTES, 'UTF-8'); $b = parse_url($base);
        if (str_starts_with($u, '//')) $u = ($b['scheme'] ?? 'https') . ':' . $u; elseif (str_starts_with($u, '/')) $u = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . $u; elseif (!preg_match('~^https?://~i', $u)) continue;
        return $u;
    }
    return null;
}
/** Read one feed and file what is new. Returns [new items, error]. */
function mi_fetch_feed(array $f): array {
    [$body, $ct, $err] = mi_http_get((string)$f['url']); $items = $err === '' ? mi_parse($body) : null;
    if ($err === '' && $items === null) { $alt = mi_discover($body, (string)$f['url']); if ($alt) { [$body, $ct, $err] = mi_http_get($alt); $items = $err === '' ? mi_parse($body) : null; } if ($err === '' && $items === null) $err = 'no feed found at this address'; }
    $new = $err === '' ? mi_file_items($f, (array)$items) : 0;
    q('UPDATE mi_feeds SET last_run_at = UTC_TIMESTAMP(), last_ok_at = IF(? = \'\', UTC_TIMESTAMP(), last_ok_at), last_new = ?, last_error = ? WHERE id = ?', [$err, $new, $err !== '' ? mb_substr($err, 0, 200) : null, (int)$f['id']]);
    return [$new, $err];
}
/** File the parsed items of a feed: clean, date window, group, duplicates (same link or same headline). Returns the number of new headlines. */
function mi_file_items(array $f, array $items): int {
    $new = 0; $min = time() - MI_FRESH_DAYS * 86400; $fixed = in_array((string)$f['grp'], MI_GROUPS, true) ? (string)$f['grp'] : null; $n = 0;
    foreach ((array)$items as [$title, $link, $sum, $ts, $src]) {
        if (++$n > 150) break; $title = mi_text($title, 300); $link = function_exists('vm_url_clean') ? vm_url_clean(trim((string)$link)) : null; if ($title === '' || !$link) continue;
        if ($ts <= 0) $ts = time(); if ($ts < $min) continue; if ($ts > time() + 86400) $ts = time();
        $source = mi_text($src, 120); if ($source === '' && preg_match('/^(.{12,}) - ([^-]{2,60})$/u', $title, $mm) && str_contains((string)$f['url'], 'news.google.')) { $title = trim($mm[1]); $source = trim($mm[2]); }
        if ($source !== '' && mb_strlen($title) > mb_strlen($source) + 15 && str_ends_with($title, ' - ' . $source)) $title = trim(mb_substr($title, 0, -mb_strlen(' - ' . $source)));   // news searches append the publisher to the headline
        if ($source === '') $source = mb_substr((string)$f['name'], 0, 120);
        $sum = mi_text($sum, 600); $sum = trim(preg_replace('/\s*(\[(…|\.\.\.|&hellip;)\]|…|\.\.\.)?\s*The post .{0,300} appeared first on .{0,120}$/us', '…', $sum)); if (mb_strlen($sum) > 280) $sum = rtrim(mb_substr($sum, 0, 277)) . '…';   // a short extract only — the article is read on the publisher's site
        if ($sum !== '' && (mb_stripos($sum, mb_substr($title, 0, 40)) === 0)) $sum = '';   // an extract that only repeats the headline
        if (mi_junk($title, $source)) continue;
        $grp = $fixed !== null && mi_fits($fixed, ' ' . mb_strtolower($title . ' ' . $sum) . ' ', false) ? $fixed : mi_classify($title, $sum, $fixed !== null ? 'marine' : (string)$f['kind']); if ($grp === null) continue;   // a bound search still has to fit its group — or, read strictly, another one — by its words
        $th = sha1(trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title)))); if (scalar('SELECT 1 FROM mi_items WHERE title_hash = ? LIMIT 1', [$th])) continue;
        try { $new += q('INSERT IGNORE INTO mi_items (feed_id, grp, title, url, source, summary, published_at, url_hash, title_hash) VALUES (?,?,?,?,?,?,?,?,?)', [(int)$f['id'], $grp, $title, mb_substr($link, 0, 1000), $source, $sum !== '' ? $sum : null, gmdate('Y-m-d H:i:s', $ts), sha1($link), $th])->rowCount(); } catch (Throwable $e) {}
    }
    return $new;
}
/** Read the active feeds, the longest-waiting first, within a time budget. Returns [feeds read, new items]. */
function mi_fetch_all(int $budget = 25): array {
    if (!mi_has()) return [0, 0]; mi_seed(); $t0 = microtime(true); $nf = 0; $nn = 0;
    foreach (all('SELECT * FROM mi_feeds WHERE is_active = 1 ORDER BY (last_run_at IS NULL) DESC, last_run_at, id') as $f) { if (microtime(true) - $t0 > $budget) break; try { [$n] = mi_fetch_feed($f); $nn += $n; } catch (Throwable $e) { error_log('[mi] ' . $f['name'] . ': ' . $e->getMessage()); } $nf++; }
    try { q('DELETE FROM mi_items WHERE published_at < (UTC_TIMESTAMP() - INTERVAL ' . MI_KEEP_DAYS . ' DAY)'); } catch (Throwable $e) {}
    return [$nf, $nn];
}
/** From the notification sweep: ONE feed per run — the one that has waited longest and was not read in the last 20 hours — so no page request ever waits for the whole list. */
function mi_sweep(): void {
    if (!mi_has()) return; mi_seed();
    $f = one('SELECT * FROM mi_feeds WHERE is_active = 1 AND (last_run_at IS NULL OR last_run_at < (UTC_TIMESTAMP() - INTERVAL 20 HOUR)) ORDER BY (last_run_at IS NULL) DESC, last_run_at, id LIMIT 1'); if (!$f) return;
    q('UPDATE mi_feeds SET last_run_at = UTC_TIMESTAMP() WHERE id = ?', [(int)$f['id']]);   // claimed first: a second request does not read the same feed
    try { mi_fetch_feed($f); } catch (Throwable $e) { error_log('[mi] ' . $f['name'] . ': ' . $e->getMessage()); }
    if (random_int(1, 30) === 1) { try { q('DELETE FROM mi_items WHERE published_at < (UTC_TIMESTAMP() - INTERVAL ' . MI_KEEP_DAYS . ' DAY)'); } catch (Throwable $e) {} }
}
function mi_week_bounds(int $back): array { $mon = strtotime('monday this week 00:00 UTC') - $back * 7 * 86400; return [gmdate('Y-m-d H:i:s', $mon), gmdate('Y-m-d H:i:s', $mon + 7 * 86400), (int) gmdate('W', $mon), (int) gmdate('o', $mon), $mon]; }
function mi_items(string $from, string $to, bool $withHidden = false): array {
    $o = array_fill_keys(MI_GROUPS, []); if (!mi_has()) return $o;
    foreach (all('SELECT * FROM mi_items WHERE published_at >= ? AND published_at < ?' . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY published_at DESC, id DESC LIMIT 600', [$from, $to]) as $r) if (isset($o[$r['grp']])) $o[$r['grp']][] = $r;
    return $o;
}
/** The tab bar of Commercial › Web Sales. */
function web_sales_tabs(string $active): void {
    $tabs = []; if (function_exists('web_inbox_has') && web_inbox_has()) $tabs['web_inbox'] = ['🌐 ' . t('web.inbox'), function_exists('web_inbox_count') ? web_inbox_count() : 0];
    if (function_exists('ws_has') && ws_has()) $tabs['web_stats'] = ['📈 ' . t('ws.title'), 0]; if (mi_has()) $tabs['marine_intel'] = ['🧭 ' . t('mi.title'), 0];
    echo '<p class="eyebrow">' . tt('nav.g_commercial') . ' › ' . tt('nav.web_sales') . '</p><div class="tabs" style="margin-top:6px;">';
    foreach ($tabs as $k => [$l, $n]) echo '<a href="' . url($k) . '"' . ($k === $active ? ' class="is-on"' : '') . '>' . e($l) . ($n ? '<span class="n">' . (int)$n . '</span>' : '') . '</a>';
    echo '</div>';
}
/** The first available tab (where the menu entry lands). */
function web_sales_home(): string { return function_exists('web_inbox_has') && web_inbox_has() ? 'web_inbox' : (function_exists('ws_has') && ws_has() ? 'web_stats' : 'marine_intel'); }
function mi_handle_action(string $action): bool {
    if (!in_array($action, ['mi_fetch', 'mi_add', 'mi_hide', 'mi_move', 'mi_feed_add', 'mi_feed_toggle', 'mi_feed_delete'], true)) return false;
    require_staff(); if (!mi_has()) throw new RuntimeException(t('err.notfound')); if (!mi_can_manage()) throw new RuntimeException(t('err.noaccess'));
    $back = fn(string $a = '') => redirect('marine_intel', ['w' => (int)($_POST['w'] ?? 0), '_r' => uniqid()], $a);
    if ($action === 'mi_fetch') { $last = (int) cp_setting('mi_manual_at', '0'); if (time() - $last < 120) throw new RuntimeException(t('mi.wait')); cp_setting_set('mi_manual_at', (string) time()); [$nf, $nn] = mi_fetch_all(40); flash('good', t('mi.fetched', ['f' => $nf, 'n' => $nn])); $back(); }
    if ($action === 'mi_add') {
        $title = mi_text($_POST['title'] ?? '', 300); $link = vm_url_clean(trim((string)($_POST['url'] ?? ''))); $grp = (string)($_POST['grp'] ?? '');
        if ($title === '' || !$link || !in_array($grp, MI_GROUPS, true)) throw new RuntimeException(t('mi.add_bad'));
        q('INSERT INTO mi_items (grp, title, url, source, summary, published_at, url_hash, title_hash, added_by) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?,?,?) ON DUPLICATE KEY UPDATE grp = VALUES(grp), hidden = 0', [$grp, $title, $link, mi_text($_POST['source'] ?? '', 120) ?: (string) parse_url($link, PHP_URL_HOST), mi_text($_POST['summary'] ?? '', 600) ?: null, sha1($link), sha1(trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title)))), (int) current_user()['id']]);
        flash('good', t('mi.added')); $back('#g-' . $grp);
    }
    if ($action === 'mi_hide') { q('UPDATE mi_items SET hidden = ? WHERE id = ?', [empty($_POST['show']) ? 1 : 0, (int)($_POST['id'] ?? 0)]); $back(); }
    if ($action === 'mi_move') { $grp = (string)($_POST['grp'] ?? ''); if (in_array($grp, MI_GROUPS, true)) q('UPDATE mi_items SET grp = ? WHERE id = ?', [$grp, (int)($_POST['id'] ?? 0)]); $back('#g-' . $grp); }
    if (!is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    if ($action === 'mi_feed_add') {
        $name = mi_text($_POST['name'] ?? '', 120); $u = vm_url_clean(trim((string)($_POST['url'] ?? ''))); $grp = (string)($_POST['grp'] ?? ''); $kind = ($_POST['kind'] ?? '') === 'marine' ? 'marine' : 'yacht';
        if ($name === '' || !$u) throw new RuntimeException(t('mi.feed_bad')); if ((int) scalar('SELECT COUNT(*) FROM mi_feeds') >= 40) throw new RuntimeException(t('mi.feed_max'));
        q('INSERT INTO mi_feeds (name, url, grp, kind) VALUES (?,?,?,?)', [$name, mb_substr($u, 0, 700), in_array($grp, MI_GROUPS, true) ? $grp : null, $kind]); flash('good', t('sys.saved')); $back('#feeds');
    }
    if ($action === 'mi_feed_toggle') { q('UPDATE mi_feeds SET is_active = 1 - is_active WHERE id = ?', [(int)($_POST['id'] ?? 0)]); $back('#feeds'); }
    if ($action === 'mi_feed_delete') { q('DELETE FROM mi_feeds WHERE id = ?', [(int)($_POST['id'] ?? 0)]); flash('good', t('sys.saved')); $back('#feeds'); }
    return true;
}
