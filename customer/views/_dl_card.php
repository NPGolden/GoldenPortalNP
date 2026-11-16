<?php
/** "Acceptance by e-mail" card for staff on a document page (R97-058). Expects $dlDoc (fsr|fc|offer|oa) and $dlId. */
if (function_exists('dl_has') && dl_has() && is_staff() && ($dlI = dl_info($dlDoc, (int)$dlId))):
    $dlRows = dl_list($dlDoc, (int)$dlId);
    if ($dlI['open'] || $dlRows):
        $dlPeople = $dlI['customer_id'] ? all("SELECT full_name AS n, email AS e FROM contacts WHERE customer_id = ? AND email LIKE '%_@_%._%' UNION SELECT full_name, email FROM customer_users WHERE customer_id = ? AND is_active = 1 AND email LIKE '%_@_%._%' ORDER BY n LIMIT 60", [$dlI['customer_id'], $dlI['customer_id']]) : [];
        $dlTone = ['sent' => 'gold', 'opened' => 'blue', 'verified' => 'blue', 'used' => 'green', 'cancelled' => 'mute', 'expired' => 'bad']; ?>
<div class="card" id="dl"><div class="card-head"><h2>✉ <?= tt('dl.title') ?></h2><span class="hint"><?= tt('dl.hint_' . ($dlDoc === 'fsr' ? 'fsr' : ($dlDoc === 'offer' ? 'offer' : 'other'))) ?></span></div>
  <?php if ($dlI['open']): ?>
  <form method="post" action="<?= e($dlI['back']) ?>#dl" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="dl_send"><input type="hidden" name="doc" value="<?= e($dlDoc) ?>"><input type="hidden" name="id" value="<?= (int)$dlId ?>">
    <div class="field"><label for="dl_email"><?= tt('dl.f_email') ?></label><input id="dl_email" name="email" type="email" required maxlength="190" list="dl_people" autocomplete="off"><datalist id="dl_people"><?php foreach ($dlPeople as $p): ?><option value="<?= e((string)$p['e']) ?>"><?= e((string)$p['n']) ?></option><?php endforeach; ?></datalist></div>
    <div class="field"><label for="dl_name"><?= tt('dl.f_name') ?></label><input id="dl_name" name="name" maxlength="120"></div>
    <div class="span2 btn-row"><button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= tt('dl.send_q') ?>">✉ <?= tt('dl.send') ?></button><span class="hint"><?= tt('dl.send_hint') ?></span></div>
  </form>
  <?php endif; ?>
  <?php if ($dlRows): ?><div class="table-scroll" style="margin-top:10px;"><table class="tbl"><thead><tr><th><?= tt('dl.c_to') ?></th><th><?= tt('dl.c_sent') ?></th><th><?= tt('dl.c_until') ?></th><th><?= tt('dl.c_state') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($dlRows as $l): $st = dl_state_of($l); ?><tr>
      <td><?= e((string)$l['email']) ?><?= $l['name'] ? '<br><span class="hint">' . e((string)$l['name']) . '</span>' : '' ?></td>
      <td><?= e(fmt_datetime((string)$l['created_at'])) ?><br><span class="hint"><?= e((string)$l['created_name']) ?><?= (int)$l['remind_n'] ? ' · ' . e(t('dl.reminded', ['n' => (int)$l['remind_n']])) : '' ?></span></td>
      <td><?= e(fmt_datetime((string)$l['expires_at'])) ?></td>
      <td><?= pill($dlTone[$st] ?? 'mute', t('dl.s_' . $st)) ?><?= $st === 'used' ? '<br><span class="hint">' . e((string)$l['signer_name'] . ($l['signer_role'] ? ', ' . $l['signer_role'] : '') . ' · ' . fmt_datetime((string)$l['used_at'])) . '</span>' : ($st === 'cancelled' && $l['cancel_note'] ? '<br><span class="hint">' . e(t('dl.cn_' . $l['cancel_note'])) . '</span>' : '') ?></td>
      <td><?php if (in_array($st, ['sent', 'opened', 'verified'], true)): ?><form method="post" action="<?= e($dlI['back']) ?>#dl" data-lock data-confirm="<?= tt('dl.cancel_q') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="dl_cancel"><input type="hidden" name="doc" value="<?= e($dlDoc) ?>"><input type="hidden" name="id" value="<?= (int)$dlId ?>"><input type="hidden" name="link" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('dl.cancel') ?></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endif; endif; ?>
