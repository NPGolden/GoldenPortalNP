<?php
/**
 * GAS-POMS — in-app notifications, with an email copy.
 *
 * Each recipient is written to in their own language: the message is
 * rendered from keys at delivery time, using that person's saved locale.
 */

declare(strict_types=1);

/**
 * @param string $titleKey  translation key for the subject line
 * @param string $bodyKey   translation key for the body
 * @param array  $vars      placeholders shared by both, already localised
 *                          where they name a status or a priority
 * @param array  $localised keys whose value is itself a translation key,
 *                          re-rendered per recipient (e.g. 'status')
 */
function notify(int $userId, ?int $orderId, string $titleKey, string $bodyKey,
                array $vars = [], array $localised = []): void {

    $loc = locale_of_user($userId);

    $v = $vars;
    foreach ($localised as $name => $key) {
        $v[$name] = t_for($loc, $key);
    }

    $title = t_for($loc, $titleKey, $v);
    $body  = t_for($loc, $bodyKey, $v);

    q('INSERT INTO notifications (user_id, order_id, title, body) VALUES (?, ?, ?, ?)',
      [$userId, $orderId, mb_substr($title, 0, 160), mb_substr($body, 0, 500)]);

    if (setting('notify_email_enabled', '1') !== '1') return;

    $u = one('SELECT full_name, email FROM users WHERE id = ? AND is_active = 1', [$userId]);
    if (!$u) return;

    $path = $orderId ? url('order', ['id' => $orderId, 'lang' => $loc]) : url('dashboard', ['lang' => $loc]);
    $link = rtrim((string) cfg('app.base_url'), '/') . '/' . $path;
    $html = email_template($title, $body, $link, $loc);

    // Send after the response is flushed so the user never waits on SMTP.
    $titleShort = mb_substr($title, 0, 160);
    defer(function () use ($u, $title, $html, $userId, $titleShort) {
        try { $sent = send_mail($u['email'], $u['full_name'], $title, $html); }
        catch (Throwable $e) { $sent = false; @error_log('mail: ' . $e->getMessage()); }
        if ($sent) {
            q('UPDATE notifications SET emailed_at = NOW()
               WHERE user_id = ? AND title = ? ORDER BY id DESC LIMIT 1', [$userId, $titleShort]);
        }
    });
}

/** Notify several people at once, skipping duplicates and nulls. */
function notify_many(array $userIds, ?int $orderId, string $titleKey, string $bodyKey,
                     array $vars = [], array $localised = []): void {
    foreach (array_unique(array_filter($userIds)) as $uid) {
        notify((int)$uid, $orderId, $titleKey, $bodyKey, $vars, $localised);
    }
}

function unread_count(int $userId): int {
    return (int) scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}

function email_template(string $title, string $body, string $link, string $loc): string {
    $company = e((string) setting('company_name', cfg('app.company')));
    return '<!doctype html><html lang="' . e($loc) . '"><head><meta charset="utf-8"></head>
      <body style="margin:0;background:#EEF1F3;font-family:Aptos,Segoe UI,Helvetica,Arial,sans-serif;">
      <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px;">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #D3DAE0;">
          <tr><td style="background:#0B1E2D;padding:18px 24px;">
            <span style="color:#C9A227;font-size:12px;letter-spacing:.18em;text-transform:uppercase;">'
              . e(t('app.name', [], $loc)) . '</span><br>
            <span style="color:#fff;font-size:16px;">' . $company . '</span>
          </td></tr>
          <tr><td style="padding:28px 24px;">
            <h1 style="margin:0 0 12px;font-size:19px;color:#0B1E2D;">' . e($title) . '</h1>
            <p style="margin:0 0 22px;font-size:15px;line-height:1.55;color:#33424E;">' . e($body) . '</p>
            <a href="' . e($link) . '" style="display:inline-block;background:#C9A227;color:#0B1E2D;text-decoration:none;
               padding:11px 22px;font-weight:600;font-size:14px;">' . e(t('email.open', [], $loc)) . '</a>
          </td></tr>
          <tr><td style="padding:16px 24px;border-top:1px solid #E4E9ED;font-size:12px;color:#7A8794;">'
            . e(t('email.why', [], $loc)) . '
          </td></tr>
        </table>
      </td></tr></table></body></html>';
}
