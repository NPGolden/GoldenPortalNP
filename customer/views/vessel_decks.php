<?php
/** Vessel › Decks & working areas — deck photos and the rectangle tool (GAS-CP-R31-001). */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$id = (int)($_GET['id'] ?? 0);
$v = one('SELECT v.*, c.name AS customer_name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ?', [$id]);
if (!$v || (!$staff && (int)$v['customer_id'] !== (int)$u['customer_id'])) { http_response_code(404); layout_head(t('err.notfound'), 'vessels'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$edit = wf_can_edit($id);
$decks = wf_decks($id); $areas = wf_areas($id, false);
$cur = (int)($_GET['deck'] ?? 0) ?: (int)($decks[0]['id'] ?? 0);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('wf.decks_title') . ' — ' . $v['name'], 'vessels');
?>
<p class="eyebrow"><a href="<?= url('vessels') ?>"><?= tt('nav.vessels') ?></a> › <a href="<?= url('vessel', ['id' => $id]) ?>"><?= e($v['name']) ?></a> › <?= tt('wf.decks_title') ?></p>
<div class="page-head">
  <div><h1>🗺 <?= tt('wf.decks_title') ?> — <?= e($v['name']) ?></h1><p class="lede"><?= tt('wf.decks_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('vessel', ['id' => $id]) ?>">← <?= tt('v.one') ?></a></div>
</div>
<div class="wf-legend"><?php foreach (WF_CLASSES as $k => $hex): ?><span><i style="background:#<?= $hex ?>"></i><?= e(wf_class_label($k)) ?></span><?php endforeach; ?></div>

<div class="cols wf-cols">
  <div>
    <div class="card">
      <div class="card-head"><h2><?= tt('wf.decks') ?> <span class="count"><?= count($decks) ?></span></h2></div>
      <?php if (!$decks): ?><p class="hint"><?= tt('wf.no_decks') ?></p><?php endif; ?>
      <div class="tabs" style="margin:6px 0 10px;"><?php foreach ($decks as $d): ?><a class="tab<?= (int)$d['id'] === $cur ? ' is-on' : '' ?>" href="<?= url('vessel_decks', ['id' => $id, 'deck' => $d['id']]) ?>"><?= e($d['name']) ?> <span class="n"><?= count(array_filter($areas, fn($a) => (int)$a['deck_id'] === (int)$d['id'])) ?></span></a><?php endforeach; ?></div>
      <?php $deck = null; foreach ($decks as $d) if ((int)$d['id'] === $cur) $deck = $d; ?>
      <?php if ($deck): ?>
        <?= wf_deck_html($deck, $areas, [], $edit) ?>
        <?php if ($edit): ?>
        <p class="hint" style="margin:8px 0 0;"><?= tt('wf.draw_hint') ?></p>
        <form method="post" action="<?= url('vessel_decks', ['id' => $id, 'deck' => $cur]) ?>" enctype="multipart/form-data" class="grid grid-4" style="align-items:end;margin-top:12px;">
          <?= csrf_field() ?><input type="hidden" name="action" value="wf_deck_save"><input type="hidden" name="vessel_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= $cur ?>">
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_name') ?></label><input name="name" maxlength="80" value="<?= e($deck['name']) ?>"></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_order') ?></label><input type="number" name="sort_order" value="<?= (int)$deck['sort_order'] ?>"></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_photo') ?></label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
          <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button>
            <button class="btn btn--ghost btn--sm" type="submit" name="action" value="wf_deck_delete" formnovalidate data-js-confirm="<?= e(t('wf.deck_delete_confirm')) ?>"><?= tt('btn.delete') ?></button></div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($edit): ?>
      <details style="margin-top:14px;"><summary class="hint">+ <?= tt('wf.deck_add') ?></summary>
        <form method="post" action="<?= url('vessel_decks', ['id' => $id]) ?>" enctype="multipart/form-data" class="grid grid-4" style="align-items:end;margin-top:8px;">
          <?= csrf_field() ?><input type="hidden" name="action" value="wf_deck_save"><input type="hidden" name="vessel_id" value="<?= $id ?>"><input type="hidden" name="id" value="0">
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_name') ?></label><input name="name" maxlength="80" placeholder="<?= tt('wf.deck_name_ph') ?>" required></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_order') ?></label><input type="number" name="sort_order" value="<?= count($decks) + 1 ?>"></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_photo') ?></label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
          <div class="btn-row"><button class="btn btn--sm" type="submit">+ <?= tt('wf.deck_add') ?></button></div>
        </form></details>
      <?php endif; ?>
    </div>
  </div>
  <div>
    <div class="card" id="wf-areas">
      <div class="card-head"><h2><?= tt('wf.areas') ?> <span class="count"><?= count(array_filter($areas, fn($a) => $a['is_active'])) ?></span></h2></div>
      <p class="hint"><?= tt('wf.areas_hint') ?></p>
      <?php if ($areas): ?>
      <table class="tbl" data-nosort><thead><tr><th><?= tt('wf.area') ?></th><th><?= tt('wf.class') ?></th><th><?= tt('wf.deck') ?></th><th><?= tt('wf.on_map') ?></th><?php if ($edit): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($areas as $a): ?><tr class="<?= $a['is_active'] ? '' : 'is-muted' ?>" data-area-row="<?= (int)$a['id'] ?>">
          <td><b><?= e($a['name']) ?></b><?= $a['notes'] ? '<div class="hint">' . e($a['notes']) . '</div>' : '' ?><?= $a['is_active'] ? '' : ' ' . pill('mute', t('v.inactive')) ?></td>
          <td><?= wf_class_pill($a['class']) ?></td><td><?= e($a['deck'] ?: '—') ?></td>
          <td><?= $a['rw'] !== null ? '<span class="pill pill--green">' . tt('wf.drawn') . '</span>' : '<span class="pill pill--mute">' . tt('wf.list_only') . '</span>' ?></td>
          <?php if ($edit): ?><td style="text-align:right;white-space:nowrap;"><a class="btn btn--ghost btn--sm" href="<?= url('vessel_decks', ['id' => $id, 'deck' => $a['deck_id'] ?: $cur, 'edit' => $a['id']]) ?>#wf-form">✎</a>
            <form method="post" action="<?= url('vessel_decks', ['id' => $id, 'deck' => $cur]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="wf_area_delete"><input type="hidden" name="vessel_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= e(t('wf.area_delete_confirm')) ?>">×</button></form></td><?php endif; ?>
        </tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
      <?php if ($edit): $ea = null; if (!empty($_GET['edit'])) foreach ($areas as $a) if ((int)$a['id'] === (int)$_GET['edit']) $ea = $a; ?>
      <form method="post" action="<?= url('vessel_decks', ['id' => $id, 'deck' => $cur]) ?>" id="wf-form" class="wf-form" style="margin-top:12px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="wf_area_save"><input type="hidden" name="vessel_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= (int)($ea['id'] ?? 0) ?>">
        <input type="hidden" name="rx" id="rx" value="<?= $ea['rx'] ?? '' ?>"><input type="hidden" name="ry" id="ry" value="<?= $ea['ry'] ?? '' ?>"><input type="hidden" name="rw" id="rw" value="<?= $ea['rw'] ?? '' ?>"><input type="hidden" name="rh" id="rh" value="<?= $ea['rh'] ?? '' ?>">
        <h3 class="ed-h"><?= $ea ? tt('wf.area_edit') : tt('wf.area_new') ?></h3>
        <div class="grid grid-2">
          <div class="field" style="margin:0;"><label><?= tt('wf.area') ?> *</label><input name="name" maxlength="120" required value="<?= e($ea['name'] ?? '') ?>" placeholder="<?= tt('wf.area_ph') ?>"></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.class') ?></label><select name="class"><?php foreach (WF_CLASSES as $k => $hex): ?><option value="<?= $k ?>"<?= $sel($k, $ea['class'] ?? 'interior') ?>><?= e(wf_class_label($k)) ?></option><?php endforeach; ?></select></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck') ?></label><select name="deck_id"><option value=""><?= tt('wf.no_deck') ?></option><?php foreach ($decks as $d): ?><option value="<?= $d['id'] ?>"<?= $sel($d['id'], $ea['deck_id'] ?? $cur) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field" style="margin:0;"><label><?= tt('wf.deck_order') ?></label><input type="number" name="sort_order" value="<?= (int)($ea['sort_order'] ?? 0) ?>"></div>
          <div class="field" style="margin:0;grid-column:1 / -1;"><label><?= tt('th.remarks') ?></label><input name="notes" maxlength="500" value="<?= e($ea['notes'] ?? '') ?>"></div>
        </div>
        <p class="hint" id="wf-rect-hint" style="margin:8px 0;"><?= ($ea['rw'] ?? null) !== null ? e(t('wf.rect_set')) : e(t('wf.rect_none')) ?></p>
        <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button><button class="btn btn--ghost btn--sm" type="button" id="wf-clear-rect"><?= tt('wf.rect_clear') ?></button><?php if ($ea): ?><a class="btn btn--ghost btn--sm" href="<?= url('vessel_decks', ['id' => $id, 'deck' => $cur]) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($edit): ?>
<script nonce="<?= csp_nonce() ?>">
(function () {   // draw a rectangle on the deck photo: mouse / touch drag; the form takes the % values; an existing area can be redrawn by dragging while it is in the form
  var deck = document.querySelector('.wf-deck.is-edit'); if (!deck) return;
  var rx = document.getElementById('rx'), ry = document.getElementById('ry'), rw = document.getElementById('rw'), rh = document.getElementById('rh'), hint = document.getElementById('wf-rect-hint');
  var ghost = document.createElement('div'); ghost.className = 'wf-rect wf-ghost'; deck.appendChild(ghost); var s = null;
  function pos(e) { var r = deck.getBoundingClientRect(); var p = e.touches ? e.touches[0] : e; return { x: Math.max(0, Math.min(100, (p.clientX - r.left) / r.width * 100)), y: Math.max(0, Math.min(100, (p.clientY - r.top) / r.height * 100)) }; }
  function show(a, b) { var x = Math.min(a.x, b.x), y = Math.min(a.y, b.y), w = Math.abs(a.x - b.x), h = Math.abs(a.y - b.y); ghost.style.display = 'block'; ghost.style.left = x + '%'; ghost.style.top = y + '%'; ghost.style.width = w + '%'; ghost.style.height = h + '%'; return { x: x, y: y, w: w, h: h }; }
  if (rw.value) show({ x: +rx.value, y: +ry.value }, { x: +rx.value + +rw.value, y: +ry.value + +rh.value });
  function down(e) { if (e.target.closest('.wf-rect') && !e.target.classList.contains('wf-ghost')) return; s = pos(e); e.preventDefault(); }
  function move(e) { if (!s) return; show(s, pos(e)); e.preventDefault(); }
  function up(e) { if (!s) return; var r = show(s, pos(e.changedTouches ? e.changedTouches[0] : e)); s = null; if (r.w < 0.5 || r.h < 0.5) { ghost.style.display = 'none'; return; } rx.value = r.x.toFixed(3); ry.value = r.y.toFixed(3); rw.value = r.w.toFixed(3); rh.value = r.h.toFixed(3); hint.textContent = <?= json_encode(t('wf.rect_set'), JSON_UNESCAPED_UNICODE) ?>; document.querySelector('#wf-form input[name=name]').focus(); }
  deck.addEventListener('mousedown', down); window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
  deck.addEventListener('touchstart', down, { passive: false }); deck.addEventListener('touchmove', move, { passive: false }); deck.addEventListener('touchend', up);
  deck.querySelectorAll('.wf-rect[data-area]').forEach(function (r) { r.addEventListener('click', function () { location.href = <?= json_encode(url('vessel_decks', ['id' => $id, 'deck' => $cur, 'edit' => ''])) ?> + r.dataset.area + '#wf-form'; }); });
  document.getElementById('wf-clear-rect').addEventListener('click', function () { rx.value = ry.value = rw.value = rh.value = ''; ghost.style.display = 'none'; hint.textContent = <?= json_encode(t('wf.rect_none'), JSON_UNESCAPED_UNICODE) ?>; });
})();
</script>
<?php endif; ?>
<?php layout_foot(); ?>
