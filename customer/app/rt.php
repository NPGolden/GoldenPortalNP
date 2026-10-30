<?php
/**
 * Rich text (lightweight) for notes, descriptions and comments.     GAS-CP-R37-001
 *
 * Text is stored as it is typed — plain text with a few portable marks that
 * stay readable everywhere (Excel exports, e-mails, old PDFs):
 *     **bold**   _italic_   __underline__
 *     - item / • item          → bulleted list
 *     1. item / 1) item        → numbered list
 *     blank line               → paragraph break
 * rt_html() turns that into safe HTML on screen (escaped first, so no HTML
 * typed by a user is ever rendered); rt_plain() gives the clean plain text
 * for PDF / Word / exports (marks removed, bullets as "•", numbers kept).
 * The toolbar above every textarea (assets/portal.js) writes the same marks.
 */

function rt_has_marks(string $s): bool { return (bool) preg_match('/\*\*|__|(?<![\w])_[^_\n]+_(?![\w])|^\s*(?:[-*•]|\d{1,2}[.)])\s/mu', $s); }

/** Inline marks → tags, on an already-escaped string. */
function rt_inline(string $e): string {
    $e = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $e);
    $e = preg_replace('/__(.+?)__/su', '<u>$1</u>', $e);
    $e = preg_replace('/(?<![\w\/:])_([^_\n]+?)_(?![\w])/u', '<i>$1</i>', $e);
    $e = preg_replace('/(?<![\w*])\*([^*\n]+?)\*(?![\w*])/u', '<i>$1</i>', $e);
    return $e;
}

/** Safe HTML for the screen. $mt: a callback applied to each escaped line (e.g. the @mention highlighter). */
function rt_html(?string $s, ?callable $inlineHook = null): string {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    if (trim($s) === '') return '';
    $lines = explode("\n", $s); $h = ''; $list = null; $para = [];
    $flushPara = function () use (&$para, &$h) { if ($para) { $h .= '<p>' . implode('<br>', $para) . '</p>'; $para = []; } };
    $closeList = function () use (&$list, &$h) { if ($list) { $h .= '</' . $list . '>'; $list = null; } };
    foreach ($lines as $ln) {
        $raw = rtrim($ln);
        if (trim($raw) === '') { $flushPara(); $closeList(); continue; }
        $kind = null; $body = $raw;
        if (preg_match('/^\s*[-*•]\s+(.*)$/u', $raw, $m)) { $kind = 'ul'; $body = $m[1]; }
        elseif (preg_match('/^\s*\d{1,2}[.)]\s+(.*)$/u', $raw, $m)) { $kind = 'ol'; $body = $m[1]; }
        $eb = e($body); if ($inlineHook) $eb = $inlineHook($eb); $eb = rt_inline($eb);
        if ($kind) {
            $flushPara();
            if ($list !== $kind) { $closeList(); $h .= '<' . $kind . '>'; $list = $kind; }
            $h .= '<li>' . $eb . '</li>';
        } else {
            $closeList();
            $para[] = $eb;
        }
    }
    $flushPara(); $closeList();
    if (!str_contains($h, '<ul>') && !str_contains($h, '<ol>')) {                       // no list: stay inline (safe inside <p>, <b>, table cells)
        return implode('<br><br>', array_map(fn($p) => substr($p, 3, -4), preg_split('/(?<=<\/p>)(?=<p>)/', $h)));
    }
    return '<div class="rt">' . $h . '</div>';
}

/** Clean plain text for PDF, Word and exports: marks removed, bullets normalised to "•", numbering kept. */
function rt_plain(?string $s): string {
    $s = (string)$s;
    if ($s === '' || !rt_has_marks($s)) return $s;
    $s = preg_replace('/\*\*(.+?)\*\*/su', '$1', $s);
    $s = preg_replace('/__(.+?)__/su', '$1', $s);
    $s = preg_replace('/(?<![\w\/:])_([^_\n]+?)_(?![\w])/u', '$1', $s);
    $s = preg_replace('/(?<![\w*])\*([^*\n]+?)\*(?![\w*])/u', '$1', $s);
    $s = preg_replace('/^(\s*)[-*]\s+/mu', '$1• ', $s);
    return $s;
}
