<?php
require __DIR__ . '/_layout.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$o  = one('SELECT o.*, ru.full_name AS requester,
                  pm.full_name AS pm_name, tm.full_name AS tm_name,
                  pr.full_name AS proc_name, dr.full_name AS driver_name,
                  p.name AS project_name
           FROM orders o
           JOIN users ru ON ru.id = o.requested_by
           JOIN users pm ON pm.id = o.pm_user_id
           JOIN users tm ON tm.id = o.tm_user_id
           LEFT JOIN users pr ON pr.id = o.procurement_user_id
           LEFT JOIN users dr ON dr.id = o.driver_user_id
           JOIN projects p ON p.project_number = o.project_number
           WHERE o.id = ?', [$id]);

if (!$o) { http_response_code(404); layout_head(t('order.notfound')); echo '<h1>' . tt('order.notfound') . '</h1>'; layout_foot(); return; }
if (!can_view_order($o, $u)) { http_response_code(403); exit(t('order.noaccess')); }

$maxItems = (int) cfg('app.max_items', 20);
$items = all('SELECT * FROM order_items WHERE order_id = ? ORDER BY line_no', [$id]);
$atts  = all('SELECT * FROM attachments WHERE order_id = ? ORDER BY item_id, id', [$id]);
$hist  = all('SELECT h.*, u.full_name FROM status_history h JOIN users u ON u.id = h.changed_by
              WHERE h.order_id = ? ORDER BY h.id DESC', [$id]);
$appr  = all('SELECT a.*, u.full_name FROM approvals a JOIN users u ON u.id = a.approver_user_id
              WHERE a.order_id = ? ORDER BY FIELD(a.level,"pm","tm")', [$id]);
$moves = allowed_transitions($o, $u);

$byItem = [];
foreach ($atts as $a) { $byItem[(int)$a['item_id']][] = $a; }

$canPriceUp   = is_role('procurement', 'system_admin') && in_array($o['status'], ['with_procurement','ordered','backordered'], true);

/* Part numbers are editable at the two approval gates by the responsible
 * approver (or an admin); Procurement edits them inside its own form.   */
$uid       = (int)$u['id'];
$canEditPN = ($o['status'] === 'awaiting_pm' && ((int)$o['pm_user_id'] === $uid || is_admin()))
          || ($o['status'] === 'awaiting_tm' && ((int)$o['tm_user_id'] === $uid || is_admin()));
$missingPN = array_column(array_filter($items, fn($i) => trim((string)$i['part_number']) === ''), 'line_no');

/* Q&A thread, merged with status history into one timeline. */
try {
    $msgs = all('SELECT m.*, u.full_name,
                        pm.body AS parent_body, pu.full_name AS parent_name
                 FROM order_messages m
                 JOIN users u  ON u.id  = m.user_id
                 LEFT JOIN order_messages pm ON pm.id = m.reply_to
                 LEFT JOIN users pu ON pu.id = pm.user_id
                 WHERE m.order_id = ? ORDER BY m.id DESC', [$id]);
} catch (Throwable $e) {
    try {                                      // pre-R4 schema: no reply_to yet
        $msgs = all('SELECT m.*, u.full_name FROM order_messages m
                     JOIN users u ON u.id = m.user_id
                     WHERE m.order_id = ? ORDER BY m.id DESC', [$id]);
    } catch (Throwable $e2) { $msgs = []; }    // pre-R3: thread hidden
}

$events = [];
foreach ($hist as $h) $events[] = ['kind' => 'status', 't' => $h['created_at'], 'row' => $h];
foreach ($msgs as $m) $events[] = ['kind' => 'msg',    't' => $m['created_at'], 'row' => $m];
usort($events, fn($a, $b) => strcmp($b['t'], $a['t']));
$canSetActual = is_role('driver', 'warehouse', 'system_admin');

layout_head($o['order_ref'], 'orders');
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($o['project_number']) ?> · <?= e($o['project_name']) ?></p>
    <h1 style="font-family:var(--mono);letter-spacing:0;"><?= e($o['order_ref']) ?></h1>
    <p class="lede" style="margin-bottom:0;">
      <?= e(t('order.raised', [
            'vessel' => $o['vessel_name'] ?: t('order.novessel'),
            'name'   => $o['requester'],
            'date'   => fmt_date($o['created_at']),
          ])) ?>
      · <span class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></span>
    </p>
  </div>
  <div style="text-align:right;">
    <?= status_pill($o['status']) ?>
    <?php if ($o['sp_synced_at']): ?>
      <p class="hint" style="margin-top:8px;"><?= e(t('order.synced', ['when' => fmt_datetime($o['sp_synced_at'])])) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php passage_rail($o); ?>

<div class="grid grid-2">
  <div class="card">
    <h2><?= tt('order.details') ?></h2>
    <dl class="dl">
      <dt><?= tt('order.project') ?></dt><dd><?= e($o['project_number']) ?> — <?= e($o['project_name']) ?></dd>
      <dt><?= tt('th.vessel') ?></dt><dd><?= e($o['vessel_name'] ?: '—') ?></dd>
      <dt><?= tt('order.pm') ?></dt><dd><?= e($o['pm_name']) ?></dd>
      <dt><?= tt('order.tm') ?></dt><dd><?= e($o['tm_name']) ?></dd>
      <dt><?= tt('order.proc') ?></dt><dd><?= e($o['proc_name'] ?: t('order.unassigned')) ?></dd>
      <dt><?= tt('order.driver') ?></dt><dd><?= e($o['driver_name'] ?: t('order.unassigned')) ?></dd>
      <dt><?= tt('order.deliver_to') ?></dt><dd><?= e($o['delivery_address'] ?: '—') ?></dd>
      <dt><?= tt('order.needed_by') ?></dt><dd class="date"><?= e(fmt_date($o['needed_by'])) ?></dd>
      <dt><?= tt('order.approx') ?></dt><dd class="date"><?= e(fmt_date($o['approx_delivery_date'])) ?></dd>
      <dt><?= tt('order.actual') ?></dt><dd class="date"><?= e(fmt_date($o['actual_delivery_date'])) ?></dd>
    </dl>
    <?php if ($o['notes']): ?>
      <h3 style="margin-top:20px;"><?= tt('order.notes') ?></h3>
      <p style="margin:0;color:var(--ink-2);"><?= nl2br(e($o['notes'])) ?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= tt('order.approvals') ?></h2>
    <?php if (!$appr): ?>
      <p class="hint"><?= tt('order.notsubmitted') ?></p>
    <?php else: ?>
      <table>
        <thead><tr><th><?= tt('th.gate') ?></th><th><?= tt('th.approver') ?></th>
                   <th><?= tt('th.decision') ?></th><th><?= tt('th.when') ?></th></tr></thead>
        <tbody>
        <?php foreach ($appr as $a): ?>
          <tr>
            <td><?= $a['level'] === 'pm' ? tt('order.pm') : tt('order.tm') ?></td>
            <td><?= e($a['full_name']) ?></td>
            <td>
              <?php $tone = ['approved'=>'good','rejected'=>'bad','pending'=>'live'][$a['decision']]; ?>
              <span class="pill pill--<?= $tone ?>"><?= e(t('decision.' . $a['decision'])) ?></span>
              <?php if ($a['comment']): ?><br><span class="hint"><?= e($a['comment']) ?></span><?php endif; ?>
            </td>
            <td class="date"><?= e(fmt_datetime($a['decided_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($moves): ?>
      <h3 style="margin-top:24px;"><?= tt('order.move') ?></h3>
      <form method="post" action="<?= url('order', ['id' => $id]) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="order_transition">
        <input type="hidden" name="order_id" value="<?= $id ?>">
        <input type="hidden" name="_return" value="order">

        <div class="field">
          <label for="to"><?= tt('order.action') ?></label>
          <select id="to" name="to" required>
            <?php foreach ($moves as $key => $label): ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="comment"><?= tt('order.comment') ?></label>
          <textarea id="comment" name="comment" style="min-height:70px;"></textarea>
        </div>
        <button class="btn btn--gold" type="submit"><?= tt('order.apply') ?></button>
      </form>
    <?php else: ?>
      <p class="hint" style="margin-top:20px;"><?= tt('order.noaction') ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt('order.items') ?>
    <span class="hint" style="font-weight:400;">— <?= e(t('order.items_of', ['n' => count($items), 'max' => $maxItems])) ?></span>
  </h2>

  <?php if ($missingPN && in_array($o['status'], ['awaiting_pm','awaiting_tm'], true)): ?>
    <div class="flash flash--warn" style="margin-top:10px;">
      <?= tt('pn.hint') ?>
      <span class="pn-missing">(<?= tt('th.lines') ?>: <?= e(implode(', ', $missingPN)) ?>)</span>
    </div>
  <?php endif; ?>

  <?php if ($canEditPN): ?>
  <form method="post" action="<?= url('order', ['id' => $id]) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="items_partnos">
    <input type="hidden" name="order_id" value="<?= $id ?>">
  <?php elseif ($canPriceUp): ?>
  <form method="post" action="<?= url('order', ['id' => $id]) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="order_dates">
    <input type="hidden" name="order_id" value="<?= $id ?>">
    <input type="hidden" name="_return" value="order">
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th style="width:36px;">#</th><th><?= tt('th.description') ?></th><th><?= tt('th.part_maker') ?></th>
        <th><?= tt('th.qty') ?></th><th><?= tt('th.supplier') ?></th><th><?= tt('th.unit_price') ?></th>
        <th><?= tt('th.approx_short') ?></th><th><?= tt('th.actual') ?></th><th><?= tt('th.status') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it): $iid = (int)$it['id']; ?>
      <tr>
        <td class="num"><?= str_pad((string)$it['line_no'], 2, '0', STR_PAD_LEFT) ?></td>
        <td>
          <?= e($it['description']) ?>
          <?php if (!empty($byItem[$iid])): ?>
            <div class="thumbs">
              <?php foreach ($byItem[$iid] as $a): ?>
                <?php if ($a['kind'] === 'photo'): ?>
                  <a href="<?= url('file', ['id' => $a['id']]) ?>" target="_blank" rel="noopener">
                    <img src="<?= url('file', ['id' => $a['id']]) ?>" alt="<?= e($a['original_name']) ?>">
                  </a>
                <?php elseif ($a['kind'] === 'audio'): ?>
                  <audio controls preload="none" src="<?= url('file', ['id' => $a['id']]) ?>"></audio>
                <?php else: ?>
                  <a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $a['id']]) ?>" target="_blank" rel="noopener">
                    <?= e($a['original_name']) ?>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="num">
          <?php if ($canEditPN): ?>
            <input type="text" name="part_number[<?= $iid ?>]" value="<?= e($it['part_number']) ?>"
                   maxlength="80" placeholder="<?= tt('new.part_no') ?>" style="min-width:120px;">
          <?php elseif ($canPriceUp): ?>
            <input type="text" name="item_partno[<?= $iid ?>]" value="<?= e($it['part_number']) ?>"
                   maxlength="80" placeholder="<?= tt('new.part_no') ?>" style="min-width:120px;">
          <?php else: ?>
            <?= $it['part_number'] ? e($it['part_number']) : '<span class="pn-missing">' . tt('pn.missing') . '</span>' ?>
          <?php endif; ?>
          <br><span class="hint"><?= e($it['manufacturer'] ?: '') ?></span>
        </td>
        <td class="num"><?= e(fmt_qty((float)$it['quantity'])) ?> <?= e($it['unit']) ?></td>

        <?php if ($canPriceUp): ?>
          <td><input type="text" name="item_supplier[<?= $iid ?>]" value="<?= e($it['supplier']) ?>" placeholder="<?= tt('th.supplier') ?>"></td>
          <td><input type="number" step="0.01" min="0" name="item_price[<?= $iid ?>]" value="<?= e($it['unit_price']) ?>"></td>
          <td><input type="date" name="item_approx[<?= $iid ?>]" value="<?= e($it['approx_delivery_date']) ?>"></td>
        <?php else: ?>
          <td><?= e($it['supplier'] ?: '—') ?></td>
          <td class="num"><?= e(fmt_money($it['unit_price'] !== null ? (float)$it['unit_price'] : null, $it['currency'])) ?></td>
          <td class="date"><?= e(fmt_date($it['approx_delivery_date'])) ?></td>
        <?php endif; ?>

        <td class="date"><?= e(fmt_date($it['actual_delivery_date'])) ?></td>
        <td><span class="pill pill--mute"><?= e(item_status_label($it['item_status'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($canEditPN): ?>
    <div class="actions" style="margin-top:18px;">
      <button class="btn" type="submit"><?= tt('pn.save') ?></button>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($canPriceUp): ?>
    <div class="grid grid-2" style="margin-top:20px;">
      <div class="field">
        <label for="approx_delivery_date"><?= tt('order.approx_all') ?></label>
        <input id="approx_delivery_date" name="approx_delivery_date" type="date" value="<?= e($o['approx_delivery_date']) ?>">
      </div>
    </div>
    <button class="btn" type="submit"><?= tt('order.save_proc') ?></button>
  </form>
  <?php endif; ?>

  <?php if ($canSetActual): ?>
    <form method="post" action="<?= url('order', ['id' => $id]) ?>" style="margin-top:18px;border-top:1px solid var(--line-soft);padding-top:18px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="order_actual_date">
      <input type="hidden" name="order_id" value="<?= $id ?>">
      <input type="hidden" name="_return" value="order">
      <div class="field" style="max-width:280px;">
        <label for="actual_delivery_date"><?= tt('order.actual_date') ?></label>
        <input id="actual_delivery_date" name="actual_delivery_date" type="date" value="<?= e($o['actual_delivery_date']) ?>">
      </div>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('order.record_actual') ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('order.history') ?> · <?= tt('qa.title') ?></h2>

  <form class="qa-form" id="qa-form" method="post" action="<?= url('order', ['id' => $id]) ?>"
        style="margin:14px 0 20px;" data-lock data-busy="<?= tt('qa.posting') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="qa_post">
    <input type="hidden" name="order_id" value="<?= $id ?>">
    <input type="hidden" name="reply_to" id="qa-reply-to" value="">
    <div class="qa-replying" id="qa-replying" hidden>
      ↩ <span id="qa-replying-name"></span>
      <button type="button" class="qa-cancel" id="qa-cancel"><?= tt('qa.cancel') ?></button>
    </div>
    <div class="field" style="margin-bottom:10px;">
      <textarea name="body" id="qa-body" maxlength="2000" required placeholder="<?= tt('qa.write') ?>"></textarea>
    </div>
    <button class="btn btn--ghost btn--sm" type="submit"><?= tt('qa.post') ?></button>
  </form>

  <?php if (!$events): ?>
    <div class="empty"><p><?= tt('qa.empty') ?></p></div>
  <?php else: ?>
  <ul class="timeline">
    <?php foreach ($events as $ev): $row = $ev['row']; ?>
      <?php if ($ev['kind'] === 'status'): ?>
        <li>
          <span class="when"><?= e(fmt_datetime($row['created_at'])) ?></span>
          <span class="what2"><?= e(t('order.set_to', ['name' => $row['full_name']])) ?>
            <?= status_pill($row['to_status']) ?></span>
          <?php if ($row['comment']): ?><br><span class="note">“<?= e($row['comment']) ?>”</span><?php endif; ?>
        </li>
      <?php else: ?>
        <li class="tl-msg">
          <span class="when"><?= e(fmt_datetime($row['created_at'])) ?></span>
          <span class="what2">💬 <strong><?= e($row['full_name']) ?></strong></span>
          <button type="button" class="qa-reply"
                  data-id="<?= (int)$row['id'] ?>"
                  data-name="<?= e($row['full_name']) ?>"
                  data-excerpt="<?= e(mb_substr($row['body'], 0, 70)) ?>">↩ <?= tt('qa.reply') ?></button>
          <?php if (!empty($row['parent_body'])): ?>
            <br><span class="qa-quote">↩ <b><?= e($row['parent_name']) ?></b>:
              “<?= e(mb_substr($row['parent_body'], 0, 70)) ?><?= mb_strlen($row['parent_body']) > 70 ? '…' : '' ?>”</span>
          <?php endif; ?>
          <br><span class="note"><?= nl2br(e($row['body'])) ?></span>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php if (is_admin()): ?>
<div class="card card--danger">
  <h2><?= tt('del.title') ?></h2>
  <p class="hint" style="margin-top:-6px;"><?= e(t('del.lede', ['ref' => $o['order_ref']])) ?></p>
  <form method="post" action="<?= url('order', ['id' => $id]) ?>" class="del-form"
        data-lock data-busy="<?= tt('del.working') ?>"
        data-confirm="<?= e(t('del.confirm', ['ref' => $o['order_ref']])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="order_delete">
    <input type="hidden" name="order_id" value="<?= $id ?>">
    <div class="field" style="max-width:360px;">
      <label for="confirm_ref"><?= e(t('del.type', ['ref' => $o['order_ref']])) ?></label>
      <input id="confirm_ref" name="confirm_ref" type="text" autocomplete="off"
             pattern="<?= e(preg_quote($o['order_ref'])) ?>" required
             placeholder="<?= e($o['order_ref']) ?>">
    </div>
    <button class="btn btn--danger" type="submit"><?= tt('del.button') ?></button>
  </form>
</div>
<?php endif; ?>

<?php layout_foot(); ?>
