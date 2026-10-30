<?php
require __DIR__ . '/_layout.php';
$u = require_role('warehouse', 'system_admin');

$slug = 'warehouse'; $joins = '';
$baseCond = '1 = 1'; $baseArgs = [];
$deskStatuses = ['ordered', 'backordered', 'in_warehouse', 'ready_for_dispatch', 'partially_fulfilled'];
$orderBy = "FIELD(o.status,'in_warehouse','ordered','partially_fulfilled','backordered','ready_for_dispatch'),
            FIELD(o.priority,'vessel_down','urgent','routine'), o.updated_at";
$title = t('nav.warehouse'); $active = 'warehouse'; $showAge = true;
$eyebrow = t('wh.eyebrow'); $lede = t('wh.lede'); $emptyText = t('wh.empty');
require __DIR__ . '/_queue.php';
