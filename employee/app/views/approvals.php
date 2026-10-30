<?php
require __DIR__ . '/_layout.php';
$u = require_role('project_manager', 'technical_manager', 'system_admin');
$r = $u['role_code'];

if ($r === 'project_manager') {
    $baseCond = 'o.pm_user_id = ?'; $baseArgs = [$u['id']];
    $deskStatuses = ['awaiting_pm'];
    $eyebrow = t('appr.gate1'); $lede = t('appr.lede1');
} elseif ($r === 'technical_manager') {
    $baseCond = 'o.tm_user_id = ?'; $baseArgs = [$u['id']];
    $deskStatuses = ['awaiting_tm'];
    $eyebrow = t('appr.gate2'); $lede = t('appr.lede2');
} else {
    $baseCond = '1 = 1'; $baseArgs = [];
    $deskStatuses = ['awaiting_pm', 'awaiting_tm'];
    $eyebrow = t('appr.both'); $lede = t('appr.ledeall');
}

$slug = 'approvals'; $joins = '';
$orderBy = "FIELD(o.priority,'vessel_down','urgent','routine'), o.submitted_at";
$ageFrom = 'o.submitted_at';
$title = t('nav.approvals'); $active = 'approvals'; $showAge = true;
$emptyText = t('appr.empty');
require __DIR__ . '/_queue.php';
