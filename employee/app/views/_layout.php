<?php
/** GAS-POMS — chrome. layout_head() ... layout_foot() */

/* ==================================================================
   Shared list machinery: per-user page size + period, multi-status
   filter bar, pagination. Used by Orders and every desk queue.
   ================================================================== */

const LIST_PAGE_SIZES = [25, 50, 100];
const LIST_PERIODS    = [30, 90, 180, 365, 0];      // 0 = all time

/** Resolve rows-per-page, period window and page number for the person,
 *  persisting any change to their profile (harmless pre-migration). */
/** Statuses that count as 'done' — hidden when the person ticks
 *  "running orders only". Delivered is included because with auto-close a
 *  delivered order is effectively finished. */
function finished_statuses(): array {
    return ['closed', 'delivered', 'cancelled', 'rejected_pm', 'rejected_tm'];
}

function list_prefs(array $u): array {
    $per  = (int)($u['pref_page_size']   ?? 25) ?: 25;
    $days = isset($u['pref_period_days']) ? (int)$u['pref_period_days'] : 90;

    if (isset($_GET['per']) && in_array((int)$_GET['per'], LIST_PAGE_SIZES, true)) {
        $new = (int)$_GET['per'];
        if ($new !== $per) { try { q('UPDATE users SET pref_page_size = ? WHERE id = ?', [$new, $u['id']]); } catch (Throwable $e) {} }
        $per = $new;
    }
    if (isset($_GET['days']) && in_array((int)$_GET['days'], LIST_PERIODS, true)) {
        $new = (int)$_GET['days'];
        if ($new !== $days) { try { q('UPDATE users SET pref_period_days = ? WHERE id = ?', [$new, $u['id']]); } catch (Throwable $e) {} }
        $days = $new;
    }
    return [$per, $days, max(1, (int)($_GET['page'] ?? 1))];
}

function period_label(int $days): string {
    return t($days === 0 ? 'flt.pall' : 'flt.p' . $days);
}

/** Multi-select status filter + period + page size. $q === null hides search. */
function filter_bar(string $slug, array $options, array $sel, int $days, int $per, ?string $q = null): void {
    ?>
    <div class="card card--tight">
      <form method="get" action="index.php" class="fltbar">
        <input type="hidden" name="p" value="<?= e($slug) ?>">
        <input type="hidden" name="lang" value="<?= e(locale()) ?>">
        <?php if ($q !== null): ?>
          <div class="field" style="margin:0;">
            <label for="q"><?= tt('orders.search') ?></label>
            <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="<?= tt('orders.search_ph') ?>">
          </div>
        <?php endif; ?>
        <div class="field" style="margin:0;">
          <label for="fstatus"><?= tt('th.status') ?></label>
          <select id="fstatus" name="status[]" multiple size="<?= min(6, count($options)) ?>">
            <?php foreach ($options as $code => $label): ?>
              <option value="<?= e($code) ?>"<?= in_array($code, $sel, true) ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint"><?= tt('flt.multi') ?></p>
        </div>
        <div class="field" style="margin:0;">
          <label for="fdays"><?= tt('flt.period') ?></label>
          <select id="fdays" name="days">
            <?php foreach (LIST_PERIODS as $d): ?>
              <option value="<?= $d ?>"<?= $d === $days ? ' selected' : '' ?>><?= e(period_label($d)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field--check" style="margin:0;">
          <label class="chk">
            <input type="checkbox" name="running" value="1" <?= !empty($_GET['running']) ? 'checked' : '' ?>>
            <?= tt('flt.running') ?>
          </label>
          <p class="hint"><?= tt('flt.running_hint') ?></p>
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
          <a class="btn btn--ghost" href="<?= url($slug) ?>"><?= tt('btn.clear') ?></a>
        </div>
      </form>
    </div>
    <?php
}

/** Previous / next controls preserving every active filter. */
function pager(string $slug, int $page, int $pages, array $keep): void {
    if ($pages <= 1) return;
    ?>
    <div class="actions" style="justify-content:center;align-items:center;">
      <?php if ($page > 1): ?>
        <a class="btn btn--ghost btn--sm" href="<?= url($slug, $keep + ['page' => $page - 1]) ?>">&lsaquo; <?= tt('btn.newer') ?></a>
      <?php endif; ?>
      <span class="hint" style="align-self:center;"><?= e(t('common.page', ['n' => $page, 'total' => $pages])) ?></span>
      <?php if ($page < $pages): ?>
        <a class="btn btn--ghost btn--sm" href="<?= url($slug, $keep + ['page' => $page + 1]) ?>"><?= tt('btn.older') ?> &rsaquo;</a>
      <?php endif; ?>
    </div>
    <?php
}

function nav_items(): array {
    $r = role();
    $items = [['dashboard', t('nav.dashboard'), '']];

    if (in_array($r, ['field_engineer', 'system_admin'], true)) {
        $items[] = ['order_new', t('nav.raise'), ''];
    }
    $items[] = ['orders', t('nav.orders'), ''];

    if (in_array($r, ['project_manager', 'technical_manager', 'system_admin'], true)) {
        $items[] = ['approvals', t('nav.approvals'), ''];
    }
    if (in_array($r, ['procurement', 'system_admin'], true))  $items[] = ['procurement', t('nav.procurement'), ''];
    if (in_array($r, ['warehouse', 'system_admin'], true))    $items[] = ['warehouse', t('nav.warehouse'), ''];
    if (in_array($r, ['driver', 'system_admin'], true))       $items[] = ['driver', t('nav.dispatch'), ''];

    $items[] = ['notifications', t('nav.notifications'), ''];

    if ($r === 'system_admin') {
        $items[] = ['admin_users',    t('nav.users'), 'admin'];
        $items[] = ['admin_projects', t('nav.projects'), 'admin'];
        $items[] = ['admin_vessels',  t('nav.vessels'), 'admin'];
        $items[] = ['admin_log',      t('nav.log'), 'admin'];
        $items[] = ['admin_settings', t('nav.settings'), 'admin'];
    }
    return $items;
}

/** Five short codes, the current one marked. Works without JavaScript. */

function layout_head(string $title, string $active = ''): void {
    $u = current_user();
    $unread = $u ? unread_count((int)$u['id']) : 0;
    ?><!doctype html>
<html lang="<?= e(locale()) ?>" dir="<?= e(LOCALES[locale()]['dir']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= tt('app.name') ?></title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="../assets/favicon.png">
<link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../../assets/app.css') ?: 1 ?>">
</head>
<body>
<!-- GAS-RENDERED <?= gmdate('Y-m-d\TH:i:s\Z') ?> r7 -->
<a class="skip" href="#main"><?= tt('nav.skip') ?></a>

<?php
// Presence: colleagues seen in the last 5 minutes. Hidden gracefully when
// the last_seen_at column has not been migrated yet.
$onlineN = null;
try {
    $onlineN = (int) scalar('SELECT COUNT(*) FROM users
        WHERE is_active = 1 AND last_seen_at >= NOW() - INTERVAL 5 MINUTE');
} catch (Throwable $e) {}
?>
<header class="brandbar">
  <a href="<?= url('dashboard') ?>">
    <img src="../assets/golden-logo.png" alt="Golden Automation Systems">
  </a>
  <div class="spacer"></div>
  <?php if ($onlineN !== null): ?>
    <button type="button" class="onlinebadge" id="online-badge"
            data-online-url="<?= e(url('online')) ?>"
            data-online-title="<?= tt('online.title') ?>"
            title="<?= tt('online.hint') ?>">
      <span class="dot"></span><?= e(t('online.label', ['n' => $onlineN])) ?>
    </button>
  <?php endif; ?>
</header>

<div class="shell">
  <aside class="rail">
    <div class="brand">
      <span class="brand-mark"></span>
      <span class="brand-text">
        <strong><?= tt('app.name') ?></strong>
        <em><?= e(setting('company_name', 'Golden A/S')) ?></em>
      </span>
    </div>

    <nav>
      <?php $lastGroup = null; foreach (nav_items() as [$slug, $label, $group]):
        if ($group === 'admin' && $lastGroup !== 'admin'): ?>
          <p class="rail-group"><?= tt('nav.admin') ?></p>
        <?php endif; $lastGroup = $group; ?>
        <a href="<?= url($slug) ?>" class="rail-link<?= $active === $slug ? ' is-here' : '' ?>">
          <?= e($label) ?>
          <?php if ($slug === 'notifications' && $unread): ?><span class="pip"><?= $unread ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="rail-foot">
      <?php language_picker(); ?>
      <p class="who"><?= e($u['full_name'] ?? '') ?></p>
      <p class="what"><?= e($u['role_name'] ?? '') ?></p>
      <form method="post" action="<?= url('logout') ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="logout">
        <button class="linkish" type="submit"><?= tt('nav.signout') ?></button>
      </form>
    </div>
  </aside>

  <main id="main" class="sheet">
    <?php foreach (flash_pull() as $f): ?>
      <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
<?php
}

function layout_foot(): void {
    ?>
  </main>
</div>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../../assets/app.js') ?: 1 ?>"></script>
</body>
</html>
<?php
}

/** The signature element: an order's passage from request to delivery. */
function passage_rail(array $order): void {
    $legs = passage_legs();
    $here = passage_index($order['status']);
    $bad  = in_array($order['status'], ['rejected_pm', 'rejected_tm', 'cancelled'], true);
    ?>
    <div class="passage<?= $bad ? ' passage--halted' : '' ?>" role="img"
         aria-label="<?= e(status_label($order['status'])) ?>">
      <?php $i = 0; foreach ($legs as $key => $label):
        $state = $i < $here ? 'done' : ($i === $here ? 'now' : 'ahead');
        if ($bad && $i === $here) $state = 'halt'; ?>
        <div class="leg leg--<?= $state ?>">
          <span class="leg-mark"></span>
          <span class="leg-label"><?= e($label) ?></span>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <?php
}

function status_pill(string $status): string {
    return '<span class="pill pill--' . status_tone($status) . '">' . e(status_label($status)) . '</span>';
}
