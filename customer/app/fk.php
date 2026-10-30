<?php
/** File categories (sp_files.kind) — built-in with translated labels + the administrators' own (Settings › File categories).   GAS-CP-R22-007
 *  Each category carries the document code used in file numbers (<project>-<code>-001) and the archive folder it files into. */
declare(strict_types=1);

const FK_BUILTIN = ['other' => ['FILE', 'files'], 'drawing' => ['DRW', 'drawings'], 'photo' => ['PHO', 'weekly-notes'], 'fat' => ['FAT', 'tests'], 'hat' => ['HAT', 'tests'], 'sat' => ['SAT', 'tests'],
                    'owner_doc' => ['OWN', 'owner-approvals'], 'class_doc' => ['CLS', 'class'], 'weekly' => ['WR', 'reports'], 'monthly' => ['PR', 'reports'], 'ga' => ['GA', 'ga']];
const FK_SYSTEM  = ['fsr_doc' => ['FSR', 'service-reports'], 'fsr_photo' => ['FSR', 'service-reports'], 'fsr_sign' => ['FSR', 'service-reports'], 'isr_doc' => ['ISR', 'inspections'], 'isr_file' => ['ISR', 'inspections'], 'offer' => ['OFF', 'offers'], 'issued' => ['DOC', 'reports'], 'file' => ['FILE', 'files'], 'comment' => ['FILE', 'files']];
const FK_TABS = ['files', 'drawings', 'owner-approvals', 'class', 'tests', 'weekly-notes', 'reports', 'service-reports', 'inspections'];

function fk_table(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'file_kinds'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** All categories keyed by slug: label, doc_code, archive_tab, sort_order, is_builtin, is_system, is_active. */
function fk_defs(): array {
    static $c = null;
    if ($c === null || !empty($GLOBALS['fk_dirty'])) {
        $c = []; $GLOBALS['fk_dirty'] = false;
        if (fk_table()) foreach (all('SELECT * FROM file_kinds ORDER BY sort_order, label') as $r)
            $c[$r['skey']] = ['label' => $r['is_builtin'] ? t('fk.' . $r['skey']) : $r['label'], 'doc_code' => $r['doc_code'], 'archive_tab' => $r['archive_tab'], 'sort_order' => (int)$r['sort_order'], 'is_builtin' => (bool)$r['is_builtin'], 'is_system' => (bool)$r['is_system'], 'is_active' => (bool)$r['is_active']];
        if (!$c) { $i = 0; foreach (FK_BUILTIN + FK_SYSTEM as $k => [$code, $tab]) $c[$k] = ['label' => t('fk.' . $k), 'doc_code' => $code, 'archive_tab' => $tab, 'sort_order' => ++$i * 10, 'is_builtin' => true, 'is_system' => isset(FK_SYSTEM[$k]), 'is_active' => true]; }
    }
    return $c;
}
/** Categories a user may pick when uploading a file (active, not system). */
function fk_choices(): array { return array_map(fn($d) => $d['label'], array_filter(fk_defs(), fn($d) => $d['is_active'] && !$d['is_system'])); }
function fk_label(string $k): string { $d = fk_defs()[$k] ?? null; if ($d) return $d['label']; $s = t('fk.' . $k); return $s === 'fk.' . $k ? ucfirst(str_replace('_', ' ', $k)) : $s; }
function fk_valid(string $k): bool { return isset(fk_defs()[$k]) || isset(FK_SYSTEM[$k]); }
function fk_doc_code(string $k): string { return (string)(fk_defs()[$k]['doc_code'] ?? 'FILE') ?: 'FILE'; }
function fk_tab(string $k): string { $t = (string)(fk_defs()[$k]['archive_tab'] ?? ''); return $t !== '' ? $t : 'files'; }

function fk_save(): string {
    require_cp_admin();
    if (!fk_table()) throw new RuntimeException(t('fkc.need_db'));
    $key = (string)($_POST['skey'] ?? ''); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['doc_code'] ?? '')), 0, 8)) ?: 'FILE';
    $tab = in_array($_POST['archive_tab'] ?? '', FK_TABS, true) ? $_POST['archive_tab'] : 'files';
    $order = (int)($_POST['sort_order'] ?? 50); $active = (int)!empty($_POST['is_active']);
    $defs = fk_defs();
    if ($key !== '' && isset($defs[$key])) {
        if ($defs[$key]['is_builtin']) q('UPDATE file_kinds SET sort_order = ?, is_active = ? WHERE skey = ?', [$order, ($key === 'other' || $defs[$key]['is_system']) ? 1 : $active, $key]);
        else { if ($label === '') throw new RuntimeException(t('fkc.need_label')); q('UPDATE file_kinds SET label = ?, doc_code = ?, archive_tab = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$label, $code, $tab, $order, $active, $key]); }
        $GLOBALS['fk_dirty'] = true; return $key;
    }
    if ($label === '') throw new RuntimeException(t('fkc.need_label'));
    $key = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label)), 0, 26); $key = trim($key, '_') ?: 'c' . time();
    $base = $key; $n = 1; while (isset($defs[$key]) || isset(FK_SYSTEM[$key])) $key = $base . '_' . (++$n);
    q('INSERT INTO file_kinds (skey, label, doc_code, archive_tab, sort_order, is_builtin, is_system, is_active) VALUES (?,?,?,?,?,0,0,1)', [$key, $label, $code, $tab, $order]);
    $GLOBALS['fk_dirty'] = true; return $key;
}
function fk_delete(string $key): void {
    require_cp_admin();
    $d = fk_defs()[$key] ?? null; if (!$d || $d['is_builtin']) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM sp_files WHERE kind = ?', [$key])) throw new RuntimeException(t('fkc.in_use'));
    q('DELETE FROM file_kinds WHERE skey = ?', [$key]); $GLOBALS['fk_dirty'] = true;
}
