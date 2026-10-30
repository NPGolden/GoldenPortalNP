<?php
/** Administrator-only "delete this record" card.   GAS-CP-R17-007
 *  expects $adAction, $adId, $adBack (page slug for the form action), $adConfirm (text), optional $adNote */
if (!function_exists('is_cp_admin') || !is_cp_admin()) return;
?>
<div class="card danger-zone ad-card">
  <h2><?= tt('ad.title') ?></h2>
  <p class="hint"><?= e($adNote ?? t('ad.hint')) ?></p>
  <form method="post" action="<?= url($adBack) ?>" data-confirm="<?= e($adConfirm) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($adAction) ?>"><input type="hidden" name="id" value="<?= (int)$adId ?>">
    <button class="btn btn--danger btn--sm" type="submit">🗑 <?= tt('btn.delete') ?></button>
  </form>
</div>
