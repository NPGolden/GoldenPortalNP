<?php
/**
 * Projects › Doubled codes — merge of two sub-projects of one project that carry the same code.   GAS-CP-R97-076
 *
 * Everything that points to the record that is removed (every table with a column subproject_id) is moved to the record that is kept;
 * then the removed record is deleted. One transaction: either all of it happens or nothing. Before the transaction is closed a file
 * with the removed record, the ids of every moved row and every dropped duplicate is written to storage/merge — the merge is not
 * undone by a button, that file is what a manual restore works from.
 *
 * Conservative on purpose: when both records hold something that can exist only once (a budget, a BOM revision with the same number …)
 * nothing is merged and the page says what to clear first.
 */

/** Link tables where the same entry on both records is simply the same thing — the one of the removed record is dropped (and kept in the file). */
const SPM_DROP_DUP = ['sp_team', 'sp_contacts', 'wf_item_subs', 'kpi_snapshots', 'sp_progress_snapshots', 'pm_milestones'];
/** Tables with at most one row per sub-project by the logic of the portal. */
const SPM_SINGLE = ['sp_budgets'];
/** Fields of the kept record that are filled from the removed one when empty. */
const SPM_FILL = ['system_id', 'description', 'deadline', 'erp_code', 'branch_id', 'billing_org_id', 'invoicing_id', 'activated_at', 'activated_offer_id', 'completed_at'];

function spm_can(): bool { return is_staff() && (mgmt_can() || is_cp_admin()); }
/** Every table that points to a sub-project: [table => engine]. */
function spm_tables(): array {
    $o = []; foreach (all("SELECT c.TABLE_NAME AS t, tb.ENGINE AS e FROM information_schema.columns c JOIN information_schema.tables tb ON tb.table_schema = c.table_schema AND tb.TABLE_NAME = c.TABLE_NAME
                           WHERE c.table_schema = DATABASE() AND c.COLUMN_NAME = 'subproject_id' AND c.TABLE_NAME <> 'subprojects' AND tb.TABLE_TYPE = 'BASE TABLE' ORDER BY c.TABLE_NAME") as $r)
        if (preg_match('/^[a-z0-9_]+$/', (string)$r['t'])) $o[(string)$r['t']] = (string)$r['e'];
    return $o;
}
function spm_has_id(string $t): bool { try { return (bool) scalar("SHOW COLUMNS FROM `{$t}` LIKE 'id'"); } catch (Throwable $e) { return false; } }

/**
 * Merge $drop into $keep. $dry = true: everything is done and then rolled back — the result is what would happen.
 * $opt['status'] = 'keep' | 'drop' — whose status the kept record has afterwards.
 * Returns ['keep','drop' (rows), 'moved' => [table => n], 'dups' => [table => n], 'blocked' => [table => reason], 'fields' => [col => [old, new]], 'file' => name|null, 'done' => bool].
 */
function spm_run(int $keepId, int $dropId, array $opt = [], bool $dry = true): array {
    if (!spm_can()) throw new RuntimeException(t('err.noaccess'));
    $K = one('SELECT * FROM subprojects WHERE id = ?', [$keepId]); $D = one('SELECT * FROM subprojects WHERE id = ?', [$dropId]);
    if (!$K || !$D || $keepId === $dropId) throw new RuntimeException(t('err.notfound'));
    if ((int)$K['project_id'] !== (int)$D['project_id'] || sp_code_norm($K['code']) === '' || sp_code_norm($K['code']) !== sp_code_norm($D['code'])) throw new RuntimeException(t('spm.e_not_double'));
    $tables = spm_tables(); foreach ($tables as $tb => $eng) if (strcasecmp($eng, 'InnoDB') !== 0) throw new RuntimeException(t('spm.e_engine', ['t' => $tb]));
    $R = ['keep' => $K, 'drop' => $D, 'moved' => [], 'dups' => [], 'blocked' => [], 'fields' => [], 'file' => null, 'done' => false];
    $snap = ['at' => date('c'), 'by' => (string)(current_user()['full_name'] ?? ''), 'keep' => $K, 'drop' => $D, 'moved' => [], 'deleted' => []];
    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ($tables as $tb => $_) {
            $n = (int) scalar("SELECT COUNT(*) FROM `{$tb}` WHERE subproject_id = ?", [$dropId]); if (!$n) continue;
            if (in_array($tb, SPM_SINGLE, true) && (int) scalar("SELECT COUNT(*) FROM `{$tb}` WHERE subproject_id = ?", [$keepId])) { $R['blocked'][$tb] = t('spm.b_single'); continue; }
            if (spm_has_id($tb)) $snap['moved'][$tb] = array_map('intval', array_column(all("SELECT id FROM `{$tb}` WHERE subproject_id = ? ORDER BY id LIMIT 20000", [$dropId]), 'id')); else $snap['moved'][$tb] = $n;
            $st = $pdo->prepare("UPDATE IGNORE `{$tb}` SET subproject_id = ? WHERE subproject_id = ?"); $st->execute([$keepId, $dropId]); $mv = $st->rowCount();
            $left = (int) scalar("SELECT COUNT(*) FROM `{$tb}` WHERE subproject_id = ?", [$dropId]);
            if ($left) {
                if (!in_array($tb, SPM_DROP_DUP, true)) { $R['blocked'][$tb] = t('spm.b_unique', ['n' => $left]); continue; }
                $snap['deleted'][$tb] = all("SELECT * FROM `{$tb}` WHERE subproject_id = ? LIMIT 5000", [$dropId]); q("DELETE FROM `{$tb}` WHERE subproject_id = ?", [$dropId]); $R['dups'][$tb] = $left;
                if (is_array($snap['moved'][$tb]) && spm_has_id($tb)) $snap['moved'][$tb] = array_values(array_diff($snap['moved'][$tb], array_map('intval', array_column($snap['deleted'][$tb], 'id'))));
            }
            if ($mv) $R['moved'][$tb] = $mv;
        }
        /* the kept record: the further progress of the two, its empty fields from the removed one, the status as chosen */
        $set = [];
        foreach ($K as $col => $v) if (str_starts_with((string)$col, 'pct_') && is_numeric($D[$col] ?? null) && (float)$D[$col] > (float)$v) $set[$col] = $D[$col];
        foreach (SPM_FILL as $col) if (array_key_exists($col, $K) && ($K[$col] === null || $K[$col] === '' || $K[$col] === 0 || $K[$col] === '0') && !($D[$col] === null || $D[$col] === '' || $D[$col] === 0 || $D[$col] === '0')) $set[$col] = $D[$col];
        if (($opt['status'] ?? 'keep') === 'drop' && (string)$D['status'] !== (string)$K['status']) $set['status'] = $D['status'];
        foreach ($set as $col => $v) $R['fields'][$col] = [$K[$col], $v];
        if ($set && preg_match('/^[a-z0-9_, =?`]+$/', $sql = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($set))))) q("UPDATE subprojects SET {$sql} WHERE id = ?", array_merge(array_values($set), [$keepId]));
        q('DELETE FROM subprojects WHERE id = ?', [$dropId]);

        if ($R['blocked'] || $dry) { $pdo->rollBack(); return $R; }
        $dir = __DIR__ . '/../storage/merge'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = 'sp_' . $dropId . '_into_' . $keepId . '_' . date('Ymd_His') . '.json';
        if (@file_put_contents($dir . '/' . $name, json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)) === false) throw new RuntimeException(t('spm.e_file'));
        $pdo->commit(); $R['file'] = $name; $R['done'] = true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    try { if (function_exists('sp_reorder')) sp_reorder((int)$K['project_id']); } catch (Throwable $e) {}
    try { if (function_exists('sp_log')) sp_log($keepId, 'merged', '#' . $dropId . ' ' . (string)$D['code'] . ' ' . (string)$D['name']); } catch (Throwable $e) {}
    if (function_exists('eng_rows_reset')) eng_rows_reset(); if (function_exists('cache_bump_later')) cache_bump_later('eng'); if (function_exists('cache_set')) cache_set('dd_n', null, 1);
    @file_put_contents(__DIR__ . '/../storage/merge/merge.log', date('c') . "\t" . (string)(current_user()['full_name'] ?? '') . "\tsub-project #{$dropId} into #{$keepId}\t" . array_sum($R['moved']) . " rows\t{$name}\n", FILE_APPEND);
    return $R;
}

function spm_handle_action(string $action): bool {
    if ($action !== 'spm_merge') return false;
    if (!spm_can()) throw new RuntimeException(t('err.noaccess'));
    $keep = (int)($_POST['keep'] ?? 0); $drop = (int)($_POST['drop'] ?? 0);
    if (empty($_POST['sure'])) throw new RuntimeException(t('spm.e_sure'));
    $R = spm_run($keep, $drop, ['status' => ($_POST['status'] ?? '') === 'drop' ? 'drop' : 'keep'], false);
    if (!$R['done']) { flash('bad', t('spm.blocked')); redirect('data_doubles', ['keep' => $keep, 'drop' => $drop]); }
    flash('good', t('spm.done', ['n' => array_sum($R['moved']), 'f' => $R['file']]));
    redirect('subproject', ['id' => $keep, '_r' => uniqid()]);
    return true;
}
