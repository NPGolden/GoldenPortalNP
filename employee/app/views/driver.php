<?php
require __DIR__ . '/_layout.php';
$u = require_role('driver', 'system_admin');

$slug = 'driver'; $joins = '';
$baseCond = "(o.status = 'ready_for_dispatch'
           OR (o.status = 'in_transit' AND (o.driver_user_id = ? OR ? = 1)))";
$baseArgs = [$u['id'], $u['role_code'] === 'system_admin' ? 1 : 0];
$deskStatuses = ['ready_for_dispatch', 'in_transit'];
$orderBy = "FIELD(o.status,'in_transit','ready_for_dispatch'),
            FIELD(o.priority,'vessel_down','urgent','routine'), o.updated_at";
$title = t('nav.dispatch'); $active = 'driver'; $showAge = true;
$eyebrow = t('drv.eyebrow'); $lede = t('drv.lede'); $emptyText = t('drv.empty');
require __DIR__ . '/_queue.php';
