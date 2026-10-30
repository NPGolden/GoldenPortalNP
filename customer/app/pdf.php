<?php
/**
 * Minimal PDF writer — pure PHP, no library.                 GAS-CP-R7-001
 *
 * A4 pages, embedded TrueType fonts (Liberation Sans regular + bold, so
 * Greek and Latin render), word-wrapped text, lines, boxes, filled
 * rectangles, JPEG images (PNG converted through GD). Coordinates are in
 * millimetres from the top-left corner, as on a drawing.
 * Enough for the SF-GLD-014 field service report; not a general engine.
 */
declare(strict_types=1);

final class PdfWriter {
    private const PT = 72 / 25.4;                    // points per millimetre
    public const W = 210.0, H = 297.0;

    private array $pages = [];                       // page content streams
    private string $cur = '';
    private array $fonts = [];                       // name => parsed font
    private array $images = [];                      // id => ['w','h','data']
    private array $usedFonts = [];
    private array $usedImages = [];
    private string $curFont = 'R';
    private float $curSize = 10;

    public function __construct() {
        $dir = __DIR__ . '/fonts';
        $this->fonts['R'] = $this->loadTtf($dir . '/LiberationSans-Regular.ttf');
        $this->fonts['B'] = $this->loadTtf($dir . '/LiberationSans-Bold.ttf');
        $this->addPage();
    }

    /* ------------------------------------------------------------ pages */
    public function addPage(): void {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = '';
        $this->usedFonts[count($this->pages)] = [];
        $this->usedImages[count($this->pages)] = [];
    }
    public function pageNo(): int { return count($this->pages) + 1; }

    /* ------------------------------------------------------------ fonts */
    /** Parse the TrueType tables we need: unitsPerEm, advance widths, cmap. */
    private function loadTtf(string $file): array {
        $d = (string) file_get_contents($file);
        if ($d === '') throw new RuntimeException('Font missing: ' . basename($file));
        $numTables = unpack('n', substr($d, 4, 2))[1];
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $o = 12 + $i * 16;
            $tag = substr($d, $o, 4);
            $tables[$tag] = ['off' => unpack('N', substr($d, $o + 8, 4))[1], 'len' => unpack('N', substr($d, $o + 12, 4))[1]];
        }
        $head = $tables['head']['off']; $upm = unpack('n', substr($d, $head + 18, 2))[1];
        $hhea = $tables['hhea']['off']; $numH = unpack('n', substr($d, $hhea + 34, 2))[1];
        $hmtx = $tables['hmtx']['off'];
        $adv = [];
        for ($g = 0; $g < $numH; $g++) $adv[$g] = unpack('n', substr($d, $hmtx + $g * 4, 2))[1];
        // cmap: find a (3,1) or (0,x) format-4 subtable
        $cmap = $tables['cmap']['off']; $n = unpack('n', substr($d, $cmap + 2, 2))[1];
        $sub = null;
        for ($i = 0; $i < $n; $i++) {
            $pid = unpack('n', substr($d, $cmap + 4 + $i * 8, 2))[1]; $eid = unpack('n', substr($d, $cmap + 6 + $i * 8, 2))[1];
            $off = unpack('N', substr($d, $cmap + 8 + $i * 8, 4))[1];
            if (($pid === 3 && $eid === 1) || $pid === 0) { $fmt = unpack('n', substr($d, $cmap + $off, 2))[1]; if ($fmt === 4) { $sub = $cmap + $off; if ($pid === 3) break; } }
        }
        $map = [];
        if ($sub !== null) {
            $segX2 = unpack('n', substr($d, $sub + 6, 2))[1]; $seg = $segX2 / 2;
            $endP = $sub + 14; $startP = $endP + $segX2 + 2; $deltaP = $startP + $segX2; $rangeP = $deltaP + $segX2;
            for ($s = 0; $s < $seg; $s++) {
                $end = unpack('n', substr($d, $endP + $s * 2, 2))[1]; $start = unpack('n', substr($d, $startP + $s * 2, 2))[1];
                $delta = unpack('n', substr($d, $deltaP + $s * 2, 2))[1]; $ro = unpack('n', substr($d, $rangeP + $s * 2, 2))[1];
                if ($start === 0xFFFF) continue;
                for ($c = $start; $c <= $end; $c++) {
                    if ($ro === 0) $gid = ($c + $delta) & 0xFFFF;
                    else {
                        $gp = $rangeP + $s * 2 + $ro + ($c - $start) * 2;
                        $gid = unpack('n', substr($d, $gp, 2))[1]; if ($gid) $gid = ($gid + $delta) & 0xFFFF;
                    }
                    if ($gid) $map[$c] = $gid;
                }
            }
        }
        // ascent/descent/bbox for the descriptor
        $asc = unpack('n', substr($d, $hhea + 4, 2))[1]; if ($asc > 32767) $asc -= 65536;
        $desc = unpack('n', substr($d, $hhea + 6, 2))[1]; if ($desc > 32767) $desc -= 65536;
        $bbox = [];
        foreach ([36, 38, 40, 42] as $k) { $v = unpack('n', substr($d, $head + $k, 2))[1]; if ($v > 32767) $v -= 65536; $bbox[] = (int) round($v * 1000 / $upm); }
        $cap = (int) round($asc * 1000 / $upm);
        return ['data' => $d, 'upm' => $upm, 'adv' => $adv, 'map' => $map, 'asc' => (int) round($asc * 1000 / $upm),
                'desc' => (int) round($desc * 1000 / $upm), 'bbox' => $bbox, 'cap' => $cap,
                'name' => str_contains($file, 'Bold') ? 'LiberationSans-Bold' : 'LiberationSans'];
    }
    public function setFont(string $style = 'R', float $size = 10): void { $this->curFont = $style === 'B' ? 'B' : 'R'; $this->curSize = $size; }

    /** Width of a string in mm at the current font/size. */
    public function width(string $s, ?string $style = null, ?float $size = null): float {
        $f = $this->fonts[$style ?? $this->curFont]; $size = $size ?? $this->curSize;
        $w = 0;
        foreach (mb_str_split($s) as $ch) {
            $gid = $f['map'][mb_ord($ch)] ?? 0;
            $w += $f['adv'][$gid] ?? $f['adv'][0];
        }
        return $w / $f['upm'] * $size / self::PT;
    }

    private function esc(string $s): string {
        $f = $this->fonts[$this->curFont]; $hex = '';
        foreach (mb_str_split($s) as $ch) $hex .= sprintf('%04X', $f['map'][mb_ord($ch)] ?? 0);
        return '<' . $hex . '>';
    }

    /* ------------------------------------------------------------ drawing */
    private function y(float $mm): float { return (self::H - $mm) * self::PT; }
    private function x(float $mm): float { return $mm * self::PT; }

    public function setColor(string $hex): void { $this->cur .= $this->rgb($hex) . ' rg ' . $this->rgb($hex) . ' RG' . "\n"; }
    private function rgb(string $hex): string {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }
    public function setLineWidth(float $mm): void { $this->cur .= sprintf('%.2F w' . "\n", $mm * self::PT); }
    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->cur .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $this->x($x1), $this->y($y1), $this->x($x2), $this->y($y2));
    }
    public function rect(float $x, float $y, float $w, float $h, string $mode = 'S'): void {
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $this->x($x), $this->y($y + $h), $w * self::PT, $h * self::PT, $mode === 'F' ? 'f' : ($mode === 'FD' ? 'B' : 'S'));
    }
    /** One line of text; align L / C / R inside width $w when given. */
    public function text(float $x, float $y, string $s, string $align = 'L', float $w = 0): void {
        if (function_exists('rt_plain')) $s = rt_plain($s);                                  // R37-001: rich-text marks never reach the PDF
        if ($s === '') return;
        $this->usedFonts[count($this->pages)][$this->curFont] = true;
        $tw = $this->width($s);
        if ($align === 'C') $x += ($w - $tw) / 2; elseif ($align === 'R') $x += $w - $tw;
        $this->cur .= sprintf("BT /F%s %.2F Tf %.2F %.2F Td %s Tj ET\n", $this->curFont, $this->curSize, $this->x($x), $this->y($y), $this->esc($s));
    }
    /** Word-wrap into lines no wider than $w mm. */
    public function wrap(string $s, float $w): array {
        $out = []; if (function_exists('rt_plain')) $s = rt_plain($s);
        foreach (preg_split('/\r\n|\r|\n/', $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try) <= $w) { $line = $try; continue; }
                if ($line !== '') $out[] = $line;
                // a single over-long word is broken by characters
                while ($this->width($word) > $w && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut)) > $w) $cut--;
                    $out[] = mb_substr($word, 0, $cut); $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out;
    }
    /** Wrapped paragraph; returns the height used (mm). $lh = line height mm. */
    public function paragraph(float $x, float $y, float $w, string $s, float $lh = 4.2, int $maxLines = 0): float {
        $lines = $this->wrap($s, $w);
        if ($maxLines && count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] = rtrim(mb_substr($lines[$maxLines - 1], 0, -1)) . '…'; }
        foreach ($lines as $i => $l) $this->text($x, $y + $lh * ($i + 1) - $lh * 0.25, $l);
        return $lh * count($lines);
    }
    public function paragraphHeight(float $w, string $s, float $lh = 4.2): float { return $lh * count($this->wrap($s, $w)); }
    /** R59-002: height a label/value cell needs so the value wraps instead of overflowing (min $minH). $valueX = offset of the value column from the cell's left edge. */
    public function kvHeight(float $w, float $valueX, string $value, float $minH = 7.0, float $lh = 4.0, float $size = 8.5): float {
        $this->setFont('R', $size); $n = max(1, count($this->wrap($value === '' ? ' ' : $value, $w - $valueX - 2)));
        return max($minH, $lh * $n + ($minH - $lh));
    }
    /** R59-002: bordered label/value cell: the value is wrapped to the cell width over as many lines as it needs; returns the height drawn.
     *  $o: h (fixed height), minH, lh, labelW (grey band width, 0 = none), fill (band colour), labelSize, valueSize, line (border colour), navy (label colour), ink (value colour). */
    public function kvCell(float $x, float $y, float $w, float $valueX, string $label, string $value, array $o = []): float {
        $minH = (float)($o['minH'] ?? 7.0); $lh = (float)($o['lh'] ?? 4.0); $vs = (float)($o['valueSize'] ?? 8.5); $ls = (float)($o['labelSize'] ?? 8.0);
        $h = isset($o['h']) ? (float)$o['h'] : $this->kvHeight($w, $valueX, $value, $minH, $lh, $vs); $lines = $this->wrap($value === '' ? ' ' : $value, $w - $valueX - 2);   // 'h' = row height fixed by the caller (two cells side by side)
        $this->setColor($o['line'] ?? '#9AA5B1'); $this->setLineWidth(0.25); $this->rect($x, $y, $w, $h);
        if (!empty($o['labelW']) && !empty($o['fill'])) { $this->setColor($o['fill']); $this->rect($x, $y, (float)$o['labelW'], $h, 'F'); $this->setColor($o['line'] ?? '#9AA5B1'); $this->rect($x, $y, $w, $h); }
        $base = $y + ($minH - $lh) / 2 + $lh * 0.78;                      // first baseline — same place as the old single-line cells
        $this->setColor($o['navy'] ?? '#0B1E2D'); $this->setFont('B', $ls); $this->text($x + 2, $base, $label);
        $this->setColor($o['ink'] ?? '#1F2937'); $this->setFont('R', $vs);
        foreach ($lines as $i => $ln) $this->text($x + $valueX, $base + $lh * $i, $ln);
        return $h;
    }

    /** Place a JPEG/PNG image (file path or raw bytes) fitted into w×h, centred. */
    public function image(string $src, float $x, float $y, float $w, float $h): bool {
        $img = $this->prepareImage($src);
        if (!$img) return false;
        $id = count($this->images) + 1; $this->images[$id] = $img;
        $this->usedImages[count($this->pages)][$id] = true;
        $r = min($w / $img['w'], $h / $img['h']); $dw = $img['w'] * $r; $dh = $img['h'] * $r;
        $dx = $x + ($w - $dw) / 2; $dy = $y + ($h - $dh) / 2;
        $this->cur .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $dw * self::PT, $dh * self::PT, $this->x($dx), $this->y($dy + $dh), $id);
        return true;
    }
    /** Normalise to baseline JPEG, max 1200 px, white background (GD when present). */
    private function prepareImage(string $src): ?array {
        $bytes = (strlen($src) < 1024 && is_file($src)) ? (string) file_get_contents($src) : $src;
        if ($bytes === '') return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info) return null;
        $isJpeg = $info[2] === IMAGETYPE_JPEG;
        if ($isJpeg && $info[0] <= 1200 && $info[1] <= 1200 && ($info['channels'] ?? 3) === 3) {
            return ['w' => $info[0], 'h' => $info[1], 'data' => $bytes, 'cs' => 'DeviceRGB'];
        }
        if (!function_exists('imagecreatefromstring')) return $isJpeg ? ['w' => $info[0], 'h' => $info[1], 'data' => $bytes, 'cs' => 'DeviceRGB'] : null;
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im); $r = min(1, 1200 / max($w, $h));
        $nw = max(1, (int) round($w * $r)); $nh = max(1, (int) round($h * $r));
        $out = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($out, 255, 255, 255); imagefill($out, 0, 0, $white);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($out, null, 85); $jpg = (string) ob_get_clean();
        imagedestroy($im); imagedestroy($out);
        return ['w' => $nw, 'h' => $nh, 'data' => $jpg, 'cs' => 'DeviceRGB'];
    }

    /* ------------------------------------------------------------ output */
    public function bytes(string $title = '', string $author = 'Golden Automation Systems'): string {
        if ($this->cur !== '' || !$this->pages) { $this->pages[] = $this->cur; $this->cur = ''; }
        $objs = [];                                   // 1-based object bodies
        $add = function (string $body) use (&$objs): int { $objs[] = $body; return count($objs); };

        // fonts: for each style used anywhere -> Type0 + CIDFontType2 + descriptor + FontFile2
        $fontObj = [];
        foreach (['R', 'B'] as $st) {
            $f = $this->fonts[$st];
            $ff = $add('<< /Length ' . strlen(gzcompress($f['data'])) . ' /Length1 ' . strlen($f['data']) . ' /Filter /FlateDecode >>stream' . "\n" . gzcompress($f['data']) . "\nendstream");
            $desc = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
                $f['name'], $f['bbox'][0], $f['bbox'][1], $f['bbox'][2], $f['bbox'][3], $f['asc'], $f['desc'], $f['cap'], $ff));
            // widths: W [ gid [w] ... ] compact as ranges of consecutive gids
            $w = '/W ['; $g = 0; $n = count($f['adv']);
            while ($g < $n) { $run = []; $start = $g; while ($g < $n && count($run) < 100) { $run[] = (int) round($f['adv'][$g] * 1000 / $f['upm']); $g++; } $w .= $start . ' [' . implode(' ', $run) . '] '; }
            $w .= ']';
            $cid = $add(sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 %s /CIDToGIDMap /Identity >>', $f['name'], $desc, $w));
            // ToUnicode: glyph -> code point, so text can be searched and copied
            $pairs = []; foreach ($f['map'] as $cp => $gid) if ($cp <= 0xFFFF) $pairs[$gid] = $cp;
            ksort($pairs); $bf = ''; $cnt = 0; $chunks = [];
            foreach ($pairs as $gid => $cp) { $bf .= sprintf("<%04X> <%04X>\n", $gid, $cp); if (++$cnt === 100) { $chunks[] = $cnt . " beginbfchar\n" . $bf . "endbfchar\n"; $bf = ''; $cnt = 0; } }
            if ($cnt) $chunks[] = $cnt . " beginbfchar\n" . $bf . "endbfchar\n";
            $cmapTxt = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def /CMapName /Adobe-Identity-UCS def /CMapType 2 def 1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
                     . implode('', $chunks) . "endcmap CMapName currentdict /CMap defineresource pop end end";
            $zc = gzcompress($cmapTxt);
            $tu = $add('<< /Length ' . strlen($zc) . ' /Filter /FlateDecode >>stream' . "\n" . $zc . "\nendstream");
            $fontObj[$st] = $add(sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $f['name'], $cid, $tu));
        }
        // images
        $imgObj = [];
        foreach ($this->images as $id => $im) {
            $imgObj[$id] = $add(sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>stream' . "\n%s\nendstream", $im['w'], $im['h'], $im['cs'], strlen($im['data']), $im['data']));
        }
        // pages
        $pagesIdx = count($objs) + 1 + count($this->pages) * 2;   // reserved index for the Pages object
        $pageIds = [];
        foreach ($this->pages as $i => $content) {
            $z = gzcompress($content);
            $c = $add('<< /Length ' . strlen($z) . ' /Filter /FlateDecode >>stream' . "\n" . $z . "\nendstream");
            $res = '/Font << ' . implode(' ', array_map(fn($st) => '/F' . $st . ' ' . $fontObj[$st] . ' 0 R', ['R', 'B'])) . ' >>';
            $xo = array_keys($this->usedImages[$i] ?? []);
            if ($xo) $res .= ' /XObject << ' . implode(' ', array_map(fn($id) => '/Im' . $id . ' ' . $imgObj[$id] . ' 0 R', $xo)) . ' >>';
            $pageIds[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents %d 0 R >>', $pagesIdx, self::W * self::PT, self::H * self::PT, $res, $c));
        }
        $pages = $add('<< /Type /Pages /Kids [' . implode(' ', array_map(fn($p) => $p . ' 0 R', $pageIds)) . '] /Count ' . count($pageIds) . ' >>');
        if ($pages !== $pagesIdx) throw new LogicException('PDF object numbering');
        $catalog = $add('<< /Type /Catalog /Pages ' . $pages . ' 0 R >>');
        $info = $add('<< /Title ' . $this->pdfStr($title) . ' /Author ' . $this->pdfStr($author) . ' /Producer (GAS Customer Portal) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>');

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        foreach ($objs as $i => $body) { $offs[] = strlen($out); $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= 'trailer << /Size ' . (count($objs) + 1) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . ' 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
    private function pdfStr(string $s): string {   // UTF-16BE with BOM so Greek titles survive
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
