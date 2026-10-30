<?php
require __DIR__ . '/_layout.php';
require_login();
layout_head(t('e404.title'));
?>
<p class="eyebrow">404</p>
<h1><?= tt('e404.title') ?></h1>
<a class="btn btn--gold" href="<?= url('dashboard') ?>" style="margin-top:12px;"><?= tt('e404.back') ?></a>
<?php layout_foot(); ?>
