<?php
require __DIR__ . '/_layout.php';
layout_head(t('e404.title'));
?>
<p class="eyebrow">404</p>
<h1><?= tt('e404.title') ?></h1>
<p class="lede"><?= tt('e404.lede') ?></p>
<a class="btn btn--gold" href="<?= url('dashboard') ?>"><?= tt('btn.back') ?></a>
<?php layout_foot(); ?>
