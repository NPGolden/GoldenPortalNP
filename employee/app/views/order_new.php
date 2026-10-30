<?php
require __DIR__ . '/_layout.php';
require_role('field_engineer', 'system_admin');

$maxItems = (int) cfg('app.max_items', 20);
$projects = all('SELECT p.project_number, p.name FROM projects p WHERE p.is_active = 1 ORDER BY p.project_number');
$vessels  = all('SELECT id, name, yard FROM vessels WHERE is_active = 1 ORDER BY name');

/** One line item. Rendered server-side for line 1, and inside a <template> for the rest. */
function line_row(string $i, string $no): void { ?>
  <div class="line">
    <div class="line-no"><?= e($no) ?></div>
    <div>
      <div class="line-grid">
        <div class="field" style="margin:0;">
          <label><?= tt('new.what') ?></label>
          <input type="text" name="description[<?= e($i) ?>]"<?= $i === '0' ? ' required' : '' ?>
                 maxlength="500" placeholder="<?= tt('new.what_ph') ?>">
        </div>
        <div class="field" style="margin:0;">
          <label><?= tt('new.part_no') ?></label>
          <input type="text" name="part_number[<?= e($i) ?>]" maxlength="80">
        </div>
        <div class="field" style="margin:0;">
          <label><?= tt('new.maker') ?></label>
          <input type="text" name="manufacturer[<?= e($i) ?>]" maxlength="120">
        </div>
        <div class="field" style="margin:0;">
          <label><?= tt('new.qty') ?></label>
          <input type="number" name="quantity[<?= e($i) ?>]" value="1" min="0.001" step="any"<?= $i === '0' ? ' required' : '' ?>>
        </div>
        <div class="field" style="margin:0;">
          <label><?= tt('new.unit') ?></label>
          <input type="text" name="unit[<?= e($i) ?>]" value="pcs" maxlength="16">
        </div>
      </div>

      <div class="line-media">
        <div class="field" style="margin:0;">
          <label><?= tt('new.photos') ?></label>
          <input type="file" name="photos[<?= e($i) ?>][]" accept="image/*" multiple capture="environment">
          <div class="thumbs"></div>
        </div>
        <div class="field" style="margin:0;" data-recorder>
          <label><?= tt('new.voice') ?></label>
          <button type="button" class="btn btn--ghost btn--sm rec-btn"><?= tt('new.record') ?></button>
          <input type="file" name="audio[<?= e($i) ?>][]" accept="audio/*" style="margin-top:8px;">
          <p class="hint rec-status"><?= tt('new.audio_hint') ?></p>
        </div>
      </div>

      <div style="margin-top:12px;">
        <button type="button" class="linkish remove-line"><?= tt('new.remove') ?></button>
      </div>
    </div>
  </div>
<?php }

layout_head(t('nav.raise'), 'order_new');
?>

<p class="eyebrow"><?= tt('new.eyebrow') ?></p>
<h1><?= tt('new.title') ?></h1>
<p class="lede"><?= e(t('new.lede', ['max' => $maxItems])) ?></p>

<?php if (!$projects): ?>
  <div class="empty"><p><?= tt('new.noprojects') ?></p></div>
  <?php layout_foot(); return; ?>
<?php endif; ?>

<form method="post" action="<?= url('order_new') ?>" enctype="multipart/form-data"
      data-lock data-busy="<?= tt('new.submitting') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="order_create">
  <?= form_token_field() ?>
  <input type="hidden" name="_return" value="order_new">

  <div class="card">
    <h2><?= tt('new.job') ?></h2>
    <div class="grid grid-3">
      <div class="field">
        <label for="project_number"><?= tt('new.project') ?></label>
        <select id="project_number" name="project_number" required>
          <option value=""><?= tt('new.project_ph') ?></option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= e($p['project_number']) ?>"><?= e($p['project_number']) ?> — <?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint"><?= tt('new.project_hint') ?></p>
      </div>

      <div class="field">
        <label for="vessel_id"><?= tt('new.vessel') ?></label>
        <select id="vessel_id" name="vessel_id">
          <option value=""><?= tt('new.vessel_none') ?></option>
          <?php foreach ($vessels as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?><?= $v['yard'] ? ' — ' . e($v['yard']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint"><?= tt('new.vessel_hint') ?></p>
      </div>

      <div class="field">
        <label for="priority"><?= tt('new.priority') ?></label>
        <select id="priority" name="priority">
          <option value="routine"><?= tt('priority.routine') ?></option>
          <option value="urgent"><?= tt('priority.urgent') ?></option>
          <option value="vessel_down"><?= tt('priority.vessel_down') ?></option>
        </select>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label for="needed_by"><?= tt('new.needed_by') ?></label>
        <input id="needed_by" name="needed_by" type="date" min="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label for="delivery_address"><?= tt('new.deliver_to') ?></label>
        <input id="delivery_address" name="delivery_address" type="text" placeholder="<?= tt('new.deliver_ph') ?>">
      </div>
    </div>

    <div class="field">
      <label for="notes"><?= tt('new.notes') ?></label>
      <textarea id="notes" name="notes" placeholder="<?= tt('new.notes_ph') ?>"></textarea>
    </div>
  </div>

  <div class="card">
    <div class="page-head" style="margin-bottom:16px;">
      <div>
        <h2 style="margin:0;"><?= tt('new.items') ?></h2>
        <p class="hint" style="margin:2px 0 0;">
          <span id="line-count"><?= e(t('new.line_of', ['n' => 1, 'max' => $maxItems])) ?></span>
        </p>
      </div>
      <button type="button" class="btn btn--ghost btn--sm" id="add-line"
              data-label-add="<?= tt('new.add') ?>"
              data-label-full="<?= e(t('new.add_full', ['max' => $maxItems])) ?>"><?= tt('new.add') ?></button>
    </div>

    <div id="lines" data-max="<?= $maxItems ?>"
         data-line-of="<?= e(t('new.line_of', ['n' => '{n}', 'max' => $maxItems])) ?>">
      <?php line_row('0', '01'); ?>
    </div>

    <template id="line-template"><?php line_row('__i__', '02'); ?></template>
  </div>

  <div class="actions">
    <button class="btn btn--gold" type="submit" name="submit_now" value="1"><?= tt('new.submit') ?></button>
    <button class="btn btn--ghost" type="submit" name="submit_now" value="0"><?= tt('new.draft') ?></button>
  </div>
</form>

<script>
  window.GAS_I18N = {
    stop:       <?= json_encode(t('new.stop')) ?>,
    record:     <?= json_encode(t('new.record')) ?>,
    recording:  <?= json_encode(t('new.recording')) ?>,
    noMic:      <?= json_encode(t('new.no_mic')) ?>,
    micRefused: <?= json_encode(t('new.mic_refused')) ?>,
    voiceOk:    <?= json_encode(t('new.voice_ok')) ?>
  };
</script>

<?php layout_foot(); ?>
