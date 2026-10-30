<?php
/**
 * Minimal .xlsx reader — pure PHP (ZipArchive + SimpleXML), no library.
 * Reads the first sheet (or a named one) into rows of plain values.
 * Enough for our import templates; not a general spreadsheet engine.
 */
declare(strict_types=1);

/** @return array<int, array<int, string>> rows of cell strings (0-based cols) */
function xlsx_rows(string $path, ?string $sheetName = null): array {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('Not a valid .xlsx file.');

    // shared strings
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) foreach ($x->si as $si) {
            // a cell string may be split into runs (<r><t>..</t></r>)
            $txt = '';
            if (isset($si->t)) $txt = (string)$si->t;
            else foreach ($si->r as $r) $txt .= (string)$r->t;
            $shared[] = $txt;
        }
    }

    // pick the sheet
    $target = 'xl/worksheets/sheet1.xml';
    if ($sheetName !== null && ($wb = $z->getFromName('xl/workbook.xml')) !== false) {
        $wx = @simplexml_load_string($wb);
        $rels = @simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels') ?: '');
        if ($wx && $rels) {
            $map = [];
            foreach ($rels->Relationship as $r) $map[(string)$r['Id']] = (string)$r['Target'];
            foreach ($wx->sheets->sheet as $s) {
                if (strcasecmp((string)$s['name'], $sheetName) === 0) {
                    $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    if (isset($map[$rid])) { $tg = $map[$rid]; $target = str_starts_with($tg, '/') ? ltrim($tg, '/') : 'xl/' . $tg; }   // absolute targets (/xl/worksheets/sheet1.xml) come from openpyxl-made workbooks
                }
            }
        }
    }
    $xml = $z->getFromName($target);
    $z->close();
    if ($xml === false) throw new RuntimeException('Sheet not found in workbook.');

    $sx = @simplexml_load_string($xml);
    if (!$sx) throw new RuntimeException('Sheet XML unreadable.');

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];                       // e.g. "C7"
            $col = xlsx_col_index(preg_replace('/\d+/', '', $ref));
            $t   = (string)$c['t'];
            $v   = isset($c->v) ? (string)$c->v : '';
            if ($t === 's')        $v = $shared[(int)$v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            elseif ($t === 'b')    $v = $v === '1' ? 'TRUE' : 'FALSE';
            $r[$col] = trim($v);
        }
        if ($r) { ksort($r); $rows[] = $r; }
    }
    return $rows;
}

function xlsx_col_index(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** Excel serial date -> Y-m-d (or the string itself if already a date). */
function xlsx_date(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (is_numeric($v)) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    return null;
}

/** Map a header row to column indexes by fuzzy name. */
function xlsx_header_map(array $headerRow, array $wanted): array {
    $norm = fn(string $s) => strtolower(preg_replace('/[^a-z0-9]+/', '', strtolower($s)));
    $idx = [];
    foreach ($headerRow as $i => $h) { $k = $norm((string)$h); if ($k !== '' && !isset($idx[$k])) $idx[$k] = $i; }
    $out = [];
    foreach ($wanted as $key => $aliases) {
        $out[$key] = null;
        foreach ((array)$aliases as $a) { $k = $norm($a); if ($k !== '' && isset($idx[$k])) { $out[$key] = $idx[$k]; break; } }
    }
    return $out;
}
