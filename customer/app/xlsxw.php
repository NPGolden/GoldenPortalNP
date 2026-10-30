<?php
/**
 * Minimal .xlsx writer (no library): several sheets, a styled header row
 * (navy fill, white bold, Aptos), frozen header, auto-filter, column widths,
 * numbers kept numeric, dates as text.                       GAS-CP-R17-003
 *
 *   xlsx_download('GAS-vessels.xlsx', [
 *       ['name' => 'Vessels', 'header' => ['Name', 'IMO'], 'rows' => [['MY A', '123']], 'widths' => [30, 12]],
 *   ]);
 */
function xlsx_download(string $filename, array $sheets): void {
    if (!class_exists('ZipArchive')) { xlsx_csv_fallback($filename, $sheets); return; }
    $tmp = xlsx_build($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, max-age=0');
    readfile($tmp); @unlink($tmp); exit;
}
/** The workbook as bytes (for storing or attaching).   R38-001 */
function xlsx_bytes(array $sheets): string { $tmp = xlsx_build($sheets); $b = (string) file_get_contents($tmp); @unlink($tmp); return $b; }
/** Build the workbook into a temp file and return its path. */
function xlsx_build(array $sheets): string {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = count($sheets);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(0, $n - 1)))
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Golden A/S Customer Portal</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Golden A/S Customer Portal</Application></Properties>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', range(0, $n - 1)))
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $defined = '';
    foreach ($sheets as $i => $s) { $cols = max(1, count($s['header'] ?? [])); $rows = count($s['rows'] ?? []) + 1; $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . xlsx_esc(xlsx_sheet_name($s['name'] ?? 'Sheet')) . '\'!$A$1:$' . xlsx_col($cols) . '$' . $rows . '</definedName>'; }
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews><sheets>'
        . implode('', array_map(fn($i) => '<sheet name="' . xlsx_esc(xlsx_sheet_name($sheets[$i]['name'] ?? ('Sheet' . ($i + 1)))) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', range(0, $n - 1)))
        . '</sheets><definedNames>' . $defined . '</definedNames></workbook>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Aptos"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/><family val="2"/></font><font><i/><sz val="9"/><color rgb="FF7A7A7A"/><name val="Aptos"/><family val="2"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B1E2D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    foreach ($sheets as $i => $s) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsx_sheet_xml($s));
    $zip->close();
    return $tmp;
}
function xlsx_sheet_xml(array $s): string {
    $header = array_values($s['header'] ?? []); $rows = $s['rows'] ?? []; $widths = $s['widths'] ?? []; $wrap = $s['wrap'] ?? [];
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $x .= '<sheetViews><sheetView workbookViewId="0"' . (empty($s['first']) ? '' : ' tabSelected="1"') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>';
    $x .= '<sheetFormatPr defaultRowHeight="15"/>';
    if ($widths) { $x .= '<cols>'; foreach ($widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>'; $x .= '</cols>'; }
    $x .= '<sheetData><row r="1" ht="28" customHeight="1">';
    foreach ($header as $c => $h) $x .= '<c r="' . xlsx_col($c + 1) . '1" t="inlineStr" s="1"><is><t>' . xlsx_esc((string)$h) . '</t></is></c>';
    $x .= '</row>';
    $r = 1;
    foreach ($rows as $row) {
        $r++; $x .= '<row r="' . $r . '">';
        foreach (array_values($row) as $c => $v) {
            if ($v === null || $v === '') continue;
            $ref = xlsx_col($c + 1) . $r;
            if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '" s="' . (is_float($v) ? 2 : 0) . '"><v>' . $v . '</v></c>';
            else $x .= '<c r="' . $ref . '" t="inlineStr"' . (in_array($c, $wrap, true) ? ' s="4"' : '') . '><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    if ($header) $x .= '<autoFilter ref="A1:' . xlsx_col(count($header)) . max(1, $r) . '"/>';
    // drop-down lists (data validation): 'lists' => [colIndex0 => ['A','B',…]] — inline for short lists, otherwise a reference to the 'Lists' sheet ('listrefs' => [colIndex0 => "Lists!$A$2:$A$9"])
    $dv = [];
    foreach ($s['lists'] ?? [] as $c => $vals) { $f = '"' . implode(',', array_map(fn($v) => str_replace(['"', ','], ['', ';'], (string)$v), $vals)) . '"'; if (strlen($f) <= 255) $dv[] = '<dataValidation type="list" allowBlank="1" showErrorMessage="1" showInputMessage="1" sqref="' . xlsx_col($c + 1) . '2:' . xlsx_col($c + 1) . '2000"><formula1>' . xlsx_esc($f) . '</formula1></dataValidation>'; }
    foreach ($s['listrefs'] ?? [] as $c => $ref) $dv[] = '<dataValidation type="list" allowBlank="1" showErrorMessage="1" showInputMessage="1" sqref="' . xlsx_col($c + 1) . '2:' . xlsx_col($c + 1) . '2000"><formula1>' . xlsx_esc($ref) . '</formula1></dataValidation>';
    foreach ($s['listranges'] ?? [] as $lr) $dv[] = '<dataValidation type="list" allowBlank="1" showErrorMessage="' . (empty($lr['strict']) ? '0' : '1') . '" showInputMessage="1" sqref="' . xlsx_esc($lr['sqref']) . '"><formula1>' . xlsx_esc($lr['ref']) . '</formula1></dataValidation>';   // one list over a whole block of cells (R30-008)
    if ($dv) $x .= '<dataValidations count="' . count($dv) . '">' . implode('', $dv) . '</dataValidations>';
    $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    return $x;
}
function xlsx_col(int $n): string { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function xlsx_esc(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function xlsx_sheet_name(string $n): string { return mb_substr(preg_replace('/[\[\]\*\/\\\\\?\:]/', ' ', $n), 0, 31) ?: 'Sheet'; }
/** Hosting without the zip extension: one CSV of the first sheet. */
function xlsx_csv_fallback(string $filename, array $sheets): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\.xlsx$/i', '', $filename) . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $s = $sheets[0] ?? ['header' => [], 'rows' => []];
    fputcsv($out, $s['header'], ';'); foreach ($s['rows'] as $r) fputcsv($out, $r, ';');
    fclose($out); exit;
}
