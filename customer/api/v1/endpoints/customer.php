<?php
/** Customer app routes (/cu/*) — GAS-CP-R68-001. Every handler is one PortalCustomer::* call; multipart bodies use $_POST / $_FILES as the portal forms do. */
declare(strict_types=1);
function customer_routes(array $u, string $method, string $path, array $body): void {
    $in = $body ?: $_POST;
    $post = fn() => $method === 'POST';
    switch (true) {
        case $method === 'GET' && $path === '/cu/home': Response::json(PortalCustomer::home($u));
        case $method === 'GET' && $path === '/cu/reports': Response::json(['items' => PortalCustomer::reports($u, (string)($_GET['status'] ?? ''))]);
        case $method === 'GET' && preg_match('#^/cu/reports/(\d+)$#', $path, $m): Response::json(PortalCustomer::report($u, (int)$m[1]));
        case $method === 'GET' && preg_match('#^/cu/reports/(\d+)/pdf$#', $path, $m): PortalCustomer::reportPdf($u, (int)$m[1]);
        case $post() && preg_match('#^/cu/reports/(\d+)/(accept|dispute)$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::reportDecide($u, (int)$m[1], $m[2], $in));
        case $method === 'GET' && $path === '/cu/approvals': Response::json(['items' => PortalCustomer::approvals($u)]);
        case $method === 'GET' && preg_match('#^/cu/approvals/(\d+)$#', $path, $m): Response::json(PortalCustomer::approval($u, (int)$m[1]));
        case $method === 'GET' && preg_match('#^/cu/approvals/(\d+)/file$#', $path, $m): PortalCustomer::approvalFile($u, (int)$m[1]);
        case $post() && preg_match('#^/cu/approvals/(\d+)/decide$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::approvalDecide($u, (int)$m[1], $in));
        case $post() && preg_match('#^/cu/approvals/(\d+)/comment$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::approvalComment($u, (int)$m[1], (string)($in['body'] ?? '')));
        case $method === 'GET' && $path === '/cu/offers': Response::json(['items' => PortalCustomer::offers($u)]);
        case $method === 'GET' && preg_match('#^/cu/offers/(\d+)$#', $path, $m): Response::json(PortalCustomer::offer($u, (int)$m[1]));
        case $method === 'GET' && preg_match('#^/cu/offers/(\d+)/file$#', $path, $m): PortalCustomer::offerFile($u, (int)$m[1]);
        case $post() && preg_match('#^/cu/offers/(\d+)/decide$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::offerDecide($u, (int)$m[1], $in));
        case $method === 'GET' && $path === '/cu/tickets': Response::json(['items' => PortalCustomer::tickets($u)]);
        case $post() && $path === '/cu/tickets': RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::ticketCreate($u, $in));
        case $method === 'GET' && preg_match('#^/cu/tickets/files/(\d+)$#', $path, $m): PortalCustomer::ticketFile($u, (int)$m[1]);
        case $method === 'GET' && preg_match('#^/cu/tickets/(\d+)$#', $path, $m): Response::json(PortalCustomer::ticket($u, (int)$m[1]));
        case $post() && preg_match('#^/cu/tickets/(\d+)/comment$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::ticketReply($u, (int)$m[1], (string)($in['body'] ?? '')));
        case $post() && preg_match('#^/cu/tickets/(\d+)/status$#', $path, $m): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalCustomer::ticketStatus($u, (int)$m[1], (string)($in['status'] ?? '')));
        case $method === 'GET' && $path === '/cu/vessels': Response::json(['items' => PortalCustomer::vessels($u)]);
        case $method === 'GET' && $path === '/cu/projects': Response::json(['items' => PortalCustomer::projects($u)]);
        case $method === 'GET' && $path === '/cu/documents': Response::json(['items' => PortalCustomer::documents($u)]);
        case $method === 'GET' && preg_match('#^/cu/documents/(\d+)$#', $path, $m): PortalCustomer::document($u, (int)$m[1]);
        case $method === 'GET' && $path === '/cu/devices': Response::json(['items' => PortalCustomer::devices($u)]);
        case $post() && preg_match('#^/cu/devices/(\d+)/revoke$#', $path, $m): PortalCustomer::revokeDevice($u, (int)$m[1]); Response::json([], 204);
        case $post() && $path === '/cu/locale': PortalCustomer::setLocale($u, (string)($in['locale'] ?? '')); Response::json([], 204);
        case $method === 'GET' && $path === '/me': Response::json(PortalCustomer::me($u));
        case $post() && $path === '/auth/logout': q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$u['device_id']]); Response::json([], 204);
        case $post() && $path === '/devices/push-token': q('UPDATE mob_devices SET push_token = ?, push_service = ? WHERE id = ?', [mb_substr((string)($in['token'] ?? ''), 0, 255), in_array($in['service'] ?? '', ['apns', 'fcm', 'webpush'], true) ? $in['service'] : 'fcm', $u['device_id']]); Response::json([], 204);
        default: Response::error(404, 'Not found');
    }
}
