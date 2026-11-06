<?php
/** Item card extras (GAS-CP-R88-001): document web links and — for software items — the licence register. Included by inventory_item.php with $it, $id, $admin, $sups. */
if (!$it) return; $extra = $extra ?? 'both';
$links = function_exists('ilk_has') && ilk_has() ? ilk_of($id) : null; $isSw = function_exists('swl_item_is_software') && swl_item_is_software($it); $lics = $isSw ? swl_of($id) : []; $keys = $isSw && swl_keys_visible();
$self = url('inventory_item', ['id' => $id]); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
?>
<?php if ($links !== null && $extra !== 'licences'): ?>
<div class="card" id="links">
  <h2>🔗 <?= tt('ilk.title') ?> <span class="count"><?= count($links) ?></span></h2>
  <?php if (!$links): ?><p class="hint"><?= tt('ilk.none') ?></p><?php else: ?>
  <ul class="list-plain" style="margin:0 0 8px; padding:0; list-style:none;">
    <?php foreach ($links as $lk): ?><li style="display:flex; gap:8px; align-items:center; padding:4px 0; border-bottom:1px solid var(--line,#eef1f5);"><span><?= ilk_icon($lk['kind']) ?></span><a href="<?= e($lk['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" style="flex:1;"><?= e($lk['title']) ?> <small class="hint">· <?= e(t('ilk.k_' . $lk['kind'])) ?> · <?= e((string) parse_url($lk['url'], PHP_URL_HOST)) ?></small></a>
      <?php if ($admin): ?><form method="post" action="<?= $self ?>#links" data-lock data-confirm="<?= e(t('ilk.remove_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="inv_link_del"><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="link_id" value="<?= (int)$lk['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('btn.delete') ?>">🗑</button></form><?php endif; ?></li><?php endforeach; ?>
  </ul><?php endif; ?>
  <?php if ($admin): ?>
  <details><summary><b>+ <?= tt('ilk.add') ?></b></summary>
    <form method="post" action="<?= $self ?>#links" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="inv_link_add"><input type="hidden" name="item_id" value="<?= $id ?>">
      <div class="grid grid-2"><div class="field"><label><?= tt('ilk.url') ?> *</label><input name="url" type="url" required maxlength="500" placeholder="https://…" inputmode="url"></div><div class="field"><label><?= tt('ilk.kind') ?></label><select name="kind" data-nosearch><?php foreach (ILK_KINDS as $k): ?><option value="<?= $k ?>"><?= ilk_icon($k) ?> <?= e(t('ilk.k_' . $k)) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label><?= tt('ilk.link_title') ?></label><input name="title" maxlength="120" placeholder="<?= tt('ilk.title_ph') ?>"></div>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button> <span class="hint"><?= tt('ilk.hint') ?></span></form>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isSw && $extra !== 'links'): $ed = (int)($_GET['lic'] ?? 0); $L = $ed ? swl_load($ed) : null; if ($L && (int)$L['item_id'] !== $id) $L = null; ?>
<div class="card" id="licences">
  <h2>🔑 <?= tt('swl.title') ?> <span class="count"><?= count($lics) ?></span></h2>
  <p class="hint" style="margin-top:0;"><?= tt('swl.lede') ?></p>
  <?php if (!$lics): ?><p class="hint"><?= tt('swl.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('swl.product') ?></th><th><?= tt('swl.key') ?></th><th class="num"><?= tt('swl.seats') ?></th><th><?= tt('swl.expires') ?></th><th><?= tt('swl.assigned') ?></th><th><?= tt('prq.status') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($lics as $lc): ?><tr class="<?= $lc['status'] === 'retired' ? 'is-retired' : '' ?>">
      <td><b><?= e($lc['product'] ?: $it['description']) ?></b><?= $lc['version'] ? ' <span class="hint">' . e(preg_match('/^v/i', $lc['version']) ? $lc['version'] : 'v' . $lc['version']) . '</span>' : '' ?><?= $lc['serial_no'] ? '<br><small class="mono hint">S/N ' . e($lc['serial_no']) . '</small>' : '' ?><?= $lc['supplier'] ? '<br><small class="hint">' . e($lc['supplier']) . ($lc['purchased_at'] ? ' · ' . e(fmt_date($lc['purchased_at'])) : '') . '</small>' : '' ?></td>
      <td class="mono"><?php if ($keys && $lc['licence_key']): ?><details><summary style="cursor:pointer;"><?= e(swl_mask($lc['licence_key'])) ?></summary><code style="user-select:all; word-break:break-all;"><?= e($lc['licence_key']) ?></code></details><?php else: ?><?= e(swl_mask($lc['licence_key'])) ?><?php endif; ?></td>
      <td class="num"><?= e(stk_qty((float)$lc['seats'])) ?></td>
      <td><?= $lc['expires_at'] ? '<span class="pill pill--' . swl_tone($lc) . '">' . e(fmt_date($lc['expires_at'])) . '</span>' . ($lc['days_left'] !== null ? '<br><small class="hint">' . e((int)$lc['days_left'] < 0 ? t('swl.s_expired') : t('swl.days_left', ['n' => (int)$lc['days_left']])) . '</small>' : '') : '<span class="hint">' . tt('swl.k_' . $lc['kind']) . '</span>' ?></td>
      <td><?= $lc['assigned_kind'] !== 'none' ? e(t('swl.a_' . $lc['assigned_kind'])) . ': <b>' . e($lc['assigned_label'] ?? '') . '</b>' : '<span class="hint">—</span>' ?><?= $lc['note'] ? '<br><small class="hint">' . e($lc['note']) . '</small>' : '' ?></td>
      <td><?= pill(swl_tone($lc), t('swl.s_' . $lc['status'])) ?></td>
      <td class="num" style="white-space:nowrap;"><?php if ($admin): ?><a class="btn btn--ghost btn--sm" href="<?= url('inventory_item', ['id' => $id, 'lic' => (int)$lc['id']]) ?>#lic-form">✎</a> <form method="post" action="<?= $self ?>#licences" style="display:inline" data-lock data-confirm="<?= e(t('swl.remove_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="inv_lic_del"><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="lic_id" value="<?= (int)$lc['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php if ($admin): $projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') OR id = ? ORDER BY project_code DESC LIMIT 400", [(int)($L['assigned_kind'] ?? '') === 'project' ? (int)$L['assigned_id'] : 0]); $vessels = all('SELECT id, name FROM vessels WHERE is_active = 1 AND merged_into IS NULL ORDER BY name LIMIT 600'); $users = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name"); $orgs = function_exists('co_all') ? co_all() : []; ?>
  <details id="lic-form"<?= $L ? ' open' : '' ?> style="margin-top:8px;"><summary><b><?= $L ? '✎ ' . tt('swl.edit') : '+ ' . tt('swl.add') ?></b></summary>
    <form method="post" action="<?= $self ?>#licences" data-lock autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="inv_lic_save"><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= (int)($L['id'] ?? 0) ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('swl.product') ?></label><input name="product" maxlength="160" value="<?= e($L['product'] ?? $it['description']) ?>"></div>
        <div class="field"><label><?= tt('swl.version') ?></label><input name="version" maxlength="60" value="<?= e($L['version'] ?? '') ?>"></div>
        <div class="field"><label><?= tt('swl.key') ?></label><input name="licence_key" maxlength="255" class="mono" value="<?= $keys ? e($L['licence_key'] ?? '') : '' ?>" autocomplete="off" spellcheck="false"></div>
        <div class="field"><label><?= tt('swl.serial') ?></label><input name="serial_no" maxlength="120" class="mono" value="<?= e($L['serial_no'] ?? '') ?>"></div>
        <div class="field"><label><?= tt('swl.seats') ?></label><input name="seats" type="number" step="0.01" min="0" value="<?= e((string)($L['seats'] ?? '1')) ?>"></div>
        <div class="field"><label><?= tt('swl.kind') ?></label><select name="kind" data-nosearch><?php foreach (SWL_KINDS as $k): ?><option value="<?= $k ?>"<?= $sel($k, $L['kind'] ?? 'perpetual') ?>><?= e(t('swl.k_' . $k)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('swl.purchased') ?></label><input name="purchased_at" type="date" value="<?= e($L['purchased_at'] ?? '') ?>"></div>
        <div class="field"><label><?= tt('swl.expires') ?></label><input name="expires_at" type="date" value="<?= e($L['expires_at'] ?? '') ?>"></div>
        <div class="field"><label><?= tt('stk.supplier') ?></label><select name="supplier_id"><option value="">—</option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $L['supplier_id'] ?? 0) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('prq.status') ?></label><select name="status" data-nosearch><?php foreach (SWL_STATES as $k): ?><option value="<?= $k ?>"<?= $sel($k, $L['status'] ?? 'active') ?>><?= e(t('swl.s_' . $k)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('swl.assigned') ?></label><select name="assigned_kind" data-nosearch id="lic-ak"><?php foreach (SWL_ASSIGN as $k): ?><option value="<?= $k ?>"<?= $sel($k, $L['assigned_kind'] ?? 'none') ?>><?= e(t('swl.a_' . $k)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('swl.assigned_to') ?></label>
          <span class="lic-a lic-a-project"><select name="assigned_id"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= ($L['assigned_kind'] ?? '') === 'project' ? $sel($p['id'], $L['assigned_id']) : '' ?>><?= e(trim(($p['project_code'] ?: '') . ' · ' . $p['name'])) ?></option><?php endforeach; ?></select></span>
          <span class="lic-a lic-a-vessel"><select name="assigned_id" disabled><option value="">—</option><?php foreach ($vessels as $vv): ?><option value="<?= (int)$vv['id'] ?>"<?= ($L['assigned_kind'] ?? '') === 'vessel' ? $sel($vv['id'], $L['assigned_id']) : '' ?>><?= e($vv['name']) ?></option><?php endforeach; ?></select></span>
          <span class="lic-a lic-a-user"><select name="assigned_id" disabled><option value="">—</option><?php foreach ($users as $uu): ?><option value="<?= (int)$uu['id'] ?>"<?= ($L['assigned_kind'] ?? '') === 'user' ? $sel($uu['id'], $L['assigned_id']) : '' ?>><?= e($uu['full_name']) ?></option><?php endforeach; ?></select></span>
          <span class="lic-a lic-a-company"><select name="assigned_id" disabled><option value="">—</option><?php foreach ($orgs as $oc): ?><option value="<?= (int)$oc['id'] ?>"<?= ($L['assigned_kind'] ?? '') === 'company' ? $sel($oc['id'], $L['assigned_id']) : '' ?>><?= e($oc['name']) ?></option><?php endforeach; ?></select></span>
        </div>
      </div>
      <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="400" value="<?= e($L['note'] ?? '') ?>"></div>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($L): ?><a class="btn btn--ghost btn--sm" href="<?= $self ?>#licences"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
  </details>
  <script nonce="<?= csp_nonce() ?>">(function(){var k=document.getElementById('lic-ak');if(!k)return;function f(){var v=k.value;document.querySelectorAll('.lic-a').forEach(function(w){var on=w.classList.contains('lic-a-'+v);w.style.display=on?'':'none';var s=w.querySelector('select');if(s)s.disabled=!on;});}k.addEventListener('change',f);f();})();</script>
  <?php endif; ?>
</div>
<?php endif; ?>
