<?php
require __DIR__ . '/_layout.php';
$u = require_role('procurement', 'system_admin');

$slug = 'procurement'; $joins = '';
$baseCond = '1 = 1'; $baseArgs = [];
$deskStatuses = ['with_procurement', 'ordered', 'backordered'];
$orderBy = "FIELD(o.status,'with_procurement','backordered','ordered'),
            FIELD(o.priority,'vessel_down','urgent','routine'), o.updated_at";
$title = t('nav.procurement'); $active = 'procurement'; $showAge = true;
$eyebrow = t('proc.eyebrow'); $lede = t('proc.lede'); $emptyText = t('proc.empty');
require __DIR__ . '/_queue.php';
