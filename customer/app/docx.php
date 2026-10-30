<?php
/**
 * Minimal .docx writer — pure PHP (ZipArchive), no library.
 * Headings, paragraphs, bullet lists and simple tables, in Aptos,
 * navy / gold styling, UTF-8 (Greek included). Enough for the
 * per-sub-project report; not a general Word engine.
 */
declare(strict_types=1);

final class DocxWriter {
    private array $body = [];
    private array $media = [];                       // rId => ['name','bytes']
    private const NAVY = '0B1E2D';
    private const GOLD = 'C89B3C';
    private const INK2 = '4A5A68';

    private static function x(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    private static function run(string $text, array $o = []): string {
        $pr = '<w:rFonts w:ascii="Aptos" w:hAnsi="Aptos" w:cs="Aptos"/>';
        if (!empty($o['b'])) $pr .= '<w:b/>';
        if (!empty($o['i'])) $pr .= '<w:i/>';
        if (!empty($o['color'])) $pr .= '<w:color w:val="' . $o['color'] . '"/>';
        if (!empty($o['sz'])) $pr .= '<w:sz w:val="' . ((int)$o['sz'] * 2) . '"/>';
        if (function_exists('rt_plain')) $text = rt_plain($text);                           // R37-001
        return '<w:r><w:rPr>' . $pr . '</w:rPr><w:t xml:space="preserve">' . self::x($text) . '</w:t></w:r>';
    }

    public function heading(string $text, int $level = 1): void {
        $sz = [1 => 20, 2 => 15, 3 => 12][$level] ?? 12;
        $color = $level === 1 ? self::NAVY : ($level === 2 ? self::GOLD : self::INK2);
        $border = $level === 1
            ? '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="2" w:color="' . self::GOLD . '"/></w:pBdr>' : '';
        $this->body[] = '<w:p><w:pPr><w:spacing w:before="' . ($level === 1 ? 360 : 240) . '" w:after="120"/>' . $border
            . '<w:jc w:val="left"/></w:pPr>' . self::run($text, ['b' => true, 'sz' => $sz, 'color' => $color]) . '</w:p>';
    }
    public function para(string $text, array $o = []): void {
        $jc = $o['align'] ?? 'both';
        $this->body[] = '<w:p><w:pPr><w:spacing w:after="120"/><w:jc w:val="' . $jc . '"/></w:pPr>'
            . self::run($text, $o + ['sz' => 11]) . '</w:p>';
    }
    public function kv(string $label, string $value): void {
        $this->body[] = '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr>'
            . self::run($label . ': ', ['b' => true, 'sz' => 11, 'color' => self::INK2])
            . self::run($value, ['sz' => 11]) . '</w:p>';
    }
    public function bullet(string $text): void {
        $this->body[] = '<w:p><w:pPr><w:ind w:left="360" w:hanging="200"/><w:spacing w:after="60"/><w:jc w:val="both"/></w:pPr>'
            . self::run('•  ' . $text, ['sz' => 11]) . '</w:p>';
    }
    /** @param string[] $headers  @param string[][] $rows */
    public function table(array $headers, array $rows, ?string $caption = null): void {
        if ($caption) {
            $this->body[] = '<w:p><w:pPr><w:spacing w:before="120" w:after="60"/><w:jc w:val="center"/></w:pPr>'
                . self::run($caption, ['b' => true, 'sz' => 10, 'color' => self::INK2]) . '</w:p>';
        }
        $marker = false; foreach ($rows as $r) if (is_array($r) && !empty($r['_tone'])) { $marker = true; break; }   // R29-007: colour marker column
        $n = max(1, count($headers));
        $mw = $marker ? 160 : 0; $w = (int) floor((9000 - $mw) / $n);
        $grid = ($marker ? '<w:gridCol w:w="' . $mw . '"/>' : '') . str_repeat('<w:gridCol w:w="' . $w . '"/>', $n);
        $tr = '<w:tr>';
        if ($marker) $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $mw . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . self::NAVY . '"/></w:tcPr><w:p/></w:tc>';
        foreach ($headers as $h) {
            $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . self::NAVY . '"/></w:tcPr>'
                 . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . self::run((string)$h, ['b' => true, 'sz' => 9, 'color' => 'FFFFFF']) . '</w:p></w:tc>';
        }
        $tr .= '</w:tr>';
        foreach ($rows as $i => $r) {
            $fill = $i % 2 ? 'F6F2E7' : 'FFFFFF';
            $tone = is_array($r) ? ($r['_tone'] ?? null) : null; $hl = is_array($r) ? ($r['_hl'] ?? []) : []; if (is_array($r)) unset($r['_tone'], $r['_hl']);
            $tr .= '<w:tr>';
            if ($marker) $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $mw . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . ($tone && function_exists('rc_hex') && rc_hex($tone) ? rc_hex($tone) : $fill) . '"/></w:tcPr><w:p/></w:tc>';
            for ($c = 0; $c < $n; $c++) {
                $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/></w:tcPr>'
                     . '<w:p><w:pPr><w:jc w:val="left"/></w:pPr>' . self::run((string)($r[$c] ?? ''), isset($hl[$c]) && function_exists('rc_hex') && rc_hex($hl[$c]) ? ['sz' => 9, 'b' => true, 'color' => rc_hex($hl[$c])] : ['sz' => 9]) . '</w:p></w:tc>';
            }
            $tr .= '</w:tr>';
        }
        $this->body[] = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:jc w:val="center"/>'
            . '<w:tblBorders>' . implode('', array_map(fn($s) => '<w:' . $s . ' w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>',
              ['top','left','bottom','right','insideH','insideV'])) . '</w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid>' . $grid . '</w:tblGrid>' . $tr . '</w:tbl><w:p/>';
    }
    public function pageBreak(): void { $this->body[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; }

    /* ---------------------------------------------------------- R7 ----
       Images and a free-form grid, enough to lay out the SF-GLD-014 form. */

    /** Register image bytes (png/jpeg) and return the inline drawing XML. */
    private function drawing(string $bytes, float $wCm, float $hCm): string {
        $info = @getimagesizefromstring($bytes);
        if (!$info) return '';
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
        // fit inside the box, keep aspect
        $r = min($wCm / $info[0], $hCm / $info[1]); $w = $info[0] * $r; $h = $info[1] * $r;
        $n = count($this->media) + 1; $rid = 'rIdImg' . $n;
        $this->media[$rid] = ['name' => 'image' . $n . '.' . $ext, 'bytes' => $bytes];
        $cx = (int) round($w * 360000); $cy = (int) round($h * 360000);
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
             . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $n . '" name="Picture ' . $n . '"/>'
             . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="' . $this->media[$rid]['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
             . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
             . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
             . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }
    /** A standalone image paragraph. */
    public function image(string $bytes, float $wCm, float $hCm, string $align = 'center'): void {
        $d = $this->drawing($bytes, $wCm, $hCm);
        if ($d !== '') $this->body[] = '<w:p><w:pPr><w:jc w:val="' . $align . '"/></w:pPr>' . $d . '</w:p>';
    }

    /** Free-form grid. $widths in twentieths of a point (sum ≈ 9640 for A4
     *  with 2 cm margins). Each row is a list of cells; a cell is a string
     *  or ['text'|'lines'=>…, 'b','i','sz','color','fill','align','span','img'=>[bytes,wCm,hCm],'h'=>minHeightTwips]. */
    public function grid(array $rows, array $widths, array $o = []): void {
        $border = $o['border'] ?? 'D9D9D9';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:jc w:val="center"/>'
             . '<w:tblBorders>' . implode('', array_map(fn($s) => '<w:' . $s . ' w:val="single" w:sz="' . ($o['sz'] ?? 4) . '" w:space="0" w:color="' . $border . '"/>',
               ['top','left','bottom','right','insideH','insideV'])) . '</w:tblBorders>'
             . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="80" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr>'
             . '<w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';
        foreach ($rows as $row) {
            $minH = 0; foreach ($row as $c) if (is_array($c) && !empty($c['h'])) $minH = max($minH, (int)$c['h']);
            $xml .= '<w:tr>' . ($minH ? '<w:trPr><w:trHeight w:val="' . $minH . '" w:hRule="atLeast"/></w:trPr>' : '');
            $col = 0;
            foreach ($row as $c) {
                $c = is_array($c) ? $c : ['text' => (string)$c];
                $span = max(1, (int)($c['span'] ?? 1));
                $w = array_sum(array_slice($widths, $col, $span)); $col += $span;
                $pr = '<w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                    . (!empty($c['fill']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $c['fill'] . '"/>' : '')
                    . (!empty($c['valign']) ? '<w:vAlign w:val="' . $c['valign'] . '"/>' : '')
                    . (!empty($c['_vm']) ? ($c['_vm'] === 'restart' ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>') : '');
                $jc = $c['align'] ?? 'left';
                $ro = ['b' => !empty($c['b']), 'i' => !empty($c['i']), 'sz' => $c['sz'] ?? 9, 'color' => $c['color'] ?? self::NAVY];
                $paras = '';
                if (!empty($c['img'])) {
                    $d = $this->drawing($c['img'][0], (float)$c['img'][1], (float)$c['img'][2]);
                    $paras .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . $d . '</w:p>';
                }
                $lines = $c['lines'] ?? preg_split('/\r\n|\r|\n/', (string)($c['text'] ?? ''));
                foreach ($lines as $ln) {                                   // a line may be ['text', ['sz' => .., 'b' => .., 'color' => ..]]
                    $lro = $ro; if (is_array($ln)) { $lro = array_merge($ro, $ln[1] ?? []); $ln = $ln[0]; }
                    $paras .= '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/><w:jc w:val="' . $jc . '"/></w:pPr>' . self::run((string)$ln, $lro) . '</w:p>';
                }
                if ($paras === '') $paras = '<w:p/>';
                $xml .= '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . $paras . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $this->body[] = $xml . '</w:tbl>' . (empty($o['tight']) ? '<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr></w:p>' : '');
    }

    /** Build the .docx and return its bytes. */
    public function bytes(string $title, string $footer = ''): string {
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
             . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
             . implode('', $this->body)
             . '<w:sectPr><w:footerReference w:type="default" r:id="rIdF"/>'
             . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567"/>'
             . '</w:sectPr></w:body></w:document>';
        $ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
             . self::run($footer, ['sz' => 8, 'color' => self::INK2]) . '</w:p></w:ftr>';
        $ct  = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
             . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
             . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
             . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
             . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
              . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
              . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
              . '</Relationships>';
        $drels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
               . '<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
        foreach ($this->media as $rid => $m) $drels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
        $drels .= '</Relationships>';
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
              . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
              . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
              . '<dc:title>' . self::x($title) . '</dc:title><dc:creator>Golden Automation Systems</dc:creator>'
              . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
              . '</cp:coreProperties>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', $ct);
        $z->addFromString('_rels/.rels', $rels);
        $z->addFromString('word/document.xml', $doc);
        $z->addFromString('word/footer1.xml', $ftr);
        $z->addFromString('word/_rels/document.xml.rels', $drels);
        $z->addFromString('docProps/core.xml', $core);
        foreach ($this->media as $m) $z->addFromString('word/media/' . $m['name'], $m['bytes']);
        $z->close();
        $b = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $b;
    }
}
