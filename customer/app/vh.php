<?php
/**
 * Vessel technical-history report (PDF / Word) built from vessel_history().   GAS-CP-R14-001
 * Doc no GAS-VH-<vessel>-<Ymd>. Numbered tables, Golden A/S header, same
 * writers as the FSR and timesheet reports. Customers get the external view.
 */

function vh_doc_no(array $v, ?array $project = null): string {
    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim((string)$v['name'])));
    return br_prefix((int)($project['id'] ?? 0) ?: null) . '-VH-' . trim($slug, '-') . ($project ? '-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', (string)($project['project_code'] ?: $project['name']))) : '') . '-' . date('Ymd');
}
function vh_kind_label(string $k): string {
    return ['project' => t('pr.one'), 'ticket' => t('tk.one'), 'jo' => t('jo.one'), 'fsr' => t('fsr.one'), 'approval' => t('oa.one')][$k] ?? $k;
}
function vh_part_type(string $t): string { return ['W' => t('fsr.pt_w'), 'G' => t('fsr.pt_g'), 'I' => t('fsr.pt_i')][$t] ?? $t; }
function vh_fmt_h(float $h): string { return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.'); }
function vh_sub_pct(array $s): int {
    return overall_pct($s);
}

/** Rows for every table, shared by PDF and Word so both documents say the same. */
function vh_tables(array $h): array {
    $staff = is_staff(); $T = [];
    $T[] = ['title' => t('vh.t_projects'), 'cols' => [[t('th.code'), 28, 'L'], [t('pr.one'), 62, 'L'], [t('sys.one'), 40, 'L'], [t('th.status'), 24, 'L'], [t('vh.progress'), 26, 'R']],
            'rows' => (function () use ($h) { $o = [];
                foreach ($h['projects'] as $p) { $o[] = ['b' => true, 'tone' => rc_status($p['status']) ?? 'navy', 'v' => [$p['project_code'] ?: '', $p['name'], '', t('pr.' . $p['status']), '']];
                    foreach ($h['subs'] as $s) if ((int)$s['project_id'] === (int)$p['id']) $o[] = ['v' => ['  ' . $s['code'], $s['name'], trim(($s['system_abbr'] ? $s['system_abbr'] . ' ' : '') . ($s['system_name'] ?? '')), t('pr.' . $s['status']), vh_sub_pct($s) . ' %']]; }
                return $o; })()];
    $T[] = ['title' => t('vh.t_disciplines'), 'cols' => [[t('disc.one'), 50, 'L'], [t('vh.technicians'), 100, 'L'], [t('vh.hours_fsr'), 30, 'R']],
            'rows' => array_map(fn($d) => ['v' => [$d['discipline'], implode(', ', array_unique($d['people'])), vh_fmt_h($d['hours'])]], $h['disciplines'])];
    $T[] = ['title' => t('vh.t_technicians'), 'cols' => [[t('vh.technician'), 52, 'L'], [t('disc.one'), 40, 'L'], [t('vh.visits'), 20, 'R'], [t('vh.hours_fsr'), 22, 'R'], [t('vh.first'), 23, 'L'], [t('vh.last'), 23, 'L']],
            'rows' => array_map(fn($x) => ['v' => [$x['name'], $x['discipline'], (string)$x['visits'], vh_fmt_h($x['hours']), fmt_date($x['first']), fmt_date($x['last'])]], $h['technicians'])];
    $T[] = ['title' => t('vh.t_services'), 'cols' => [[t('th.date'), 20, 'L'], [t('pr.one'), 22, 'L'], [t('fsr.one'), 30, 'L'], [t('tk.one'), 24, 'L'], [t('vh.service'), 84, 'L']],
            'rows' => array_map(fn($s) => ['v' => [fmt_date($s['date']), trim($s['project'] . ' ' . $s['sub']), $s['ref'], $s['ticket'] ?: '—', $s['what'] . ($s['issues'] ? "\n" . t('fsr.open_issues') . ': ' . $s['issues'] : '')]], $h['services'])];
    $T[] = ['title' => t('vh.t_parts'), 'cols' => [[t('th.date'), 20, 'L'], [t('pr.one'), 22, 'L'], [t('fsr.one'), 30, 'L'], [t('tk.one'), 24, 'L'], [t('vh.part'), 60, 'L'], [t('fsr.pt'), 24, 'L']],
            'rows' => array_map(fn($p) => ['v' => [fmt_date($p['date']), trim($p['project'] . ' ' . $p['sub']), $p['ref'], $p['ticket'] ?: '—', $p['part'], vh_part_type($p['type'])]], $h['parts'])];
    $T[] = ['title' => t('vh.t_tickets'), 'cols' => [[t('tk.no'), 24, 'L'], [t('th.date'), 20, 'L'], [t('pr.one'), 22, 'L'], [t('tk.title_f'), 50, 'L'], [t('tk.category'), 22, 'L'], [t('th.status'), 20, 'L'], [t('tk.resolution'), 22, 'L']],
            'rows' => array_map(fn($x) => ['tone' => rc_ticket($x), 'v' => [$x['ticket_no'], fmt_date(substr((string)$x['created_at'], 0, 10)), $x['project_code'] ?: '—', $x['title'], tk_categories()[$x['category']] ?? $x['category'], t('tk.st_' . $x['status']), (string)($x['resolution'] ?? '')]], $h['tickets'])];
    $T[] = ['title' => t('vh.t_jos'), 'cols' => [[t('jo.no'), 44, 'L'], [t('th.date'), 20, 'L'], [t('pr.one'), 22, 'L'], [t('tk.one'), 24, 'L'], [t('jo.title_f'), 46, 'L'], [t('th.status'), 24, 'L']],
            'rows' => array_map(fn($j) => ['v' => [$j['jo_no'], fmt_date(substr((string)$j['created_at'], 0, 10)), $j['project_code'] ?: '—', $j['ticket_no'] ?: '—', $j['title'], t('jo.st_' . $j['status'])]], $h['jos'])];
    if ($staff && !empty($h['timesheets']['discipline'])) {
        $T[] = ['title' => t('vh.t_timesheets'), 'cols' => [[t('disc.one'), 60, 'L'], [t('ts.normal'), 30, 'R'], [t('ts.overtime'), 30, 'R'], [t('ts.holiday'), 30, 'R'], [t('ts.total'), 30, 'R']],
                'rows' => array_merge(array_map(fn($r) => ['v' => [$r['label'], ts_fmt((float)$r['normal']), ts_fmt((float)$r['overtime']), ts_fmt((float)$r['holiday']), ts_fmt((float)$r['total'])]], $h['timesheets']['discipline']),
                                      [['b' => true, 'v' => [t('ts.total'), ts_fmt((float)$h['timesheets']['total']['normal']), ts_fmt((float)$h['timesheets']['total']['overtime']), ts_fmt((float)$h['timesheets']['total']['holiday']), ts_fmt((float)$h['timesheets']['total']['total'])]]])];
    }
    if (function_exists('iss_has') && iss_has()) foreach ($h['projects'] as $p) foreach (iss_report_tables((int)$p['id'], null) as $it) if ($it['rows']) { $it['title'] = ($p['project_code'] ?: $p['name']) . ' · ' . $it['title']; $T[] = $it; }   // R29-001
    if (function_exists('eq_vessel_tables')) foreach (eq_vessel_tables((int)$h['vessel']['id']) as $et) $T[] = $et;   // R27-002: equipment on board and its service history
    $T[] = ['title' => t('vh.t_timeline'), 'cols' => [[t('th.date'), 20, 'L'], [t('pr.one'), 22, 'L'], [t('vh.kind'), 24, 'L'], [t('th.ref'), 40, 'L'], [t('tk.one'), 22, 'L'], [t('vh.what'), 52, 'L']],
            'rows' => array_map(fn($e) => ['v' => [fmt_date($e['date']), $e['project'] ?: '—', vh_kind_label($e['kind']), $e['ref'], $e['ticket'] ?: '—', $e['text']]], $h['timeline'])];
    return $T;
}

function vh_render_pdf(array $h): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $v = $h['vessel']; $docNo = vh_doc_no($v, $h['only_project'] ?? null); $bd = br_doc((int)($h['only_project']['id'] ?? 0) ?: null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $green = '#2F7D5C';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0;
    $logo = $bd['logo'];
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $docNo, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('vh.title')));
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $docNo);
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y = 31.0; if ($pdf->pageNo() === 1) $y += rc_legend_pdf($pdf, $L, 27.2, $R - $L);
    };
    $footer = function () use ($pdf, $ink, $L, $W, $docNo, $bd) {
        $pdf->setColor($ink); $pdf->setFont('R', 7);
        $pdf->text($L, 291, $bd['footer'] . ' · ' . $docNo . ' · ' . date('d.m.Y H:i'));
        $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W);
    };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $hh) use (&$y, $newPage) { if ($y + $hh > 276) $newPage(); };
    $hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols);
        $lh = 3.6; $pdf->setFont('B', 7.5); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap($lab, $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 2.0; $x = $L; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F');
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy);
            foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.4 + $lh * ($k + 1) - 0.8, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh;
    };
    // a row whose cells wrap; repeats the header on a page break
    $drow = function (array $cols, array $vals, bool $bold = false, ?string $tone = null, array $hl = []) use ($pdf, $ink, $line, $head, $L, &$y, $need, $hrow, $newPage) { $cols = rc_fit_cols($cols);
        $lh = 3.9; $pdf->setFont($bold ? 'B' : 'R', 7.8);
        $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($vals[$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 1.6;
        if ($y + $hh > 276) { $newPage(); $hrow($cols); $pdf->setFont($bold ? 'B' : 'R', 7.8); }
        $x = $L; if ($bold) { $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); }
        rc_bar_pdf($pdf, $tone, $x, $y, $hh);
        foreach ($cols as $i => [$lab, $cw, $al]) {
            $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor(isset($hl[$i]) && rc_hex($hl[$i]) ? '#' . rc_hex($hl[$i]) : $ink); if (isset($hl[$i])) $pdf->setFont('B', 7.8);
            foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.2 + $lh * ($k + 1) - 0.9, $ln, $al, $cw - 3);
            if (isset($hl[$i])) $pdf->setFont($bold ? 'B' : 'R', 7.8);
            $x += $cw;
        }
        $y += $hh;
    };
    $table = function (array $T) use (&$tableNo, &$y, $need, $pdf, $navy, $L, $hrow, $drow) {
        $y += 3; $need(18); $tableNo++;
        $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $T['title']); $y += 6.5;
        $hrow($T['cols']);
        if (!$T['rows']) { $drow($T['cols'], [t('vh.none')]); return; }
        foreach ($T['rows'] as $r) $drow($T['cols'], $r['v'], !empty($r['b']), $r['tone'] ?? null, $r['hl'] ?? []);
    };

    $header(); $footer();
    $kv = [[t('v.one'), $v['name'] . ($v['imo'] ? ' · IMO ' . $v['imo'] : '')], [t('v.customer'), (string)($v['customer_name'] ?: '—')], [t('v.yard'), (string)($v['yard'] ?: '—')],
           [t('rp.issued'), date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? '')], [t('vh.scope'), (!empty($h['only_project']) ? t('pr.one') . ' ' . ($h['only_project']['project_code'] ?: $h['only_project']['name']) . ' · ' : t('vh.all_projects') . ' · ') . t(is_staff() ? 'vh.scope_internal' : 'vh.scope_external')]];
    foreach ($kv as [$k, $val]) { $pdf->setColor($line); $pdf->rect($L, $y, $W, 6); $pdf->setColor($navy); $pdf->setFont('B', 8); $pdf->text($L + 2, $y + 4.1, $k); $pdf->setColor($ink); $pdf->setFont('R', 8.5); $pdf->text($L + 45, $y + 4.1, $val); $y += 6; }
    $y += 4;
    $s = $h['stats'];
    $tiles = [[t('vh.k_projects'), $s['projects'] . ' / ' . $s['subs'], $navy], [t('vh.k_disciplines'), (string)$s['disciplines'], $gold], [t('vh.k_technicians'), (string)$s['technicians'], $green],
              [t('vh.k_services'), (string)$s['services'], $navy], [t('vh.k_parts'), (string)$s['parts'], $gold], [t('vh.k_tickets') . ($s['open_tickets'] ? ' · ' . $s['open_tickets'] . ' ' . t('tk.st_open') : ''), (string)$s['tickets'], $green]];
    $tw = ($W - 15) / 6; $x = $L;
    foreach ($tiles as [$lab, $val, $col]) { $pdf->setColor($head); $pdf->rect($x, $y, $tw, 16, 'F'); $pdf->setColor($col); $pdf->setFont('B', 12); $pdf->text($x, $y + 8.5, $val, 'C', $tw); $pdf->setColor($ink); $fs = 6.5; $pdf->setFont('R', $fs); while ($pdf->width(mb_strtoupper($lab)) > $tw - 2 && $fs > 4.5) { $fs -= 0.25; $pdf->setFont('R', $fs); } $pdf->text($x, $y + 13.5, mb_strtoupper($lab), 'C', $tw); $x += $tw + 3; }
    $y += 20;
    $pdf->setColor($ink); $pdf->setFont('R', 7.5); $y += $pdf->paragraph($L, $y, $W, t('vh.note'), 3.8) + 3;
    foreach (vh_tables($h) as $T) $table($T);
    return $pdf->bytes($docNo);
}

function vh_render_docx(array $h): string {
    require_once __DIR__ . '/docx.php';
    $w = new DocxWriter(); $v = $h['vessel']; $docNo = vh_doc_no($v, $h['only_project'] ?? null); $bd = br_doc((int)($h['only_project']['id'] ?? 0) ?: null);
    $logo = (string) @file_get_contents($bd['logo']);
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4'; $tableNo = 0;
    $w->grid([[['lines' => [mb_strtoupper(t('vh.title')), $docNo], 'b' => true, 'sz' => 14, 'color' => $NAVY], ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    rc_legend_docx($w);
    $kv = fn($l) => ['lines' => [$l], 'b' => true, 'sz' => 8]; $vv = fn($x) => ['text' => (string)$x, 'sz' => 9];
    $w->grid([[$kv(t('v.one')), $vv($v['name'] . ($v['imo'] ? ' · IMO ' . $v['imo'] : ''))], [$kv(t('v.customer')), $vv($v['customer_name'] ?: '—')], [$kv(t('v.yard')), $vv($v['yard'] ?: '—')],
              [$kv(t('rp.issued')), $vv(date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? ''))], [$kv(t('vh.scope')), $vv((!empty($h['only_project']) ? t('pr.one') . ' ' . ($h['only_project']['project_code'] ?: $h['only_project']['name']) . ' · ' : t('vh.all_projects') . ' · ') . t(is_staff() ? 'vh.scope_internal' : 'vh.scope_external'))]], [2400, 7240], ['border' => '9AA5B1']);
    $s = $h['stats'];
    $tile = fn($lab, $val, $col) => ['lines' => [[(string)$val, ['sz' => 14]], [mb_strtoupper($lab), ['sz' => 6.5, 'b' => false, 'color' => '1F2937']]], 'b' => true, 'color' => $col, 'fill' => $HEAD, 'align' => 'center', 'valign' => 'center'];
    $w->grid([[$tile(t('vh.k_projects'), $s['projects'] . ' / ' . $s['subs'], $NAVY), $tile(t('vh.k_disciplines'), $s['disciplines'], 'C89B3C'), $tile(t('vh.k_technicians'), $s['technicians'], '2F7D5C'),
               $tile(t('vh.k_services'), $s['services'], $NAVY), $tile(t('vh.k_parts'), $s['parts'], 'C89B3C'), $tile(t('vh.k_tickets') . ($s['open_tickets'] ? ' · ' . $s['open_tickets'] . ' ' . t('tk.st_open') : ''), $s['tickets'], '2F7D5C')]], [1606, 1606, 1606, 1606, 1606, 1610], ['border' => 'FFFFFF']);
    $w->para(t('vh.note'), ['sz' => 9, 'i' => true]);
    $hdr = fn($x) => ['text' => $x, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center'];
    $c = fn($x, $al = 'left', $b = false) => ['text' => (string)$x, 'sz' => 8.5, 'align' => $al, 'b' => $b];
    foreach (vh_tables($h) as $T) {
        $tableNo++;
        $w->para(t('rp.table') . ' ' . $tableNo . ' — ' . $T['title'], ['b' => true, 'align' => 'left', 'color' => $NAVY]);
        $tot = array_sum(array_column($T['cols'], 1));
        $widths = array_map(fn($col) => (int) round(9640 * $col[1] / $tot), $T['cols']);
        $out = [array_map(fn($col) => $hdr($col[0]), $T['cols'])];
        if (!$T['rows']) $out[] = array_merge([$c(t('vh.none'))], array_fill(0, count($T['cols']) - 1, ''));
        $hasTone = (bool) array_filter($T['rows'], fn($r) => !empty($r['tone']));
        if ($hasTone) { array_unshift($out[0], $hdr('')); $widths = array_map(fn($x) => (int) round($x * 9460 / 9640), $widths); array_unshift($widths, 180); }
        foreach ($T['rows'] as $r) { $cells = array_map(fn($i) => array_merge($c($r['v'][$i] ?? '', $T['cols'][$i][2] === 'R' ? 'right' : 'left', !empty($r['b'])), isset($r['hl'][$i]) && rc_hex($r['hl'][$i]) ? ['color' => rc_hex($r['hl'][$i]), 'b' => true] : []), array_keys($T['cols'])); if ($hasTone) array_unshift($cells, ['text' => '', 'fill' => rc_hex($r['tone'] ?? null) ?: 'FFFFFF']); $out[] = $cells; }
        $w->grid($out, $widths, ['border' => '9AA5B1']);
    }
    return $w->bytes($docNo, $bd['footer'] . ' · ' . $docNo);
}
