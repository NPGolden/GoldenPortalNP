<?php
/**
 * Known shipyards (GAS-CP-R97-052): propose the "Yard" role for organisations whose name carries a general shipyard word
 * or the name of a known yard. Nothing is changed by the scan — the roles are added only for the rows approved on
 * ?p=companies_yards. The name list was approved by the owner on 15/11/2026.
 */
declare(strict_types=1);

/** Stems found anywhere in the name. */
const YD_STEMS = ['shipyard', 'shipbuild', 'ship build', 'ship repair', 'shiprepair', 'dockyard', 'drydock', 'dry dock', 'boatyard', 'boat yard', 'yacht yard', 'werft', 'scheepswerf', 'cantiere', 'cantieri', 'chantier nav', 'chantiers', 'astillero', 'estaleiro', 'tersane', 'ναυπηγ', 'stocznia', 'brodogradili', 'skipsverft', 'skibsvaerft'];
/** Whole words. */
const YD_WORDS = ['yard', 'yards', 'werf', 'verft', 'varv'];
/** Known yards — matched as whole words / phrases on the normalised name. */
const YD_NAMES = [
    // Netherlands
    'feadship', 'de vries', 'van lent', 'oceanco', 'heesen', 'amels', 'damen', 'royal huisman', 'huisman', 'vitters', 'hakvoort', 'moonen', 'icon yachts', 'balk', 'holterman', 'van der valk', 'jongert', 'contest yachts', 'royal ihc', 'ihc', 'bodewes', 'barkmeijer', 'ferus smit', 'niestern sander', 'de hoop', 'kooiman', 'padmos', 'conoship', 'neptune marine',
    // Germany
    'lurssen', 'luerssen', 'blohm voss', 'blohm and voss', 'abeking rasmussen', 'abeking and rasmussen', 'abeking', 'nobiskrug', 'german naval yards', 'meyer werft', 'fassmer', 'lloyd werft', 'peene werft', 'flensburger', 'fsg', 'thyssenkrupp marine', 'tkms',
    // Italy
    'benetti', 'azimut', 'sanlorenzo', 'san lorenzo', 'ferretti', 'crn', 'custom line', 'pershing', 'perini navi', 'perini', 'baglietto', 'codecasa', 'rossinavi', 'rossi navi', 'tankoa', 'isa yachts', 'palumbo', 'columbus yachts', 'mondomarine', 'mondo marine', 'cantiere delle marche', 'overmarine', 'mangusta', 'admiral yachts', 'italian sea group', 'nca refit', 'fincantieri', 'mariotti', 'amico co', 'amico and co', 'lusben', 'wider yachts', 'vsy', 'viareggio superyachts', 'picchiotti', 'arcadia yachts', 'bluegame', 'san giorgio del porto', 'visentini', 'intermarine',
    // United Kingdom
    'sunseeker', 'princess yachts', 'pendennis', 'fairline', 'babcock', 'bae systems', 'cammell laird', 'harland wolff', 'harland and wolff', 'devonport',
    // France, Monaco
    'chantiers de l atlantique', 'couach', 'ocea', 'piriou', 'naval group', 'cmn', 'constructions mecaniques de normandie', 'monaco marine', 'compositeworks',
    // Spain, Portugal
    'mb92', 'mb 92', 'astilleros de mallorca', 'navantia', 'armon', 'gondan', 'zamakona', 'astondoa', 'nodosa', 'barreras', 'varadero valencia', 'west sea',
    // Greece
    'neorion', 'elefsis', 'elefsina', 'skaramangas', 'skaramanga', 'hellenic shipyards', 'onex shipyards', 'onex neorion', 'onex elefsis', 'chalkis', 'halkis', 'spanopoulos', 'golden yachts', 'megatechnica',
    // Turkey
    'turquoise yachts', 'bilgin', 'numarine', 'sirena', 'dunya', 'sedef', 'tersan', 'besiktas', 'cemre', 'sanmar', 'rmk marine', 'mengi yay', 'alia yachts', 'ak yacht', 'akyacht', 'bering yachts', 'ada yacht', 'sarp yachts', 'dearsan', 'desan', 'gemak', 'kuzey star',
    // Adriatic, Poland
    'uljanik', 'brodosplit', '3 maj', 'viktor lenac', 'brodotrogir', 'adriatic 42', 'bijela', 'remontowa', 'crist', 'sunreef', 'galeon',
    // Nordics
    'ulstein', 'vard', 'kleven', 'havyard', 'fosen', 'myklebust', 'westcon', 'meyer turku', 'rauma marine', 'baltic yachts', 'nautor', 'karstensen', 'orskov', 'fayard',
    // USA, Canada
    'westport yachts', 'trinity yachts', 'burger boat', 'delta marine', 'christensen yachts', 'christensen shipyards', 'derecktor', 'rybovich', 'lauderdale marine center', 'bollinger', 'huntington ingalls', 'bath iron works', 'nassco', 'electric boat', 'austal', 'halter marine', 'edison chouest', 'marinette marine', 'vigor marine', 'vigor industrial', 'gladding hearn', 'nichols brothers', 'hinckley', 'viking yachts', 'hatteras', 'thunderbolt marine', 'detyens', 'colonna', 'seaspan', 'irving',
    // Asia
    'hd hyundai heavy', 'hyundai heavy', 'hyundai mipo', 'samsung heavy', 'hanwha ocean', 'dsme', 'hj shipbuilding', 'mitsubishi heavy', 'imabari', 'japan marine united', 'kawasaki heavy', 'oshima', 'tsuneishi', 'namura', 'cssc', 'cosco shipping heavy', 'cosco heavy', 'hudong zhonghua', 'jiangnan', 'yangzijiang', 'waigaoqiao', 'cheoy lee', 'kingship', 'heysea', 'horizon yachts', 'ocean alexander', 'keppel', 'sembcorp marine', 'seatrium', 'st engineering marine', 'paxocean', 'pax ocean', 'strategic marine', 'asl marine', 'mazagon dock', 'garden reach', 'pt pal',
    // Middle East
    'gulf craft', 'asry', 'drydocks world', 'nakilat', 'n kom', 'albwardy', 'grandweld', 'international maritime industries',
    // Australia, New Zealand, other
    'echo yachts', 'silveryachts', 'silver yachts', 'incat', 'civmec', 'rivergate', 'alloy yachts', 'mcmullen wing', 'mcmullen and wing', 'yachting developments', 'orams', 'southern wind', 'inace', 'mcp yachts',
];
/** Words that suggest a group company which is not itself a yard — proposed, but not ticked. */
const YD_WEAK = ['components', 'services', 'service', 'electronics', 'equipment', 'supply', 'supplies', 'trading', 'finance', 'financial', 'insurance', 'brokerage', 'charter', 'academy', 'foundation', 'logistics', 'software', 'consulting', 'consultants', 'design', 'agency', 'agencies'];

/** Lower case, accents folded, punctuation to spaces, padded with one space on each side. */
function yd_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ü' => 'u', 'ö' => 'o', 'ä' => 'a', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n', 'ş' => 's', 'š' => 's', 'ś' => 's', 'ž' => 'z', 'ż' => 'z', 'ğ' => 'g', 'ł' => 'l', 'đ' => 'd', 'æ' => 'ae', 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', '&' => ' and ', '+' => ' and ']);
    $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return ' ' . trim((string) preg_replace('/\s+/', ' ', $s)) . ' ';
}
/** What in the name says "yard"? ['why' => 'name'|'word', 'hit' => text, 'weak' => bool] or null. */
function yd_match(string $name): ?array {
    $n = yd_norm($name); if (trim($n) === '') return null; $hit = null;
    foreach (YD_NAMES as $k) if (str_contains($n, ' ' . $k . ' ')) { if ($hit === null || strlen($k) > strlen($hit['hit'])) $hit = ['why' => 'name', 'hit' => $k]; }
    if ($hit === null) { foreach (YD_STEMS as $k) if (str_contains($n, $k)) { $hit = ['why' => 'word', 'hit' => $k]; break; } }
    if ($hit === null) { foreach (YD_WORDS as $k) if (str_contains($n, ' ' . $k . ' ')) { $hit = ['why' => 'word', 'hit' => $k]; break; } }
    if ($hit === null) return null;
    $hit['weak'] = false; if ($hit['why'] === 'name') foreach (YD_WEAK as $w) if (str_contains($n, ' ' . $w . ' ')) { $hit['weak'] = true; break; }
    return $hit;
}
function yd_can(): bool { return function_exists('ct_manage') && ct_manage() && !(function_exists('pa_only') && pa_only()) && function_exists('co_roles_has') && co_roles_has(); }
/** Organisations that are not yet a yard and whose name matches. Read-only. */
function yd_scan(): array {
    $out = [];
    foreach (all("SELECT co.id, co.name, co.kind, co.roles, co.country, co.city, co.is_active, co.is_supplier, co.customer_id, (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) n_contacts FROM companies co WHERE co.kind <> 'yard' AND NOT FIND_IN_SET('yard', co.roles) ORDER BY co.name") as $r) {
        $m = yd_match((string)$r['name']); if ($m) $out[] = $r + $m;
    }
    return $out;
}
/** Add the Yard role to the chosen organisations — only to those the scan proposes now. Returns the number changed. */
function yd_apply(array $ids): int {
    if (!yd_can()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_flip(array_map('intval', $ids)); $n = 0; $u = current_user(); $names = [];
    foreach (yd_scan() as $r) {
        if (!isset($ids[(int)$r['id']])) continue;
        $roles = co_roles($r); $roles[] = 'yard'; $roles = array_values(array_unique(array_diff($roles, ['other']))) ?: ['yard'];
        q('UPDATE companies SET roles = ?, kind = ? WHERE id = ?', [implode(',', $roles), co_main_kind($roles), (int)$r['id']]); $n++; $names[] = (string)$r['name'];
    }
    if ($n) @file_put_contents(dirname(__DIR__) . '/storage/yards.log', date('Y-m-d H:i:s') . "\t" . (int)($u['id'] ?? 0) . "\t" . str_replace(["\t", "\n"], ' ', (string)($u['full_name'] ?? '')) . "\t" . $n . "\t" . str_replace(["\t", "\n"], ' ', implode(' | ', $names)) . "\n", FILE_APPEND | LOCK_EX);
    return $n;
}
function yd_handle_action(string $action): bool {
    if ($action !== 'yd_apply') return false; require_staff();
    $n = yd_apply((array)($_POST['ids'] ?? [])); flash('good', t('yd.done', ['n' => $n])); redirect('companies_yards', ['_r' => uniqid()]);
    return true;
}
