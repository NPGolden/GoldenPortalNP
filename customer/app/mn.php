<?php
declare(strict_types=1);
/** Help search across the manual editions the user may read (GAS-CP-R31-005). */
function mn_editions(): array { $e = ['customer' => t('mn.for_customer')]; if (is_staff()) { $e['golden'] = t('mn.for_golden'); $e['pm'] = t('mn.for_pm'); } return $e; }
function mn_flatten(array $body): string {
    $out = [];
    foreach ($body as $b) {
        if (is_string($b)) $out[] = $b;
        elseif (isset($b['ul'])) $out[] = implode(' ', $b['ul']);
        elseif (isset($b['ol'])) $out[] = implode(' ', $b['ol']);
        elseif (isset($b['tip'])) $out[] = $b['tip'];
        elseif (isset($b['cap'])) $out[] = $b['cap'];
    }
    return implode(' ', $out);
}
/** Sections whose title or text contains every word of the query; best matches first. */
function mn_search(string $q, int $limit = 30): array {
    $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), fn($w) => mb_strlen($w) >= 2));
    if (!$words) return [];
    $hits = [];
    foreach (mn_editions() as $ed => $label) {
        $M = require __DIR__ . '/../views/_manual_' . $ed . '.php';
        foreach ($M['sections'] as $s) {
            $title = (string)$s['title']; $text = mn_flatten($s['body']); $hay = mb_strtolower($title . ' ' . $text); $score = 0; $pos = null;
            foreach ($words as $w) { $p = mb_strpos($hay, $w); if ($p === false) { $score = 0; break; } $score += mb_strpos(mb_strtolower($title), $w) !== false ? 5 : 1; $pos = $pos === null ? $p : min($pos, $p); }
            if (!$score) continue;
            $phrase = mb_strtolower(trim($q)); if (mb_strlen($phrase) >= 3 && mb_strpos($hay, $phrase) !== false) { $score += 10; $pos = mb_strpos($hay, $phrase); if (mb_strpos(mb_strtolower($title), $phrase) !== false) $score += 10; }
            $tp = max(0, ($pos ?? 0) - mb_strlen($title) - 1); $start = max(0, $tp - 80); $snip = mb_substr($text, $start, 220);
            $snip = e(($start > 0 ? '…' : '') . $snip . (mb_strlen($text) > $start + 220 ? '…' : ''));
            foreach ($words as $w) $snip = preg_replace('/(' . preg_quote(e($w), '/') . ')/iu', '<mark>$1</mark>', $snip);
            $hits[] = ['edition' => $ed, 'edition_label' => $label, 'id' => $s['id'], 'title' => $title, 'score' => $score, 'snippet' => $snip];
        }
    }
    usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($hits, 0, $limit);
}
