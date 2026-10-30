<?php
/** Celebrations — birthdays (employee card › date of birth) and Greek Orthodox name days.   GAS-CP-R62-004
 *  Name days: fixed dates plus the movable feasts tied to Orthodox Easter (wc_easter_orthodox). Names are matched on the
 *  first name of the Greek name (accents stripped, hyphenated names → first part), else on the Latin first name. */
declare(strict_types=1);

/** Name-day table: key = canonical name, value = [date rule, Greek variants (upper, no accents), Latin variants (lower)].
 *  Date rule: 'MM-DD' fixed · 'E+n' / 'E-n' days from Orthodox Easter · 'george' / 'mark' special (moved into Bright Week when they fall in Lent). */
function cel_table(): array {
    return [
        'Αγ. Βασιλείου' => ['01-01', ['ΒΑΣΙΛΕΙΟΣ', 'ΒΑΣΙΛΗΣ', 'ΒΑΣΙΛΙΚΗ', 'ΒΑΣΩ'], ['vasileios', 'vasilis', 'vassilis', 'basil', 'vasiliki', 'vaso']],
        'Αγ. Ιωάννου Προδρόμου' => ['01-07', ['ΙΩΑΝΝΗΣ', 'ΓΙΑΝΝΗΣ', 'ΙΩΑΝΝΑ', 'ΓΙΑΝΝΑ'], ['ioannis', 'giannis', 'yiannis', 'yannis', 'john', 'ioanna', 'gianna']],
        'Αντώνιος'    => ['01-17', ['ΑΝΤΩΝΙΟΣ', 'ΑΝΤΩΝΗΣ', 'ΑΝΤΩΝΙΑ'], ['antonios', 'antonis', 'anthony', 'antonia']],
        'Αθανάσιος'   => ['01-18', ['ΑΘΑΝΑΣΙΟΣ', 'ΘΑΝΑΣΗΣ', 'ΑΘΑΝΑΣΙΑ', 'ΝΑΣΟΣ'], ['athanasios', 'thanasis', 'athanasia', 'nasos']],
        'Γρηγόριος'   => ['01-25', ['ΓΡΗΓΟΡΙΟΣ', 'ΓΡΗΓΟΡΗΣ'], ['grigorios', 'grigoris', 'gregory']],
        'Χαράλαμπος'  => ['02-10', ['ΧΑΡΑΛΑΜΠΟΣ', 'ΧΑΡΗΣ', 'ΜΠΑΜΠΗΣ'], ['charalampos', 'haralambos', 'charis', 'babis']],
        'Φωτεινή'     => ['02-26', ['ΦΩΤΕΙΝΗ', 'ΦΑΝΗ'], ['fotini', 'foteini', 'fani']],
        'Ευαγγελισμός' => ['03-25', ['ΕΥΑΓΓΕΛΟΣ', 'ΒΑΓΓΕΛΗΣ', 'ΕΥΑΓΓΕΛΙΑ', 'ΕΥΑ'], ['evangelos', 'vangelis', 'evangelia', 'eva']],
        'Λεωνίδας'    => ['04-15', ['ΛΕΩΝΙΔΑΣ'], ['leonidas']],
        'Αγ. Γεωργίου' => ['george', ['ΓΕΩΡΓΙΟΣ', 'ΓΙΩΡΓΟΣ', 'ΓΕΩΡΓΙΑ', 'ΓΩΓΩ'], ['georgios', 'giorgos', 'george', 'yorgos', 'georgia']],
        'Μάρκος'      => ['mark', ['ΜΑΡΚΟΣ'], ['markos', 'mark', 'marco']],
        'Ειρήνη'      => ['05-05', ['ΕΙΡΗΝΗ'], ['irini', 'eirini', 'irene']],
        'Ησαΐας'      => ['05-09', ['ΗΣΑΙΑΣ'], ['isaias']],
        'Αχιλλέας'    => ['05-15', ['ΑΧΙΛΛΕΑΣ'], ['achilleas', 'achilles']],
        'Κωνσταντίνου & Ελένης' => ['05-21', ['ΚΩΝΣΤΑΝΤΙΝΟΣ', 'ΚΩΣΤΑΣ', 'ΚΩΝΣΤΑΝΤΙΝΑ', 'ΝΤΙΝΑ', 'ΕΛΕΝΗ', 'ΕΛΕΝΑ'], ['konstantinos', 'kostas', 'costas', 'constantine', 'konstantina', 'eleni', 'elena', 'helen']],
        'Πέτρου & Παύλου' => ['06-29', ['ΠΕΤΡΟΣ', 'ΠΑΥΛΟΣ', 'ΠΑΥΛΙΝΑ'], ['petros', 'peter', 'petro', 'pavlos', 'paul', 'pavlina']],
        'Απόστολος'   => ['06-30', ['ΑΠΟΣΤΟΛΟΣ', 'ΤΟΛΗΣ'], ['apostolos', 'tolis']],
        'Αγ. Κυριακής' => ['07-07', ['ΚΥΡΙΑΚΗ', 'ΚΥΡΙΑΚΟΣ', 'ΚΙΚΗ'], ['kyriaki', 'kyriakos', 'kiki']],
        'Αγ. Μαρίνας' => ['07-17', ['ΜΑΡΙΝΑ', 'ΜΑΡΓΑΡΙΤΑ'], ['marina', 'margarita']],
        'Ηλίας'       => ['07-20', ['ΗΛΙΑΣ'], ['ilias', 'elias']],
        'Παρασκευή'   => ['07-26', ['ΠΑΡΑΣΚΕΥΗ', 'ΒΟΥΛΑ', 'ΕΥΗ'], ['paraskevi', 'voula', 'evi']],
        'Παντελεήμων' => ['07-27', ['ΠΑΝΤΕΛΕΗΜΩΝ', 'ΠΑΝΤΕΛΗΣ'], ['panteleimon', 'pantelis']],
        'Μεταμόρφωση του Σωτήρος' => ['08-06', ['ΣΩΤΗΡΙΟΣ', 'ΣΩΤΗΡΗΣ', 'ΣΩΤΗΡΙΑ'], ['sotirios', 'sotiris', 'sotiria']],
        'Κοιμήσεως της Θεοτόκου' => ['08-15', ['ΜΑΡΙΑ', 'ΠΑΝΑΓΙΩΤΗΣ', 'ΠΑΝΟΣ', 'ΠΑΝΑΓΙΩΤΑ', 'ΔΕΣΠΟΙΝΑ', 'ΜΑΡΙΟΣ', 'ΜΑΙΡΗ'], ['maria', 'mary', 'panagiotis', 'panos', 'panagiota', 'despina', 'marios', 'mario', 'mairi']],
        'Αλέξανδρος'  => ['08-30', ['ΑΛΕΞΑΝΔΡΟΣ', 'ΑΛΕΞΗΣ', 'ΑΛΕΞΑΝΔΡΑ'], ['alexandros', 'alexander', 'alexis', 'alexandra']],
        'Αγ. Ελισάβετ' => ['09-05', ['ΕΛΙΣΑΒΕΤ', 'ΜΠΕΤΤΥ', 'ΕΛΙΖΑ'], ['elisavet', 'elizabeth', 'betty', 'eliza']],
        'Ύψωση Τιμίου Σταυρού' => ['09-14', ['ΣΤΑΥΡΟΣ', 'ΣΤΑΥΡΟΥΛΑ'], ['stavros', 'stavroula']],
        'Αγ. Σοφίας' => ['09-17', ['ΣΟΦΙΑ', 'ΕΛΠΙΔΑ', 'ΑΓΑΠΗ', 'ΠΙΣΤΗ'], ['sofia', 'sophia', 'elpida', 'agapi', 'pisti']],
        'Ευστάθιος'   => ['09-20', ['ΕΥΣΤΑΘΙΟΣ', 'ΣΤΑΘΗΣ'], ['efstathios', 'stathis']],
        'Κυριακή του Θωμά' => ['E+7', ['ΘΩΜΑΣ'], ['thomas']],
        'Γεράσιμος'   => ['10-20', ['ΓΕΡΑΣΙΜΟΣ', 'ΜΑΚΗΣ'], ['gerasimos', 'makis']],
        'Ιάκωβος'     => ['10-23', ['ΙΑΚΩΒΟΣ'], ['iakovos', 'jacob']],
        'Αγ. Δημητρίου' => ['10-26', ['ΔΗΜΗΤΡΙΟΣ', 'ΔΗΜΗΤΡΗΣ', 'ΔΗΜΗΤΡΑ', 'ΜΙΜΗΣ', 'ΤΖΙΜΗΣ'], ['dimitrios', 'dimitris', 'demetrios', 'jim', 'dimitra', 'mimis']],
        'Λουκάς'      => ['10-18', ['ΛΟΥΚΑΣ'], ['loukas', 'luke', 'luca']],
        'Ταξιαρχών (Μιχαήλ & Γαβριήλ)' => ['11-08', ['ΑΓΓΕΛΙΚΗ', 'ΑΓΓΕΛΟΣ', 'ΜΙΧΑΗΛ', 'ΜΙΧΑΛΗΣ', 'ΓΑΒΡΙΗΛ', 'ΣΤΑΜΑΤΙΟΣ', 'ΣΤΑΜΑΤΗΣ', 'ΣΤΑΜΑΤΙΝΑ', 'ΜΑΤΙΝΑ', 'ΤΑΞΙΑΡΧΗΣ'], ['angeliki', 'angelos', 'michail', 'michalis', 'michael', 'gavriil', 'stamatios', 'stamatis', 'stamatina', 'matina']],
        'Νεκτάριος'   => ['11-09', ['ΝΕΚΤΑΡΙΟΣ'], ['nektarios']],
        'Αγ. Μηνά & Βικτωρίας' => ['11-11', ['ΜΗΝΑΣ', 'ΒΙΚΤΩΡΙΑ', 'ΒΙΚΤΩΡ'], ['minas', 'viktoria', 'victoria', 'viktor']],
        'Φίλιππος'    => ['11-14', ['ΦΙΛΙΠΠΟΣ'], ['filippos', 'philip']],
        'Ματθαίος'    => ['11-16', ['ΜΑΤΘΑΙΟΣ'], ['matthaios', 'matthew']],
        'Αικατερίνη'  => ['11-25', ['ΑΙΚΑΤΕΡΙΝΗ', 'ΚΑΤΕΡΙΝΑ', 'ΚΑΤΙΑ'], ['aikaterini', 'katerina', 'katia', 'catherine']],
        'Αγ. Στυλιανού' => ['11-26', ['ΣΤΥΛΙΑΝΟΣ', 'ΣΤΕΛΙΟΣ', 'ΣΤΕΛΛΑ'], ['stylianos', 'stelios', 'stella']],
        'Ανδρέας'     => ['11-30', ['ΑΝΔΡΕΑΣ', 'ΑΝΔΡΙΑΝΑ'], ['andreas', 'andrew', 'andriana']],
        'Σάββας'      => ['12-05', ['ΣΑΒΒΑΣ'], ['savvas']],
        'Αγ. Νικολάου' => ['12-06', ['ΝΙΚΟΛΑΟΣ', 'ΝΙΚΟΣ', 'ΝΙΚΟΛΕΤΑ', 'ΝΙΚΗ'], ['nikolaos', 'nikos', 'nicholas', 'nikoleta', 'niki']],
        'Άννα'        => ['12-09', ['ΑΝΝΑ'], ['anna']],
        'Σπυρίδων'    => ['12-12', ['ΣΠΥΡΙΔΩΝ', 'ΣΠΥΡΟΣ', 'ΣΠΥΡΙΔΟΥΛΑ'], ['spyridon', 'spyros', 'spiros', 'spyridoula']],
        'Ελευθέριος'  => ['12-15', ['ΕΛΕΥΘΕΡΙΟΣ', 'ΛΕΥΤΕΡΗΣ', 'ΕΛΕΥΘΕΡΙΑ'], ['eleftherios', 'lefteris', 'lefteri', 'eleftheria']],
        'Διονύσιος'   => ['12-17', ['ΔΙΟΝΥΣΙΟΣ', 'ΔΙΟΝΥΣΗΣ', 'ΝΙΟΝΙΟΣ'], ['dionysios', 'dionysis', 'dennis']],
        'Χριστούγεννα' => ['12-25', ['ΧΡΗΣΤΟΣ', 'ΧΡΙΣΤΙΝΑ', 'ΧΡΥΣΑ'], ['christos', 'chris', 'cristiano', 'christina', 'chrysa']],
        'Εμμανουήλ'   => ['12-26', ['ΕΜΜΑΝΟΥΗΛ', 'ΜΑΝΟΛΗΣ', 'ΜΑΝΟΣ', 'ΕΜΜΑΝΟΥΕΛΑ'], ['emmanouil', 'manolis', 'manos', 'emmanuel', 'emmanouela']],
        'Στέφανος'    => ['12-27', ['ΣΤΕΦΑΝΟΣ', 'ΣΤΕΦΑΝΙΑ'], ['stefanos', 'stephen', 'stefania']],
        'Πάσχα' => ['E+0', ['ΑΝΑΣΤΑΣΙΟΣ', 'ΤΑΣΟΣ', 'ΑΝΑΣΤΑΣΙΑ', 'ΛΑΜΠΡΟΣ', 'ΛΑΜΠΡΙΝΗ', 'ΠΑΣΧΑΛΗΣ'], ['anastasios', 'tasos', 'anastasia', 'lampros', 'lamprini', 'paschalis']],
        'Ραφαήλ'      => ['E+2', ['ΡΑΦΑΗΛ', 'ΡΑΦΑΕΛΑ'], ['rafail', 'rafael', 'rafaela']],
        'Ζωοδόχου Πηγής' => ['E+5', ['ΖΩΗ'], ['zoi', 'zoe']],
        'Αγ. Θεοδώρων' => ['E-57', ['ΘΕΟΔΩΡΟΣ', 'ΘΟΔΩΡΗΣ', 'ΘΕΟΔΩΡΑ', 'ΔΩΡΑ'], ['theodoros', 'thodoris', 'theodore', 'theodora', 'dora']],
        'Θεοφάνεια' => ['01-06', ['ΦΩΤΙΟΣ', 'ΦΩΤΗΣ', 'ΦΩΤΕΙΝΟΣ', 'ΘΕΟΦΑΝΗΣ', 'ΦΑΝΗΣ'], ['fotios', 'fotis', 'theofanis', 'fanis']],
        'Ξενοφών'     => ['01-26', ['ΞΕΝΟΦΩΝ'], ['xenofon', 'xenophon']],
        'Ψυχοσάββατο' => ['E-50', ['ΙΣΜΗΝΗ'], ['ismini']],        // All Souls Saturday (Ψυχοσάββατο) — names from antiquity
        'Ψυχοσάββατο (αρχαία ονόματα)' => ['E-50', ['ΜΙΛΤΙΑΔΗΣ', 'ΘΑΛΗΣ', 'ΑΡΗΣ', 'ΠΗΝΕΛΟΠΗ', 'ΙΑΣΩΝ', 'ΟΔΥΣΣΕΑΣ', 'ΗΡΑΚΛΗΣ', 'ΠΕΡΙΚΛΗΣ', 'ΣΩΚΡΑΤΗΣ', 'ΑΡΙΣΤΕΙΔΗΣ', 'ΑΡΙΣΤΟΤΕΛΗΣ', 'ΘΕΜΙΣΤΟΚΛΗΣ'], ['miltiadis', 'thalis', 'aris', 'pinelopi', 'penelope', 'iason', 'odysseas', 'iraklis', 'periklis', 'sokratis', 'aristeidis', 'aristotelis', 'themistoklis']],
        'Ιωσήφ'       => ['xmas-sun', ['ΙΩΣΗΦ', 'ΣΗΦΗΣ'], ['iosif', 'joseph', 'sifis']],
        'Βεσελίν'     => ['', ['ΒΕΣΕΛΙΝ'], ['veselin']],
    ];
}

/** Strip Greek accents / dialytika, uppercase. */
function cel_norm_el(string $s): string {
    $s = mb_strtoupper(trim($s));
    return strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ', 'ΐ' => 'Ι', 'ΰ' => 'Υ', 'ά' => 'Α', 'έ' => 'Ε', 'ή' => 'Η', 'ί' => 'Ι', 'ό' => 'Ο', 'ύ' => 'Υ', 'ώ' => 'Ω']);
}
/** First name of a person: Greek name is "SURNAME FIRST", Latin name "First Last"; hyphenated → first part. */
function cel_first_names(array $p): array {
    $out = [];
    $el = trim((string)($p['name_el'] ?? '')); if ($el !== '') { $parts = preg_split('/\s+/', $el); if (count($parts) >= 2) { array_shift($parts); } foreach ($parts as $x) foreach (explode('-', $x) as $y) if ($y !== '') $out[] = ['el', cel_norm_el($y)]; }
    $en = trim((string)($p['name_en'] ?? '')); if ($en !== '') { $first = preg_split('/\s+/', $en)[0]; foreach (explode('-', $first) as $y) if ($y !== '') $out[] = ['en', mb_strtolower($y)]; }
    return $out;
}
/** Date of a rule in a year (null = no name day). */
function cel_rule_date(string $rule, int $y): ?string {
    if ($rule === '') return null;
    $easter = function_exists('wc_easter_orthodox') ? wc_easter_orthodox($y) : null;
    $add = fn(string $base, int $d) => (new DateTime($base))->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    if (preg_match('/^E([+-]\d+)$/', $rule, $m)) return $easter ? $add($easter, (int)$m[1]) : null;
    if ($rule === 'george') { $d = "$y-04-23"; return ($easter && $d <= $easter) ? $add($easter, 1) : $d; }     // St George moves to Bright Monday when 23 April falls in Lent / Holy Week
    if ($rule === 'mark')   { $d = "$y-04-25"; return ($easter && $d <= $easter) ? $add($easter, 2) : $d; }     // St Mark → Bright Tuesday
    if ($rule === 'xmas-sun') { $t = strtotime("$y-12-25"); $dow = (int) date('N', $t); return date('Y-m-d', $t + (7 - $dow) * 86400); }   // Sunday after Christmas
    return "$y-$rule";
}
/** Name day of a person in a year: ['date' => Y-m-d, 'name' => canonical] or null. */
function cel_nameday(array $p, int $y): ?array {
    static $idx = null;
    if ($idx === null) { $idx = ['el' => [], 'en' => []]; foreach (cel_table() as $name => [$rule, $els, $ens]) { foreach ($els as $v) $idx['el'][cel_norm_el($v)] = [$name, $rule]; foreach ($ens as $v) $idx['en'][$v] = [$name, $rule]; } }
    foreach (cel_first_names($p) as [$lang, $v]) if (isset($idx[$lang][$v])) { [$name, $rule] = $idx[$lang][$v]; $d = cel_rule_date($rule, $y); return $d ? ['date' => $d, 'name' => $name] : null; }
    return null;
}
/** Upcoming celebrations within $days from today (active employees): [['date','kind' => birthday|nameday,'person','age'|null,'name'|null], …] sorted by date. */
function cel_upcoming(int $days = 7, bool $withAge = false): array {
    if (!function_exists('hr_people')) return []; $today = date('Y-m-d'); $end = date('Y-m-d', strtotime("+$days days")); $y0 = (int) substr($today, 0, 4); $y1 = (int) substr($end, 0, 4); $out = [];
    foreach (hr_people(false) as $p) {
        if (empty($p['is_active'])) continue;
        $bd = (string)($p['birth_date'] ?? ''); if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bd, $m)) { foreach (array_unique([$y0, $y1]) as $y) { $d = sprintf('%04d-%s-%s', $y, $m[2], $m[3]); if ($m[2] === '02' && $m[3] === '29' && !checkdate(2, 29, $y)) $d = "$y-02-28"; if ($d >= $today && $d <= $end) $out[] = ['date' => $d, 'kind' => 'birthday', 'person' => $p, 'age' => $withAge ? $y - (int)$m[1] : null, 'name' => null]; } }
        foreach (array_unique([$y0, $y1]) as $y) { $nd = cel_nameday($p, $y); if ($nd && $nd['date'] >= $today && $nd['date'] <= $end) $out[] = ['date' => $nd['date'], 'kind' => 'nameday', 'person' => $p, 'age' => null, 'name' => $nd['name']]; }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['kind'], $a['person']['name_en']] <=> [$b['date'], $b['kind'], $b['person']['name_en']]);
    return $out;
}
/** Card HTML (dashboard / HR dashboard). $withAge only for HR. */
function cel_card(int $days, bool $withAge, bool $linkCards): string {
    $rows = cel_upcoming($days, $withAge); if (!$rows) return '';
    $h = '<div class="card" id="celebrations"><h2>🎉 ' . tt('cel.title') . ' <span class="hint" style="display:inline;margin-left:6px;">' . e(t('cel.next_days', ['n' => $days])) . '</span></h2><ul class="cel-list" style="list-style:none;margin:0;padding:0;display:grid;gap:4px;">';
    $today = date('Y-m-d'); $tom = date('Y-m-d', strtotime('+1 day'));
    foreach ($rows as $r) {
        $p = $r['person']; $when = $r['date'] === $today ? '<b>' . tt('cel.today') . '</b>' : ($r['date'] === $tom ? tt('cel.tomorrow') : e(fmt_date($r['date'])));
        $who = $linkCards ? '<a href="' . url('hr_person', ['id' => (int)$p['id']]) . '">' . e($p['name_en']) . '</a>' : e($p['name_en']);
        $what = $r['kind'] === 'birthday' ? '🎂 ' . tt('cel.birthday') . ($r['age'] !== null ? ' <span class="hint">(' . (int)$r['age'] . ')</span>' : '') : '🎊 ' . tt('cel.nameday') . ' <span class="hint">· ' . e((string)$r['name']) . '</span>';
        $h .= '<li' . ($r['date'] === $today ? ' style="background:#FFFBEA;border-radius:6px;padding:2px 6px;"' : ' style="padding:2px 6px;"') . '><span class="hint" style="display:inline-block;min-width:92px;">' . $when . '</span> ' . $who . ' <span class="hint">·</span> ' . $what . '</li>';
    }
    return $h . '</ul></div>';
}
