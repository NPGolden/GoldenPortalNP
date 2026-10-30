<?php
require __DIR__ . '/_layout.php';
require_role('system_admin');

$u = current_user();
[$per, $days, $page] = list_prefs($u);       // per-user rows/page + period

$event = preg_replace('/[^a-z_]/', '', (string)($_GET['event'] ?? ''));
$who   = trim((string)($_GET['who'] ?? ''));

$where = []; $args = [];
if ($event) { $where[] = 'l.event = ?'; $args[] = $event; }
if ($who !== '') {
    // Search across everything a log row carries: person, email tried,
    // IP address and the free-text detail column.
    $where[] = '(u.full_name LIKE ? OR l.email_tried LIKE ? OR l.ip_address LIKE ? OR l.detail LIKE ?)';
    array_push($args, "%{$who}%", "%{$who}%", "%{$who}%", "%{$who}%");
}
if ($days > 0) { $where[] = 'l.created_at >= NOW() - INTERVAL ? DAY'; $args[] = $days; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) scalar("SELECT COUNT(*) FROM access_log l LEFT JOIN users u ON u.id = l.user_id {$w}", $args);
$rows  = all("SELECT l.*, u.full_name FROM access_log l LEFT JOIN users u ON u.id = l.user_id
              {$w} ORDER BY l.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);

$events = ['login_success','login_failed','twofa_success','twofa_failed','logout','lockout',
           'password_change','role_change','user_created','user_disabled','vessel_import','sharepoint_sync'];

$tone = fn(string $ev) => in_array($ev, ['login_failed','twofa_failed','lockout'], true) ? 'bad'
      : (in_array($ev, ['login_success','twofa_success'], true) ? 'good' : 'mute');

layout_head(t('al.title'), 'admin_log');
?>
<p class="eyebrow"><?= tt('nav.admin') ?></p>
<h1><?= tt('al.title') ?></h1>
<p class="lede"><?= e(t('al.lede', ['n' => number_format($total)])) ?></p>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.3fr 1fr .8fr .6fr auto;">
    <input type="hidden" name="p" value="admin_log">
    <input type="hidden" name="lang" value="<?= e(locale()) ?>">
    <div class="field" style="margin:0;">
      <label for="who"><?= tt('al.who') ?></label>
      <input id="who" name="who" type="text" value="<?= e($who) ?>">
    </div>
    <div class="field" style="margin:0;">
      <label for="event"><?= tt('al.event') ?></label>
      <select id="event" name="event">
        <option value=""><?= tt('al.anyevent') ?></option>
        <?php foreach ($events as $ev): ?>
          <option value="<?= e($ev) ?>"<?= $event === $ev ? ' selected' : '' ?>><?= e(event_label($ev)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;">
      <label for="fdays"><?= tt('flt.period') ?></label>
      <select id="fdays" name="days">
        <?php foreach (LIST_PERIODS as $d): ?>
          <option value="<?= $d ?>"<?= $d === $days ? ' selected' : '' ?>><?= e(period_label($d)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;">
      <label for="fper"><?= tt('flt.per') ?></label>
      <select id="fper" name="per">
        <?php foreach (LIST_PAGE_SIZES as $n): ?>
          <option value="<?= $n ?>"<?= $n === $per ? ' selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fltbar-actions">
      <button class="btn btn--ghost" type="submit"><?= tt('btn.apply') ?></button>
      <a class="btn btn--ghost" href="<?= url('admin_log') ?>"><?= tt('btn.clear') ?></a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><p><?= tt('al.empty') ?></p></div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= tt('th.when') ?></th><th><?= tt('th.person') ?></th><th><?= tt('th.event') ?></th>
                 <th><?= tt('th.detail') ?></th><th><?= tt('th.ip') ?></th><th><?= tt('th.device') ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td class="date"><?= e(fmt_datetime($l['created_at'])) ?></td>
          <td><?= e($l['full_name'] ?: ($l['email_tried'] ?: t('al.unknown'))) ?></td>
          <td><span class="pill pill--<?= $tone($l['event']) ?>"><?= e(event_label($l['event'])) ?></span></td>
          <td><?= e($l['detail'] ?: '—') ?></td>
          <td class="num"><?= e($l['ip_address'] ?: '—') ?></td>
          <td class="hint" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($l['user_agent'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php $pages = max(1, (int) ceil($total / $per));
          $keep = array_filter(['event' => $event ?: null, 'who' => $who ?: null,
                                'days' => $days, 'per' => $per], fn($v) => $v !== null); ?>
    <?php pager('admin_log', $page, $pages, $keep); ?>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
