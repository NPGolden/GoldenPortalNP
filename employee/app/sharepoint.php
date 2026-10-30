<?php
/**
 * GAS-POMS — SharePoint "Open Orders" list, over Microsoft Graph.
 *
 * Entra ID app registration needs the application permission
 * Sites.ReadWrite.All with admin consent. Client credentials flow.
 *
 * The SharePoint list is expected to carry these internal column names:
 *   Title (order ref), ProjectNumber, VesselName, RequestedBy,
 *   ProjectManager, TechnicalManager, Priority, OrderStatus,
 *   ApproxDelivery, ActualDelivery, LineCount, LastSync
 */

declare(strict_types=1);

function sp_enabled(): bool {
    return (bool) cfg('sharepoint.enabled', false) || setting('sharepoint_enabled') === '1';
}

function sp_conf(string $key): string {
    $fromFile = (string) cfg('sharepoint.' . $key, '');
    if ($fromFile !== '') return $fromFile;
    $map = [
        'tenant_id'     => 'graph_tenant_id',
        'client_id'     => 'graph_client_id',
        'client_secret' => 'graph_client_secret',
        'site_id'       => 'sharepoint_site_id',
        'list_id'       => 'sharepoint_list_id',
    ];
    return (string) setting($map[$key] ?? $key, '');
}

/** Cached app-only access token. */
function graph_token(): ?string {
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;

    $tenant = sp_conf('tenant_id');
    $body   = http_build_query([
        'client_id'     => sp_conf('client_id'),
        'client_secret' => sp_conf('client_secret'),
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);

    [$code, $resp] = http_json('POST', "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
        $body, ['Content-Type: application/x-www-form-urlencoded']);

    if ($code !== 200 || empty($resp['access_token'])) {
        error_log('Graph token failed: ' . json_encode($resp));
        return null;
    }
    $tok = $resp['access_token'];
    $exp = time() + (int) ($resp['expires_in'] ?? 3600);
    return $tok;
}

function http_json(string $method, string $url, $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string)$raw, true) ?? []];
}

function sp_log(?int $orderId, string $dir, int $code, bool $ok, string $msg): void {
    q('INSERT INTO sharepoint_sync (order_id, direction, http_status, ok, message) VALUES (?, ?, ?, ?, ?)',
      [$orderId, $dir, $code, $ok ? 1 : 0, mb_substr($msg, 0, 500)]);
}

/** Create or update the list item for an order. Safe to call on every change. */
function sharepoint_push(int $orderId): bool {
    if (!sp_enabled()) return false;

    $token = graph_token();
    if (!$token) { sp_log($orderId, 'push', 0, false, 'No access token'); return false; }

    $o = one('SELECT o.*, ru.full_name AS req_name, pm.full_name AS pm_name, tm.full_name AS tm_name,
                     (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS line_count
              FROM orders o
              JOIN users ru ON ru.id = o.requested_by
              JOIN users pm ON pm.id = o.pm_user_id
              JOIN users tm ON tm.id = o.tm_user_id
              WHERE o.id = ?', [$orderId]);
    if (!$o) return false;

    $fields = [
        'Title'            => $o['order_ref'],
        'ProjectNumber'    => $o['project_number'],
        'VesselName'       => $o['vessel_name'],
        'RequestedBy'      => $o['req_name'],
        'ProjectManager'   => $o['pm_name'],
        'TechnicalManager' => $o['tm_name'],
        'Priority'         => priority_label($o['priority']),
        'OrderStatus'      => status_label($o['status']),
        'ApproxDelivery'   => $o['approx_delivery_date'],
        'ActualDelivery'   => $o['actual_delivery_date'],
        'LineCount'        => (int) $o['line_count'],
        'LastSync'         => gmdate('c'),
    ];

    $site = sp_conf('site_id');
    $list = sp_conf('list_id');
    $hdrs = ["Authorization: Bearer {$token}", 'Content-Type: application/json'];

    if ($o['sp_item_id']) {
        $url = "https://graph.microsoft.com/v1.0/sites/{$site}/lists/{$list}/items/{$o['sp_item_id']}/fields";
        [$code, $resp] = http_json('PATCH', $url, $fields, $hdrs);
        $ok = $code >= 200 && $code < 300;
    } else {
        $url = "https://graph.microsoft.com/v1.0/sites/{$site}/lists/{$list}/items";
        [$code, $resp] = http_json('POST', $url, ['fields' => $fields], $hdrs);
        $ok = $code >= 200 && $code < 300;
        if ($ok && !empty($resp['id'])) {
            q('UPDATE orders SET sp_item_id = ? WHERE id = ?', [(string)$resp['id'], $orderId]);
        }
    }

    if ($ok) q('UPDATE orders SET sp_synced_at = NOW() WHERE id = ?', [$orderId]);
    sp_log($orderId, 'push', $code, $ok, $ok ? 'Synced' : json_encode($resp));
    return $ok;
}

/** Push every open order. Point a cron job at install/sync_sharepoint.php. */
function sharepoint_push_open(): array {
    $rows = all("SELECT id FROM orders WHERE status NOT IN ('closed','cancelled','draft')");
    $done = ['ok' => 0, 'failed' => 0];
    foreach ($rows as $r) {
        sharepoint_push((int)$r['id']) ? $done['ok']++ : $done['failed']++;
    }
    return $done;
}

/** Read the list back — used to reconcile status changes made in SharePoint. */
function sharepoint_pull(): array {
    if (!sp_enabled()) return [];
    $token = graph_token();
    if (!$token) return [];

    $site = sp_conf('site_id');
    $list = sp_conf('list_id');
    $url  = "https://graph.microsoft.com/v1.0/sites/{$site}/lists/{$list}/items?expand=fields&\$top=200";
    [$code, $resp] = http_json('GET', $url, null, ["Authorization: Bearer {$token}"]);

    sp_log(null, 'pull', $code, $code === 200, $code === 200 ? 'Fetched' : json_encode($resp));
    return $resp['value'] ?? [];
}
