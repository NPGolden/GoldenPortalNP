<?php
/** Monthly sub-project report — data assembly and Word rendering shared by the print view (views/report_sp.php)
 *  and the Issue action (R20).                                              GAS-CP-R3-001 / R20-001 */
declare(strict_types=1);

/** @return array{sp:array,R:array,rev:string,title:string,docNo:string,meta:array,stageRows:array} */
function rp_sp_data(int $id): ?array {
    $sp = load_subproject($id);
    if (!$sp) return null;
    [$vis, ] = vis_sql('visibility');
    $R = [
      'drawings' => all("SELECT d.*, vs.name AS system_name FROM drawing_requests d LEFT JOIN vessel_systems vs ON vs.id = d.system_id WHERE d.subproject_id = ? OR (d.project_id = ? AND d.subproject_id IS NULL AND d.status = 'pending') ORDER BY d.status = 'pending' DESC, d.expected_at IS NULL, d.expected_at, d.kind, d.seq_no", [$id, (int)$sp['project_id']]),   // R36-003: pending first, plus the project-wide pending ones
      'owner'    => all('SELECT * FROM owner_approvals WHERE subproject_id = ? ORDER BY submitted_at, id', [$id]),
      'class'    => all('SELECT * FROM class_submittals WHERE subproject_id = ? ORDER BY submitted_at, id', [$id]),
      'tests'    => all('SELECT * FROM test_reports WHERE subproject_id = ? ORDER BY FIELD(kind,"FAT","HAT","SAT"), planned_at', [$id]),
      'notes'    => all("SELECT n.*, cu.full_name FROM sp_notes n JOIN customer_users cu ON cu.id = n.author_id WHERE n.subproject_id = ? AND {$vis} ORDER BY n.note_date DESC LIMIT 40", [$id]),
      'comments' => all("SELECT c.*, cu.full_name, cu.role FROM sp_comments c JOIN customer_users cu ON cu.id = c.author_id WHERE c.subproject_id = ? AND {$vis} ORDER BY c.id", [$id]),
      'snaps'    => all('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? ORDER BY snap_date DESC LIMIT 6', [$id]),
    ];
    $rev   = 'R' . (int) scalar('SELECT COUNT(*) FROM sp_progress_snapshots WHERE subproject_id = ?', [$id]);
    $title = t('rp.title', ['code' => $sp['code'] ?: '', 'name' => $sp['name']]);
    $bd = br_doc((int)$sp['project_id'], $id); $docNo = $bd['prefix'] . '-PR-' . preg_replace('/[^A-Z0-9]/', '', strtoupper(($sp['project_code'] ?: 'PRJ') . ($sp['code'] ?: ''))) . '-' . date('Ym');
    $meta  = [
      [t('rp.docno'), $docNo], [t('pr.one'), ($sp['project_code'] ? $sp['project_code'] . ' — ' : '') . $sp['project_name']],
      [t('sp.one'), ($sp['code'] ? $sp['code'] . ' — ' : '') . $sp['name']], [t('th.vessel'), $sp['vessel'] ?: '—'],
      [t('sys.one'), $sp['system_name'] ?: '—'], [t('rp.customer'), $sp['customer_name'] ?? '—'],
      [t('rp.prepared'), $bd['name']], [t('sub.rev'), $rev], [t('th.date'), date('d.m.Y')],
      [t('th.status'), t('pr.' . $sp['status'])],
    ];
    $stageRows = []; foreach (stage_defs() as $d) $stageRows[] = [t($d['key']), pct_txt($sp[$d['col']])];
    $stageRows[] = [t('sp.overall'), overall_pct($sp) . '%'];

    return compact('sp', 'R', 'rev', 'title', 'docNo', 'meta', 'stageRows', 'bd');
}
/** The Word document, as bytes. */
function rp_sp_docx(array $D): string {
    extract($D);
require_once __DIR__ . '/docx.php';
    $d = new DocxWriter();
    $d->heading($title, 1);
    foreach ($meta as [$k, $v]) $d->kv($k, (string)$v);
    rc_legend_docx($d);
    $d->heading(t('rp.sec_abbr'), 2);
    foreach ([['FAT', t('ab.fat')], ['HAT', t('ab.hat')], ['SAT', t('ab.sat')], ['GA', t('ab.ga')], ['PM', t('ab.pm')], ['R[n]', t('ab.rev')]] as [$k, $v]) $d->kv($k, $v);
    $d->heading(t('rp.sec_revisions'), 2);
    $d->table([t('sub.rev'), t('th.date'), t('rp.rev_title')],
        array_merge([[$rev, date('d.m.Y'), t('rp.rev_current')]],
                    array_map(fn($s) => ['R' . $s['id'], fmt_date($s['snap_date']), t('sp.snapshot')], $R['snaps'])),
        t('rp.tab_rev'));
    $d->heading(t('tab.process'), 2);
    if ($sp['description']) $d->para((string)$sp['description']);
    $d->table([t('rp.stage'), t('rp.pct')], array_map(fn($r) => $r + ['_tone' => rc_pct((int) preg_replace('/\D/', '', (string)$r[1]) ?: (str_contains((string)$r[1], 'N/A') || str_contains((string)$r[1], t('sp.na')) ? PCT_NA : 0))], $stageRows), t('rp.tab_stages'));
    $d->heading(t('dr.title'), 2);
    $d->table(['#', t('dr.item'), t('sys.one'), t('dr.responsible'), t('dr.sent'), t('dr.received'), t('th.status')],
        array_map(fn($r) => [(string)(int)$r['seq_no'], $r['title'], $r['system_name'] ?? '', $r['responsible'], fmt_date($r['request_sent']), $r['status'] === 'pending' ? ($r['expected_at'] ? t('dr.exp_short') . ' ' . fmt_date($r['expected_at']) : '—') : fmt_date($r['received_at']), t('dr.' . $r['status']), '_tone' => $r['status'] === 'pending' && $r['expected_at'] && $r['expected_at'] < date('Y-m-d') ? 'red' : rc_status($r['status'])], $R['drawings'])
        ?: [['', t('dr.empty'), '', '', '', '', '']], t('rp.tab_drawings'));
    $d->heading(t('own.title'), 2);
    $d->table([t('sub.docno'), t('sub.doc'), t('sub.rev'), t('sub.submitted'), t('sub.decided'), t('th.status')],
        array_map(fn($r) => [$r['doc_no'], $r['title'], $r['revision'], fmt_date($r['submitted_at']), fmt_date($r['decided_at']), t('sub.' . $r['status']), '_tone' => rc_status($r['status'])], $R['owner']) ?: [['', t('sub.empty'), '', '', '', '']], t('rp.tab_owner'));
    $d->heading(t('cls.title'), 2);
    $d->table([t('sub.docno'), t('sub.doc'), t('cls.society'), t('sub.rev'), t('sub.submitted'), t('th.status')],
        array_map(fn($r) => [$r['doc_no'], $r['title'], $r['class_society'], $r['revision'], fmt_date($r['submitted_at']), t('sub.' . $r['status']), '_tone' => rc_status($r['status'])], $R['class']) ?: [['', t('sub.empty'), '', '', '', '']], t('rp.tab_class'));
    $d->heading(t('tst.title'), 2);
    $d->table([t('tst.kind'), t('sub.doc'), t('tst.planned'), t('tst.performed'), t('th.status')],
        array_map(fn($r) => [$r['kind'], $r['title'], fmt_date($r['planned_at']), fmt_date($r['performed_at']), t('tst.' . $r['status']), '_tone' => rc_status($r['status']) ?? rc_due($r['planned_at'] ?? null)], $R['tests']) ?: [['', t('tst.empty'), '', '', '']], t('rp.tab_tests'));
    if (function_exists('iss_has') && iss_has()) {                                                                        // R29-001: open issues of the sub-project
        $d->heading(t('iss.title'), 2);
        foreach (iss_report_tables((int)$sp['project_id'], (int)$sp['id'], date('Y-m-d', strtotime('-30 days'))) as $it) $d->table(array_column($it['cols'], 0), array_map(fn($r) => $r['v'] + ['_tone' => $r['tone'] ?? null, '_hl' => $r['hl'] ?? []], $it['rows']) ?: [array_merge([t('iss.none')], array_fill(0, count($it['cols']) - 1, ''))], $it['title']);
    }
    $d->heading(t('wk.notes'), 2);
    foreach ($R['notes'] as $n) $d->bullet(fmt_date($n['note_date']) . ' — ' . $n['body']);
    if (!$R['notes']) $d->para(t('wk.nonotes'), ['i' => true]);
    $d->heading(t('cm.title'), 2);
    foreach ($R['comments'] as $c) $d->bullet(fmt_datetime($c['created_at']) . ' · ' . $c['full_name'] . ': ' . $c['body']);
    if (!$R['comments']) $d->para(t('cm.empty'), ['i' => true]);
    $bytes = $d->bytes($title, $docNo . ' · ' . $rev . ' · ' . $bd['footer']);
    return $bytes;
}
