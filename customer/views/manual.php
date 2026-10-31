<?php
/** User manual — two editions: ?p=manual&for=customer (everybody) and ?p=manual&for=golden (staff only).   GAS-CP-R20-002
 *  Content lives in _manual_customer.php / _manual_golden.php; figures in ../assets/manual/. Figures and sections are numbered automatically. */
require __DIR__ . '/_layout.php';
$u = require_login();
$for = (string)($_GET['for'] ?? 'customer');
if (in_array($for, ['golden', 'pm', 'staff', 'hr'], true) && !is_staff()) $for = 'customer';
if ($for === 'hr' && !(function_exists('hr_can') && hr_can())) $for = 'staff';   // R63-003: the HR handbook is for HR / Owner / administrators
if (!in_array($for, ['customer', 'golden', 'pm', 'staff', 'hr'], true)) $for = 'customer';
$M = require __DIR__ . '/_manual_' . $for . '.php';
$news = []; foreach (array_slice(cp_patches(), 0, 14) as $pt) $news[] = $pt['version'] . ' · ' . fmt_date($pt['date']) . ' — ' . $pt['title'];
$M['sections'][] = ['id' => 'whatsnew', 'title' => t('mn.whatsnew'), 'body' => [t('mn.whatsnew_lede', ['v' => CP_VERSION]), ['ul' => $news]]];   // R23-002: release notes from the patch list, always current
$assetVer = @filemtime(__DIR__ . '/../../assets/manual') ?: 1;
$figNo = 0; $figList = [];
$figHtml = function (string $file, string $cap) use (&$figNo, &$figList, $assetVer): string {
    $figNo++; $figList[] = [$figNo, $cap];
    $src = '../assets/manual/' . rawurlencode($file) . '?v=' . $assetVer;
    $isSvg = str_ends_with($file, '.svg');
    return '<figure class="mn-fig' . ($isSvg ? ' mn-fig--svg' : '') . '" id="fig' . $figNo . '"><a href="' . e($src) . '" target="_blank" rel="noopener"><img src="' . e($src) . '" alt="' . e($cap) . '" loading="lazy"></a><figcaption><b>' . e(t('mn.figure', ['n' => $figNo])) . '</b> — ' . e($cap) . '</figcaption></figure>';
};
$render = function (array $body) use ($figHtml): string {
    $h = '';
    foreach ($body as $b) {
        if (is_string($b)) $h .= '<p>' . e($b) . '</p>';
        elseif (isset($b['fig'])) $h .= $figHtml($b['fig'], $b['cap'] ?? '');
        elseif (isset($b['ul'])) $h .= '<ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $b['ul'])) . '</ul>';
        elseif (isset($b['ol'])) $h .= '<ol>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $b['ol'])) . '</ol>';
        elseif (isset($b['tip'])) $h .= '<div class="mn-tip"><span>💡</span><div>' . e($b['tip']) . '</div></div>';
    }
    return $h;
};
$sections = '';
foreach ($M['sections'] as $i => $s) $sections .= '<section class="mn-sec" id="' . e($s['id']) . '"><h2><span class="mn-no">' . ($i + 1) . '</span>' . e($s['title']) . '</h2>' . $render($s['body']) . '</section>';
layout_head($M['title'], 'support');
?>
<p class="eyebrow"><a href="<?= url('support') ?>"><?= tt('su.title') ?></a> › <?= tt('mn.title') ?></p>
<div class="page-head no-print">
  <div><h1><?= e($M['title']) ?></h1><p class="lede"><?= e($M['lede']) ?></p></div>
  <div class="btn-row">
    <?php if (is_staff()): ?><a class="btn btn--ghost btn--sm<?= $for === 'customer' ? ' is-on' : '' ?>" href="<?= url('manual', ['for' => 'customer']) ?>"><?= tt('mn.for_customer') ?></a><a class="btn btn--ghost btn--sm<?= $for === 'golden' ? ' is-on' : '' ?>" href="<?= url('manual', ['for' => 'golden']) ?>"><?= tt('mn.for_golden') ?></a><a class="btn btn--ghost btn--sm<?= $for === 'pm' ? ' is-on' : '' ?>" href="<?= url('manual', ['for' => 'pm']) ?>"><?= tt('mn.for_pm') ?></a><a class="btn btn--ghost btn--sm<?= $for === 'staff' ? ' is-on' : '' ?>" href="<?= url('manual', ['for' => 'staff']) ?>"><?= tt('mn.for_staff') ?></a><?php if (function_exists('hr_can') && hr_can()): ?><a class="btn btn--ghost btn--sm<?= $for === 'hr' ? ' is-on' : '' ?>" href="<?= url('manual', ['for' => 'hr']) ?>"><?= tt('mn.for_hr') ?></a><?php endif; endif; ?>
    <button class="btn btn--gold btn--sm" type="button" data-js-print="1">⎙ <?= tt('mn.print') ?></button>
  </div>
</div>
<div class="mn-wrap">
  <aside class="mn-toc no-print">
    <div class="card card--flat">
      <h3 class="ed-h"><?= tt('mn.contents') ?></h3>
      <input type="search" id="mn-filter" placeholder="<?= tt('mn.filter_ph') ?>" value="<?= e((string)($_GET['hl'] ?? '')) ?>" style="width:100%;box-sizing:border-box;margin:0 0 8px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;font:400 13px var(--sans);">
      <ol class="mn-toc-list"><?php foreach ($M['sections'] as $s): ?><li><a href="#<?= e($s['id']) ?>"><?= e($s['title']) ?></a></li><?php endforeach; ?></ol>
      <p class="hint" style="margin:10px 0 0;"><?= e(t('mn.version', ['v' => CP_VERSION, 'd' => fmt_date(CP_BUILD)])) ?></p>
    </div>
  </aside>
  <div class="mn-body card">
    <?= $sections ?>
    <section class="mn-sec" id="figures"><h2><span class="mn-no">◫</span><?= tt('mn.figures') ?></h2>
      <ol class="mn-figlist"><?php foreach ($figList as [$n, $cap]): ?><li><a href="#fig<?= $n ?>"><?= e(t('mn.figure', ['n' => $n])) ?></a> — <?= e($cap) ?></li><?php endforeach; ?></ol>
    </section>
    <p class="hint mn-foot"><?= e(t('mn.foot', ['v' => CP_VERSION])) ?></p>
  </div>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {   // live filter of the manual: sections without a match are folded, matches highlighted (also from a Support search: ?hl=)
  var inp = document.getElementById('mn-filter'); if (!inp) return;
  var secs = Array.prototype.slice.call(document.querySelectorAll('.mn-sec')), toc = Array.prototype.slice.call(document.querySelectorAll('.mn-toc-list li'));
  function clear(el) { el.querySelectorAll('mark.mn-hl').forEach(function (m) { m.replaceWith(document.createTextNode(m.textContent)); }); el.normalize(); }
  function mark(el, q) { var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null); var nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode); var re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
    nodes.forEach(function (n) { if (!re.test(n.nodeValue)) return; var frag = document.createDocumentFragment(), last = 0, s = n.nodeValue; s.replace(re, function (m, i) { frag.appendChild(document.createTextNode(s.slice(last, i))); var mk = document.createElement('mark'); mk.className = 'mn-hl'; mk.textContent = m; frag.appendChild(mk); last = i + m.length; }); frag.appendChild(document.createTextNode(s.slice(last))); n.parentNode.replaceChild(frag, n); }); }
  function apply() { var q = inp.value.trim(); secs.forEach(function (s, i) { clear(s); var hit = !q || s.textContent.toLowerCase().indexOf(q.toLowerCase()) >= 0; s.classList.toggle('is-folded', !hit); if (toc[i]) toc[i].classList.toggle('is-off', !hit); if (hit && q) mark(s, q); }); }
  inp.addEventListener('input', apply); if (inp.value) { apply(); var h = location.hash && document.querySelector(location.hash); if (h) h.scrollIntoView(); }
})();
</script>
<?php layout_foot(); ?>
